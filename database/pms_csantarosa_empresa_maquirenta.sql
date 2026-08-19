CREATE TABLE IF NOT EXISTS empresa_maquirenta_csantarosa_pms (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
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
    UNIQUE KEY uq_em_pms_csantarosa_nro (nro_pms),
    KEY idx_em_pms_csantarosa_usuario (registered_by_user_id),
    KEY idx_em_pms_csantarosa_rango (rango_inicio, rango_fin),
    CONSTRAINT fk_em_pms_csantarosa_usuario FOREIGN KEY (registered_by_user_id)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
