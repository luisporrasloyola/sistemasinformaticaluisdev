-- Ejecutar una sola vez después de pmi_clientes_habilitaciones.sql.
ALTER TABLE client_pmi_habilitations
    ADD COLUMN group_title VARCHAR(150) DEFAULT NULL AFTER habilitation_type;
