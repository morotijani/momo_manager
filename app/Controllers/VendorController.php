<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Services\AuditService;

final class VendorController
{
    public function index(Request $request): void
    {
        $user = Security::requireRole($request, ['OWNER', 'MANAGER']);
        $pdo = Database::connection();
        $companyId = (int) $user['company_id'];

        $stmt = $pdo->prepare(
            "SELECT u.id, u.full_name, u.phone, u.email, u.role, u.branch_id, u.is_active, u.last_login_at, u.created_at,
                    b.name AS branch_name, b.code AS branch_code,
                    COUNT(CASE WHEN t.status = 'COMPLETED' THEN 1 END) AS total_tx_count,
                    COALESCE(SUM(CASE WHEN t.status = 'COMPLETED' THEN t.amount ELSE 0 END), 0) AS total_volume,
                    COALESCE(SUM(CASE WHEN t.status = 'COMPLETED' AND t.tx_type = 'DEPOSIT' THEN 1 ELSE 0 END), 0) AS deposit_count,
                    COALESCE(SUM(CASE WHEN t.status = 'COMPLETED' AND t.tx_type = 'TRANSFER' THEN 1 ELSE 0 END), 0) AS transfer_count,
                    COALESCE(SUM(CASE WHEN t.status = 'COMPLETED' AND t.tx_type = 'WITHDRAWAL' THEN 1 ELSE 0 END), 0) AS withdrawal_count,
                    COALESCE(SUM(CASE WHEN t.status IN ('VOID_REQUESTED', 'VOIDED') THEN 1 ELSE 0 END), 0) AS void_flags
             FROM users u
             LEFT JOIN branches b ON b.id = u.branch_id
             LEFT JOIN transactions t ON t.vendor_id = u.id AND t.company_id = u.company_id
             WHERE u.company_id = ?
             GROUP BY u.id, u.full_name, u.phone, u.email, u.role, u.branch_id, u.is_active, u.last_login_at, u.created_at, b.name, b.code
             ORDER BY CASE u.role WHEN 'OWNER' THEN 1 WHEN 'MANAGER' THEN 2 ELSE 3 END, u.id ASC"
        );
        $stmt->execute([$companyId]);
        $members = $stmt->fetchAll();

        foreach ($members as &$m) {
            $m['id'] = (int) $m['id'];
            $m['branch_id'] = $m['branch_id'] !== null ? (int) $m['branch_id'] : null;
            $m['is_active'] = (int) $m['is_active'] === 1;
            $m['total_tx_count'] = (int) $m['total_tx_count'];
            $m['total_volume'] = round((float) $m['total_volume'], 2);
            $m['deposit_count'] = (int) $m['deposit_count'];
            $m['transfer_count'] = (int) $m['transfer_count'];
            $m['withdrawal_count'] = (int) $m['withdrawal_count'];
            $m['void_flags'] = (int) $m['void_flags'];
        }
        unset($m);

        Response::success(['vendors' => $members]);
    }

    public function store(Request $request): void
    {
        $actor = Security::requireRole($request, ['OWNER', 'MANAGER']);
        $companyId = (int) $actor['company_id'];

        $fullName = $request->string('full_name');
        $phone = Security::normalizeGhanaPhone($request->string('phone'));
        $email = strtolower($request->string('email')) ?: null;
        $password = (string) $request->input('password', '');
        $role = strtoupper($request->string('role', 'VENDOR'));
        $branchId = $request->int('branch_id', 0);

        if (!in_array($role, ['VENDOR', 'MANAGER'], true)) {
            $role = 'VENDOR';
        }
        if ($role === 'MANAGER' && $actor['role'] !== 'OWNER') {
            Response::error('Only the Enterprise Owner can create Manager accounts.', 403);
        }
        if (mb_strlen($fullName) < 2) {
            Response::error('Enter employee full name.', 422);
        }
        if (!Security::isValidGhanaPhone($phone)) {
            Response::error('Enter a valid 10-digit Ghana mobile number for login.', 422);
        }
        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters.', 422);
        }

        $pdo = Database::connection();

        if ($role === 'VENDOR' && $branchId <= 0) {
            Response::error('Select a branch to assign this vendor to.', 422);
        }

        if ($branchId > 0) {
            $bCheck = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND company_id = ? LIMIT 1');
            $bCheck->execute([$branchId, $companyId]);
            if ($bCheck->fetchColumn() === false) {
                Response::error('Selected branch does not belong to your enterprise.', 422);
            }
        } else {
            $branchId = null;
        }

        $uCheck = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $uCheck->execute([$phone]);
        if ($uCheck->fetchColumn() !== false) {
            Response::error('This phone number is already registered to another user.', 409);
        }

        $now = Database::now();
        $hash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->prepare(
            'INSERT INTO users (company_id, branch_id, full_name, phone, email, password_hash, role, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?)'
        )->execute([$companyId, $branchId, $fullName, $phone, $email, $hash, $role, $now, $now]);

        $newId = (int) $pdo->lastInsertId();

        AuditService::log($companyId, (int) $actor['id'], 'VENDOR_CREATED', 'user', $newId, [
            'full_name' => $fullName,
            'phone' => $phone,
            'role' => $role,
            'branch_id' => $branchId,
        ], $request->ip());

        Response::success(['vendor_id' => $newId], "{$fullName} added to your team.", 201);
    }

    public function update(Request $request): void
    {
        $actor = Security::requireRole($request, ['OWNER', 'MANAGER']);
        $companyId = (int) $actor['company_id'];
        $targetId = (int) $request->param('id', '0');

        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ? AND company_id = ? LIMIT 1');
        $stmt->execute([$targetId, $companyId]);
        $target = $stmt->fetch();

        if (!$target) {
            Response::error('Team member not found.', 404);
        }
        if ($target['role'] === 'OWNER' && $actor['role'] !== 'OWNER') {
            Response::error('Managers cannot modify the Enterprise Owner.', 403);
        }

        $fullName = $request->string('full_name') ?: $target['full_name'];
        $branchId = array_key_exists('branch_id', $request->all())
            ? ($request->int('branch_id', 0) ?: null)
            : ($target['branch_id'] !== null ? (int) $target['branch_id'] : null);

        if ($branchId !== null) {
            $bCheck = $pdo->prepare('SELECT id FROM branches WHERE id = ? AND company_id = ? LIMIT 1');
            $bCheck->execute([$branchId, $companyId]);
            if ($bCheck->fetchColumn() === false) {
                Response::error('Invalid branch selected.', 422);
            }
        }

        $isActive = (int) $target['is_active'];
        if (array_key_exists('is_active', $request->all()) && $target['role'] !== 'OWNER') {
            $isActive = $request->bool('is_active') ? 1 : 0;
        }

        $now = Database::now();
        $newPassword = (string) $request->input('new_password', '');

        if ($newPassword !== '') {
            if (strlen($newPassword) < 6) {
                Response::error('New password must be at least 6 characters.', 422);
            }
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $pdo->prepare(
                'UPDATE users SET full_name = ?, branch_id = ?, is_active = ?, password_hash = ?, updated_at = ? WHERE id = ? AND company_id = ?'
            )->execute([$fullName, $branchId, $isActive, $hash, $now, $targetId, $companyId]);
        } else {
            $pdo->prepare(
                'UPDATE users SET full_name = ?, branch_id = ?, is_active = ?, updated_at = ? WHERE id = ? AND company_id = ?'
            )->execute([$fullName, $branchId, $isActive, $now, $targetId, $companyId]);
        }

        AuditService::log($companyId, (int) $actor['id'], 'VENDOR_UPDATED', 'user', $targetId, [
            'full_name' => $fullName,
            'branch_id' => $branchId,
            'is_active' => $isActive,
            'password_reset' => $newPassword !== '',
        ], $request->ip());

        Response::success([], 'Employee profile updated.');
    }
}
