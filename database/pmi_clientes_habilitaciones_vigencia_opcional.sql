-- Ejecutar una sola vez en bases que ya tienen client_pmi_habilitations.
ALTER TABLE client_pmi_habilitations
    MODIFY COLUMN registration_date DATE DEFAULT NULL,
    MODIFY COLUMN start_date DATE DEFAULT NULL,
    MODIFY COLUMN end_date DATE DEFAULT NULL;
