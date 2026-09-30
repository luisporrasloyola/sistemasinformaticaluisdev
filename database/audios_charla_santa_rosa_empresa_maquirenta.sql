-- Audios de Charla de Central Térmica Santa Rosa, independientes de Ventanilla.
CREATE TABLE IF NOT EXISTS empresa_maquirenta_santa_rosa_audios_charla_maquinarias (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emsrac_maquinaria_nombre (nombre),
    KEY idx_emsrac_maquinaria_estado (estado, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_santa_rosa_audios_charla (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    maquinaria_tipo_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    observaciones TEXT DEFAULT NULL,
    archivo_path VARCHAR(255) DEFAULT NULL,
    archivo_nombre_original VARCHAR(255) DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emsrac_maquinaria_fecha (maquinaria_tipo_id, fecha),
    KEY idx_emsrac_usuario (registered_by_user_id),
    KEY idx_emsrac_fecha (fecha),
    CONSTRAINT fk_emsrac_maquinaria FOREIGN KEY (maquinaria_tipo_id)
        REFERENCES empresa_maquirenta_santa_rosa_audios_charla_maquinarias (id),
    CONSTRAINT fk_emsrac_usuario FOREIGN KEY (registered_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa_maquirenta_santa_rosa_audios_charla_maquinarias (nombre, estado) VALUES
    ('Camión Grúa', 1), ('Montacargas', 1)
ON DUPLICATE KEY UPDATE estado = VALUES(estado);
