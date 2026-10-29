<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class Security
{
    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        if (PHP_SAPI !== 'cli' && !headers_sent()) {
            session_set_cookie_params([
                'lifetime' => 86400 * 7,
                'path' => '/',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_name('MOMO_MANAGER_SID');
        }
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(Request $request): void
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return;
        }

        // Requests authenticated via stateless Bearer token do not use cookie sessions
        $authHeader = $request->header('authorization');
        if (str_starts_with(strtolower($authHeader), 'bearer ')) {
            return;
        }

        $expected = self::csrfToken();
        $provided = $request->header('x-csrf-token');
        if ($provided === '') {
            $provided = $request->string('_csrf');
        }

        if ($provided === '' || !hash_equals($expected, $provided)) {
            Response::error('Security session expired or invalid CSRF token. Please refresh the page.', 419);
        }
    }

    public static function normalizeGhanaPhone(string $raw): string
    {
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (str_starts_with($digits, '233') && strlen($digits) === 12) {
            return '0' . substr($digits, 3);
        }
        if (strlen($digits) === 9 && !str_starts_with($digits, '0')) {
            return '0' . $digits;
        }
        return $digits;
    }

    public static function isValidGhanaPhone(string $normalized): bool
    {
        return (bool) preg_match('/^0[235]\d{8}$/', $normalized);
    }

    public static function issueBearerToken(int $userId, int $companyId, string $deviceName = 'Web App'): string
    {
        $pdo = Database::connection();
        $plain = bin2hex(random_bytes(32));
        $hash = hash('sha256', $plain);
        $now = Database::now();
        $expires = date('Y-m-d H:i:s', time() + 86400 * 14);

        $stmt = $pdo->prepare(
            'INSERT INTO api_tokens (user_id, company_id, token_hash, device_name, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$userId, $companyId, $hash, mb_substr($deviceName, 0, 120), $expires, $now]);

        return $plain;
    }

    public static function revokeBearerToken(Request $request): void
    {
        $authHeader = $request->header('authorization');
        if (str_starts_with(strtolower($authHeader), 'bearer ')) {
            $plain = trim(substr($authHeader, 7));
            if ($plain !== '') {
                $hash = hash('sha256', $plain);
                $pdo = Database::connection();
                $stmt = $pdo->prepare('DELETE FROM api_tokens WHERE token_hash = ?');
                $stmt->execute([$hash]);
            }
        }
    }

    public static function loginSession(int $userId, int $companyId): void
    {
        self::startSession();
        if (PHP_SAPI !== 'cli' && !headers_sent() && session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
        $_SESSION['user_id'] = $userId;
        $_SESSION['company_id'] = $companyId;
    }

    public static function logoutSession(): void
    {
        self::startSession();
        unset($_SESSION['user_id'], $_SESSION['company_id']);
    }

    public static function currentUser(Request $request): ?array
    {
        $pdo = Database::connection();
        $userId = null;

        $authHeader = $request->header('authorization');
        if (str_starts_with(strtolower($authHeader), 'bearer ')) {
            $plain = trim(substr($authHeader, 7));
            if ($plain !== '') {
                $hash = hash('sha256', $plain);
                $stmt = $pdo->prepare(
                    'SELECT user_id FROM api_tokens WHERE token_hash = ? AND expires_at > ?'
                );
                $stmt->execute([$hash, Database::now()]);
                $found = $stmt->fetchColumn();
                if ($found !== false) {
                    $userId = (int) $found;
                }
            }
        }

        if ($userId === null) {
            self::startSession();
            if (!empty($_SESSION['user_id'])) {
                $userId = (int) $_SESSION['user_id'];
            }
        }

        if ($userId === null || $userId <= 0) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT u.id, u.company_id, u.branch_id, u.full_name, u.phone, u.email, u.role, u.is_active,
                    c.name AS company_name, c.business_reg_no, c.currency,
                    c.low_cash_threshold, c.low_ecash_threshold,
                    b.name AS branch_name, b.code AS branch_code, b.location AS branch_location
             FROM users u
             INNER JOIN companies c ON c.id = u.company_id
             LEFT JOIN branches b ON b.id = u.branch_id
             WHERE u.id = ?
             LIMIT 1'
        );
        $stmt->execute([$userId]);
        $user = $stmt->fetch();

        if (!$user || (int) $user['is_active'] !== 1) {
            return null;
        }

        $user['id'] = (int) $user['id'];
        $user['company_id'] = (int) $user['company_id'];
        $user['branch_id'] = $user['branch_id'] !== null ? (int) $user['branch_id'] : null;
        $user['low_cash_threshold'] = (float) $user['low_cash_threshold'];
        $user['low_ecash_threshold'] = (float) $user['low_ecash_threshold'];

        return $user;
    }

    public static function requireAuth(Request $request): array
    {
        self::verifyCsrf($request);
        $user = self::currentUser($request);
        if ($user === null) {
            Response::error('Authentication required. Please sign in.', 401);
        }
        return $user;
    }

    /**
     * @param list<string> $allowedRoles
     */
    public static function requireRole(Request $request, array $allowedRoles): array
    {
        $user = self::requireAuth($request);
        if (!in_array($user['role'], $allowedRoles, true)) {
            Response::error('Access denied. Insufficient permissions for this action.', 403);
        }
        return $user;
    }
}
