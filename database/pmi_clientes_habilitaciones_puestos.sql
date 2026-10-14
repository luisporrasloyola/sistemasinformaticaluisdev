-- Puestos visibles por habilitación de personal. Los registros existentes conservan sus puestos actuales.
CREATE TABLE IF NOT EXISTS client_pmi_habilitation_positions (
    habilitation_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (habilitation_id, position_id),
    KEY idx_client_pmi_hab_position (position_id),
    CONSTRAINT fk_client_pmi_hab_position_hab FOREIGN KEY (habilitation_id) REFERENCES client_pmi_habilitations(id) ON DELETE CASCADE,
    CONSTRAINT fk_client_pmi_hab_position_position FOREIGN KEY (position_id) REFERENCES positions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO client_pmi_habilitation_positions (habilitation_id, position_id)
SELECT h.id, wp.position_id
FROM client_pmi_habilitations h
JOIN worker_positions wp ON wp.worker_id = h.subject_id
WHERE h.area = 'personal'
  AND NOT EXISTS (SELECT 1 FROM client_pmi_habilitation_positions hp WHERE hp.habilitation_id = h.id);
