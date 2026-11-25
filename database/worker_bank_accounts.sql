-- Cuentas bancarias del personal y catálogo de bancos.
CREATE TABLE IF NOT EXISTS worker_bank_catalog (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_worker_bank_catalog_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS worker_bank_accounts (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    bank_id INT UNSIGNED NOT NULL,
    account_number TEXT NOT NULL,
    currency CHAR(3) NOT NULL,
    cci TEXT NOT NULL,
    PRIMARY KEY (id),
    KEY idx_worker_bank_accounts_worker (worker_id),
    KEY idx_worker_bank_accounts_bank (bank_id),
    CONSTRAINT fk_worker_bank_accounts_worker FOREIGN KEY (worker_id)
        REFERENCES workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_worker_bank_accounts_bank FOREIGN KEY (bank_id)
        REFERENCES worker_bank_catalog (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
