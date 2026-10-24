-- Registros independientes de Cobranzas > Valorización.
-- Ejecutar una vez en MySQL/MariaDB antes de utilizar el módulo.
CREATE TABLE IF NOT EXISTS cobranzas_valorizacion_registros (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    equipo VARCHAR(120) NOT NULL,
    placa_serie VARCHAR(80) NOT NULL,
    fecha_servicio DATE NOT NULL,
    hora_inicio TIME NOT NULL,
    hora_final TIME NOT NULL,
    horas_total_minutos SMALLINT UNSIGNED NOT NULL,
    observaciones VARCHAR(2000) DEFAULT NULL,
    archivo_path VARCHAR(255) NOT NULL,
    archivo_nombre_original VARCHAR(255) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_cobranzas_valorizacion_cliente_fecha (client_id, fecha_servicio, id),
    CONSTRAINT fk_cobranzas_valorizacion_cliente FOREIGN KEY (client_id)
        REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
