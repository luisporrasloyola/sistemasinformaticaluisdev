ALTER TABLE users
    ADD COLUMN worker_id INT UNSIGNED NULL AFTER role,
    ADD CONSTRAINT fk_users_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL;

CREATE UNIQUE INDEX uq_users_worker_id ON users (worker_id);

