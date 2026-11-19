-- Ejecutar una vez en la base de datos del sistema.
CREATE TABLE IF NOT EXISTS finance_company_sole_opening_balances (
    month_key CHAR(7) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    updated_by_user_id INT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (month_key),
    CONSTRAINT fk_company_sole_balance_user FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_company_sole_movements (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    movement_date DATE NOT NULL,
    expense DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    expense_concept VARCHAR(500) NOT NULL DEFAULT '',
    deposit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deposit_concept VARCHAR(500) NOT NULL DEFAULT '',
    created_by_user_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL,
    PRIMARY KEY (id),
    KEY idx_company_sole_movement_date (movement_date, id),
    CONSTRAINT fk_company_sole_movement_user FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
