<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Services\AuditService;

final class ShiftController
{
    public function index(Request $request): void
    {
        $user = Security::requireAuth($request);
        $pdo = Database::connection();
        $companyId = (int) $user['company_id'];

        $sql = 'SELECT s.*, b.name AS branch_name, b.code AS branch_code, u.full_name AS vendor_name
                FROM shifts s
                INNER JOIN branches b ON b.id = s.branch_id
                INNER JOIN users u ON u.id = s.vendor_id
                WHERE s.company_id = ?';
        $params = [$companyId];

        if ($user['role'] === 'VENDOR') {
            $sql .= ' AND s.branch_id = ?';
            $params[] = (int) ($user['branch_id'] ?? 0);
        } elseif ($request->query('branch_id')) {
            $sql .= ' AND s.branch_id = ?';
            $params[] = (int) $request->query('branch_id');
        }

        $sql .= ' ORDER BY s.id DESC LIMIT 30';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $shifts = $stmt->fetchAll();

        foreach ($shifts as &$s) {
            $s['id'] = (int) $s['id'];
            $s['branch_id'] = (int) $s['branch_id'];
            $s['vendor_id'] = (int) $s['vendor_id'];
            $s['opening_cash'] = round((float) $s['opening_cash'], 2);
            $s['closing_cash_expected'] = $s['closing_cash_expected'] !== null ? round((float) $s['closing_cash_expected'], 2) : null;
            $s['closing_cash_actual'] = $s['closing_cash_actual'] !== null ? round((float) $s['closing_cash_actual'], 2) : null;
            $s['cash_variance'] = $s['cash_variance'] !== null ? round((float) $s['cash_variance'], 2) : null;
            $s['ecash_variance'] = $s['ecash_variance'] !== null ? round((float) $s['ecash_variance'], 2) : null;
            $s['opening_wallets'] = json_decode((string) $s['opening_wallets_json'], true) ?: [];
            $s['closing_wallets_expected'] = $s['closing_wallets_expected_json'] ? (json_decode((string) $s['closing_wallets_expected_json'], true) ?: []) : null;
            $s['closing_wallets_actual'] = $s['closing_wallets_actual_json'] ? (json_decode((string) $s['closing_wallets_actual_json'], true) ?: []) : null;
        }
        unset($s);

        Response::success(['shifts' => $shifts]);
    }

    public function open(Request $request): void
    {
        $user = Security::requireAuth($request);
        $companyId = (int) $user['company_id'];

        $branchId = $user['role'] === 'VENDOR'
            ? (int) ($user['branch_id'] ?? 0)
            : ($request->int('branch_id') ?: (int) ($user['branch_id'] ?? 0));

        if ($branchId <= 0) {
            Response::error('Select a branch to open a shift.', 422);
        }

        $pdo = Database::connection();

        $openCheck = $pdo->prepare("SELECT id FROM shifts WHERE branch_id = ? AND company_id = ? AND status = 'OPEN' LIMIT 1");
        $openCheck->execute([$branchId, $companyId]);
        if ($openCheck->fetchColumn() !== false) {
            Response::error('There is already an active open shift at this branch. Close it before opening a new one.', 409);
        }

        $bStmt = $pdo->prepare('SELECT id, name, cash_balance FROM branches WHERE id = ? AND company_id = ? LIMIT 1');
        $bStmt->execute([$branchId, $companyId]);
        $branch = $bStmt->fetch();
        if (!$branch) {
            Response::error('Branch not found.', 404);
        }

        $wStmt = $pdo->prepare('SELECT network, ecash_balance FROM branch_wallets WHERE branch_id = ? AND company_id = ?');
        $wStmt->execute([$branchId, $companyId]);
        $walletSnap = [];
        foreach ($wStmt->fetchAll() as $w) {
            $walletSnap[$w['network']] = round((float) $w['ecash_balance'], 2);
        }

        $openingCash = round((float) $branch['cash_balance'], 2);
        $now = Database::now();

        $pdo->prepare(
            "INSERT INTO shifts (company_id, branch_id, vendor_id, opening_cash, opening_wallets_json, status, opened_at)
             VALUES (?, ?, ?, ?, ?, 'OPEN', ?)"
        )->execute([$companyId, $branchId, (int) $user['id'], $openingCash, json_encode($walletSnap), $now]);

        $shiftId = (int) $pdo->lastInsertId();

        AuditService::log($companyId, (int) $user['id'], 'SHIFT_OPENED', 'shift', $shiftId, [
            'branch_id' => $branchId,
            'opening_cash' => $openingCash,
            'opening_wallets' => $walletSnap,
        ], $request->ip());

        Response::success(['shift_id' => $shiftId], 'Shift opened and float balances snapshotted.', 201);
    }

    public function close(Request $request): void
    {
        $user = Security::requireAuth($request);
        $companyId = (int) $user['company_id'];
        $shiftId = (int) $request->param('id', '0');

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM shifts WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$shiftId, $companyId]);
        $shift = $stmt->fetch();

        if (!$shift) {
            Response::error('Shift not found.', 404);
        }
        if ($shift['status'] !== 'OPEN') {
            Response::error('This shift is already closed.', 422);
        }
        if ($user['role'] === 'VENDOR' && (int) $shift['branch_id'] !== (int) ($user['branch_id'] ?? 0)) {
            Response::error('You can only close shifts for your assigned branch.', 403);
        }

        $branchId = (int) $shift['branch_id'];

        $bStmt = $pdo->prepare('SELECT cash_balance FROM branches WHERE id = ? AND company_id = ? LIMIT 1');
        $bStmt->execute([$branchId, $companyId]);
        $expectedCash = round((float) $bStmt->fetchColumn(), 2);

        $wStmt = $pdo->prepare('SELECT network, ecash_balance FROM branch_wallets WHERE branch_id = ? AND company_id = ?');
        $wStmt->execute([$branchId, $companyId]);
        $expectedWallets = ['MTN' => 0.0, 'TELECEL' => 0.0, 'AT' => 0.0];
        foreach ($wStmt->fetchAll() as $w) {
            $expectedWallets[$w['network']] = round((float) $w['ecash_balance'], 2);
        }

        $actualCash = $request->float('actual_cash', $expectedCash);
        $actualWallets = [
            'MTN' => $request->float('actual_mtn', $expectedWallets['MTN']),
            'TELECEL' => $request->float('actual_telecel', $expectedWallets['TELECEL']),
            'AT' => $request->float('actual_at', $expectedWallets['AT']),
        ];
        $notes = $request->string('notes') ?: null;

        $cashVariance = round($actualCash - $expectedCash, 2);
        $ecashVariance = round(array_sum($actualWallets) - array_sum($expectedWallets), 2);

        $status = (abs($cashVariance) >= 0.01 || abs($ecashVariance) >= 0.01) ? 'FLAGGED' : 'CLOSED';
        $now = Database::now();

        $pdo->prepare(
            'UPDATE shifts
             SET closing_cash_expected = ?,
                 closing_cash_actual = ?,
                 cash_variance = ?,
                 closing_wallets_expected_json = ?,
                 closing_wallets_actual_json = ?,
                 ecash_variance = ?,
                 notes = ?,
                 status = ?,
                 closed_at = ?
             WHERE id = ? AND company_id = ?'
        )->execute([
            $expectedCash,
            $actualCash,
            $cashVariance,
            json_encode($expectedWallets),
            json_encode($actualWallets),
            $ecashVariance,
            $notes,
            $status,
            $now,
            $shiftId,
            $companyId,
        ]);

        AuditService::log($companyId, (int) $user['id'], 'SHIFT_CLOSED', 'shift', $shiftId, [
            'status' => $status,
            'cash_variance' => $cashVariance,
            'ecash_variance' => $ecashVariance,
        ], $request->ip());

        $msg = $status === 'FLAGGED'
            ? sprintf('Shift closed with variance (Cash diff: GH₵ %s, E-Cash diff: GH₵ %s). Flagged for Boss review.', number_format($cashVariance, 2), number_format($ecashVariance, 2))
            : 'Shift closed and 100% balanced!';

        Response::success([
            'status' => $status,
            'cash_variance' => $cashVariance,
            'ecash_variance' => $ecashVariance,
        ], $msg);
    }
}
