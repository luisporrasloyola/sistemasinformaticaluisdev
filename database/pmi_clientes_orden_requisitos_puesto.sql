-- Ejecutar una vez, después de pmi_clientes_requisitos_por_puesto.sql y pmi_clientes_por_proyecto.sql.
ALTER TABLE client_pmi_general_position_requirements
    ADD COLUMN sort_order INT UNSIGNED NOT NULL DEFAULT 0 AFTER requirement_id;
