-- Ejecutar una sola vez. Registro independiente de depósitos al personal y compras.
CREATE TABLE IF NOT EXISTS finance_pending_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    creditor VARCHAR(150) NOT NULL,
    due_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    reason TEXT NOT NULL,
    created_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_finance_pending_due_date (due_date, id),
    CONSTRAINT fk_finance_pending_creator FOREIGN KEY (created_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS finance_pending_payment_abonos (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    pending_payment_id BIGINT UNSIGNED NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    deposit_id INT UNSIGNED NULL DEFAULT NULL,
    proof_path VARCHAR(255) NULL DEFAULT NULL,
    recorded_by_user_id INT UNSIGNED NULL DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_finance_pending_abono_payment (pending_payment_id, id),
    KEY idx_finance_pending_abono_deposit (deposit_id),
    CONSTRAINT fk_finance_pending_abono_payment FOREIGN KEY (pending_payment_id)
        REFERENCES finance_pending_payments (id) ON DELETE CASCADE,
    CONSTRAINT fk_finance_pending_abono_user FOREIGN KEY (recorded_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_finance_pending_abono_deposit FOREIGN KEY (deposit_id)
        REFERENCES finance_personal_deposits (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
