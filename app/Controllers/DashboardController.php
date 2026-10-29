<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;

final class DashboardController
{
    public function index(Request $request): void
    {
        $user = Security::requireAuth($request);
        $pdo = Database::connection();
        $companyId = (int) $user['company_id'];
        $isVendor = $user['role'] === 'VENDOR';
        $vendorBranchId = (int) ($user['branch_id'] ?? 0);

        $date = trim((string) $request->query('date', date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = date('Y-m-d');
        }
        $dayStart = $date . ' 00:00:00';
        $dayEnd = $date . ' 23:59:59';

        // 1. Branches & Wallets
        if ($isVendor) {
            $bStmt = $pdo->prepare('SELECT * FROM branches WHERE company_id = ? AND id = ?');
            $bStmt->execute([$companyId, $vendorBranchId]);
        } else {
            $bStmt = $pdo->prepare('SELECT * FROM branches WHERE company_id = ? ORDER BY id ASC');
            $bStmt->execute([$companyId]);
        }
        $branches = $bStmt->fetchAll();

        $wStmt = $pdo->prepare('SELECT * FROM branch_wallets WHERE company_id = ? ORDER BY id ASC');
        $wStmt->execute([$companyId]);
        $walletsByBranch = [];
        foreach ($wStmt->fetchAll() as $w) {
            $w['id'] = (int) $w['id'];
            $w['branch_id'] = (int) $w['branch_id'];
            $w['ecash_balance'] = round((float) $w['ecash_balance'], 2);
            $walletsByBranch[$w['branch_id']][] = $w;
        }

        // Today's stats per branch
        $bStatStmt = $pdo->prepare(
            "SELECT branch_id,
                    COUNT(*) AS tx_count,
                    COALESCE(SUM(amount), 0) AS volume,
                    COALESCE(SUM(fee_charged), 0) AS fees
             FROM transactions
             WHERE company_id = ? AND status = 'COMPLETED' AND created_at BETWEEN ? AND ?
             GROUP BY branch_id"
        );
        $bStatStmt->execute([$companyId, $dayStart, $dayEnd]);
        $branchStats = [];
        foreach ($bStatStmt->fetchAll() as $bs) {
            $branchStats[(int) $bs['branch_id']] = [
                'tx_count' => (int) $bs['tx_count'],
                'volume' => round((float) $bs['volume'], 2),
                'fees' => round((float) $bs['fees'], 2),
            ];
        }

        // Open shifts per branch
        $sStmt = $pdo->prepare(
            "SELECT s.*, u.full_name AS vendor_name
             FROM shifts s
             INNER JOIN users u ON u.id = s.vendor_id
             WHERE s.company_id = ? AND s.status = 'OPEN'"
        );
        $sStmt->execute([$companyId]);
        $openShiftByBranch = [];
        foreach ($sStmt->fetchAll() as $os) {
            $os['id'] = (int) $os['id'];
            $os['opening_cash'] = round((float) $os['opening_cash'], 2);
            $os['opening_wallets'] = json_decode((string) $os['opening_wallets_json'], true) ?: [];
            $openShiftByBranch[(int) $os['branch_id']] = $os;
        }

        $totalCash = 0.0;
        $totalEcash = 0.0;
        $ecashByNetwork = ['MTN' => 0.0, 'TELECEL' => 0.0, 'AT' => 0.0];
        $lowCashThreshold = (float) $user['low_cash_threshold'];
        $lowEcashThreshold = (float) $user['low_ecash_threshold'];
        $alerts = [];

        foreach ($branches as &$b) {
            $bid = (int) $b['id'];
            $b['id'] = $bid;
            $b['cash_balance'] = round((float) $b['cash_balance'], 2);
            $b['is_active'] = (int) $b['is_active'] === 1;
            $b['wallets'] = $walletsByBranch[$bid] ?? [];
            $b['total_ecash'] = 0.0;

            if ($b['is_active'] && $b['cash_balance'] < $lowCashThreshold) {
                $alerts[] = [
                    'type' => 'LOW_CASH',
                    'branch_id' => $bid,
                    'branch_name' => $b['name'],
                    'label' => "{$b['name']}: Low Physical Cash (GH₵ " . number_format($b['cash_balance'], 2) . ')',
                ];
            }

            foreach ($b['wallets'] as $w) {
                $b['total_ecash'] = round($b['total_ecash'] + $w['ecash_balance'], 2);
                $ecashByNetwork[$w['network']] = round(($ecashByNetwork[$w['network']] ?? 0.0) + $w['ecash_balance'], 2);
                if ($b['is_active'] && $w['ecash_balance'] < $lowEcashThreshold) {
                    $alerts[] = [
                        'type' => 'LOW_ECASH',
                        'branch_id' => $bid,
                        'branch_name' => $b['name'],
                        'network' => $w['network'],
                        'label' => "{$b['name']}: Low {$w['network']} Float (GH₵ " . number_format($w['ecash_balance'], 2) . ')',
                    ];
                }
            }

            $b['total_capital'] = round($b['cash_balance'] + $b['total_ecash'], 2);
            $b['today'] = $branchStats[$bid] ?? ['tx_count' => 0, 'volume' => 0.0, 'fees' => 0.0];
            $b['open_shift'] = $openShiftByBranch[$bid] ?? null;

            $totalCash = round($totalCash + $b['cash_balance'], 2);
            $totalEcash = round($totalEcash + $b['total_ecash'], 2);
        }
        unset($b);

        // 2. Today's KPI Breakdown
        $kpiSql = "SELECT
                        COUNT(CASE WHEN status = 'COMPLETED' THEN 1 END) AS tx_count,
                        COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN amount ELSE 0 END), 0) AS total_volume,
                        COUNT(CASE WHEN status = 'COMPLETED' AND tx_type = 'DEPOSIT' THEN 1 END) AS deposits_count,
                        COALESCE(SUM(CASE WHEN status = 'COMPLETED' AND tx_type = 'DEPOSIT' THEN amount ELSE 0 END), 0) AS deposits_volume,
                        COUNT(CASE WHEN status = 'COMPLETED' AND tx_type = 'TRANSFER' THEN 1 END) AS transfers_count,
                        COALESCE(SUM(CASE WHEN status = 'COMPLETED' AND tx_type = 'TRANSFER' THEN amount ELSE 0 END), 0) AS transfers_volume,
                        COUNT(CASE WHEN status = 'COMPLETED' AND tx_type = 'WITHDRAWAL' THEN 1 END) AS withdrawals_count,
                        COALESCE(SUM(CASE WHEN status = 'COMPLETED' AND tx_type = 'WITHDRAWAL' THEN amount ELSE 0 END), 0) AS withdrawals_volume,
                        COALESCE(SUM(CASE WHEN status = 'COMPLETED' THEN fee_charged ELSE 0 END), 0) AS fees_collected
                   FROM transactions
                   WHERE company_id = ? AND created_at BETWEEN ? AND ?";
        $kpiParams = [$companyId, $dayStart, $dayEnd];
        if ($isVendor) {
            $kpiSql .= ' AND branch_id = ?';
            $kpiParams[] = $vendorBranchId;
        }
        $kpiStmt = $pdo->prepare($kpiSql);
        $kpiStmt->execute($kpiParams);
        $kpi = $kpiStmt->fetch() ?: [];

        // Pending void requests & flagged shifts
        $voidCountStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM transactions WHERE company_id = ? AND status = 'VOID_REQUESTED'"
            . ($isVendor ? ' AND branch_id = ' . $vendorBranchId : '')
        );
        $voidCountStmt->execute([$companyId]);
        $pendingVoidsCount = (int) $voidCountStmt->fetchColumn();

        $flaggedStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM shifts WHERE company_id = ? AND status = 'FLAGGED'"
            . ($isVendor ? ' AND branch_id = ' . $vendorBranchId : '')
        );
        $flaggedStmt->execute([$companyId]);
        $flaggedShiftsCount = (int) $flaggedStmt->fetchColumn();

        // 3. Recent Transactions
        $recSql = "SELECT t.*, b.name AS branch_name, b.code AS branch_code, u.full_name AS vendor_name, bw.merchant_number AS wallet_number
                   FROM transactions t
                   INNER JOIN branches b ON b.id = t.branch_id
                   INNER JOIN users u ON u.id = t.vendor_id
                   INNER JOIN branch_wallets bw ON bw.id = t.wallet_id
                   WHERE t.company_id = ?" . ($isVendor ? ' AND t.branch_id = ?' : '') . '
                   ORDER BY t.id DESC
                   LIMIT 20';
        $recStmt = $pdo->prepare($recSql);
        $recStmt->execute($isVendor ? [$companyId, $vendorBranchId] : [$companyId]);
        $recent = array_map([TransactionController::class, 'formatRow'], $recStmt->fetchAll());

        // 4. Owner/Manager extras: Pending Void Requests + Vendor Performance Leaderboard
        $pendingVoids = [];
        $leaderboard = [];
        if (!$isVendor) {
            $pvStmt = $pdo->prepare(
                "SELECT t.*, b.name AS branch_name, u.full_name AS vendor_name, bw.merchant_number AS wallet_number
                 FROM transactions t
                 INNER JOIN branches b ON b.id = t.branch_id
                 INNER JOIN users u ON u.id = t.vendor_id
                 INNER JOIN branch_wallets bw ON bw.id = t.wallet_id
                 WHERE t.company_id = ? AND t.status = 'VOID_REQUESTED'
                 ORDER BY t.void_requested_at DESC, t.id DESC"
            );
            $pvStmt->execute([$companyId]);
            $pendingVoids = array_map([TransactionController::class, 'formatRow'], $pvStmt->fetchAll());

            $lbStmt = $pdo->prepare(
                "SELECT u.id, u.full_name, u.phone, u.role, u.is_active, b.name AS branch_name,
                        COUNT(CASE WHEN t.status = 'COMPLETED' AND t.created_at BETWEEN ? AND ? THEN 1 END) AS today_tx_count,
                        COALESCE(SUM(CASE WHEN t.status = 'COMPLETED' AND t.created_at BETWEEN ? AND ? THEN t.amount ELSE 0 END), 0) AS today_volume,
                        COUNT(CASE WHEN t.status = 'COMPLETED' THEN 1 END) AS lifetime_tx_count,
                        COALESCE(SUM(CASE WHEN t.status = 'COMPLETED' THEN t.amount ELSE 0 END), 0) AS lifetime_volume
                 FROM users u
                 LEFT JOIN branches b ON b.id = u.branch_id
                 LEFT JOIN transactions t ON t.vendor_id = u.id AND t.company_id = u.company_id
                 WHERE u.company_id = ? AND u.role IN ('VENDOR', 'MANAGER')
                 GROUP BY u.id, u.full_name, u.phone, u.role, u.is_active, b.name
                 ORDER BY today_volume DESC, lifetime_volume DESC"
            );
            $lbStmt->execute([$dayStart, $dayEnd, $dayStart, $dayEnd, $companyId]);
            foreach ($lbStmt->fetchAll() as $lb) {
                $lb['id'] = (int) $lb['id'];
                $lb['is_active'] = (int) $lb['is_active'] === 1;
                $lb['today_tx_count'] = (int) $lb['today_tx_count'];
                $lb['today_volume'] = round((float) $lb['today_volume'], 2);
                $lb['lifetime_tx_count'] = (int) $lb['lifetime_tx_count'];
                $lb['lifetime_volume'] = round((float) $lb['lifetime_volume'], 2);
                $leaderboard[] = $lb;
            }
        }

        Response::success([
            'date' => $date,
            'summary' => [
                'total_cash' => $totalCash,
                'total_ecash' => $totalEcash,
                'total_capital' => round($totalCash + $totalEcash, 2),
                'ecash_by_network' => $ecashByNetwork,
            ],
            'today' => [
                'tx_count' => (int) ($kpi['tx_count'] ?? 0),
                'total_volume' => round((float) ($kpi['total_volume'] ?? 0), 2),
                'deposits_count' => (int) ($kpi['deposits_count'] ?? 0),
                'deposits_volume' => round((float) ($kpi['deposits_volume'] ?? 0), 2),
                'transfers_count' => (int) ($kpi['transfers_count'] ?? 0),
                'transfers_volume' => round((float) ($kpi['transfers_volume'] ?? 0), 2),
                'withdrawals_count' => (int) ($kpi['withdrawals_count'] ?? 0),
                'withdrawals_volume' => round((float) ($kpi['withdrawals_volume'] ?? 0), 2),
                'fees_collected' => round((float) ($kpi['fees_collected'] ?? 0), 2),
                'pending_voids_count' => $pendingVoidsCount,
                'flagged_shifts_count' => $flaggedShiftsCount,
            ],
            'alerts' => $alerts,
            'branches' => $branches,
            'recent_transactions' => $recent,
            'pending_voids' => $pendingVoids,
            'vendor_leaderboard' => $leaderboard,
            'open_shift' => $isVendor ? ($openShiftByBranch[$vendorBranchId] ?? null) : null,
        ]);
    }
}
