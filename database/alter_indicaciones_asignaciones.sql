-- Indicaciones operativas opcionales asociadas a una asignacion.
ALTER TABLE attendance_assignments
    ADD COLUMN IF NOT EXISTS instructions VARCHAR(500) NULL AFTER activity;
