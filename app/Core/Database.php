<?php

declare(strict_types=1);

namespace App\Core;

use Database\DemoSeeder;
use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;
    private static string $rootPath = '';

    public static function init(string $rootPath): void
    {
        self::$rootPath = $rootPath;
    }

    public static function reset(): void
    {
        self::$pdo = null;
    }

    public static function driver(): string
    {
        return strtolower((string) Config::get('DB_DRIVER', 'mysql'));
    }

    public static function forUpdate(): string
    {
        return self::driver() === 'mysql' ? ' FOR UPDATE' : '';
    }

    public static function now(): string
    {
        return date('Y-m-d H:i:s');
    }

    public static function connection(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $driver = self::driver();

        if ($driver === 'sqlite') {
            $sqlitePath = (string) Config::get('DB_SQLITE_PATH', ':memory:');
            $pdo = new PDO('sqlite:' . $sqlitePath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            $pdo->exec('PRAGMA foreign_keys = ON;');
            self::$pdo = $pdo;
            self::migrateSqlite($pdo);
            if (Config::bool('SEED_DEMO_DATA', true)) {
                DemoSeeder::seedIfEmpty($pdo);
            }
            return self::$pdo;
        }

        $host = (string) Config::get('DB_HOST', '127.0.0.1');
        $port = (string) Config::get('DB_PORT', '3306');
        $dbName = preg_replace('/[^a-zA-Z0-9_]/', '', (string) Config::get('DB_NAME', 'momo_manager')) ?: 'momo_manager';
        $user = (string) Config::get('DB_USER', 'root');
        $pass = (string) Config::get('DB_PASS', '');

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 4,
        ];

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Code 1049 = Unknown database: create database automatically on XAMPP first run
            if (str_contains($e->getMessage(), '1049') || str_contains(strtolower($e->getMessage()), 'unknown database')) {
                $serverDsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                $serverPdo = new PDO($serverDsn, $user, $pass, $options);
                $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;");
                $serverPdo = null;

                $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, $options);
            } else {
                throw $e;
            }
        }

        self::$pdo = $pdo;
        self::ensureMysqlSchema($pdo);
        if (Config::bool('SEED_DEMO_DATA', true)) {
            DemoSeeder::seedIfEmpty($pdo);
        }

        return self::$pdo;
    }

    private static function ensureMysqlSchema(PDO $pdo): void
    {
        $stmt = $pdo->query("SHOW TABLES LIKE 'companies'");
        if ($stmt !== false && $stmt->fetchColumn() !== false) {
            return;
        }

        $schemaFile = self::$rootPath . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'schema.sql';
        if (!is_file($schemaFile)) {
            throw new \RuntimeException('Missing database/schema.sql');
        }

        $sql = (string) file_get_contents($schemaFile);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql) ?? $sql;
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $statement) {
            if ($statement !== '') {
                $pdo->exec($statement);
            }
        }
    }

    private static function migrateSqlite(PDO $pdo): void
    {
        $check = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='companies'");
        if ($check !== false && $check->fetchColumn() !== false) {
            return;
        }

        $pdo->exec(<<<'SQL'
CREATE TABLE companies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    business_reg_no TEXT NULL,
    owner_phone TEXT NOT NULL,
    currency TEXT NOT NULL DEFAULT 'GHS',
    low_cash_threshold NUMERIC NOT NULL DEFAULT 500.00,
    low_ecash_threshold NUMERIC NOT NULL DEFAULT 500.00,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE branches (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    code TEXT NOT NULL,
    location TEXT NULL,
    cash_balance NUMERIC NOT NULL DEFAULT 0.00,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);

CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    branch_id INTEGER NULL,
    full_name TEXT NOT NULL,
    phone TEXT NOT NULL UNIQUE,
    email TEXT NULL,
    password_hash TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT 'VENDOR',
    is_active INTEGER NOT NULL DEFAULT 1,
    last_login_at TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
);

CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    company_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    device_name TEXT NULL,
    expires_at TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE branch_wallets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    network TEXT NOT NULL,
    merchant_number TEXT NOT NULL,
    account_name TEXT NOT NULL,
    ecash_balance NUMERIC NOT NULL DEFAULT 0.00,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(branch_id, network),
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE shifts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    vendor_id INTEGER NOT NULL,
    opening_cash NUMERIC NOT NULL,
    opening_wallets_json TEXT NOT NULL,
    closing_cash_expected NUMERIC NULL,
    closing_cash_actual NUMERIC NULL,
    cash_variance NUMERIC NULL,
    closing_wallets_expected_json TEXT NULL,
    closing_wallets_actual_json TEXT NULL,
    ecash_variance NUMERIC NULL,
    notes TEXT NULL,
    status TEXT NOT NULL DEFAULT 'OPEN',
    opened_at TEXT NOT NULL,
    closed_at TEXT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    wallet_id INTEGER NOT NULL,
    vendor_id INTEGER NOT NULL,
    shift_id INTEGER NULL,
    tx_type TEXT NOT NULL,
    network TEXT NOT NULL,
    receiver_network TEXT NOT NULL,
    customer_phone TEXT NOT NULL,
    customer_name TEXT NULL,
    sender_name TEXT NULL,
    sender_phone TEXT NULL,
    amount NUMERIC NOT NULL,
    fee_charged NUMERIC NOT NULL DEFAULT 0.00,
    cash_impact NUMERIC NOT NULL,
    ecash_impact NUMERIC NOT NULL,
    cash_balance_after NUMERIC NOT NULL,
    ecash_balance_after NUMERIC NOT NULL,
    cashout_allowed_confirmed INTEGER NOT NULL DEFAULT 0,
    telco_tx_id TEXT NOT NULL,
    reference TEXT NULL,
    raw_sms TEXT NULL,
    entry_mode TEXT NOT NULL DEFAULT 'MANUAL',
    status TEXT NOT NULL DEFAULT 'COMPLETED',
    void_reason TEXT NULL,
    void_requested_by INTEGER NULL,
    void_requested_at TEXT NULL,
    void_resolved_by INTEGER NULL,
    void_resolved_at TEXT NULL,
    void_resolution_note TEXT NULL,
    created_at TEXT NOT NULL,
    UNIQUE(company_id, network, telco_tx_id),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    FOREIGN KEY (wallet_id) REFERENCES branch_wallets(id) ON DELETE CASCADE,
    FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE float_adjustments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    branch_id INTEGER NOT NULL,
    wallet_id INTEGER NULL,
    user_id INTEGER NOT NULL,
    bucket TEXT NOT NULL,
    direction TEXT NOT NULL,
    amount NUMERIC NOT NULL,
    balance_after NUMERIC NOT NULL,
    reason TEXT NOT NULL,
    created_at TEXT NOT NULL,
    FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
);

CREATE TABLE audit_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    company_id INTEGER NOT NULL,
    user_id INTEGER NULL,
    action TEXT NOT NULL,
    entity_type TEXT NOT NULL,
    entity_id INTEGER NULL,
    details_json TEXT NULL,
    ip_address TEXT NULL,
    created_at TEXT NOT NULL
);
SQL);
    }
}
