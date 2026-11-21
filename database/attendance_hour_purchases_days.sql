-- Ejecutar una sola vez si ya existe attendance_hour_purchases.
ALTER TABLE attendance_hour_purchases
    ADD COLUMN purchase_unit ENUM('hour', 'day') NOT NULL DEFAULT 'hour' AFTER purchase_date,
    ADD COLUMN quantity_decimal DECIMAL(8,2) NULL AFTER purchase_unit;

UPDATE attendance_hour_purchases
SET quantity_decimal = hours_decimal
WHERE quantity_decimal IS NULL;

ALTER TABLE attendance_hour_purchases
    MODIFY COLUMN quantity_decimal DECIMAL(8,2) NOT NULL;
