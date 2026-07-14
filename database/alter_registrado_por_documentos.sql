ALTER TABLE worker_requirements
    ADD COLUMN registered_by_user_id INT UNSIGNED NULL AFTER original_file_name,
    ADD KEY idx_worker_requirements_registered_by (registered_by_user_id),
    ADD CONSTRAINT fk_worker_requirements_registered_by FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE maquinaria_documentos
    ADD COLUMN registered_by_user_id INT UNSIGNED NULL AFTER archivo_nombre_original,
    ADD KEY idx_maquinaria_documentos_registered_by (registered_by_user_id),
    ADD CONSTRAINT fk_maquinaria_documentos_registered_by FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE empresa_documentos
    ADD COLUMN registered_by_user_id INT UNSIGNED NULL AFTER archivo_nombre_original,
    ADD KEY idx_empresa_documentos_registered_by (registered_by_user_id),
    ADD CONSTRAINT fk_empresa_documentos_registered_by FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE empresa_seguridad_documentos
    ADD COLUMN registered_by_user_id INT UNSIGNED NULL AFTER archivo_nombre_original,
    ADD KEY idx_empresa_seguridad_documentos_registered_by (registered_by_user_id),
    ADD CONSTRAINT fk_empresa_seguridad_documentos_registered_by FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE empresa_calidad_documentos
    ADD COLUMN registered_by_user_id INT UNSIGNED NULL AFTER archivo_nombre_original,
    ADD KEY idx_empresa_calidad_documentos_registered_by (registered_by_user_id),
    ADD CONSTRAINT fk_empresa_calidad_documentos_registered_by FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE empresa_medio_ambiente_documentos
    ADD COLUMN registered_by_user_id INT UNSIGNED NULL AFTER archivo_nombre_original,
    ADD KEY idx_empresa_medio_ambiente_documentos_registered_by (registered_by_user_id),
    ADD CONSTRAINT fk_empresa_medio_ambiente_documentos_registered_by FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL;
