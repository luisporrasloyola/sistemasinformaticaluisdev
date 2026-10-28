-- Ejecutar una sola vez en phpMyAdmin antes de publicar los cambios de PMI Individual.
-- file_path y original_file_name se conservan: los adjuntos existentes no se modifican.
ALTER TABLE worker_requirements
    ADD COLUMN document_type ENUM('legacy','pdf','pdf_word','pdf_word_image') NOT NULL DEFAULT 'legacy' AFTER original_file_name,
    ADD COLUMN word_file_path VARCHAR(255) DEFAULT NULL AFTER document_type,
    ADD COLUMN word_original_file_name VARCHAR(255) DEFAULT NULL AFTER word_file_path,
    ADD COLUMN image_file_path VARCHAR(255) DEFAULT NULL AFTER word_original_file_name,
    ADD COLUMN image_original_file_name VARCHAR(255) DEFAULT NULL AFTER image_file_path;
