-- Ejecutar una sola vez en bases que ya tienen client_pmi_habilitations.
ALTER TABLE client_pmi_habilitations
    ADD COLUMN pass_file_path VARCHAR(255) DEFAULT NULL AFTER has_pass;
