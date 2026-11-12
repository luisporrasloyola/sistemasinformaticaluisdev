CREATE TABLE IF NOT EXISTS empresa_maquirenta_valorizacion_ajustes (
    central VARCHAR(20) NOT NULL,
    charla_id INT UNSIGNED NOT NULL,
    costo_equipo DECIMAL(12,2) NULL,
    PRIMARY KEY (central, charla_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_valorizacion_ajustes_dia (
    central VARCHAR(20) NOT NULL,
    fecha DATE NOT NULL,
    costo_supervisor DECIMAL(12,2) NULL,
    total DECIMAL(12,2) NULL,
    PRIMARY KEY (central, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
