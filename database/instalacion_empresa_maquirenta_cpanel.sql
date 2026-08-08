-- Empresa Maquirenta: instalación y actualización completa para cPanel.
-- Ejecutar una sola vez sobre la base de datos del proyecto.

CREATE TABLE IF NOT EXISTS empresas_maquirenta (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    razon_social VARCHAR(180) NOT NULL,
    ruc VARCHAR(20) NOT NULL,
    direccion VARCHAR(255) DEFAULT NULL,
    foto_path VARCHAR(255) DEFAULT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresas_maquirenta_ruc (ruc),
    KEY idx_empresas_maquirenta_status_razon (status, razon_social)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_documentos_catalogo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    nombre VARCHAR(150) NOT NULL,
    tipo_segmentacion VARCHAR(20) NOT NULL DEFAULT 'ninguna',
    estado TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_empresa_maquirenta_documento_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_documentos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    empresa_maquirenta_id INT UNSIGNED NOT NULL,
    documento_id INT UNSIGNED NOT NULL,
    segmento_clave VARCHAR(80) NOT NULL DEFAULT '',
    segmento_etiqueta VARCHAR(120) DEFAULT NULL,
    periodo_anio SMALLINT UNSIGNED NOT NULL DEFAULT 0,
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
    UNIQUE KEY uq_empresa_maquirenta_documento_segmento
        (empresa_maquirenta_id, documento_id, segmento_clave, periodo_anio),
    KEY idx_em_documentos_catalogo (documento_id),
    KEY idx_em_documentos_usuario (registered_by_user_id),
    CONSTRAINT fk_em_documentos_empresa
        FOREIGN KEY (empresa_maquirenta_id) REFERENCES empresas_maquirenta (id),
    CONSTRAINT fk_em_documentos_catalogo
        FOREIGN KEY (documento_id) REFERENCES empresa_maquirenta_documentos_catalogo (id),
    CONSTRAINT fk_em_documentos_usuario
        FOREIGN KEY (registered_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibilidad cuando en cPanel ya existen las tablas de la primera versión.
ALTER TABLE empresa_maquirenta_documentos_catalogo
    ADD COLUMN IF NOT EXISTS tipo_segmentacion VARCHAR(20) NOT NULL DEFAULT 'ninguna' AFTER nombre;

ALTER TABLE empresa_maquirenta_documentos
    ADD COLUMN IF NOT EXISTS segmento_clave VARCHAR(80) NOT NULL DEFAULT '' AFTER documento_id,
    ADD COLUMN IF NOT EXISTS segmento_etiqueta VARCHAR(120) DEFAULT NULL AFTER segmento_clave,
    ADD COLUMN IF NOT EXISTS periodo_anio SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER segmento_etiqueta;

-- Categorías iniciales y su comportamiento dinámico.
INSERT INTO empresa_maquirenta_documentos_catalogo (nombre, tipo_segmentacion, estado) VALUES
    ('Informe', 'numero', 1),
    ('Boletas', 'mes', 1),
    ('Permisos de trabajo', 'codigo', 1)
ON DUPLICATE KEY UPDATE
    tipo_segmentacion = VALUES(tipo_segmentacion),
    estado = VALUES(estado);

-- Convierte documentos de la primera versión sin eliminar archivos ni fechas.
UPDATE empresa_maquirenta_documentos d
JOIN empresa_maquirenta_documentos_catalogo c ON c.id = d.documento_id
SET d.segmento_clave = CASE c.tipo_segmentacion
        WHEN 'numero' THEN '1'
        WHEN 'mes' THEN DATE_FORMAT(d.fecha_inicio, '%m')
        WHEN 'codigo' THEN 'GENERAL'
        ELSE ''
    END,
    d.segmento_etiqueta = CASE c.tipo_segmentacion
        WHEN 'numero' THEN '1'
        WHEN 'mes' THEN ELT(
            MONTH(d.fecha_inicio),
            'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
            'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'
        )
        WHEN 'codigo' THEN 'General'
        ELSE NULL
    END,
    d.periodo_anio = CASE
        WHEN c.tipo_segmentacion = 'mes' THEN YEAR(d.fecha_inicio)
        ELSE 0
    END
WHERE d.segmento_clave = '';

-- Sustituye la restricción antigua por la que admite números, meses y códigos.
ALTER TABLE empresa_maquirenta_documentos
    DROP INDEX IF EXISTS uq_empresa_maquirenta_documento,
    ADD UNIQUE INDEX IF NOT EXISTS uq_empresa_maquirenta_documento_segmento
        (empresa_maquirenta_id, documento_id, segmento_clave, periodo_anio);
