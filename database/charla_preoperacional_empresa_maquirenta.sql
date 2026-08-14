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
    fecha DATE NOT NULL,
    observaciones TEXT DEFAULT NULL,
    archivo_path VARCHAR(255) DEFAULT NULL,
    archivo_nombre_original VARCHAR(255) DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emcp_maquinaria_fecha (maquinaria_tipo_id, fecha),
    KEY idx_emcp_usuario (registered_by_user_id),
    KEY idx_emcp_fecha (fecha),
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
    fecha DATE NOT NULL,
    observaciones TEXT DEFAULT NULL,
    archivo_path VARCHAR(255) DEFAULT NULL,
    archivo_nombre_original VARCHAR(255) DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_emsrcp_maquinaria_fecha (maquinaria_tipo_id, fecha),
    KEY idx_emsrcp_usuario (registered_by_user_id),
    KEY idx_emsrcp_fecha (fecha),
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
-- Compatibilidad con la versión anterior basada en rango semanal y Nro. PMS.
ALTER TABLE empresa_maquirenta_charla_preoperacional
    ADD COLUMN IF NOT EXISTS fecha DATE NULL AFTER maquinaria_tipo_id;
SET @emcp_has_rango = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_maquirenta_charla_preoperacional' AND COLUMN_NAME = 'rango_inicio');
SET @emcp_migrate = IF(@emcp_has_rango > 0, 'UPDATE empresa_maquirenta_charla_preoperacional SET fecha = rango_inicio WHERE fecha IS NULL', 'SELECT 1');
PREPARE emcp_stmt FROM @emcp_migrate;
EXECUTE emcp_stmt;
DEALLOCATE PREPARE emcp_stmt;
SET @emcp_legacy_nullable = IF(@emcp_has_rango > 0, 'ALTER TABLE empresa_maquirenta_charla_preoperacional MODIFY rango_inicio DATE NULL, MODIFY rango_fin DATE NULL, MODIFY nro_pms TINYINT UNSIGNED NULL', 'SELECT 1');
PREPARE emcp_legacy_stmt FROM @emcp_legacy_nullable;
EXECUTE emcp_legacy_stmt;
DEALLOCATE PREPARE emcp_legacy_stmt;
ALTER TABLE empresa_maquirenta_charla_preoperacional
    MODIFY COLUMN fecha DATE NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_emcp_maquinaria_fecha (maquinaria_tipo_id, fecha),
    ADD INDEX IF NOT EXISTS idx_emcp_fecha (fecha);

ALTER TABLE empresa_maquirenta_santa_rosa_charla_preoperacional
    ADD COLUMN IF NOT EXISTS fecha DATE NULL AFTER maquinaria_tipo_id;
SET @emsrcp_has_rango = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'empresa_maquirenta_santa_rosa_charla_preoperacional' AND COLUMN_NAME = 'rango_inicio');
SET @emsrcp_migrate = IF(@emsrcp_has_rango > 0, 'UPDATE empresa_maquirenta_santa_rosa_charla_preoperacional SET fecha = rango_inicio WHERE fecha IS NULL', 'SELECT 1');
PREPARE emsrcp_stmt FROM @emsrcp_migrate;
EXECUTE emsrcp_stmt;
DEALLOCATE PREPARE emsrcp_stmt;
SET @emsrcp_legacy_nullable = IF(@emsrcp_has_rango > 0, 'ALTER TABLE empresa_maquirenta_santa_rosa_charla_preoperacional MODIFY rango_inicio DATE NULL, MODIFY rango_fin DATE NULL, MODIFY nro_pms TINYINT UNSIGNED NULL', 'SELECT 1');
PREPARE emsrcp_legacy_stmt FROM @emsrcp_legacy_nullable;
EXECUTE emsrcp_legacy_stmt;
DEALLOCATE PREPARE emsrcp_legacy_stmt;
ALTER TABLE empresa_maquirenta_santa_rosa_charla_preoperacional
    MODIFY COLUMN fecha DATE NOT NULL,
    ADD UNIQUE INDEX IF NOT EXISTS uq_emsrcp_maquinaria_fecha (maquinaria_tipo_id, fecha),
    ADD INDEX IF NOT EXISTS idx_emsrcp_fecha (fecha);
