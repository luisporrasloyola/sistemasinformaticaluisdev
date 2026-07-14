CREATE TABLE IF NOT EXISTS empresa_calidad_catalogo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(160) NOT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_empresa_calidad_catalogo_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa_calidad_catalogo (nombre, estado) VALUES
('Politica de calidad', 1),
('Matriz de oportunidades', 1)
ON DUPLICATE KEY UPDATE estado = VALUES(estado);

CREATE TABLE IF NOT EXISTS empresa_calidad_documentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    documento_id INT UNSIGNED NOT NULL,
    fecha_registro DATE NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    observaciones TEXT NULL,
    archivo_path VARCHAR(255) NULL,
    archivo_nombre_original VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_empresa_calidad_documento (empresa_id, documento_id),
    KEY idx_empresa_calidad_empresa (empresa_id),
    KEY idx_empresa_calidad_documento (documento_id),
    CONSTRAINT fk_empresa_calidad_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_empresa_calidad_catalogo FOREIGN KEY (documento_id) REFERENCES empresa_calidad_catalogo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_medio_ambiente_catalogo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(160) NOT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_empresa_medio_ambiente_catalogo_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa_medio_ambiente_catalogo (nombre, estado) VALUES
('Politica de medio ambiente', 1),
('Matriz IAA', 1)
ON DUPLICATE KEY UPDATE estado = VALUES(estado);

CREATE TABLE IF NOT EXISTS empresa_medio_ambiente_documentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    empresa_id INT UNSIGNED NOT NULL,
    documento_id INT UNSIGNED NOT NULL,
    fecha_registro DATE NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    observaciones TEXT NULL,
    archivo_path VARCHAR(255) NULL,
    archivo_nombre_original VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_empresa_medio_ambiente_documento (empresa_id, documento_id),
    KEY idx_empresa_medio_ambiente_empresa (empresa_id),
    KEY idx_empresa_medio_ambiente_documento (documento_id),
    CONSTRAINT fk_empresa_medio_ambiente_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_empresa_medio_ambiente_catalogo FOREIGN KEY (documento_id) REFERENCES empresa_medio_ambiente_catalogo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
