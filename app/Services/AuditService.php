<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

final class AuditService
{
    public static function log(
        int $companyId,
        ?int $userId,
        string $action,
        string $entityType,
        ?int $entityId = null,
        array $details = [],
        string $ipAddress = '127.0.0.1'
    ): void {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (company_id, user_id, action, entity_type, entity_id, details_json, ip_address, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $companyId,
            $userId,
            $action,
            $entityType,
            $entityId,
            $details !== [] ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            $ipAddress,
            Database::now(),
        ]);
    }
}
