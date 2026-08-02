-- Las jornadas extraordinarias guardan Actividad e Indicaciones directamente
-- en attendance_programs. Se eliminan personalizaciones paralelas antiguas
-- para que todos los modulos consulten una unica fuente de informacion.
DELETE ajo
FROM attendance_journey_overrides AS ajo
INNER JOIN attendance_programs AS ap
    ON ap.assignment_id = ajo.assignment_id
   AND ap.program_date = ajo.journey_date;

-- Versiones anteriores guardaban el contenido visible de "Indicaciones" como
-- puntos previstos. Se recupera ese texto en el campo definitivo sin reemplazar
-- indicaciones que ya hayan sido guardadas correctamente.
UPDATE attendance_programs AS ap
INNER JOIN (
    SELECT program_id,
           GROUP_CONCAT(destination ORDER BY stop_order SEPARATOR '\n') AS indications
    FROM attendance_program_stops
    GROUP BY program_id
) AS legacy ON legacy.program_id = ap.id
SET ap.notes = legacy.indications
WHERE ap.notes IS NULL OR TRIM(ap.notes) = '';
