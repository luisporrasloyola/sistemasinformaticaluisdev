-- Ajustes persistentes de Valorizaciones. Se puede ejecutar nuevamente al agregar campos.
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

-- Descuento y observaciones por fecha y central. Se puede ejecutar este archivo
-- nuevamente en instalaciones que ya tienen las dos tablas anteriores.
CREATE TABLE IF NOT EXISTS empresa_maquirenta_valorizacion_detalles (
    central VARCHAR(20) NOT NULL,
    fecha DATE NOT NULL,
    descuento DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    observaciones VARCHAR(1000) DEFAULT NULL,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (central, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exclusión individual de equipos de la valorización. Sin fila equivale a incluido.
CREATE TABLE IF NOT EXISTS empresa_maquirenta_valorizacion_bloqueos (
    central VARCHAR(20) NOT NULL,
    charla_id INT UNSIGNED NOT NULL,
    bloqueado TINYINT(1) NOT NULL DEFAULT 1,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (central, charla_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
