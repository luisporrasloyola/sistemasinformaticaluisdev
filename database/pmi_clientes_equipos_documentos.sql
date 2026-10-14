-- Cambio de significado de category='equipos': source_id ahora apunta al catálogo de documentos de maquinaria.
-- Conservar las selecciones antiguas de maquinarias antes de limpiar las referencias incompatibles.
CREATE TABLE IF NOT EXISTS client_pmi_migration_flags (
    name VARCHAR(80) NOT NULL PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_pmi_legacy_equipment_selections (
    client_id INT UNSIGNED NOT NULL,
    source_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (client_id, source_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

START TRANSACTION;
INSERT IGNORE INTO client_pmi_legacy_equipment_selections (client_id, source_id, created_at)
SELECT client_id, source_id, created_at FROM client_pmi_general_items
WHERE category = 'equipos' AND NOT EXISTS (SELECT 1 FROM client_pmi_migration_flags WHERE name = 'equipos_documents_v1');
DELETE FROM client_pmi_general_items
WHERE category = 'equipos' AND NOT EXISTS (SELECT 1 FROM client_pmi_migration_flags WHERE name = 'equipos_documents_v1');
INSERT IGNORE INTO client_pmi_migration_flags (name) VALUES ('equipos_documents_v1');
COMMIT;
