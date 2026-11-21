CREATE TABLE IF NOT EXISTS attendance_hour_purchases (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    worker_id INT UNSIGNED NOT NULL,
    purchase_date DATE NOT NULL,
    purchase_unit ENUM('hour', 'day') NOT NULL DEFAULT 'hour',
    quantity_decimal DECIMAL(8,2) NOT NULL,
    hours_decimal DECIMAL(8,2) NOT NULL,
    hourly_rate DECIMAL(10,2) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    purchased_minutes INT UNSIGNED NOT NULL,
    registered_by_user_id INT UNSIGNED DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_attendance_hour_purchases_worker_date (worker_id, purchase_date),
    CONSTRAINT fk_attendance_hour_purchases_worker FOREIGN KEY (worker_id) REFERENCES workers (id) ON DELETE CASCADE,
    CONSTRAINT fk_attendance_hour_purchases_user FOREIGN KEY (registered_by_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
