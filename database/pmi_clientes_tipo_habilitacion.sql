-- Ejecutar una sola vez en bases donde ya existe client_pmi_habilitations.
ALTER TABLE client_pmi_habilitations
    ADD COLUMN habilitation_type ENUM('individual','grupal') NOT NULL DEFAULT 'individual' AFTER subject_id,
    ADD COLUMN has_pass TINYINT(1) DEFAULT NULL AFTER habilitation_type,
    ADD COLUMN has_validity TINYINT(1) DEFAULT NULL AFTER has_pass;
