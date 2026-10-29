-- MoMo Manager — Multi-Owner Mobile Money Enterprise Schema (MySQL 8+ / MariaDB 10.4+)

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL,
    business_reg_no VARCHAR(80) NULL,
    owner_phone VARCHAR(25) NOT NULL,
    currency VARCHAR(10) NOT NULL DEFAULT 'GHS',
    low_cash_threshold DECIMAL(14,2) NOT NULL DEFAULT 500.00,
    low_ecash_threshold DECIMAL(14,2) NOT NULL DEFAULT 500.00,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branches (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    code VARCHAR(30) NOT NULL,
    location VARCHAR(180) NULL,
    cash_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX idx_branches_company (company_id),
    CONSTRAINT fk_branches_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(25) NOT NULL,
    email VARCHAR(160) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'VENDOR',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_users_phone (phone),
    INDEX idx_users_company (company_id),
    INDEX idx_users_branch (branch_id),
    CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_users_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    device_name VARCHAR(120) NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_api_tokens_hash (token_hash),
    INDEX idx_api_tokens_user (user_id),
    CONSTRAINT fk_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS branch_wallets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    network VARCHAR(20) NOT NULL,
    merchant_number VARCHAR(25) NOT NULL,
    account_name VARCHAR(120) NOT NULL,
    ecash_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uq_branch_network (branch_id, network),
    INDEX idx_wallets_company (company_id),
    CONSTRAINT fk_wallets_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS shifts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    vendor_id BIGINT UNSIGNED NOT NULL,
    opening_cash DECIMAL(14,2) NOT NULL,
    opening_wallets_json TEXT NOT NULL,
    closing_cash_expected DECIMAL(14,2) NULL,
    closing_cash_actual DECIMAL(14,2) NULL,
    cash_variance DECIMAL(14,2) NULL,
    closing_wallets_expected_json TEXT NULL,
    closing_wallets_actual_json TEXT NULL,
    ecash_variance DECIMAL(14,2) NULL,
    notes TEXT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
    opened_at DATETIME NOT NULL,
    closed_at DATETIME NULL,
    INDEX idx_shifts_company_status (company_id, status),
    INDEX idx_shifts_branch (branch_id),
    CONSTRAINT fk_shifts_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_shifts_vendor FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS transactions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    wallet_id BIGINT UNSIGNED NOT NULL,
    vendor_id BIGINT UNSIGNED NOT NULL,
    shift_id BIGINT UNSIGNED NULL,
    tx_type VARCHAR(20) NOT NULL,
    network VARCHAR(20) NOT NULL,
    receiver_network VARCHAR(20) NOT NULL,
    customer_phone VARCHAR(25) NOT NULL,
    customer_name VARCHAR(120) NULL,
    sender_name VARCHAR(120) NULL,
    sender_phone VARCHAR(25) NULL,
    amount DECIMAL(14,2) NOT NULL,
    fee_charged DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    cash_impact DECIMAL(14,2) NOT NULL,
    ecash_impact DECIMAL(14,2) NOT NULL,
    cash_balance_after DECIMAL(14,2) NOT NULL,
    ecash_balance_after DECIMAL(14,2) NOT NULL,
    cashout_allowed_confirmed TINYINT(1) NOT NULL DEFAULT 0,
    telco_tx_id VARCHAR(80) NOT NULL,
    reference VARCHAR(160) NULL,
    raw_sms TEXT NULL,
    entry_mode VARCHAR(20) NOT NULL DEFAULT 'MANUAL',
    status VARCHAR(25) NOT NULL DEFAULT 'COMPLETED',
    void_reason TEXT NULL,
    void_requested_by BIGINT UNSIGNED NULL,
    void_requested_at DATETIME NULL,
    void_resolved_by BIGINT UNSIGNED NULL,
    void_resolved_at DATETIME NULL,
    void_resolution_note TEXT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uq_company_net_txid (company_id, network, telco_tx_id),
    INDEX idx_tx_company_created (company_id, created_at),
    INDEX idx_tx_branch_created (branch_id, created_at),
    INDEX idx_tx_vendor_created (vendor_id, created_at),
    INDEX idx_tx_status (company_id, status),
    CONSTRAINT fk_tx_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE,
    CONSTRAINT fk_tx_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE,
    CONSTRAINT fk_tx_wallet FOREIGN KEY (wallet_id) REFERENCES branch_wallets(id) ON DELETE CASCADE,
    CONSTRAINT fk_tx_vendor FOREIGN KEY (vendor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS float_adjustments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    branch_id BIGINT UNSIGNED NOT NULL,
    wallet_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    bucket VARCHAR(20) NOT NULL,
    direction VARCHAR(10) NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    balance_after DECIMAL(14,2) NOT NULL,
    reason VARCHAR(200) NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_float_company_created (company_id, created_at),
    CONSTRAINT fk_float_branch FOREIGN KEY (branch_id) REFERENCES branches(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    action VARCHAR(60) NOT NULL,
    entity_type VARCHAR(40) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details_json TEXT NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME NOT NULL,
    INDEX idx_audit_company_created (company_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
