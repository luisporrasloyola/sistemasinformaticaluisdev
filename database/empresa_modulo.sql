CREATE TABLE IF NOT EXISTS empresas (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    razon_social VARCHAR(180) NOT NULL,
    ruc VARCHAR(20) NOT NULL,
    direccion VARCHAR(255) NULL,
    foto_path VARCHAR(255) NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_empresas_ruc (ruc)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_documentos_catalogo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(120) NOT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_empresa_documentos_catalogo_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa_documentos_catalogo (nombre, estado) VALUES
('Ficha RUC', 1),
('Vigencia', 1),
('Copia literal', 1)
ON DUPLICATE KEY UPDATE estado = VALUES(estado);

CREATE TABLE IF NOT EXISTS empresa_documentos (
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
    UNIQUE KEY uq_empresa_documento (empresa_id, documento_id),
    KEY idx_empresa_documentos_empresa (empresa_id),
    KEY idx_empresa_documentos_documento (documento_id),
    CONSTRAINT fk_empresa_documentos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
    CONSTRAINT fk_empresa_documentos_catalogo FOREIGN KEY (documento_id) REFERENCES empresa_documentos_catalogo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
