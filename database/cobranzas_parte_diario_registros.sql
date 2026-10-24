-- Registros de Parte diario, independientes de PMI y de Empresa Maquirenta.
-- Ejecutar una vez después de cobranzas_parte_diario_maquinarias.sql.
CREATE TABLE IF NOT EXISTS cobranzas_parte_diario_registros (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    maquinaria_id INT UNSIGNED NOT NULL,
    fecha DATE NOT NULL,
    observaciones VARCHAR(2000) DEFAULT NULL,
    archivo_path VARCHAR(255) DEFAULT NULL,
    archivo_nombre_original VARCHAR(255) DEFAULT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cobranzas_parte_maquinaria_fecha (maquinaria_id, fecha, id),
    CONSTRAINT fk_cobranzas_parte_maquinaria FOREIGN KEY (maquinaria_id)
        REFERENCES cobranzas_parte_diario_maquinarias(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
