-- Precio por hora por cliente y tipo de equipo en Cobranzas > Valorización.
CREATE TABLE IF NOT EXISTS cobranzas_valorizacion_precios_hora (
    client_id INT UNSIGNED NOT NULL,
    equipo_clave VARCHAR(120) NOT NULL,
    precio_hora DECIMAL(12,2) NOT NULL DEFAULT 37.50,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, equipo_clave),
    CONSTRAINT fk_cobranzas_valorizacion_precio_cliente FOREIGN KEY (client_id)
        REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
