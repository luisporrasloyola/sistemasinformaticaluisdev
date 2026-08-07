-- Reparación puntual de la jornada de Nelson Humberto Aular Blanco
-- del 03/08/2026 en la base de datos de producción.
--
-- Conserva la entrada original de las 09:45 (marca 109), vincula a esa
-- jornada la salida real de las 18:02 (marca 120) y elimina únicamente
-- la segunda entrada accidental de las 17:56 (marca 119).
-- Ejecutar una sola vez.

START TRANSACTION;

UPDATE attendance_marks salida
JOIN attendance_marks entrada_original
  ON entrada_original.id = 109
 AND entrada_original.worker_id = 3
 AND entrada_original.mark_date = '2026-08-03'
 AND entrada_original.mark_type = 'entrada'
SET salida.assignment_id = entrada_original.assignment_id,
    salida.program_id = entrada_original.program_id,
    salida.schedule_id = entrada_original.schedule_id
WHERE salida.id = 120
  AND salida.worker_id = 3
  AND salida.mark_date = '2026-08-03'
  AND salida.mark_type = 'salida';

DELETE FROM attendance_marks
WHERE id = 119
  AND worker_id = 3
  AND assignment_id = 121
  AND mark_date = '2026-08-03'
  AND mark_time = '17:56:42'
  AND mark_type = 'entrada';

COMMIT;
