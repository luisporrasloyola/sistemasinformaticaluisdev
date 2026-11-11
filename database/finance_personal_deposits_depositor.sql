-- Ejecutar una sola vez en cada base de datos que ya tenga finance_personal_deposits.
ALTER TABLE finance_personal_deposits
    ADD COLUMN depositor VARCHAR(150) NULL DEFAULT NULL AFTER creditor;
