-- Ejecutar una vez en instalaciones que ya tengan worker_bank_accounts.
-- Quita los tamaños fijos anteriores de número de cuenta y CCI.
ALTER TABLE worker_bank_accounts
    MODIFY COLUMN account_number TEXT NOT NULL,
    MODIFY COLUMN cci TEXT NOT NULL;
