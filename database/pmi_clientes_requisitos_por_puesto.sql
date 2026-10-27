-- Requisitos generales seleccionados para cada puesto de un cliente.
CREATE TABLE IF NOT EXISTS client_pmi_general_position_requirements (
    client_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    requirement_id INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (client_id, position_id, requirement_id),
    CONSTRAINT fk_client_pmi_position_requirements_position FOREIGN KEY (client_id, position_id)
        REFERENCES client_pmi_general_positions(client_id, position_id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_position_requirements_requirement FOREIGN KEY (requirement_id)
        REFERENCES requirements_catalog(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
