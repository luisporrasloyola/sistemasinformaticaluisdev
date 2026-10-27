-- Puestos seleccionados para cada cliente en Documentos generales > Personal.
-- No modifica los requisitos de personal ya registrados.
CREATE TABLE IF NOT EXISTS client_pmi_general_positions (
    client_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, position_id),
    CONSTRAINT fk_client_pmi_general_position_client FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_general_position_position FOREIGN KEY (position_id) REFERENCES positions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
