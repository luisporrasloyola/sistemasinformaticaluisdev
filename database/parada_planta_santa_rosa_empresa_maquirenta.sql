-- Parada de Planta de Central Térmica Santa Rosa, independiente de Ventanilla.
CREATE TABLE IF NOT EXISTS empresa_maquirenta_santa_rosa_parada_planta_catalogo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emsrpp_catalogo_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_santa_rosa_parada_planta_documentos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_maquirenta_id INT UNSIGNED NOT NULL,
    documento_id INT UNSIGNED NOT NULL,
    fecha_registro DATE NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    observaciones TEXT DEFAULT NULL,
    archivo_path VARCHAR(255) DEFAULT NULL,
    archivo_nombre_original VARCHAR(255) DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emsrpp_documento_empresa (empresa_maquirenta_id, documento_id),
    KEY idx_emsrpp_documento (documento_id),
    KEY idx_emsrpp_usuario (registered_by_user_id),
    CONSTRAINT fk_emsrpp_empresa FOREIGN KEY (empresa_maquirenta_id) REFERENCES empresas_maquirenta (id),
    CONSTRAINT fk_emsrpp_catalogo FOREIGN KEY (documento_id) REFERENCES empresa_maquirenta_santa_rosa_parada_planta_catalogo (id),
    CONSTRAINT fk_emsrpp_usuario FOREIGN KEY (registered_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
