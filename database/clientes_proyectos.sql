-- Catálogo de proyectos exclusivo de Clientes, independiente de Control de personal.
-- Ejecutar una sola vez en la base que ya tiene la tabla clients.
CREATE TABLE IF NOT EXISTS client_project_catalog (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(180) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_client_project_catalog_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS client_project_assignments (
    client_id INT UNSIGNED NOT NULL,
    project_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, project_id),
    KEY idx_client_project_assignments_project (project_id),
    CONSTRAINT fk_client_project_assignments_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_project_assignments_project FOREIGN KEY (project_id) REFERENCES client_project_catalog(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
