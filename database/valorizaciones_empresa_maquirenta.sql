-- Ajustes persistentes de Valorizaciones. Ejecutar una vez en cada base de datos.
CREATE TABLE IF NOT EXISTS empresa_maquirenta_valorizacion_equipos (
    central VARCHAR(20) NOT NULL,
    charla_id INT UNSIGNED NOT NULL,
    horas_trabajo DECIMAL(5,2) DEFAULT NULL,
    tarifa_equipo DECIMAL(12,2) DEFAULT NULL,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (central, charla_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_valorizacion_supervisor (
    central VARCHAR(20) NOT NULL,
    fecha DATE NOT NULL,
    tarifa_supervisor DECIMAL(12,2) NOT NULL DEFAULT 50.00,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (central, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
