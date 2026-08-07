-- Reparación puntual de la jornada de Willian León Romani
-- del 03/08/2026 en la base de datos de producción.
--
-- Conserva la entrada original de las 09:42 (marca 108), vincula a esa
-- jornada la salida real de las 17:38 (marca 114) y elimina únicamente
-- la segunda entrada accidental de las 14:10 (marca 110).
-- Ejecutar una sola vez.

START TRANSACTION;

UPDATE attendance_marks salida
JOIN attendance_marks entrada_original
  ON entrada_original.id = 108
 AND entrada_original.worker_id = 37
 AND entrada_original.mark_date = '2026-08-03'
 AND entrada_original.mark_type = 'entrada'
SET salida.assignment_id = entrada_original.assignment_id,
    salida.program_id = entrada_original.program_id,
    salida.schedule_id = entrada_original.schedule_id
WHERE salida.id = 114
  AND salida.worker_id = 37
  AND salida.mark_date = '2026-08-03'
  AND salida.mark_type = 'salida';

DELETE FROM attendance_marks
WHERE id = 110
  AND worker_id = 37
  AND assignment_id = 117
  AND mark_date = '2026-08-03'
  AND mark_time = '14:10:16'
  AND mark_type = 'entrada';

COMMIT;
