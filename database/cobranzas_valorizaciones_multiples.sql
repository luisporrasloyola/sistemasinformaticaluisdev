-- Ejecutar una sola vez antes de publicar el código de múltiples valorizaciones.
-- Conserva la cabecera, registros y precios existentes como primera valorización de cada cliente.
CREATE TABLE IF NOT EXISTS cobranzas_valorizaciones (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    lugar_servicio VARCHAR(200) NOT NULL DEFAULT '',
    equipo VARCHAR(150) NOT NULL DEFAULT '',
    capacidad VARCHAR(100) NOT NULL DEFAULT '',
    periodo VARCHAR(120) NOT NULL DEFAULT '',
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cv_valorizaciones_cliente (client_id, id),
    CONSTRAINT fk_cv_valorizaciones_cliente FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cobranzas_valorizaciones (client_id, lugar_servicio, equipo, capacidad, periodo)
SELECT c.id, COALESCE(d.lugar_servicio, ''), COALESCE(d.equipo, ''), COALESCE(d.capacidad, ''), COALESCE(d.periodo, '')
FROM clients c
LEFT JOIN cobranzas_valorizacion_datos_cliente d ON d.client_id = c.id
LEFT JOIN cobranzas_valorizaciones v ON v.client_id = c.id
WHERE v.id IS NULL AND (
    d.client_id IS NOT NULL
    OR EXISTS (SELECT 1 FROM cobranzas_valorizacion_registros r WHERE r.client_id = c.id)
    OR EXISTS (SELECT 1 FROM cobranzas_valorizacion_precios_hora p WHERE p.client_id = c.id)
);

ALTER TABLE cobranzas_valorizacion_registros
    ADD COLUMN valorizacion_id INT UNSIGNED DEFAULT NULL AFTER client_id;

UPDATE cobranzas_valorizacion_registros r
JOIN (SELECT client_id, MIN(id) AS valorizacion_id FROM cobranzas_valorizaciones GROUP BY client_id) v
    ON v.client_id = r.client_id
SET r.valorizacion_id = v.valorizacion_id;

ALTER TABLE cobranzas_valorizacion_registros
    MODIFY COLUMN valorizacion_id INT UNSIGNED NOT NULL,
    ADD KEY idx_cv_registros_valorizacion_fecha (valorizacion_id, fecha_servicio, id),
    ADD CONSTRAINT fk_cv_registros_valorizacion FOREIGN KEY (valorizacion_id)
        REFERENCES cobranzas_valorizaciones(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS cobranzas_valorizacion_precios_valorizacion (
    valorizacion_id INT UNSIGNED NOT NULL,
    equipo_clave VARCHAR(120) NOT NULL,
    precio_hora DECIMAL(12,2) NOT NULL DEFAULT 37.50,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (valorizacion_id, equipo_clave),
    CONSTRAINT fk_cv_precio_valorizacion FOREIGN KEY (valorizacion_id)
        REFERENCES cobranzas_valorizaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO cobranzas_valorizacion_precios_valorizacion (valorizacion_id, equipo_clave, precio_hora)
SELECT v.valorizacion_id, p.equipo_clave, p.precio_hora
FROM cobranzas_valorizacion_precios_hora p
JOIN (SELECT client_id, MIN(id) AS valorizacion_id FROM cobranzas_valorizaciones GROUP BY client_id) v
    ON v.client_id = p.client_id;
