CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(160) NOT NULL UNIQUE,
    role VARCHAR(60) NOT NULL DEFAULT 'Administrador',
    worker_id INT UNSIGNED NULL,
    password VARCHAR(255) NOT NULL,
    status TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_users_worker_id (worker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE companies (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL UNIQUE,
    status TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE positions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(160) NOT NULL UNIQUE,
    status TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE requirements_catalog (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL UNIQUE,
    status TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE position_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    position_id INT UNSIGNED NOT NULL,
    requirement_id INT UNSIGNED NOT NULL,
    UNIQUE KEY uq_position_requirement (position_id, requirement_id),
    CONSTRAINT fk_pr_position FOREIGN KEY (position_id) REFERENCES positions(id),
    CONSTRAINT fk_pr_requirement FOREIGN KEY (requirement_id) REFERENCES requirements_catalog(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE workers (
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
    status TINYINT(1) NOT NULL DEFAULT 1,
    photo_path VARCHAR(255) NULL,
    signature_path VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_workers_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE worker_positions (
    worker_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (worker_id, position_id),
    CONSTRAINT fk_wp_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_wp_position FOREIGN KEY (position_id) REFERENCES positions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE users
    ADD CONSTRAINT fk_users_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL;

CREATE TABLE worker_requirements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    worker_id INT UNSIGNED NOT NULL,
    position_id INT UNSIGNED NOT NULL,
    requirement_id INT UNSIGNED NOT NULL,
    registration_date DATE NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    observations TEXT NULL,
    file_path VARCHAR(255) NULL,
    original_file_name VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_worker_position_requirement (worker_id, position_id, requirement_id),
    CONSTRAINT fk_wr_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE CASCADE,
    CONSTRAINT fk_wr_position FOREIGN KEY (position_id) REFERENCES positions(id),
    CONSTRAINT fk_wr_requirement FOREIGN KEY (requirement_id) REFERENCES requirements_catalog(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO users (name, email, role, password) VALUES
('Administrador', 'admin@lifemaquinarias.local', 'Administrador', '$2y$10$2WG1Jkz9q975zQP/qOIdfuQ8Ipgodpz66S7qilEu8YB1ahyTltvLC');
-- Acceso inicial: admin@lifemaquinarias.local / Admin123*

INSERT INTO companies (name) VALUES ('Life Maquinarias'), ('Aliado Estratégico');

INSERT INTO positions (name) VALUES
('Operador de Grúa'),
('Rigger'),
('Operador de Camión Grúa'),
('Operador de Man Lift'),
('Operador de Tijeral'),
('Operador de Montacargas');

INSERT INTO requirements_catalog (name) VALUES
('SCTR'),
('VIDA LEY'),
('CERTIADULTO'),
('DNI'),
('CAMO'),
('EMO'),
('Licencia de conducir'),
('Record de conducir (operadores y conductor)'),
('Certificado de operador de camión'),
('Certificado de manejo defensivo'),
('Certificado de rigger'),
('Trabajos en altura'),
('Certificado de montacarguista'),
('Certificado de operador de montacargas'),
('Certificado de operador de tijeral');

INSERT INTO position_requirements (position_id, requirement_id)
SELECT p.id, r.id
FROM positions p
JOIN requirements_catalog r ON r.name IN ('SCTR','VIDA LEY','DNI','EMO','CERTIADULTO');

INSERT INTO position_requirements (position_id, requirement_id)
SELECT p.id, r.id FROM positions p JOIN requirements_catalog r
WHERE (p.name = 'Operador de Grúa' AND r.name IN ('CAMO','Licencia de conducir','Record de conducir (operadores y conductor)','Certificado de manejo defensivo','Trabajos en altura'))
   OR (p.name = 'Rigger' AND r.name IN ('Certificado de rigger','Trabajos en altura'))
   OR (p.name = 'Operador de Camión Grúa' AND r.name IN ('Licencia de conducir','Record de conducir (operadores y conductor)','Certificado de operador de camión','Certificado de manejo defensivo'))
   OR (p.name = 'Operador de Man Lift' AND r.name IN ('Certificado de manejo defensivo','Trabajos en altura'))
   OR (p.name = 'Operador de Tijeral' AND r.name IN ('Certificado de operador de tijeral','Trabajos en altura'))
   OR (p.name = 'Operador de Montacargas' AND r.name IN ('Certificado de montacarguista','Certificado de operador de montacargas','Certificado de manejo defensivo'));

CREATE TABLE maquinarias (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id INT UNSIGNED NULL,
    equipo VARCHAR(150) NOT NULL,
    serie_placa VARCHAR(80) NOT NULL,
    anio_equipo YEAR NOT NULL,
    foto_path VARCHAR(255) NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_maquinaria_serie_placa (serie_placa),
    CONSTRAINT fk_maquinarias_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE maquinaria_documentos_catalogo (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(150) NOT NULL,
    estado TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_maquinaria_documento_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE maquinaria_documentos (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    maquinaria_id INT UNSIGNED NOT NULL,
    documento_id INT UNSIGNED NOT NULL,
    fecha_registro DATE NOT NULL,
    fecha_inicio DATE NOT NULL,
    fecha_fin DATE NOT NULL,
    observaciones TEXT NULL,
    archivo_path VARCHAR(255) NULL,
    archivo_nombre_original VARCHAR(255) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_maquinaria_documento (maquinaria_id, documento_id),
    CONSTRAINT fk_maquinaria_documentos_maquinaria FOREIGN KEY (maquinaria_id) REFERENCES maquinarias(id) ON DELETE CASCADE,
    CONSTRAINT fk_maquinaria_documentos_catalogo FOREIGN KEY (documento_id) REFERENCES maquinaria_documentos_catalogo(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO maquinaria_documentos_catalogo (nombre) VALUES
('Tarjeta de propiedad'),
('SOAT'),
('Revisiones técnicas'),
('Permiso MTC'),
('Seguro RC'),
('Seguro TREC'),
('Certificado de operatividad'),
('Plan de mantenimiento'),
('Informe de mantenimiento');


CREATE TABLE attendance_control (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fecha DATE NOT NULL,
    nombre_apellido VARCHAR(180) NOT NULL,
    lugar_actividad TEXT NOT NULL,
    empresa_proyecto VARCHAR(180) NULL,
    puesto VARCHAR(160) NULL,
    record_hash CHAR(64) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_attendance_record (record_hash),
    KEY idx_attendance_fecha (fecha),
    KEY idx_attendance_nombre (nombre_apellido),
    KEY idx_attendance_empresa (empresa_proyecto),
    KEY idx_attendance_puesto (puesto)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(80) NOT NULL DEFAULT 'worker_created',
    title VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    worker_id INT UNSIGNED NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    KEY fk_notif_worker (worker_id),
    CONSTRAINT fk_notif_worker FOREIGN KEY (worker_id) REFERENCES workers(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
