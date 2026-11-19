-- Ejecutar una vez después de finance_company_sole_account.sql.
-- Datos de ingreso y comprobante opcional asociados a un gasto que proviene de Depósitos al personal.
CREATE TABLE IF NOT EXISTS finance_company_sole_deposit_details (
    source_deposit_id INT UNSIGNED NOT NULL,
    deposit DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deposit_concept VARCHAR(500) NOT NULL DEFAULT '',
    proof_path VARCHAR(255) NULL DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (source_deposit_id),
    CONSTRAINT fk_company_sole_source_deposit FOREIGN KEY (source_deposit_id)
        REFERENCES finance_personal_deposits (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
