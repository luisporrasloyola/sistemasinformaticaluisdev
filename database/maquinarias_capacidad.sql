-- Ejecutar una vez para agregar la capacidad en Datos generales de maquinaria.
ALTER TABLE maquinarias ADD COLUMN capacidad VARCHAR(100) NOT NULL DEFAULT '' AFTER equipo;
