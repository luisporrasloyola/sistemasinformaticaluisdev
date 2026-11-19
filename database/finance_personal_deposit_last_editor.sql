-- Ejecutar una vez antes de mostrar quién editó por última vez un depósito.
-- Se conserva registered_by_user_id como autor original para los permisos.
ALTER TABLE finance_personal_deposits
    ADD COLUMN last_edited_by_user_id INT UNSIGNED NULL DEFAULT NULL AFTER registered_by_user_id,
    ADD KEY idx_finance_personal_deposit_last_editor (last_edited_by_user_id),
    ADD CONSTRAINT fk_finance_personal_deposit_last_editor
        FOREIGN KEY (last_edited_by_user_id) REFERENCES users (id) ON DELETE SET NULL;
