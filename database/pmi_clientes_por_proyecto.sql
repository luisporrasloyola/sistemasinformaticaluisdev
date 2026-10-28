-- Ejecutar una sola vez tras clientes_proyectos.sql y todas las migraciones PMI existentes.
-- Los datos actuales conservan project_id = 0: Sin proyecto.
ALTER TABLE client_pmi_general_items ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    DROP PRIMARY KEY, ADD PRIMARY KEY (client_id, project_id, category, source_id);
ALTER TABLE client_pmi_general_position_requirements DROP FOREIGN KEY fk_client_pmi_position_requirements_position;
ALTER TABLE client_pmi_general_positions ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    DROP PRIMARY KEY, ADD PRIMARY KEY (client_id, project_id, position_id);
ALTER TABLE client_pmi_general_position_requirements ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    DROP PRIMARY KEY, ADD PRIMARY KEY (client_id, project_id, position_id, requirement_id),
    ADD CONSTRAINT fk_client_pmi_position_requirements_position_project FOREIGN KEY (client_id, project_id, position_id)
        REFERENCES client_pmi_general_positions(client_id, project_id, position_id) ON DELETE CASCADE;
ALTER TABLE client_pmi_base_documents ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    ADD KEY idx_client_pmi_base_project (client_id, project_id);
ALTER TABLE client_pmi_base_catalog ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    DROP INDEX uq_client_pmi_catalog_name, ADD UNIQUE KEY uq_client_pmi_catalog_project_name (client_id, project_id, name);
ALTER TABLE client_pmi_base_folders ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    DROP INDEX uq_client_pmi_folder_name, ADD UNIQUE KEY uq_client_pmi_folder_project_name (client_id, project_id, name);
ALTER TABLE client_pmi_format_catalog ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    DROP INDEX uq_client_pmi_format_catalog, ADD UNIQUE KEY uq_client_pmi_format_catalog_project (client_id, project_id, name);
ALTER TABLE client_pmi_format_documents ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    ADD KEY idx_client_pmi_format_project (client_id, project_id);
ALTER TABLE client_pmi_format_folders ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    DROP INDEX uq_client_pmi_format_folder, ADD UNIQUE KEY uq_client_pmi_format_folder_project (client_id, project_id, name);
ALTER TABLE client_pmi_habilitations ADD COLUMN project_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER client_id,
    DROP INDEX uq_client_pmi_habilitation, ADD UNIQUE KEY uq_client_pmi_habilitation_project (client_id, project_id, area, subject_id);
