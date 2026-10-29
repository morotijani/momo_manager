<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Services\LedgerService;
use App\Services\SmsParserService;
use InvalidArgumentException;

final class TransactionController
{
    public function index(Request $request): void
    {
        $user = Security::requireAuth($request);
        $pdo = Database::connection();
        $companyId = (int) $user['company_id'];

        $where = ['t.company_id = ?'];
        $params = [$companyId];

        if ($user['role'] === 'VENDOR') {
            $where[] = 't.branch_id = ?';
            $params[] = (int) ($user['branch_id'] ?? 0);
        } elseif ($request->query('branch_id')) {
            $where[] = 't.branch_id = ?';
            $params[] = (int) $request->query('branch_id');
        }

        if ($request->query('vendor_id') && $user['role'] !== 'VENDOR') {
            $where[] = 't.vendor_id = ?';
            $params[] = (int) $request->query('vendor_id');
        }

        $network = strtoupper(trim((string) $request->query('network', '')));
        if (in_array($network, ['MTN', 'TELECEL', 'AT'], true)) {
            $where[] = 't.network = ?';
            $params[] = $network;
        }

        $txType = strtoupper(trim((string) $request->query('tx_type', '')));
        if (in_array($txType, ['DEPOSIT', 'TRANSFER', 'WITHDRAWAL'], true)) {
            $where[] = 't.tx_type = ?';
            $params[] = $txType;
        }

        $status = strtoupper(trim((string) $request->query('status', '')));
        if (in_array($status, ['COMPLETED', 'VOID_REQUESTED', 'VOIDED'], true)) {
            $where[] = 't.status = ?';
            $params[] = $status;
        }

        $dateFrom = trim((string) $request->query('date_from', ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $where[] = 't.created_at >= ?';
            $params[] = $dateFrom . ' 00:00:00';
        }

        $dateTo = trim((string) $request->query('date_to', ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $where[] = 't.created_at <= ?';
            $params[] = $dateTo . ' 23:59:59';
        }

        $q = trim((string) $request->query('q', ''));
        if ($q !== '') {
            $like = '%' . $q . '%';
            $where[] = '(t.customer_phone LIKE ? OR t.customer_name LIKE ? OR t.telco_tx_id LIKE ? OR t.reference LIKE ? OR t.sender_phone LIKE ?)';
            array_push($params, $like, $like, $like, $like, $like);
        }

        $whereSql = implode(' AND ', $where);
        $isCsv = strtolower((string) $request->query('export', '')) === 'csv';
        $limit = $isCsv ? 2000 : min(200, max(10, (int) $request->query('limit', 80)));

        $sql = "SELECT t.*,
                       b.name AS branch_name, b.code AS branch_code,
                       u.full_name AS vendor_name,
                       bw.merchant_number AS wallet_number
                FROM transactions t
                INNER JOIN branches b ON b.id = t.branch_id
                INNER JOIN users u ON u.id = t.vendor_id
                INNER JOIN branch_wallets bw ON bw.id = t.wallet_id
                WHERE {$whereSql}
                ORDER BY t.id DESC
                LIMIT {$limit}";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        if ($isCsv) {
            $csvHeaders = [
                'Date & Time', 'Branch', 'Vendor', 'Type', 'Wallet Network', 'Receiver Network',
                'Customer/Receiver Phone', 'Customer Name', 'Sender Name', 'Sender Phone',
                'Amount (GHS)', 'OTC Fee (GHS)', 'Cash Impact', 'E-Cash Impact',
                'Telco Tx ID', 'Reference', 'Entry Mode', 'Status',
            ];
            $csvRows = [];
            foreach ($rows as $r) {
                $csvRows[] = [
                    $r['created_at'],
                    $r['branch_name'],
                    $r['vendor_name'],
                    $r['tx_type'],
                    $r['network'],
                    $r['receiver_network'],
                    $r['customer_phone'],
                    $r['customer_name'] ?? '',
                    $r['sender_name'] ?? '',
                    $r['sender_phone'] ?? '',
                    number_format((float) $r['amount'], 2, '.', ''),
                    number_format((float) $r['fee_charged'], 2, '.', ''),
                    number_format((float) $r['cash_impact'], 2, '.', ''),
                    number_format((float) $r['ecash_impact'], 2, '.', ''),
                    $r['telco_tx_id'],
                    $r['reference'] ?? '',
                    $r['entry_mode'],
                    $r['status'],
                ];
            }
            Response::csv('momo_transactions_' . date('Ymd_His') . '.csv', $csvHeaders, $csvRows);
        }

        // Totals across filtered set
        $sumSql = "SELECT COUNT(*) AS count_all,
                          COALESCE(SUM(CASE WHEN t.status = 'COMPLETED' THEN t.amount ELSE 0 END), 0) AS volume_completed,
                          COALESCE(SUM(CASE WHEN t.status = 'COMPLETED' THEN t.fee_charged ELSE 0 END), 0) AS fees_completed
                   FROM transactions t
                   WHERE {$whereSql}";
        $sumStmt = $pdo->prepare($sumSql);
        $sumStmt->execute($params);
        $totals = $sumStmt->fetch() ?: ['count_all' => 0, 'volume_completed' => 0, 'fees_completed' => 0];

        foreach ($rows as &$r) {
            $r = self::formatRow($r);
        }
        unset($r);

        Response::success([
            'transactions' => $rows,
            'totals' => [
                'count' => (int) $totals['count_all'],
                'volume' => round((float) $totals['volume_completed'], 2),
                'fees' => round((float) $totals['fees_completed'], 2),
            ],
        ]);
    }

    public function parseSms(Request $request): void
    {
        Security::requireAuth($request);
        $rawSms = $request->string('raw_sms');
        if ($rawSms === '') {
            Response::error('Paste the telco confirmation SMS first.', 422);
        }

        $parsed = SmsParserService::parse($rawSms);
        Response::success(['parsed' => $parsed]);
    }

    public function store(Request $request): void
    {
        $user = Security::requireAuth($request);

        try {
            $result = LedgerService::recordTransaction($user, $request->all(), $request->ip());
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }

        Response::success($result, 'Transaction recorded & balances updated.', 201);
    }

    public function requestVoid(Request $request): void
    {
        $user = Security::requireAuth($request);
        $txId = (int) $request->param('id', '0');

        try {
            LedgerService::requestVoid($user, $txId, $request->string('reason'), $request->ip());
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }

        Response::success([], 'Void / correction request sent to the Enterprise Owner for approval.');
    }

    public function resolveVoid(Request $request): void
    {
        $boss = Security::requireRole($request, ['OWNER', 'MANAGER']);
        $txId = (int) $request->param('id', '0');

        try {
            $res = LedgerService::resolveVoid(
                $boss,
                $txId,
                $request->string('decision'),
                $request->string('note'),
                $request->ip()
            );
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }

        $msg = $res['status'] === 'VOIDED'
            ? 'Transaction voided and Cash / SIM E-Cash balances reversed.'
            : 'Void request rejected; transaction remains active.';
        Response::success($res, $msg);
    }

    public static function formatRow(array $r): array
    {
        $r['id'] = (int) $r['id'];
        $r['branch_id'] = (int) $r['branch_id'];
        $r['wallet_id'] = (int) $r['wallet_id'];
        $r['vendor_id'] = (int) $r['vendor_id'];
        $r['amount'] = round((float) $r['amount'], 2);
        $r['fee_charged'] = round((float) $r['fee_charged'], 2);
        $r['cash_impact'] = round((float) $r['cash_impact'], 2);
        $r['ecash_impact'] = round((float) $r['ecash_impact'], 2);
        $r['cash_balance_after'] = round((float) $r['cash_balance_after'], 2);
        $r['ecash_balance_after'] = round((float) $r['ecash_balance_after'], 2);
        $r['cashout_allowed_confirmed'] = (int) $r['cashout_allowed_confirmed'] === 1;
        return $r;
    }
}
