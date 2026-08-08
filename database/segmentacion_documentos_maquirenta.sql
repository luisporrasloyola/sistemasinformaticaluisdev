ALTER TABLE empresa_maquirenta_documentos_catalogo
    ADD COLUMN IF NOT EXISTS tipo_segmentacion VARCHAR(20) NOT NULL DEFAULT 'ninguna' AFTER nombre;

UPDATE empresa_maquirenta_documentos_catalogo SET tipo_segmentacion = 'numero' WHERE LOWER(nombre) = 'informe';
UPDATE empresa_maquirenta_documentos_catalogo SET tipo_segmentacion = 'mes' WHERE LOWER(nombre) = 'boletas';
UPDATE empresa_maquirenta_documentos_catalogo SET tipo_segmentacion = 'codigo' WHERE LOWER(nombre) = 'permisos de trabajo';

ALTER TABLE empresa_maquirenta_documentos
    ADD COLUMN IF NOT EXISTS segmento_clave VARCHAR(80) NOT NULL DEFAULT '' AFTER documento_id,
    ADD COLUMN IF NOT EXISTS segmento_etiqueta VARCHAR(120) DEFAULT NULL AFTER segmento_clave,
    ADD COLUMN IF NOT EXISTS periodo_anio SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER segmento_etiqueta;

UPDATE empresa_maquirenta_documentos d
JOIN empresa_maquirenta_documentos_catalogo c ON c.id = d.documento_id
SET d.segmento_clave = CASE c.tipo_segmentacion
        WHEN 'numero' THEN '1'
        WHEN 'mes' THEN DATE_FORMAT(d.fecha_inicio, '%m')
        WHEN 'codigo' THEN 'GENERAL'
        ELSE ''
    END,
    d.segmento_etiqueta = CASE c.tipo_segmentacion
        WHEN 'numero' THEN '1'
        WHEN 'mes' THEN ELT(MONTH(d.fecha_inicio), 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre')
        WHEN 'codigo' THEN 'General'
        ELSE NULL
    END,
    d.periodo_anio = CASE WHEN c.tipo_segmentacion = 'mes' THEN YEAR(d.fecha_inicio) ELSE 0 END
WHERE d.segmento_clave = '';

ALTER TABLE empresa_maquirenta_documentos
    DROP INDEX IF EXISTS uq_empresa_maquirenta_documento,
    ADD UNIQUE INDEX IF NOT EXISTS uq_empresa_maquirenta_documento_segmento
        (empresa_maquirenta_id, documento_id, segmento_clave, periodo_anio);
