-- Ejecutar una vez después de pmi_clientes_formatos.sql.
-- Conserva las observaciones históricas; los enlaces nuevos se guardan en el documento.
ALTER TABLE client_pmi_format_documents
    ADD COLUMN link_url VARCHAR(2048) DEFAULT NULL AFTER file_path;
