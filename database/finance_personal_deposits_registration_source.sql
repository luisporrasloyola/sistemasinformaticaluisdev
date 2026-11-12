-- Ejecutar una sola vez. Los depósitos existentes se clasifican como Gemini.
ALTER TABLE finance_personal_deposits
    ADD COLUMN registration_source VARCHAR(10) NOT NULL DEFAULT 'gemini' AFTER deposit_date;
