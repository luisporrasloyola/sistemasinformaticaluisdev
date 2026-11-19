-- Ejecutar una vez en instalaciones que ya tienen finance_pending_payment_abonos.
-- Los abonos de depósitos se eliminan si se elimina su depósito de origen.
ALTER TABLE finance_pending_payment_abonos
    ADD COLUMN deposit_id INT UNSIGNED NULL DEFAULT NULL AFTER amount,
    ADD KEY idx_finance_pending_abono_deposit (deposit_id),
    ADD CONSTRAINT fk_finance_pending_abono_deposit FOREIGN KEY (deposit_id)
        REFERENCES finance_personal_deposits (id) ON DELETE CASCADE;
