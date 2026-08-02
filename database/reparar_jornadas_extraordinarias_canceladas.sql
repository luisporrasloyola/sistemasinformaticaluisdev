-- Reparación de jornadas extraordinarias canceladas automáticamente al
-- desactivar su asignación habitual. Ejecutar una sola vez en lifemaquinarias.
-- No modifica jornadas con marcaciones ni desplazamientos registrados.

UPDATE attendance_programs ap
JOIN attendance_assignments aa ON aa.id = ap.assignment_id
SET ap.status = 'programada'
WHERE ap.schedule_source = 'extraordinary'
  AND ap.status = 'cancelada'
  AND aa.status = 0
  AND ap.program_date >= CURDATE()
  AND NOT EXISTS (
      SELECT 1 FROM attendance_marks am WHERE am.program_id = ap.id
  )
  AND NOT EXISTS (
      SELECT 1 FROM attendance_trips atp WHERE atp.program_id = ap.id
  );
