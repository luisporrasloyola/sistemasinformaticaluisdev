<?php
$habilitations = [];
$habilitationSearch = trim((string) ($_GET['habilitacion_buscar'] ?? ''));
$habilitationSearch = function_exists('mb_substr') ? mb_substr($habilitationSearch, 0, 100) : substr($habilitationSearch, 0, 100);
$habilitationPage = max(1, (int) ($_GET['habilitacion_pagina'] ?? 1));
$habilitationArea = ($_GET['habilitacion_area'] ?? 'personal') === 'equipo' ? 'equipo' : 'personal';
$habilitationPageSize = 5;
$habilitationTotal = 0;
$habilitationPages = 1;
$habilitationCards = [];
if ($clientId) {
    $habilitationFrom = " FROM client_pmi_habilitations h
        LEFT JOIN workers w ON h.area = 'personal' AND w.id = h.subject_id
        LEFT JOIN maquinarias m ON h.area = 'equipo' AND m.id = h.subject_id
        LEFT JOIN users u ON u.id = h.registered_by_user_id
        WHERE h.client_id = :client_id AND h.project_id = :project_id AND h.area = :area";
    $habilitationParams = ['client_id' => $clientId, 'project_id' => $projectId, 'area' => $habilitationArea];
    $stmt = db()->prepare("SELECT h.*, COALESCE(w.full_name, m.equipo, 'Registro eliminado') AS subject_name,
        COALESCE(w.document_number, m.serie_placa, '') AS subject_detail, COALESCE(u.name, '-') AS registered_by
        " . $habilitationFrom . "
        ORDER BY FIELD(h.area, 'personal', 'equipo'), subject_name, h.id");
    $stmt->execute($habilitationParams);
    $allHabilitations = $stmt->fetchAll();
    $allCards = [];
    foreach ($allHabilitations as $row) {
        $title = trim((string) ($row['group_title'] ?? ''));
        $isGroup = $row['area'] === 'personal' && $row['habilitation_type'] === 'grupal' && $title !== '';
        $key = $isGroup ? 'group:' . mb_strtolower($title) : 'record:' . $row['id'];
        if (!isset($allCards[$key])) $allCards[$key] = ['is_group' => $isGroup, 'title' => $title, 'members' => []];
        $allCards[$key]['members'][] = $row;
    }
    if ($habilitationSearch !== '') {
        $needle = mb_strtolower($habilitationSearch);
        $allCards = array_filter($allCards, static function (array $card) use ($needle): bool {
            if (mb_strpos(mb_strtolower($card['title']), $needle) !== false) return true;
            foreach ($card['members'] as $member) {
                if (mb_strpos(mb_strtolower($member['subject_name'] . ' ' . $member['subject_detail']), $needle) !== false) return true;
            }
            return false;
        });
    }
    $habilitationTotal = count($allCards);
    $habilitationPages = max(1, (int) ceil($habilitationTotal / $habilitationPageSize));
    $habilitationPage = min($habilitationPage, $habilitationPages);
    $selectedCards = array_slice(array_values($allCards), ($habilitationPage - 1) * $habilitationPageSize, $habilitationPageSize);
    foreach ($selectedCards as $card) foreach ($card['members'] as $member) $habilitations[] = $member;
    $workerPositions = db()->prepare('SELECT p.id, p.name FROM client_pmi_habilitation_positions hp JOIN worker_positions wp ON wp.worker_id = ? AND wp.position_id = hp.position_id JOIN client_pmi_general_positions gp ON gp.client_id = ? AND gp.project_id = ? AND gp.position_id = hp.position_id JOIN positions p ON p.id = hp.position_id WHERE hp.habilitation_id = ? ORDER BY p.name, p.id');
    // Los requisitos se definen por cliente y puesto; el último registro del trabajador aporta sus datos.
    $workerDocs = db()->prepare("SELECT wr.id, pr.position_id, rc.name, rc.id AS catalog_id,
        wr.registration_date, wr.start_date, wr.end_date, wr.file_path, COALESCE(u.name, '-') AS registered_by
        FROM client_pmi_general_position_requirements pr
        JOIN requirements_catalog rc ON rc.id = pr.requirement_id
        LEFT JOIN worker_requirements wr ON wr.id = (
            SELECT latest.id FROM worker_requirements latest
            WHERE latest.worker_id = :subject_id AND latest.position_id = pr.position_id AND latest.requirement_id = pr.requirement_id
            ORDER BY latest.registration_date DESC, latest.id DESC LIMIT 1
        )
        LEFT JOIN users u ON u.id = wr.registered_by_user_id
        WHERE pr.client_id = :client_id AND pr.project_id = :project_id AND pr.position_id = :position_id ORDER BY rc.name");
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
        WHERE gi.client_id = :client_id AND gi.project_id = :project_id AND gi.category = 'equipos'
        ORDER BY dc.nombre");
    foreach ($habilitations as &$habilitation) {
        if ($habilitation['area'] === 'personal') {
            $workerPositions->execute([$habilitation['subject_id'], $clientId, $projectId, $habilitation['id']]);
            $habilitation['positions'] = $workerPositions->fetchAll();
            $habilitation['documents_by_position'] = [];
            foreach ($habilitation['positions'] as $position) {
                $workerDocs->execute(['client_id' => $clientId, 'project_id' => $projectId, 'subject_id' => $habilitation['subject_id'], 'position_id' => $position['id']]);
                $habilitation['documents_by_position'][(int) $position['id']] = $workerDocs->fetchAll();
            }
            $habilitation['progress_by_position'] = [];
            foreach ($habilitation['documents_by_position'] as $positionId => $documents) {
                $total = count($documents);
                $complete = 0;
                foreach ($documents as $document) {
                    if (!empty($document['end_date'])) { ++$complete; }
                }
                $habilitation['progress_by_position'][(int) $positionId] = [
                    'total' => $total,
                    'complete' => $complete,
                    'percent' => $total ? (int) round($complete * 100 / $total) : 0,
                ];
            }
            $habilitation['documents'] = $habilitation['positions'] ? $habilitation['documents_by_position'][(int) $habilitation['positions'][0]['id']] : [];
        } else {
            $machineDocs->execute(['client_id' => $clientId, 'project_id' => $projectId, 'subject_id' => $habilitation['subject_id']]);
            $habilitation['documents'] = $machineDocs->fetchAll();
        }
    }
    unset($habilitation);
    $enrichedById = [];
    foreach ($habilitations as $habilitation) $enrichedById[(int) $habilitation['id']] = $habilitation;
    foreach ($selectedCards as $card) {
        $card['members'] = array_map(static fn(array $member): array => $enrichedById[(int) $member['id']], $card['members']);
        $habilitationCards[] = $card;
    }

    $personalFormats = [];
    $personalFormatRecords = [];
    $personalHabilitationIds = array_values(array_map('intval', array_column(array_filter($habilitations,
        static fn(array $h): bool => $h['area'] === 'personal'), 'id')));
    if ($personalHabilitationIds) {
        $formatNames = db()->prepare('SELECT DISTINCT c.id, c.name FROM client_pmi_format_documents d
            JOIN client_pmi_format_catalog c ON c.id = d.catalog_id AND c.client_id = d.client_id AND c.project_id = d.project_id
            WHERE d.client_id = :client_id AND d.project_id = :project_id ORDER BY c.name, c.id');
        $formatNames->execute(['client_id' => $clientId, 'project_id' => $projectId]);
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
    // El avance corresponde a todas las filas mostradas: requisitos del puesto y formatos.
    foreach ($habilitationCards as &$card) {
        foreach ($card['members'] as &$member) {
            if ($member['area'] !== 'personal') continue;
            $formatTotal = count($personalFormats);
            $formatComplete = 0;
            foreach ($personalFormats as $format) {
                $savedFormat = $personalFormatRecords[(int) $member['id']][(int) $format['id']] ?? null;
                if (!empty($savedFormat['end_date'])) ++$formatComplete;
            }
            if (!$member['progress_by_position']) {
                $member['progress_by_position'][0] = ['total' => 0, 'complete' => 0, 'percent' => 0];
            }
            foreach ($member['progress_by_position'] as &$progress) {
                $progress['total'] += $formatTotal;
                $progress['complete'] += $formatComplete;
                $progress['percent'] = $progress['total'] ? (int) round($progress['complete'] * 100 / $progress['total']) : 0;
            }
            unset($progress);
        }
        unset($member);
    }
    unset($card);
}
