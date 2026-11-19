ALTER TABLE finance_personal_per_diem_edits
    ADD COLUMN honorarios DECIMAL(10,2) DEFAULT NULL AFTER amount_override;
