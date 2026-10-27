-- Ejecutar una sola vez tras pmi_clientes_habilitaciones_vigencia_opcional.sql.
-- Conserva las fechas y marca como vigentes las habilitaciones de Personal anteriores.
UPDATE client_pmi_habilitations
SET has_validity = 1
WHERE area = 'personal'
  AND has_validity IS NULL
  AND registration_date IS NOT NULL
  AND start_date IS NOT NULL
  AND end_date IS NOT NULL;
