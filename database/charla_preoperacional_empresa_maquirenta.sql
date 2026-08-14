-- Instalación de Charla preoperacional para ambas centrales térmicas.
-- Los datos de Ventanilla y Santa Rosa se almacenan de forma independiente.

CREATE TABLE IF NOT EXISTS empresa_maquirenta_charla_preoperacional_maquinarias (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcp_maquinaria_nombre (nombre),
    KEY idx_emcp_maquinaria_estado (estado, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_charla_preoperacional (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    maquinaria_tipo_id INT UNSIGNED NOT NULL,
    rango_inicio DATE NOT NULL,
    rango_fin DATE NOT NULL,
    nro_pms TINYINT UNSIGNED NOT NULL,
    observaciones TEXT DEFAULT NULL,
    archivo_path VARCHAR(255) DEFAULT NULL,
    archivo_nombre_original VARCHAR(255) DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcp_maquinaria_pms (maquinaria_tipo_id, nro_pms),
    KEY idx_emcp_usuario (registered_by_user_id),
    KEY idx_emcp_rango (rango_inicio, rango_fin),
    CONSTRAINT fk_emcp_maquinaria FOREIGN KEY (maquinaria_tipo_id)
        REFERENCES empresa_maquirenta_charla_preoperacional_maquinarias (id),
    CONSTRAINT fk_emcp_usuario FOREIGN KEY (registered_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_santa_rosa_charla_preoperacional_maquinarias (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emsrcp_maquinaria_nombre (nombre),
    KEY idx_emsrcp_maquinaria_estado (estado, nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_santa_rosa_charla_preoperacional (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    maquinaria_tipo_id INT UNSIGNED NOT NULL,
    rango_inicio DATE NOT NULL,
    rango_fin DATE NOT NULL,
    nro_pms TINYINT UNSIGNED NOT NULL,
    observaciones TEXT DEFAULT NULL,
    archivo_path VARCHAR(255) DEFAULT NULL,
    archivo_nombre_original VARCHAR(255) DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emsrcp_maquinaria_pms (maquinaria_tipo_id, nro_pms),
    KEY idx_emsrcp_usuario (registered_by_user_id),
    KEY idx_emsrcp_rango (rango_inicio, rango_fin),
    CONSTRAINT fk_emsrcp_maquinaria FOREIGN KEY (maquinaria_tipo_id)
        REFERENCES empresa_maquirenta_santa_rosa_charla_preoperacional_maquinarias (id),
    CONSTRAINT fk_emsrcp_usuario FOREIGN KEY (registered_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO empresa_maquirenta_charla_preoperacional_maquinarias (nombre, estado) VALUES
    ('Camión Grúa', 1), ('Montacargas', 1)
ON DUPLICATE KEY UPDATE estado = VALUES(estado);

INSERT INTO empresa_maquirenta_santa_rosa_charla_preoperacional_maquinarias (nombre, estado) VALUES
    ('Camión Grúa', 1), ('Montacargas', 1)
ON DUPLICATE KEY UPDATE estado = VALUES(estado);
