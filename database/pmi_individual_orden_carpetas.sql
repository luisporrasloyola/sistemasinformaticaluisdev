-- Ejecutar una sola vez después de carpetas_pmi_individual.sql.
-- Los registros actuales conservan su orden alfabético hasta que se utilicen las flechas.
ALTER TABLE pmi_requirement_folder_items
    ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER folder_id,
    ADD KEY idx_pmi_folder_sort (folder_id, sort_order);

ALTER TABLE pmi_requirement_folders
    ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER name,
    ADD KEY idx_pmi_folder_order (worker_id, position_id, sort_order);

ALTER TABLE worker_requirements
    ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER requirement_id,
    ADD KEY idx_worker_requirement_sort (worker_id, position_id, sort_order);
