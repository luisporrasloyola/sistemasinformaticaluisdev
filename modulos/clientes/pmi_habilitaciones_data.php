<?php
$habilitations = [];
$habilitationSearch = trim((string) ($_GET['habilitacion_buscar'] ?? ''));
$habilitationSearch = function_exists('mb_substr') ? mb_substr($habilitationSearch, 0, 100) : substr($habilitationSearch, 0, 100);
$habilitationPage = max(1, (int) ($_GET['habilitacion_pagina'] ?? 1));
$habilitationPageSize = 5;
$habilitationTotal = 0;
$habilitationPages = 1;
if ($clientId) {
    $habilitationFrom = " FROM client_pmi_habilitations h
        LEFT JOIN workers w ON h.area = 'personal' AND w.id = h.subject_id
        LEFT JOIN maquinarias m ON h.area = 'equipo' AND m.id = h.subject_id
        LEFT JOIN users u ON u.id = h.registered_by_user_id
        WHERE h.client_id = :client_id";
    $habilitationParams = ['client_id' => $clientId];
    if ($habilitationSearch !== '') {
        $habilitationFrom .= " AND (w.full_name LIKE :worker_name OR w.document_number LIKE :worker_detail OR m.equipo LIKE :machine_name OR m.serie_placa LIKE :machine_detail)";
        $term = '%' . $habilitationSearch . '%';
        $habilitationParams += ['worker_name' => $term, 'worker_detail' => $term, 'machine_name' => $term, 'machine_detail' => $term];
    }
    $count = db()->prepare('SELECT COUNT(*)' . $habilitationFrom);
    $count->execute($habilitationParams);
    $habilitationTotal = (int) $count->fetchColumn();
    $habilitationPages = max(1, (int) ceil($habilitationTotal / $habilitationPageSize));
    $habilitationPage = min($habilitationPage, $habilitationPages);
    $stmt = db()->prepare("SELECT h.*, COALESCE(w.full_name, m.equipo, 'Registro eliminado') AS subject_name,
        COALESCE(w.document_number, m.serie_placa, '') AS subject_detail, COALESCE(u.name, '-') AS registered_by
        " . $habilitationFrom . "
        ORDER BY FIELD(h.area, 'personal', 'equipo'), subject_name, h.id LIMIT " . $habilitationPageSize . " OFFSET " . (($habilitationPage - 1) * $habilitationPageSize));
    $stmt->execute($habilitationParams);
    $habilitations = $stmt->fetchAll();
    $workerPositions = db()->prepare('SELECT p.id, p.name FROM client_pmi_habilitation_positions hp JOIN worker_positions wp ON wp.worker_id = ? AND wp.position_id = hp.position_id JOIN positions p ON p.id = hp.position_id WHERE hp.habilitation_id = ? ORDER BY p.name, p.id');
    // El catálogo del cliente define las filas; el registro más reciente aporta sus datos.
    $workerDocs = db()->prepare("SELECT wr.id, wr.position_id, rc.name, rc.id AS catalog_id,
        wr.registration_date, wr.start_date, wr.end_date, wr.file_path, COALESCE(u.name, '-') AS registered_by
        FROM client_pmi_general_items gi
        JOIN requirements_catalog rc ON rc.id = gi.source_id
        LEFT JOIN worker_requirements wr ON wr.id = (
            SELECT latest.id FROM worker_requirements latest
            WHERE latest.worker_id = :subject_id AND latest.position_id = :position_id AND latest.requirement_id = gi.source_id
            ORDER BY latest.registration_date DESC, latest.id DESC LIMIT 1
        )
        LEFT JOIN users u ON u.id = wr.registered_by_user_id
        WHERE gi.client_id = :client_id AND gi.category = 'personal' ORDER BY rc.name");
    $machineDocs = db()->prepare("SELECT md.id, dc.nombre AS name, dc.id AS catalog_id,
        md.fecha_registro AS registration_date, md.fecha_inicio AS start_date, md.fecha_fin AS end_date,
        md.archivo_path AS file_path, COALESCE(u.name, '-') AS registered_by
        FROM client_pmi_general_items gi JOIN maquinaria_documentos_catalogo dc ON dc.id = gi.source_id
        LEFT JOIN maquinaria_documentos md ON md.id = (
            SELECT latest.id FROM maquinaria_documentos latest
            WHERE latest.maquinaria_id = :subject_id AND latest.documento_id = gi.source_id
            ORDER BY latest.fecha_registro DESC, latest.id DESC LIMIT 1
        )
        LEFT JOIN users u ON u.id = md.registered_by_user_id
        WHERE gi.client_id = :client_id AND gi.category = 'equipos'
        ORDER BY dc.nombre");
    foreach ($habilitations as &$habilitation) {
        if ($habilitation['area'] === 'personal') {
            $workerPositions->execute([$habilitation['subject_id'], $habilitation['id']]);
            $habilitation['positions'] = $workerPositions->fetchAll();
            $habilitation['documents_by_position'] = [];
            foreach ($habilitation['positions'] as $position) {
                $workerDocs->execute(['client_id' => $clientId, 'subject_id' => $habilitation['subject_id'], 'position_id' => $position['id']]);
                $habilitation['documents_by_position'][(int) $position['id']] = $workerDocs->fetchAll();
            }
            $habilitation['documents'] = $habilitation['positions'] ? $habilitation['documents_by_position'][(int) $habilitation['positions'][0]['id']] : [];
        } else {
            $machineDocs->execute(['client_id' => $clientId, 'subject_id' => $habilitation['subject_id']]);
            $habilitation['documents'] = $machineDocs->fetchAll();
        }
    }
    unset($habilitation);

    $personalFormats = [];
    $personalFormatRecords = [];
    $personalHabilitationIds = array_values(array_map('intval', array_column(array_filter($habilitations,
        static fn(array $h): bool => $h['area'] === 'personal'), 'id')));
    if ($personalHabilitationIds) {
        $formatNames = db()->prepare('SELECT DISTINCT c.id, c.name FROM client_pmi_format_documents d
            JOIN client_pmi_format_catalog c ON c.id = d.catalog_id AND c.client_id = d.client_id
            WHERE d.client_id = :client_id ORDER BY c.name, c.id');
        $formatNames->execute(['client_id' => $clientId]);
        $personalFormats = $formatNames->fetchAll();
        if ($personalFormats) {
            $idsSql = implode(',', array_fill(0, count($personalHabilitationIds), '?'));
            $savedFormats = db()->prepare("SELECT f.*, COALESCE(u.name, '-') AS registered_by
                FROM client_pmi_habilitation_formats f LEFT JOIN users u ON u.id = f.registered_by_user_id
                WHERE f.habilitation_id IN ($idsSql)");
            $savedFormats->execute($personalHabilitationIds);
            foreach ($savedFormats->fetchAll() as $saved) {
                $personalFormatRecords[(int) $saved['habilitation_id']][(int) $saved['catalog_id']] = $saved;
            }
        }
    }
}
