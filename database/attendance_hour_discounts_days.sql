-- Ejecutar una vez después de attendance_hour_discounts.sql.
-- Permite registrar descuentos por horas o por días (1 día = 8 h 30 min).
ALTER TABLE attendance_hour_discounts
    ADD COLUMN discount_unit ENUM('hour', 'day') NOT NULL DEFAULT 'hour' AFTER discount_date,
    ADD COLUMN quantity_decimal DECIMAL(8,2) NULL AFTER discount_unit;

UPDATE attendance_hour_discounts
SET quantity_decimal = hours_decimal
WHERE quantity_decimal IS NULL;

ALTER TABLE attendance_hour_discounts
    MODIFY COLUMN quantity_decimal DECIMAL(8,2) NOT NULL;
