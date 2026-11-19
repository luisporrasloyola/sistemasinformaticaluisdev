-- Ejecutar una vez en instalaciones con finance_pending_payment_abonos existente.
ALTER TABLE finance_pending_payment_abonos
    ADD COLUMN proof_path VARCHAR(255) NULL DEFAULT NULL AFTER deposit_id;
