-- Ejecutar antes de publicar la edición del motivo en Gastos de personal.
-- Guarda el texto mostrado en Gastos sin modificar los datos del depósito.
CREATE TABLE IF NOT EXISTS finance_personal_expense_reasons (
    deposit_id INT UNSIGNED NOT NULL,
    worker_id INT UNSIGNED NOT NULL,
    reason VARCHAR(1000) NOT NULL,
    PRIMARY KEY (deposit_id, worker_id),
    CONSTRAINT fk_personal_expense_reason_deposit FOREIGN KEY (deposit_id)
        REFERENCES finance_personal_deposits (id) ON DELETE CASCADE,
    CONSTRAINT fk_personal_expense_reason_worker FOREIGN KEY (worker_id)
        REFERENCES workers (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
