-- Personal y PMI Individual independientes para Empresa Maquirenta > Formatos.
CREATE TABLE IF NOT EXISTS empresa_maquirenta_formato_empresas (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL UNIQUE,
 status TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_formato_puestos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL UNIQUE,
 status TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_formato_personal (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 company_id INT UNSIGNED NULL,
 full_name VARCHAR(180) NOT NULL,
 document_type ENUM('DNI','Carnet de Extranjería','Pasaporte') NOT NULL,
 document_number VARCHAR(30) NOT NULL UNIQUE,
 blood_type VARCHAR(15) NULL,
 address VARCHAR(220) NULL,
 phone VARCHAR(40) NULL,
 email VARCHAR(160) NULL,
 birth_date DATE NULL,
 personal_observations TEXT NULL,
 status TINYINT(1) NOT NULL DEFAULT 1,
 photo_path VARCHAR(255) NULL,
 signature_path VARCHAR(255) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_emfp_company FOREIGN KEY (company_id) REFERENCES empresa_maquirenta_formato_empresas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_formato_personal_puestos (
 worker_id INT UNSIGNED NOT NULL,
 position_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (worker_id, position_id),
 CONSTRAINT fk_emfpp_worker FOREIGN KEY (worker_id) REFERENCES empresa_maquirenta_formato_personal(id) ON DELETE CASCADE,
 CONSTRAINT fk_emfpp_position FOREIGN KEY (position_id) REFERENCES empresa_maquirenta_formato_puestos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_formato_requisitos_catalogo (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(180) NOT NULL UNIQUE,
 status TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_formato_puesto_requisitos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 position_id INT UNSIGNED NOT NULL,
 requirement_id INT UNSIGNED NOT NULL,
 UNIQUE KEY uq_emfpr (position_id, requirement_id),
 CONSTRAINT fk_emfpr_position FOREIGN KEY (position_id) REFERENCES empresa_maquirenta_formato_puestos(id),
 CONSTRAINT fk_emfpr_requirement FOREIGN KEY (requirement_id) REFERENCES empresa_maquirenta_formato_requisitos_catalogo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_formato_requisitos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 worker_id INT UNSIGNED NOT NULL,
 position_id INT UNSIGNED NOT NULL,
 requirement_id INT UNSIGNED NOT NULL,
 registration_date DATE NOT NULL,
 start_date DATE NOT NULL,
 end_date DATE NOT NULL,
 observations TEXT NULL,
 observation_status VARCHAR(20) NOT NULL DEFAULT 'none',
 observation_by_user_id INT UNSIGNED NULL,
 observation_at DATETIME NULL,
 observation_resolved_by_user_id INT UNSIGNED NULL,
 observation_resolved_at DATETIME NULL,
 file_path VARCHAR(255) NULL,
 original_file_name VARCHAR(255) NULL,
 registered_by_user_id INT UNSIGNED NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_emfr_worker_position_requirement (worker_id, position_id, requirement_id),
 KEY idx_emfr_registered_by (registered_by_user_id),
 KEY idx_emfr_observation_status (observation_status),
 CONSTRAINT fk_emfr_worker FOREIGN KEY (worker_id) REFERENCES empresa_maquirenta_formato_personal(id) ON DELETE CASCADE,
 CONSTRAINT fk_emfr_position FOREIGN KEY (position_id) REFERENCES empresa_maquirenta_formato_puestos(id),
 CONSTRAINT fk_emfr_requirement FOREIGN KEY (requirement_id) REFERENCES empresa_maquirenta_formato_requisitos_catalogo(id),
 CONSTRAINT fk_emfr_registered_by FOREIGN KEY (registered_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_emfr_observation_by FOREIGN KEY (observation_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_emfr_observation_resolved_by FOREIGN KEY (observation_resolved_by_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS empresa_maquirenta_formato_requisito_actividad (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 worker_requirement_id INT UNSIGNED NOT NULL,
 user_id INT UNSIGNED NULL,
 action_type VARCHAR(40) NOT NULL,
 description TEXT NOT NULL,
 created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 KEY idx_emfra_requirement (worker_requirement_id),
 KEY idx_emfra_user (user_id),
 CONSTRAINT fk_emfra_requirement FOREIGN KEY (worker_requirement_id) REFERENCES empresa_maquirenta_formato_requisitos(id) ON DELETE CASCADE,
 CONSTRAINT fk_emfra_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
