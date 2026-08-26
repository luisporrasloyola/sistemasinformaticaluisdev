-- Permisos de trabajo de Central Térmica Ventanilla.
-- Incluye historial independiente de vigencias y múltiples archivos por cierre.
CREATE TABLE IF NOT EXISTS empresa_maquirenta_permisos_trabajo (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permiso_nombre VARCHAR(180) NOT NULL,
    fecha_registro DATE NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    observaciones TEXT DEFAULT NULL,
    archivo_path VARCHAR(255) DEFAULT NULL,
    archivo_nombre_original VARCHAR(255) DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_em_permisos_vigencia (fecha_inicio, fecha_vencimiento),
    KEY idx_em_permisos_registro (fecha_registro),
    KEY idx_em_permisos_usuario (registered_by_user_id),
    CONSTRAINT fk_em_permisos_usuario FOREIGN KEY (registered_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_permiso_vigencias (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    permiso_trabajo_id INT UNSIGNED NOT NULL,
    fecha_registro DATE DEFAULT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_vencimiento DATE NOT NULL,
    observaciones TEXT DEFAULT NULL,
    fecha_cierre DATE DEFAULT NULL,
    closed_by_user_id INT UNSIGNED DEFAULT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_em_permiso_vigencia_fin (permiso_trabajo_id, fecha_vencimiento),
    KEY idx_em_permiso_vigencia_actual (permiso_trabajo_id, fecha_vencimiento, id),
    KEY idx_em_permiso_vigencia_usuario (registered_by_user_id),
    CONSTRAINT fk_em_permiso_vigencia_permiso FOREIGN KEY (permiso_trabajo_id)
        REFERENCES empresa_maquirenta_permisos_trabajo (id) ON DELETE CASCADE,
    CONSTRAINT fk_em_permiso_vigencia_usuario FOREIGN KEY (registered_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_permiso_archivos (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    vigencia_id INT UNSIGNED NOT NULL,
    archivo_path VARCHAR(255) NOT NULL,
    archivo_nombre_original VARCHAR(255) NOT NULL,
    tipo_archivo VARCHAR(20) NOT NULL DEFAULT 'vigencia',
    uploaded_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_em_permiso_archivo_vigencia (vigencia_id, created_at),
    KEY idx_em_permiso_archivo_usuario (uploaded_by_user_id),
    CONSTRAINT fk_em_permiso_archivo_vigencia FOREIGN KEY (vigencia_id)
        REFERENCES empresa_maquirenta_permiso_vigencias (id) ON DELETE CASCADE,
    CONSTRAINT fk_em_permiso_archivo_usuario FOREIGN KEY (uploaded_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migra registros creados con la primera versión sin eliminar fechas ni adjuntos.
INSERT INTO empresa_maquirenta_permiso_vigencias
    (permiso_trabajo_id, fecha_inicio, fecha_vencimiento, observaciones, registered_by_user_id, created_at, updated_at)
SELECT p.id, p.fecha_inicio, p.fecha_vencimiento, p.observaciones,
       p.registered_by_user_id, p.created_at, p.updated_at
FROM empresa_maquirenta_permisos_trabajo p
WHERE NOT EXISTS (
    SELECT 1 FROM empresa_maquirenta_permiso_vigencias v
    WHERE v.permiso_trabajo_id = p.id
);

INSERT INTO empresa_maquirenta_permiso_archivos
    (vigencia_id, archivo_path, archivo_nombre_original, uploaded_by_user_id, created_at)
SELECT v.id, p.archivo_path,
       COALESCE(NULLIF(p.archivo_nombre_original, ''), SUBSTRING_INDEX(p.archivo_path, '/', -1)),
       p.registered_by_user_id, p.created_at
FROM empresa_maquirenta_permisos_trabajo p
JOIN empresa_maquirenta_permiso_vigencias v
  ON v.permiso_trabajo_id = p.id
 AND v.fecha_inicio = p.fecha_inicio
 AND v.fecha_vencimiento = p.fecha_vencimiento
WHERE COALESCE(p.archivo_path, '') <> ''
  AND NOT EXISTS (
      SELECT 1 FROM empresa_maquirenta_permiso_archivos a
      WHERE a.vigencia_id = v.id AND a.archivo_path = p.archivo_path
  );
-- Actualización: el cierre es explícito y requiere fecha y documento de cierre.
ALTER TABLE empresa_maquirenta_permiso_vigencias
    ADD COLUMN IF NOT EXISTS fecha_cierre DATE DEFAULT NULL AFTER observaciones,
    ADD COLUMN IF NOT EXISTS closed_by_user_id INT UNSIGNED DEFAULT NULL AFTER fecha_cierre;

ALTER TABLE empresa_maquirenta_permiso_archivos
    ADD COLUMN IF NOT EXISTS tipo_archivo VARCHAR(20) NOT NULL DEFAULT 'vigencia' AFTER archivo_nombre_original;
-- Fecha de registro independiente para cada vigencia y ampliación.
ALTER TABLE empresa_maquirenta_permiso_vigencias
    ADD COLUMN IF NOT EXISTS fecha_registro DATE DEFAULT NULL AFTER permiso_trabajo_id;

UPDATE empresa_maquirenta_permiso_vigencias v
JOIN empresa_maquirenta_permisos_trabajo p ON p.id = v.permiso_trabajo_id
SET v.fecha_registro = CASE
    WHEN v.id = (SELECT vi.id FROM empresa_maquirenta_permiso_vigencias vi WHERE vi.permiso_trabajo_id = v.permiso_trabajo_id ORDER BY vi.fecha_vencimiento, vi.id LIMIT 1)
        THEN p.fecha_registro
    ELSE DATE(v.created_at)
END
WHERE v.fecha_registro IS NULL;
