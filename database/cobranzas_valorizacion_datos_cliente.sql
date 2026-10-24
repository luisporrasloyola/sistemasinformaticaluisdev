-- Datos de cabecera de Cobranzas > Valorización por cliente.
CREATE TABLE IF NOT EXISTS cobranzas_valorizacion_datos_cliente (
    client_id INT UNSIGNED NOT NULL,
    lugar_servicio VARCHAR(200) NOT NULL DEFAULT '',
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id),
    CONSTRAINT fk_cobranzas_valorizacion_datos_cliente FOREIGN KEY (client_id)
        REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
