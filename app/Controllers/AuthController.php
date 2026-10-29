<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Security;
use App\Services\AuditService;
use PDO;

final class AuthController
{
    public function me(Request $request): void
    {
        $user = Security::currentUser($request);
        $demoAccounts = [];

        if (Config::bool('SEED_DEMO_DATA', true)) {
            $pdo = Database::connection();
            $stmt = $pdo->query(
                "SELECT u.full_name, u.phone, u.role, c.name AS company_name, b.name AS branch_name
                 FROM users u
                 INNER JOIN companies c ON c.id = u.company_id
                 LEFT JOIN branches b ON b.id = u.branch_id
                 WHERE c.name = 'Move Money Enterprise' AND u.is_active = 1
                 ORDER BY CASE u.role WHEN 'OWNER' THEN 1 WHEN 'MANAGER' THEN 2 ELSE 3 END, u.id ASC
                 LIMIT 6"
            );
            $demoAccounts = $stmt ? $stmt->fetchAll() : [];
        }

        Response::success([
            'authenticated' => $user !== null,
            'user' => $user,
            'csrf_token' => Security::csrfToken(),
            'demo_accounts' => $demoAccounts,
        ]);
    }

    public function register(Request $request): void
    {
        Security::verifyCsrf($request);

        $companyName = $request->string('company_name');
        $regNo = $request->string('business_reg_no') ?: null;
        $fullName = $request->string('full_name');
        $phone = Security::normalizeGhanaPhone($request->string('phone'));
        $email = strtolower($request->string('email')) ?: null;
        $password = (string) $request->input('password', '');

        $firstBranchName = $request->string('first_branch_name') ?: 'Main Branch';
        $firstBranchLocation = $request->string('first_branch_location') ?: null;
        $openingCash = max(0.0, $request->float('opening_cash', 0.0));

        $mtnNum = Security::normalizeGhanaPhone($request->string('mtn_number') ?: $phone);
        $mtnEcash = max(0.0, $request->float('mtn_ecash', 0.0));
        $telNum = Security::normalizeGhanaPhone($request->string('telecel_number') ?: $phone);
        $telEcash = max(0.0, $request->float('telecel_ecash', 0.0));
        $atNum = Security::normalizeGhanaPhone($request->string('at_number') ?: $phone);
        $atEcash = max(0.0, $request->float('at_ecash', 0.0));

        if (mb_strlen($companyName) < 2) {
            Response::error('Enter your registered company or enterprise name (e.g. Move Money Enterprise).', 422);
        }
        if (mb_strlen($fullName) < 2) {
            Response::error('Enter your full name.', 422);
        }
        if (!Security::isValidGhanaPhone($phone)) {
            Response::error('Enter a valid 10-digit Ghana phone number (e.g. 0244123456).', 422);
        }
        if (strlen($password) < 6) {
            Response::error('Password must be at least 6 characters long.', 422);
        }

        $pdo = Database::connection();
        $exists = $pdo->prepare('SELECT id FROM users WHERE phone = ? LIMIT 1');
        $exists->execute([$phone]);
        if ($exists->fetchColumn() !== false) {
            Response::error('This phone number is already registered. Please sign in instead.', 409);
        }

        $now = Database::now();
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO companies (name, business_reg_no, owner_phone, currency, low_cash_threshold, low_ecash_threshold, created_at, updated_at)
                 VALUES (?, ?, ?, ?, 500.00, 500.00, ?, ?)'
            )->execute([$companyName, $regNo, $phone, 'GHS', $now, $now]);
            $companyId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO branches (company_id, name, code, location, cash_balance, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, 1, ?, ?)'
            )->execute([$companyId, $firstBranchName, 'BR-01', $firstBranchLocation, $openingCash, $now, $now]);
            $branchId = (int) $pdo->lastInsertId();

            $walletStmt = $pdo->prepare(
                'INSERT INTO branch_wallets (company_id, branch_id, network, merchant_number, account_name, ecash_balance, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, 1, ?, ?)'
            );
            $upperComp = mb_strtoupper(mb_substr($companyName, 0, 40));
            $walletStmt->execute([$companyId, $branchId, 'MTN', $mtnNum, "{$upperComp} - MTN", $mtnEcash, $now, $now]);
            $walletStmt->execute([$companyId, $branchId, 'TELECEL', $telNum, "{$upperComp} - TELECEL", $telEcash, $now, $now]);
            $walletStmt->execute([$companyId, $branchId, 'AT', $atNum, "{$upperComp} - AT", $atEcash, $now, $now]);

            $pdo->prepare(
                'INSERT INTO users (company_id, branch_id, full_name, phone, email, password_hash, role, is_active, last_login_at, created_at, updated_at)
                 VALUES (?, NULL, ?, ?, ?, ?, ?, 1, ?, ?, ?)'
            )->execute([$companyId, $fullName, $phone, $email, $passwordHash, 'OWNER', $now, $now, $now]);
            $userId = (int) $pdo->lastInsertId();

            AuditService::log($companyId, $userId, 'ENTERPRISE_REGISTERED', 'company', $companyId, [
                'company_name' => $companyName,
                'first_branch' => $firstBranchName,
            ], $request->ip());

            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Response::error('Could not create enterprise: ' . $e->getMessage(), 500);
        }

        Security::loginSession($userId, $companyId);
        $token = Security::issueBearerToken($userId, $companyId, 'Owner Registration');
        $user = Security::currentUser($request);

        Response::success([
            'user' => $user,
            'token' => $token,
            'csrf_token' => Security::csrfToken(),
        ], 'Enterprise registered! Welcome to MoMo Manager.', 201);
    }

    public function login(Request $request): void
    {
        Security::verifyCsrf($request);

        $identifier = $request->string('identifier') ?: $request->string('phone');
        $password = (string) $request->input('password', '');

        if ($identifier === '' || $password === '') {
            Response::error('Enter your phone number (or email) and password.', 422);
        }

        $pdo = Database::connection();
        if (str_contains($identifier, '@')) {
            $stmt = $pdo->prepare('SELECT * FROM users WHERE LOWER(email) = LOWER(?) LIMIT 1');
            $stmt->execute([$identifier]);
        } else {
            $normalizedPhone = Security::normalizeGhanaPhone($identifier);
            $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = ? LIMIT 1');
            $stmt->execute([$normalizedPhone]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || !password_verify($password, (string) $row['password_hash'])) {
            Response::error('Invalid phone/email or password.', 401);
        }

        if ((int) $row['is_active'] !== 1) {
            Response::error('Your account has been suspended by the Enterprise Owner.', 403);
        }

        $userId = (int) $row['id'];
        $companyId = (int) $row['company_id'];
        $now = Database::now();

        $pdo->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([$now, $userId]);

        Security::loginSession($userId, $companyId);
        $token = Security::issueBearerToken($userId, $companyId, 'Web Login');

        AuditService::log($companyId, $userId, 'USER_LOGIN', 'user', $userId, [
            'role' => $row['role'],
        ], $request->ip());

        $user = Security::currentUser($request);

        Response::success([
            'user' => $user,
            'token' => $token,
            'csrf_token' => Security::csrfToken(),
        ], 'Signed in successfully.');
    }

    public function logout(Request $request): void
    {
        Security::verifyCsrf($request);
        Security::revokeBearerToken($request);
        Security::logoutSession();

        Response::success([
            'csrf_token' => Security::csrfToken(),
        ], 'Signed out.');
    }
}
