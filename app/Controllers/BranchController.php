<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Services\AuditService;
use App\Services\LedgerService;
use InvalidArgumentException;

final class BranchController
{
    public function index(Request $request): void
    {
        $user = Security::requireAuth($request);
        $pdo = Database::connection();
        $companyId = (int) $user['company_id'];

        if ($user['role'] === 'VENDOR') {
            $stmt = $pdo->prepare('SELECT * FROM branches WHERE company_id = ? AND id = ? ORDER BY id ASC');
            $stmt->execute([$companyId, (int) ($user['branch_id'] ?? 0)]);
        } else {
            $stmt = $pdo->prepare('SELECT * FROM branches WHERE company_id = ? ORDER BY id ASC');
            $stmt->execute([$companyId]);
        }
        $branches = $stmt->fetchAll();

        $wStmt = $pdo->prepare('SELECT * FROM branch_wallets WHERE company_id = ? ORDER BY branch_id ASC');
        $wStmt->execute([$companyId]);
        $walletsByBranch = [];
        foreach ($wStmt->fetchAll() as $w) {
            $w['id'] = (int) $w['id'];
            $w['branch_id'] = (int) $w['branch_id'];
            $w['ecash_balance'] = round((float) $w['ecash_balance'], 2);
            $walletsByBranch[$w['branch_id']][] = $w;
        }

        $vStmt = $pdo->prepare(
            "SELECT id, branch_id, full_name, phone, role, is_active
             FROM users
             WHERE company_id = ? AND role IN ('VENDOR', 'MANAGER')"
        );
        $vStmt->execute([$companyId]);
        $vendorsByBranch = [];
        foreach ($vStmt->fetchAll() as $v) {
            if ($v['branch_id'] !== null) {
                $vendorsByBranch[(int) $v['branch_id']][] = $v;
            }
        }

        foreach ($branches as &$b) {
            $bid = (int) $b['id'];
            $b['id'] = $bid;
            $b['cash_balance'] = round((float) $b['cash_balance'], 2);
            $b['is_active'] = (int) $b['is_active'] === 1;
            $b['wallets'] = $walletsByBranch[$bid] ?? [];
            $b['total_ecash'] = round(array_sum(array_column($b['wallets'], 'ecash_balance')), 2);
            $b['total_capital'] = round($b['cash_balance'] + $b['total_ecash'], 2);
            $b['vendors'] = $vendorsByBranch[$bid] ?? [];
        }
        unset($b);

        $adjStmt = $pdo->prepare(
            'SELECT fa.*, b.name AS branch_name, bw.network AS wallet_network, u.full_name AS actor_name
             FROM float_adjustments fa
             INNER JOIN branches b ON b.id = fa.branch_id
             LEFT JOIN branch_wallets bw ON bw.id = fa.wallet_id
             INNER JOIN users u ON u.id = fa.user_id
             WHERE fa.company_id = ?
             ORDER BY fa.id DESC
             LIMIT 25'
        );
        $adjStmt->execute([$companyId]);

        Response::success([
            'branches' => $branches,
            'float_adjustments' => $adjStmt->fetchAll(),
        ]);
    }

    public function store(Request $request): void
    {
        $user = Security::requireRole($request, ['OWNER', 'MANAGER']);
        $companyId = (int) $user['company_id'];

        $name = $request->string('name');
        $location = $request->string('location') ?: null;
        $openingCash = max(0.0, $request->float('cash_balance', 0.0));

        if (mb_strlen($name) < 2) {
            Response::error('Enter a branch name (e.g. East Legon Boundary Rd).', 422);
        }

        $mtnNum = Security::normalizeGhanaPhone($request->string('mtn_number') ?: $user['phone']);
        $mtnBal = max(0.0, $request->float('mtn_ecash', 0.0));
        $telNum = Security::normalizeGhanaPhone($request->string('telecel_number') ?: $user['phone']);
        $telBal = max(0.0, $request->float('telecel_ecash', 0.0));
        $atNum = Security::normalizeGhanaPhone($request->string('at_number') ?: $user['phone']);
        $atBal = max(0.0, $request->float('at_ecash', 0.0));

        $pdo = Database::connection();
        $now = Database::now();

        $pdo->beginTransaction();
        try {
            $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM branches WHERE company_id = ?');
            $cntStmt->execute([$companyId]);
            $nextNum = ((int) $cntStmt->fetchColumn()) + 1;
            $code = sprintf('BR-%02d', $nextNum);

            $pdo->prepare(
                'INSERT INTO branches (company_id, name, code, location, cash_balance, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
            )->execute([$companyId, $name, $code, $location, $openingCash, $now, $now]);
            $branchId = (int) $pdo->lastInsertId();

            $upperBranch = mb_strtoupper(mb_substr($name, 0, 25));
            $wStmt = $pdo->prepare(
                'INSERT INTO branch_wallets (company_id, branch_id, network, merchant_number, account_name, ecash_balance, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $wStmt->execute([$companyId, $branchId, 'MTN', $mtnNum, "{$upperBranch} - MTN", $mtnBal, $now, $now]);
            $wStmt->execute([$companyId, $branchId, 'TELECEL', $telNum, "{$upperBranch} - TELECEL", $telBal, $now, $now]);
            $wStmt->execute([$companyId, $branchId, 'AT', $atNum, "{$upperBranch} - AT", $atBal, $now, $now]);

            AuditService::log($companyId, (int) $user['id'], 'BRANCH_CREATED', 'branch', $branchId, [
                'name' => $name,
                'code' => $code,
                'opening_cash' => $openingCash,
            ], $request->ip());

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to create branch: ' . $e->getMessage(), 500);
        }

        Response::success(['branch_id' => $branchId], "Branch '{$name}' created successfully.", 201);
    }

    public function update(Request $request): void
    {
        $user = Security::requireRole($request, ['OWNER', 'MANAGER']);
        $companyId = (int) $user['company_id'];
        $branchId = (int) $request->param('id', '0');

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM branches WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$branchId, $companyId]);
        $branch = $stmt->fetch();
        if (!$branch) {
            Response::error('Branch not found.', 404);
        }

        $name = $request->string('name') ?: $branch['name'];
        $location = array_key_exists('location', $request->all())
            ? ($request->string('location') ?: null)
            : $branch['location'];
        $isActive = array_key_exists('is_active', $request->all())
            ? ($request->bool('is_active') ? 1 : 0)
            : (int) $branch['is_active'];

        $now = Database::now();
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE branches SET name = ?, location = ?, is_active = ?, updated_at = ? WHERE id = ? AND company_id = ?')
                ->execute([$name, $location, $isActive, $now, $branchId, $companyId]);

            $walletsInput = $request->input('wallets');
            if (is_array($walletsInput)) {
                $updW = $pdo->prepare(
                    'UPDATE branch_wallets
                     SET merchant_number = ?, account_name = ?, updated_at = ?
                     WHERE branch_id = ? AND company_id = ? AND network = ?'
                );
                foreach (['MTN', 'TELECEL', 'AT'] as $net) {
                    if (!empty($walletsInput[$net]) && is_array($walletsInput[$net])) {
                        $mNum = Security::normalizeGhanaPhone((string) ($walletsInput[$net]['merchant_number'] ?? ''));
                        $aName = trim((string) ($walletsInput[$net]['account_name'] ?? ''));
                        if ($mNum !== '' && $aName !== '') {
                            $updW->execute([$mNum, $aName, $now, $branchId, $companyId, $net]);
                        }
                    }
                }
            }

            AuditService::log($companyId, (int) $user['id'], 'BRANCH_UPDATED', 'branch', $branchId, [
                'name' => $name,
                'is_active' => $isActive,
            ], $request->ip());

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Failed to update branch: ' . $e->getMessage(), 500);
        }

        Response::success([], 'Branch updated.');
    }

    public function adjustFloat(Request $request): void
    {
        $user = Security::requireRole($request, ['OWNER', 'MANAGER']);
        $branchId = (int) $request->param('id', '0');

        try {
            $result = LedgerService::adjustFloat(
                $user,
                $branchId,
                $request->string('bucket'),
                $request->string('direction'),
                $request->float('amount'),
                $request->string('reason'),
                $request->string('network') ?: null,
                $request->ip()
            );
        } catch (InvalidArgumentException $e) {
            Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            Response::error($e->getMessage(), 400);
        }

        Response::success($result, 'Float balance updated.');
    }
}
