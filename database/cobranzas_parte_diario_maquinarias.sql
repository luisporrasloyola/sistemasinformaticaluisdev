-- Catálogo de maquinaria propio de Cobranzas > Parte diario, independiente por cliente.
CREATE TABLE IF NOT EXISTS cobranzas_parte_diario_maquinarias (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    client_id INT UNSIGNED NOT NULL,
    nombre VARCHAR(120) NOT NULL,
    creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cobranzas_maquinaria_cliente_nombre (client_id, nombre),
    CONSTRAINT fk_cobranzas_maquinaria_cliente FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
