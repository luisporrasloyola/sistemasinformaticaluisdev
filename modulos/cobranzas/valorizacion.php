<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/cobranzas_valorizacion_periodo.php';
require_module_access('cobranzas.valorizacion');

function valor_minutes(string $start, string $end): ?int
{
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start) || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $end)) return null;
    [$h1, $m1] = array_map('intval', explode(':', $start));
    [$h2, $m2] = array_map('intval', explode(':', $end));
    $minutes = ($h2 * 60 + $m2 - $h1 * 60 - $m1 + 1440) % 1440;
    // Una salida anterior a la llegada corresponde al día siguiente.
    // El refrigerio fijo de 60 minutos debe dejar una duración positiva.
    return $minutes > 60 ? $minutes - 60 : null;
}

function valor_equipo_completo(string $equipment, string $plate): string
{
    $equipment = trim($equipment);
    $plate = trim($plate);
    return $plate !== '' && !str_ends_with(mb_strtolower($equipment, 'UTF-8'), mb_strtolower($plate, 'UTF-8'))
        ? $equipment . ' ' . $plate : $equipment;
}

function valor_tipo_equipo(string $equipment): string
{
    $equipment = trim($equipment);
    return trim((string) preg_replace('/\s+[A-Z0-9]{1,8}-[A-Z0-9-]{2,}\s*$/iu', '', $equipment));
}

function valor_clave_equipo(string $equipment): string
{
    $name = mb_strtolower(valor_tipo_equipo($equipment), 'UTF-8');
    return strtr($name, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u']);
}

$clientId = filter_var($_GET['cliente_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$client = null;
$commercialContact = null;
$serviceLocation = '';
$records = [];
$totalMinutes = 0;
$firstServiceDate = null;
$lastServiceDate = null;
$totalPriceCents = 0;
$equipmentRates = [];
$error = '';
$defaultMonth = null;
if ($clientId && !isset($_GET['mes']) && ($_GET['periodo'] ?? 'mes') !== 'fechas') {
    $latestStmt = db()->prepare('SELECT MAX(fecha_servicio) FROM cobranzas_valorizacion_registros WHERE client_id = ?');
    $latestStmt->execute([$clientId]);
    $latestDate = $latestStmt->fetchColumn();
    if ($latestDate) $defaultMonth = substr((string) $latestDate, 0, 7);
}
try {
    $period = valorizacion_periodo($_GET, $defaultMonth);
} catch (InvalidArgumentException $exception) {
    $error = $exception->getMessage();
    $period = valorizacion_periodo([], $defaultMonth);
}
$periodWhere = ' AND fecha_servicio BETWEEN ? AND ?';
$periodParams = [$period['start'], $period['end']];
$page = max(1, min(10000, (int) ($_GET['pagina'] ?? 1)));
$hasMore = false;
if ($clientId) {
    $stmt = db()->prepare('SELECT id, business_name, ruc FROM clients WHERE id = ?');
    $stmt->execute([$clientId]);
    $client = $stmt->fetch();
    if (!$client) { http_response_code(404); exit('Cliente no encontrado.'); }
    $contactStmt = db()->prepare("SELECT name, phone FROM client_contacts WHERE client_id = ? AND LOWER(position) LIKE '%comercial%' ORDER BY sort_order, id LIMIT 1");
    $contactStmt->execute([$clientId]);
    $commercialContact = $contactStmt->fetch() ?: null;
    try {
        $locationStmt = db()->prepare('SELECT lugar_servicio FROM cobranzas_valorizacion_datos_cliente WHERE client_id = ?');
        $locationStmt->execute([$clientId]);
        $serviceLocation = (string) ($locationStmt->fetchColumn() ?: '');
    } catch (PDOException $exception) {
        error_log('Error al cargar lugar de servicio: ' . $exception->getMessage());
        $error = 'No se pudo cargar el lugar de servicio. Ejecute database/cobranzas_valorizacion_datos_cliente.sql.';
    }
    $periodQuery = ['cliente_id' => $clientId];
    if ($period['mode'] === 'mes') $periodQuery += ['periodo' => 'mes', 'mes' => $period['month']];
    else $periodQuery += ['periodo' => 'fechas', 'desde' => $period['from'], 'hasta' => $period['to']];
    $pageUrl = 'modulos/cobranzas/valorizacion.php?' . http_build_query($periodQuery);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf($_POST['csrf_token'] ?? null);
        $action = (string) ($_POST['action'] ?? '');
        $recordId = filter_var($_POST['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $existing = null;
        if ($recordId) {
            $stmt = db()->prepare('SELECT id, archivo_path FROM cobranzas_valorizacion_registros WHERE id = ? AND client_id = ?');
            $stmt->execute([$recordId, $clientId]);
            $existing = $stmt->fetch();
        }
        if ($action === 'delete') {
            if (!$existing) $error = 'No se encontró el registro.';
            else {
                db()->prepare('DELETE FROM cobranzas_valorizacion_registros WHERE id = ? AND client_id = ?')->execute([$recordId, $clientId]);
                delete_uploaded_file($existing['archivo_path']);
                redirect($pageUrl);
            }
        } elseif ($action === 'save') {
            $equipment = trim((string) ($_POST['equipo'] ?? ''));
            // La placa/serie forma parte del único campo Equipo en esta pantalla.
            $plate = '';
            $date = (string) ($_POST['fecha_servicio'] ?? '');
            $start = (string) ($_POST['hora_inicio'] ?? '');
            $end = (string) ($_POST['hora_final'] ?? '');
            $notes = trim((string) ($_POST['observaciones'] ?? ''));
            $parsedDate = preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
            $minutes = valor_minutes($start, $end);
            if (($_POST['record_id'] ?? '') !== '' && !$existing) $error = 'No se encontró el registro.';
            elseif ($equipment === '' || mb_strlen($equipment, 'UTF-8') > 120) $error = 'Ingrese el equipo con su placa o serie (máximo 120 caracteres).';
            elseif (!$parsedDate || $parsedDate->format('Y-m-d') !== $date) $error = 'Seleccione una fecha válida.';
            elseif ($minutes === null) $error = 'Ingrese horas válidas: el turno debe durar más de una hora para descontar el refrigerio. Se admite cruzar la medianoche.';
            elseif (mb_strlen($notes, 'UTF-8') > 2000) $error = 'Las observaciones no pueden superar 2000 caracteres.';
            elseif (!$existing && ($_FILES['adjunto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) $error = 'Adjunte la imagen o PDF original.';
            elseif (($_FILES['adjunto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && (int) ($_FILES['adjunto']['size'] ?? 0) > 5 * 1024 * 1024) $error = 'El archivo supera 5 MB. Reduzca la imagen o seleccione un PDF más pequeño.';
            else {
                $newPath = null;
                try {
                    $file = upload_file($_FILES['adjunto'] ?? [], 'cobranzas_valorizacion', document_attachment_mimes());
                    $newPath = $file['path'];
                    if ($existing) {
                        $sql = 'UPDATE cobranzas_valorizacion_registros SET equipo = ?, placa_serie = ?, fecha_servicio = ?, hora_inicio = ?, hora_final = ?, horas_total_minutos = ?, observaciones = ?';
                        $values = [$equipment, $plate, $date, $start, $end, $minutes, $notes ?: null];
                        if ($newPath) { $sql .= ', archivo_path = ?, archivo_nombre_original = ?'; array_push($values, $newPath, $file['name']); }
                        $sql .= ' WHERE id = ? AND client_id = ?';
                        array_push($values, $recordId, $clientId);
                        db()->prepare($sql)->execute($values);
                        if ($newPath) delete_uploaded_file($existing['archivo_path']);
                    } else {
                        db()->prepare('INSERT INTO cobranzas_valorizacion_registros (client_id, equipo, placa_serie, fecha_servicio, hora_inicio, hora_final, horas_total_minutos, observaciones, archivo_path, archivo_nombre_original) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
                            ->execute([$clientId, $equipment, $plate, $date, $start, $end, $minutes, $notes ?: null, $newPath, $file['name']]);
                    }
                    redirect('modulos/cobranzas/valorizacion.php?' . http_build_query([
                        'cliente_id' => $clientId,
                        'periodo' => 'mes',
                        'mes' => substr($date, 0, 7),
                    ]));
                } catch (Throwable $exception) {
                    if ($newPath) delete_uploaded_file($newPath);
                    $error = $exception instanceof RuntimeException ? $exception->getMessage() : 'No se pudo guardar el registro. Verifique la tabla SQL.';
                    if (!($exception instanceof RuntimeException)) error_log('Error en Cobranzas Valorización: ' . $exception->getMessage());
                }
            }
        }
    }
    try {
        $offset = ($page - 1) * 25;
        $stmt = db()->prepare('SELECT id, equipo, placa_serie, fecha_servicio, hora_inicio, hora_final, horas_total_minutos, observaciones, archivo_path FROM cobranzas_valorizacion_registros WHERE client_id = ?' . $periodWhere . ' ORDER BY fecha_servicio DESC, id DESC LIMIT 26 OFFSET ' . $offset);
        $stmt->execute(array_merge([$clientId], $periodParams));
        $records = $stmt->fetchAll();
        $hasMore = count($records) > 25;
        if ($hasMore) array_pop($records);
        foreach ($records as &$record) {
            $record['equipo_completo'] = valor_equipo_completo((string) $record['equipo'], (string) $record['placa_serie']);
        }
        unset($record);
        $datesStmt = db()->prepare('SELECT MIN(fecha_servicio) AS first_date, MAX(fecha_servicio) AS last_date FROM cobranzas_valorizacion_registros WHERE client_id = ?' . $periodWhere);
        $datesStmt->execute(array_merge([$clientId], $periodParams));
        $dates = $datesStmt->fetch();
        $firstServiceDate = $dates['first_date'] ?? null;
        $lastServiceDate = $dates['last_date'] ?? null;
        $hoursStmt = db()->prepare('SELECT equipo, placa_serie, SUM(horas_total_minutos) AS total_minutos FROM cobranzas_valorizacion_registros WHERE client_id = ?' . $periodWhere . ' GROUP BY equipo, placa_serie');
        $hoursStmt->execute(array_merge([$clientId], $periodParams));
        foreach ($hoursStmt as $equipmentHours) {
            $fullEquipment = valor_equipo_completo((string) $equipmentHours['equipo'], (string) $equipmentHours['placa_serie']);
            $equipmentKey = valor_clave_equipo($fullEquipment);
            $minutes = (int) $equipmentHours['total_minutos'];
            $totalMinutes += $minutes;
            if ($equipmentKey === '') continue;
            if (!isset($equipmentRates[$equipmentKey])) {
                $equipmentRates[$equipmentKey] = ['name' => $equipmentKey === 'camion grua' ? 'Camión Grúa' : valor_tipo_equipo($fullEquipment), 'price' => '37.50', 'minutes' => 0];
            }
            $equipmentRates[$equipmentKey]['minutes'] += $minutes;
        }
        if ($equipmentRates) {
            $rateStmt = db()->prepare('SELECT equipo_clave, precio_hora FROM cobranzas_valorizacion_precios_hora WHERE client_id = ?');
            $rateStmt->execute([$clientId]);
            foreach ($rateStmt as $savedRate) {
                if (isset($equipmentRates[$savedRate['equipo_clave']])) {
                    $equipmentRates[$savedRate['equipo_clave']]['price'] = number_format((float) $savedRate['precio_hora'], 2, '.', '');
                }
            }
        }
        foreach ($equipmentRates as $rate) {
            $totalPriceCents += (int) round(((float) $rate['price']) * 100 * $rate['minutes'] / 60);
        }
    } catch (PDOException $exception) {
        error_log('Error al listar Cobranzas Valorización: ' . $exception->getMessage());
        $error = 'No se pudieron cargar los registros. Ejecute database/cobranzas_valorizacion_registros.sql.';
    }
}
require __DIR__ . '/../../includes/header.php';
?>
<style>
.cv-panel.work-panel{padding:16px 18px!important;margin-bottom:12px!important}.cv-client-layout{display:grid;grid-template-columns:minmax(0,1.7fr) minmax(260px,1fr);gap:24px}.cv-client-layout.cv-client-layout-empty{grid-template-columns:1fr}.cv-client-main,.cv-client-supplier{min-width:0}.cv-client-row{display:grid;grid-template-columns:124px minmax(0,1fr);align-items:center;gap:10px;min-height:38px;margin-bottom:8px}.cv-client-row:last-child{margin-bottom:0}.cv-client-row label,.cv-client-row .cv-client-detail-label{margin:0;color:#344761;font-size:.83rem;font-weight:700}.cv-client-row-value{min-width:0}.cv-client-row-value .select2-container{width:100%!important}.cv-client-row .form-control{height:38px}.cv-client-detail-value{display:block;min-width:0;overflow-wrap:anywhere;font-weight:600}.cv-detail-error{display:block;color:#b42318;font-size:.75rem;margin-top:3px}@media(max-width:900px){.cv-client-layout{grid-template-columns:1fr;gap:8px}.cv-client-supplier{padding-top:10px;border-top:1px solid #e2e8f0}}@media(max-width:600px){.cv-client-row{grid-template-columns:1fr;gap:3px;margin-bottom:12px}}
.cv-table{min-width:0;width:100%;table-layout:fixed}.cv-table th{background:#f4f7fb;color:#415474;font-size:.72rem;text-transform:uppercase}.cv-table td,.cv-table th{padding:.6rem .5rem;vertical-align:middle;overflow-wrap:anywhere}.cv-preview{display:flex;align-items:center;justify-content:center;min-height:45vh;background:#f8fafc}.cv-preview img{max-width:100%;max-height:72vh;object-fit:contain}.cv-preview iframe{width:100%;height:72vh;border:0}@media(max-width:1100px){.cv-table{min-width:900px}}
.cv-review-layout{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(0,1fr);gap:16px;align-items:start}.cv-review-document,.cv-review-fields{min-width:0}.cv-review-document{position:sticky;top:0}.cv-review-document #cvPreviewContent{max-height:45vh;overflow:auto}.cv-review-document #cvPreviewContent canvas{max-width:100%}.cv-review-fields .row{--bs-gutter-y:.75rem;margin-left:0;margin-right:0}.cv-review-suggestion{display:flex;align-items:center;justify-content:space-between;gap:8px;padding:5px 0;border-bottom:1px solid #e2e8f0}.cv-review-suggestion:last-child{border-bottom:0}.cv-review-suggestion span{min-width:0;overflow-wrap:anywhere}.cv-review-fields .cv-field-state{font-size:.74rem;margin-top:3px}.cv-review-fields .cv-field-state.is-pending{color:#9a6700}.cv-review-fields .cv-field-state.is-ready{color:#08764d}@media(max-width:991px){.cv-review-layout{grid-template-columns:1fr}.cv-review-document{position:static}.cv-review-document #cvPreviewContent{max-height:34vh}}
.cv-visual-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.cv-visual-card{min-width:0;padding:7px;background:#fff;border:1px solid #d9e3ef;border-radius:7px;cursor:text}.cv-visual-card span{display:block;margin-bottom:4px;color:#334155}.cv-visual-card canvas{display:block;width:100%;height:auto;max-height:96px;object-fit:contain;object-position:left center;background:#fff}.cv-visual-card:first-child{grid-column:1/-1}@media(max-width:575px){.cv-visual-grid{grid-template-columns:1fr}.cv-visual-card:first-child{grid-column:auto}}
.cv-entry[hidden],.cv-upload #cvProgressBox[hidden]{display:none!important}.cv-hidden-source,#cvReviewSuggestions{display:none}.cv-entry.needs-file .cv-hidden-source,.cv-entry.editing .cv-hidden-source{display:block;margin-bottom:12px}.cv-entry.needs-file #cvPreviewHost,.cv-entry.editing #cvPreviewHost{display:none}.cv-entry .cv-review-fields .row{margin-left:0;margin-right:0}.cv-upload .progress{height:10px}.cv-entry{scroll-margin-top:20px}
.cv-record .cv-input{display:none}.cv-record.cv-editing .cv-view{display:none}.cv-record.cv-editing .cv-input{display:block}.cv-record.cv-editing button.cv-input{display:inline-flex}.cv-record.cv-editing{background:#eef5ff}.cv-record .cv-row-actions{display:flex;align-items:center;gap:5px;white-space:nowrap}.cv-record .cv-inline-form{display:none}.cv-record .cv-inline-total{min-width:50px}.cv-record .form-control-sm{min-width:0;width:100%}
.cv-table th:last-child,.cv-table td:last-child{text-align:center}
.cv-table tfoot td{background:#f4f7fb;border-top:1px solid #d7e2f0;color:#0f172a;font-weight:800}.cv-table tfoot .cv-hours-label{font-size:.85rem;white-space:nowrap;font-weight:800}.cv-table tfoot .cv-hours-value{font-size:1.05rem;color:#0b3b80;font-weight:800}
.cv-table tfoot .cv-rate-label{text-align:right;font-size:.85rem}.cv-table tfoot .cv-rate-input{width:100%;min-width:0;padding:.3rem .35rem;font-weight:700}.cv-table tfoot .cv-rate-status{font-size:.75rem;font-weight:600;color:#08764d}.cv-table tfoot .cv-rate-status.is-error{color:#b42318}
.cv-table tfoot .cv-period-label{text-align:right;font-size:.85rem;font-weight:800}.cv-table tfoot .cv-period-value{font-size:1.05rem;font-weight:800;color:#0b3b80;white-space:nowrap}
.cv-record .cv-row-actions{justify-content:center;gap:7px}
.cv-record .cv-row-actions .cvDelete{margin:0;padding:0;display:block;flex:0 0 38px;width:38px;height:38px}
.cv-record .cv-row-actions .cv-action-btn{appearance:none;box-sizing:border-box;display:grid;place-items:center;flex:0 0 38px;width:38px;height:38px;min-width:38px;max-width:38px;min-height:38px;max-height:38px;margin:0;padding:0;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#2563eb;line-height:1;box-shadow:none;cursor:pointer;vertical-align:middle;transition:background-color .15s,border-color .15s}
.cv-record .cv-row-actions .cv-action-btn i{display:block;font-size:17px;line-height:1}
.cv-record .cv-row-actions .cv-action-edit{color:#0f172a}
.cv-record .cv-row-actions .cv-action-file{color:#2563eb}
.cv-record .cv-row-actions .cv-action-file-pdf{color:#dc3545}
.cv-record .cv-row-actions .cv-action-file i{font-size:20px}
.cv-record .cv-row-actions .cv-action-btn:hover{background:#eff6ff;border-color:#93b4e9}
.cv-record .cv-row-actions .cv-action-delete{color:#dc3545;border-color:#f3a7ad}
.cv-record .cv-row-actions .cv-action-delete:hover{background:#fff1f2;border-color:#dc3545}
.cv-record .cv-row-actions .cv-action-btn:focus-visible{outline:2px solid #2563eb;outline-offset:2px}
.cv-record.cv-editing .cv-row-actions .cv-inline-edit,.cv-record.cv-editing .cv-row-actions .cvDelete{display:none}
.cv-period-tools{display:flex;align-items:end;justify-content:flex-end;gap:8px;flex-wrap:wrap}.cv-period-tools .cv-period-field{min-width:140px}.cv-period-tools .cv-period-field label{display:block;margin-bottom:3px;color:#526b91;font-size:.7rem;font-weight:800;letter-spacing:.04em;text-transform:uppercase}.cv-period-tools .form-control,.cv-period-tools .form-select{height:38px}.cv-period-tools [hidden]{display:none!important}@media(max-width:700px){.cv-period-tools{justify-content:flex-start}.cv-period-tools .cv-period-field{flex:1 1 140px}}
.cv-client-panel.work-panel,.cv-upload.work-panel{border:1px solid #dedbd4!important;border-radius:15px!important;box-shadow:0 5px 18px rgba(60,55,45,.055)!important}
.cv-client-panel.work-panel{background:linear-gradient(115deg,#fbfaf7 0%,#f4f2ed 100%)!important}
.cv-client-panel .cv-client-layout{grid-template-columns:minmax(0,1.7fr) minmax(255px,.95fr);gap:18px;align-items:center}
.cv-client-panel .cv-client-layout.cv-client-layout-empty{grid-template-columns:1fr}
.cv-client-panel .cv-client-row{min-height:36px;margin-bottom:5px}
.cv-client-panel .cv-client-row:last-child{margin-bottom:0}
.cv-client-panel .cv-client-row label,.cv-client-panel .cv-client-detail-label{color:#455b79;font-weight:700}
.cv-client-panel .cv-client-detail-value{color:#172b4d;font-weight:700}
.cv-client-panel .form-control,.cv-client-panel .form-select{height:36px;border-color:#cbd9eb;border-radius:9px;background-color:#fff;box-shadow:none}
.cv-client-panel .form-control:focus,.cv-client-panel .form-select:focus{border-color:#4f83e6;box-shadow:0 0 0 3px rgba(37,99,235,.12)}
.cv-client-panel .cv-client-supplier{display:block;align-self:start;min-height:0;padding:0 0 0 16px;border:0;border-left:1px solid #dedbd4;border-radius:0;background:transparent}
.cv-client-panel .cv-client-supplier .cv-client-row{grid-template-columns:100px minmax(0,1fr);min-height:36px;margin-bottom:5px}
.cv-client-panel .cv-client-supplier .cv-client-row+.cv-client-row{padding:0;border:0}
.cv-client-panel .cv-client-supplier .cv-client-detail-value{overflow-wrap:anywhere}
.cv-upload.work-panel{background:linear-gradient(115deg,#fbfaf7 0%,#f4f2ed 100%)!important}
.cv-upload #cvUploadTitle{color:#172b4d;font-weight:750}
.cv-upload .text-muted{color:#586d8a!important}
.cv-upload .d-flex>div>p{max-width:720px;line-height:1.45}
.cv-upload label.btn-primary{display:inline-flex;align-items:center;justify-content:center;min-height:40px;padding:.48rem .9rem;border-radius:9px;box-shadow:0 4px 10px rgba(37,99,235,.15);font-weight:700;transition:transform .15s,box-shadow .15s}
.cv-upload label.btn-primary:hover{transform:translateY(-1px);box-shadow:0 7px 14px rgba(37,99,235,.22)}
.cv-upload label.btn-primary:focus-visible{outline:3px solid #93b4ff;outline-offset:2px}
.cv-upload .cv-file-hint{display:inline-block;margin-top:7px!important;padding:4px 9px;border:1px solid #e4dfd6;border-radius:7px;background:#fff;color:#526986!important;font-size:.75rem}
.cv-upload-title{display:flex;align-items:center;gap:8px}.cv-upload-title h2{margin:0!important}.cv-info-button{display:inline-grid;place-items:center;flex:0 0 26px;width:26px;height:26px;padding:0;border:1px solid #bdd2f4;border-radius:50%;background:#eef5ff;color:#2563eb;cursor:pointer;transition:background-color .15s,border-color .15s}.cv-info-button:hover{background:#dfeeff;border-color:#8fb3ed}.cv-info-button:focus-visible{outline:3px solid #93b4ff;outline-offset:2px}.cv-info-button i{font-size:14px}.cv-model-example{display:block;width:auto;max-width:100%;max-height:65vh;margin:0 auto;border:1px solid #d9e3ef;border-radius:8px;object-fit:contain}
@media(max-width:900px){.cv-client-panel .cv-client-layout{grid-template-columns:1fr;gap:12px}.cv-client-panel .cv-client-supplier{padding:10px 0 0;border:0;border-top:1px solid #dedbd4}.cv-client-panel .cv-client-supplier .cv-client-row{grid-template-columns:124px minmax(0,1fr)}}
@media(max-width:600px){.cv-client-panel .cv-client-row,.cv-client-panel .cv-client-supplier .cv-client-row{grid-template-columns:1fr;gap:3px}.cv-upload .d-flex>label.btn-primary{width:100%;margin-top:6px}.cv-upload .d-flex>div{width:100%}}
</style>
<div class="page-title"><div><h1>Valorización</h1><p>Cobranzas por cliente.</p></div></div>
<section class="work-panel cv-panel cv-client-panel"><div class="cv-client-layout<?= $client ? '' : ' cv-client-layout-empty' ?>">
<div class="cv-client-main">
<div class="cv-client-row"><label for="cvClient">Cliente</label><div class="cv-client-row-value"><select class="form-select" id="cvClient" aria-label="Buscar cliente por razón social o RUC"><option></option><?php if ($client): ?><option value="<?= (int) $client['id'] ?>" selected><?= e($client['business_name'] . ' - ' . $client['ruc']) ?></option><?php endif; ?></select></div></div>
<?php if ($client): ?>
<div class="cv-client-row"><label for="cvServiceLocation">Lugar de servicio</label><div class="cv-client-row-value"><input class="form-control" id="cvServiceLocation" type="text" value="<?= e($serviceLocation) ?>" maxlength="200" placeholder="Ingrese el lugar de servicio" data-client-id="<?= (int) $clientId ?>" data-csrf="<?= e(csrf_token()) ?>" data-endpoint="<?= e(APP_URL . '/servicios/cobranzas/valorizacion_lugar.php') ?>"><span class="cv-detail-error" id="cvLocationError" role="alert"></span></div></div>
<div class="cv-client-row"><span class="cv-client-detail-label">RUC - Cliente</span><div class="cv-client-row-value cv-client-detail-value"><?= e($client['ruc']) ?></div></div>
<div class="cv-client-row"><span class="cv-client-detail-label">Contacto</span><div class="cv-client-row-value cv-client-detail-value"><?= $commercialContact ? e($commercialContact['name'] . ' · ' . $commercialContact['phone']) : 'Sin contacto comercial' ?></div></div>
<?php endif; ?>
</div>
<?php if ($client): ?><div class="cv-client-supplier">
<div class="cv-client-row"><span class="cv-client-detail-label">Proveedor</span><div class="cv-client-row-value cv-client-detail-value">LIFE EQUIPOS INDUSTRIALES SAC</div></div>
<div class="cv-client-row"><span class="cv-client-detail-label">RUC</span><div class="cv-client-row-value cv-client-detail-value">20603633441</div></div>
</div><?php endif; ?>
</div></section>
<?php if ($client): ?>
<section class="work-panel cv-panel cv-upload" aria-labelledby="cvUploadTitle">
<div class="d-flex align-items-center justify-content-between gap-2 flex-wrap"><div><div class="cv-upload-title mb-1"><h2 class="h5" id="cvUploadTitle">Nuevo parte diario</h2><button class="cv-info-button" type="button" data-bs-toggle="modal" data-bs-target="#cvModelInfoModal" aria-label="Ver modelo de parte diario" title="Ver modelo de parte diario"><i class="fa-solid fa-circle-info" aria-hidden="true"></i></button></div><p class="small text-muted mb-0">Adjunte una foto o PDF; si se leen todos los datos, el registro aparecerá en la tabla automáticamente.</p></div><label class="btn btn-primary mb-0" for="cvQuickFile"><i class="fa-solid fa-file-arrow-up me-1" aria-hidden="true"></i>Seleccionar archivo</label><input class="visually-hidden" type="file" id="cvQuickFile" accept="image/jpeg,image/png,image/webp,application/pdf" aria-label="Seleccionar imagen o PDF"></div>
<div class="small text-muted mt-2 cv-file-hint">JPG, PNG, WEBP o PDF. Máximo 5 MB por archivo.</div>
<div id="cvProgressBox" class="mt-3" hidden><div class="d-flex justify-content-between small mb-1"><strong id="cvProgressLabel">Preparando archivo…</strong><span id="cvProgressFile"></span></div><div class="progress" role="progressbar" aria-label="Progreso de lectura" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar progress-bar-striped progress-bar-animated" id="cvProgressBar" style="width:15%"></div></div></div>
</section>
<div class="modal fade" id="cvModelInfoModal" tabindex="-1" aria-labelledby="cvModelInfoTitle" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="cvModelInfoTitle">Modelo de parte diario</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><p class="mb-3">Sube una foto nítida o un PDF de este modelo para que la IA lea automáticamente el equipo, la fecha y las horas.</p><img class="cv-model-example" src="<?= e(APP_URL . '/img/parte2.jpg') ?>" alt="Ejemplo del modelo de parte diario requerido" loading="lazy"><div class="text-center mt-2"><a href="<?= e(APP_URL . '/img/parte2.jpg') ?>" target="_blank" rel="noopener noreferrer">Ver imagen en tamaño completo</a></div></div></div></div></div>
<?php require __DIR__ . '/valorizacion_form.php'; ?>
<section class="work-panel cv-panel"><div class="d-flex align-items-end justify-content-between gap-3 flex-wrap mb-3"><h2 class="h5 mb-0">Registros de valorización</h2><div class="d-flex align-items-end gap-2 flex-wrap"><form class="cv-period-tools" id="cvPeriodForm" method="get" action="<?= e(APP_URL . '/modulos/cobranzas/valorizacion.php') ?>"><input type="hidden" name="cliente_id" value="<?= (int) $clientId ?>"><div class="cv-period-field"><label for="cvPeriodMode">Periodo</label><select class="form-select form-select-sm" id="cvPeriodMode" name="periodo"><option value="mes" <?= $period['mode'] === 'mes' ? 'selected' : '' ?>>Por mes</option><option value="fechas" <?= $period['mode'] === 'fechas' ? 'selected' : '' ?>>Entre fechas</option></select></div><div class="cv-period-field" id="cvPeriodMonthField" <?= $period['mode'] !== 'mes' ? 'hidden' : '' ?>><label for="cvPeriodMonth">Mes</label><input class="form-control form-control-sm" id="cvPeriodMonth" name="mes" type="month" value="<?= e($period['month']) ?>" <?= $period['mode'] === 'mes' ? 'required' : 'disabled' ?>></div><div class="cv-period-field" id="cvPeriodFromField" <?= $period['mode'] !== 'fechas' ? 'hidden' : '' ?>><label for="cvPeriodFrom">Desde</label><input class="form-control form-control-sm" id="cvPeriodFrom" name="desde" type="date" value="<?= e($period['from']) ?>" <?= $period['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div><div class="cv-period-field" id="cvPeriodToField" <?= $period['mode'] !== 'fechas' ? 'hidden' : '' ?>><label for="cvPeriodTo">Hasta</label><input class="form-control form-control-sm" id="cvPeriodTo" name="hasta" type="date" value="<?= e($period['to']) ?>" <?= $period['mode'] === 'fechas' ? 'required' : 'disabled' ?>></div><button class="btn btn-outline-primary" type="submit">Ver</button></form><button class="btn btn-outline-danger" id="cvExportPdf" type="button" data-url="<?= e(APP_URL . '/servicios/cobranzas/valorizacion_reporte_datos.php?' . http_build_query($periodQuery)) ?>" <?= $totalMinutes <= 0 ? 'disabled' : '' ?>><i class="fa-solid fa-file-pdf me-1" aria-hidden="true"></i>PDF</button></div></div>
<?php if ($error !== '' && (($_POST['action'] ?? '') !== 'save' || ($_POST['inline_edit'] ?? '') === '1')): ?><div class="alert alert-danger py-2" role="alert"><?= e($error) ?></div><?php endif; ?>
<div class="table-responsive border rounded"><table class="table table-hover align-middle mb-0 cv-table"><colgroup><col style="width:22%"><col style="width:12%"><col style="width:10%"><col style="width:10%"><col style="width:15%"><col style="width:15%"><col style="width:16%"></colgroup><thead><tr><th>Equipo</th><th>Fecha del servicio</th><th>Hora de inicio</th><th>Hora final</th><th>Horas en total</th><th>Observaciones</th><th>Acciones</th></tr></thead><tbody>
<?php if (!$records): ?><tr><td colspan="7" class="text-center text-muted py-4">No hay registros en el periodo seleccionado.</td></tr><?php endif; ?>
<?php foreach ($records as $record): require __DIR__ . '/valorizacion_row.php'; endforeach; ?>
</tbody><tfoot>
<tr><td colspan="3"></td><td class="cv-hours-label">Horas totales:</td><td class="cv-hours-value"><?= sprintf('%02d:%02d', intdiv($totalMinutes, 60), $totalMinutes % 60) ?></td><td colspan="2"></td></tr>
<?php foreach ($equipmentRates as $equipmentKey => $rate): ?>
<tr><td colspan="4" class="cv-rate-label">Precio por hora <?= e($rate['name']) ?>:</td><td><input class="form-control form-control-sm cv-rate-input" type="number" name="precio_hora" value="<?= e($rate['price']) ?>" data-minutes="<?= (int) $rate['minutes'] ?>" data-saved-price="<?= e($rate['price']) ?>" data-client-id="<?= (int) $clientId ?>" data-equipment-key="<?= e($equipmentKey) ?>" data-csrf="<?= e(csrf_token()) ?>" data-endpoint="<?= e(APP_URL . '/servicios/cobranzas/valorizacion_precio.php') ?>" min="0" max="9999999999.99" step="0.01" required aria-label="Precio por hora <?= e($rate['name']) ?>"></td><td><span class="cv-rate-status" role="status" aria-live="polite"></span></td><td></td></tr>
<?php endforeach; ?>
<?php if ($firstServiceDate && $lastServiceDate): ?>
<tr><td colspan="4" class="cv-period-label">Precio del <?= e(date('d/m/Y', strtotime($firstServiceDate))) ?> al <?= e(date('d/m/Y', strtotime($lastServiceDate))) ?>:</td><td class="cv-period-value" id="cvPeriodPrice"><?= number_format($totalPriceCents / 100, 2, '.', ',') ?></td><td colspan="2"></td></tr>
<tr><td colspan="4" class="cv-period-label">IGV (18 %):</td><td class="cv-period-value" id="cvPeriodIgv"><?= number_format(round($totalPriceCents * 18 / 100) / 100, 2, '.', ',') ?></td><td colspan="2"></td></tr>
<tr><td colspan="4" class="cv-period-label">Monto total:</td><td class="cv-period-value" id="cvGrandTotal"><?= number_format(($totalPriceCents + (int) round($totalPriceCents * 18 / 100)) / 100, 2, '.', ',') ?></td><td colspan="2"></td></tr>
<?php endif; ?>
</tfoot></table></div><?php if ($page > 1 || $hasMore): ?><nav class="d-flex justify-content-end gap-2 mt-3" aria-label="Páginas de registros"><?php if ($page > 1): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL . '/' . $pageUrl . '&pagina=' . ($page - 1)) ?>">Anterior</a><?php endif; ?><?php if ($hasMore): ?><a class="btn btn-sm btn-outline-primary" href="<?= e(APP_URL . '/' . $pageUrl . '&pagina=' . ($page + 1)) ?>">Siguiente</a><?php endif; ?></nav><?php endif; ?></section>

<div class="modal fade" id="cvFileModal" tabindex="-1" aria-labelledby="cvFileTitle" aria-hidden="true"><div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h2 class="modal-title h5" id="cvFileTitle">Archivo original</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body cv-preview" id="cvFileBody"></div></div></div></div>
<?php endif; ?>
<script type="module" src="<?= e(APP_URL . '/recursos/js/cobranzas_valorizacion_archivo.js?v=8') ?>"></script>
<script type="module" src="<?= e(APP_URL . '/recursos/js/cobranzas_valorizacion_ocr.js?v=19') ?>"></script>
<script type="module" src="<?= e(APP_URL . '/recursos/js/cobranzas_valorizacion_reporte.js?v=16') ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const periodMode = document.getElementById('cvPeriodMode');
    if (periodMode) {
        const periodFields = [
            ['mes', document.getElementById('cvPeriodMonthField'), document.getElementById('cvPeriodMonth')],
            ['fechas', document.getElementById('cvPeriodFromField'), document.getElementById('cvPeriodFrom')],
            ['fechas', document.getElementById('cvPeriodToField'), document.getElementById('cvPeriodTo')]
        ];
        const updatePeriodFields = () => periodFields.forEach(([mode, wrapper, input]) => {
            const active = periodMode.value === mode;
            wrapper.hidden = !active;
            input.disabled = !active;
            input.required = active;
        });
        periodMode.addEventListener('change', updatePeriodFields);
        updatePeriodFields();
    }
    const search = jQuery('#cvClient');
    search.select2({theme:'bootstrap4',width:'100%',placeholder:'Seleccione o busque un cliente',ajax:{url:<?= json_encode(APP_URL . '/servicios/cobranzas/buscar_clientes_valorizacion.php') ?>,dataType:'json',delay:250,data:params=>({q:params.term||'',page:params.page||1}),processResults:data=>({results:data.results||[],pagination:data.pagination||{more:false}})},language:{searching:()=> 'Buscando clientes…',noResults:()=> 'No se encontraron clientes'}});
    search.on('select2:select', event => {window.location.href = <?= json_encode(APP_URL . '/modulos/cobranzas/valorizacion.php?cliente_id=') ?> + encodeURIComponent(event.params.data.id);});
    <?php if ($client): ?>
    const serviceLocation = document.getElementById('cvServiceLocation');
    const locationError = document.getElementById('cvLocationError');
    serviceLocation.addEventListener('change', async () => {
        const data = new FormData();
        data.set('csrf_token', serviceLocation.dataset.csrf);
        data.set('cliente_id', serviceLocation.dataset.clientId);
        data.set('lugar_servicio', serviceLocation.value.trim());
        locationError.textContent = '';
        serviceLocation.disabled = true;
        try {
            const response = await fetch(serviceLocation.dataset.endpoint, {method:'POST', body:data, credentials:'same-origin', headers:{Accept:'application/json'}});
            if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('La sesión venció. Actualice la página e ingrese de nuevo.');
            const result = await response.json();
            if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar el lugar de servicio.');
            serviceLocation.value = serviceLocation.value.trim();
        } catch (error) {
            locationError.textContent = error.message || 'No se pudo guardar el lugar de servicio.';
        } finally {
            serviceLocation.disabled = false;
        }
    });
    const formElement = document.getElementById('cvFormModal');
    const quickFile = document.getElementById('cvQuickFile');
    const progressBox = document.getElementById('cvProgressBox');
    const progressBar = document.getElementById('cvProgressBar');
    const progressLabel = document.getElementById('cvProgressLabel');
    const progressFile = document.getElementById('cvProgressFile');
    let autoSaving = false;
    const showProgress = (label, width, active = true) => {
        progressBox.hidden = false;
        progressLabel.textContent = label;
        progressBar.style.width = width + '%';
        if (active) progressBar.classList.remove('bg-warning');
        progressBar.classList.toggle('progress-bar-animated', active);
        progressBar.classList.toggle('bg-success', !active && width === 100);
        progressBox.querySelector('.progress').setAttribute('aria-valuenow', String(width));
    };
    const openPanel = (visible = true) => {
        formElement.dispatchEvent(new Event('show.bs.modal'));
        formElement.hidden = !visible;
        formElement.dispatchEvent(new Event('shown.bs.modal'));
        if (visible) formElement.scrollIntoView({behavior:'smooth', block:'start'});
    };
    const fields = {id:document.getElementById('cvId'),equipo:document.getElementById('cvEquipment'),fecha_servicio:document.getElementById('cvDate'),hora_inicio:document.getElementById('cvStart'),hora_final:document.getElementById('cvEnd'),observaciones:document.getElementById('cvNotes')};
    const updateTotal = () => {
        const start = fields.hora_inicio.value, end = fields.hora_final.value;
        const total = document.getElementById('cvTotal'), feedback = document.getElementById('cvTimeFeedback');
        total.value = '—';
        feedback.textContent = '';
        fields.hora_final.setCustomValidity('');
        if (!start || !end) return;
        const validTime = /^(?:[01]\d|2[0-3]):[0-5]\d$/;
        if (!validTime.test(start) || !validTime.test(end)) {
            feedback.textContent = 'Ingrese horas válidas.';
            feedback.className = 'small mt-1 text-danger';
            fields.hora_final.setCustomValidity('Ingrese horas válidas.');
            return;
        }
        const [h1,m1] = start.split(':').map(Number), [h2,m2] = end.split(':').map(Number);
        const first = h1*60+m1, last = h2*60+m2;
        const elapsed = (last-first+1440)%1440;
        if (elapsed <= 60) {
            feedback.textContent = 'El turno debe durar más de 1 hora para descontar el refrigerio.';
            feedback.className = 'small mt-1 text-danger';
            fields.hora_final.setCustomValidity(feedback.textContent);
            return;
        }
        const payable = elapsed-60;
        total.value = String(Math.floor(payable/60)).padStart(2,'0') + ':' + String(payable%60).padStart(2,'0');
        feedback.textContent = (last < first ? 'Salida al día siguiente. ' : '') + 'Se descontó 1 hora de refrigerio.';
        feedback.className = 'small mt-1 text-success';
    };
    fields.hora_inicio.addEventListener('input',updateTotal);fields.hora_final.addEventListener('input',updateTotal);
    quickFile.addEventListener('change', () => {
        const file = quickFile.files[0];
        if (!file) return;
        autoSaving = false;
        formElement.querySelector('form').reset();
        formElement.classList.remove('needs-file');
        formElement.classList.remove('editing');
        fields.id.value = '';
        document.getElementById('cvFile').required = true;
        document.getElementById('cvFormTitle').textContent = 'Revisar valorización';
        document.getElementById('cvSelectedFile').textContent = file.name;
        updateTotal();
        openPanel(false);
        const transfer = new DataTransfer();
        transfer.items.add(file);
        const originalInput = document.getElementById('cvFile');
        originalInput.files = transfer.files;
        progressFile.textContent = file.name;
        showProgress('Preparando archivo…', 15);
        originalInput.dispatchEvent(new Event('change', {bubbles:true}));
        quickFile.value = '';
    });
    document.addEventListener('cv:reading-start', () => {quickFile.disabled=true;showProgress('Leyendo datos con Gemini…', 55);});
    document.addEventListener('cv:reading-complete', event => {
        const form = formElement.querySelector('form');
        const file = document.getElementById('cvFile').files[0];
        const complete = event.detail?.count === 4 && file && file.size <= 5 * 1024 * 1024
            && document.getElementById('cvTotal').value !== '—' && form.checkValidity();
        if (complete && !autoSaving) {
            autoSaving = true;
            showProgress('Datos detectados. Guardando registro…', 90);
            setTimeout(() => form.requestSubmit(), 0);
            return;
        }
        quickFile.disabled=false;
        showProgress('Revise los datos pendientes y guarde el registro.', 100, false);
        formElement.hidden = false;
        formElement.scrollIntoView({behavior:'smooth', block:'start'});
    });
    document.addEventListener('cv:reading-error', event => {
        quickFile.disabled=false;
        if (!document.getElementById('cvFile').files.length) formElement.classList.add('needs-file');
        showProgress(event.detail?.message || 'No se pudo completar la lectura; ingrese los datos manualmente.', 100, false);
        progressBar.classList.remove('bg-success');
        progressBar.classList.add('bg-warning');
        formElement.hidden = false;
    });
    document.getElementById('cvCancel').addEventListener('click', () => {
        formElement.hidden = true;
        progressBox.hidden = true;
        formElement.dispatchEvent(new Event('hidden.bs.modal'));
        document.dispatchEvent(new CustomEvent('cv:source', {detail:null}));
    });
    const updatePeriodPrice = () => {
        const periodPrice = document.getElementById('cvPeriodPrice');
        if (!periodPrice) return;
        let totalCents = 0;
        document.querySelectorAll('.cv-rate-input').forEach(input => {
            const priceCents = Math.round(Number(input.dataset.savedPrice) * 100);
            totalCents += Math.round(priceCents * Number(input.dataset.minutes) / 60);
        });
        const moneyFormatter = new Intl.NumberFormat('en-US', {minimumFractionDigits:2, maximumFractionDigits:2});
        periodPrice.textContent = moneyFormatter.format(totalCents / 100);
        const periodIgv = document.getElementById('cvPeriodIgv');
        const igvCents = Math.round(totalCents * 18 / 100);
        if (periodIgv) periodIgv.textContent = moneyFormatter.format(igvCents / 100);
        const grandTotal = document.getElementById('cvGrandTotal');
        if (grandTotal) grandTotal.textContent = moneyFormatter.format((totalCents + igvCents) / 100);
    };
    document.querySelectorAll('.cv-rate-input').forEach(priceInput => {
        const status = priceInput.closest('tr').querySelector('.cv-rate-status');
        priceInput.addEventListener('change', async () => {
            if (!priceInput.reportValidity()) return;
            const formData = new FormData();
            formData.set('csrf_token', priceInput.dataset.csrf);
            formData.set('cliente_id', priceInput.dataset.clientId);
            formData.set('equipo_clave', priceInput.dataset.equipmentKey);
            formData.set('precio_hora', priceInput.value);
            priceInput.disabled = true;
            status.classList.remove('is-error');
            status.textContent = '';
            try {
                const response = await fetch(priceInput.dataset.endpoint, {method:'POST', body:formData, credentials:'same-origin', headers:{Accept:'application/json'}});
                if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('La sesión venció. Actualice la página e ingrese de nuevo.');
                const result = await response.json();
                if (!response.ok || !result.ok) throw new Error(result.message || 'No se pudo guardar.');
                priceInput.value = result.price;
                priceInput.dataset.savedPrice = result.price;
                updatePeriodPrice();
                status.textContent = '';
            } catch (error) {
                status.classList.add('is-error');
                status.textContent = error.message || 'No se pudo guardar.';
            } finally {
                priceInput.disabled = false;
            }
        });
    });
    document.querySelectorAll('.cv-record').forEach(row => {
        const form = row.querySelector('.cv-inline-form');
        const start = row.querySelector('[name="hora_inicio"]');
        const end = row.querySelector('[name="hora_final"]');
        const total = row.querySelector('.cv-inline-total');
        const save = row.querySelector('.cv-inline-save');
        const updateRowTotal = () => {
            const validTime = /^(?:[01]\d|2[0-3]):[0-5]\d$/;
            let minutes = null;
            if (validTime.test(start.value) && validTime.test(end.value)) {
                const [h1,m1] = start.value.split(':').map(Number), [h2,m2] = end.value.split(':').map(Number);
                const elapsed = (h2*60+m2-h1*60-m1+1440)%1440;
                if (elapsed > 60) minutes = elapsed - 60;
            }
            total.textContent = minutes === null ? 'Revisar horas' : String(Math.floor(minutes/60)).padStart(2,'0') + ':' + String(minutes%60).padStart(2,'0');
            end.setCustomValidity(minutes === null ? 'El turno debe durar más de una hora.' : '');
            save.disabled = minutes === null;
        };
        row.querySelector('.cv-inline-edit').addEventListener('click', () => {
            document.querySelectorAll('.cv-record.cv-editing').forEach(other => {if (other !== row) other.querySelector('.cv-inline-cancel').click();});
            row.classList.add('cv-editing');
            updateRowTotal();
            row.querySelector('[name="equipo"]').focus();
        });
        row.querySelector('.cv-inline-cancel').addEventListener('click', () => {form.reset();row.classList.remove('cv-editing');updateRowTotal();});
        start.addEventListener('input', updateRowTotal);
        end.addEventListener('input', updateRowTotal);
        if (row.classList.contains('cv-editing')) updateRowTotal();
    });
    document.querySelectorAll('.cvDelete').forEach(form=>form.addEventListener('submit',async event=>{event.preventDefault();const result=await Swal.fire({title:'¿Eliminar este registro?',text:'También se eliminará el archivo original. Esta acción no se puede deshacer.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, eliminar',cancelButtonText:'Cancelar',confirmButtonColor:'#dc3545',reverseButtons:true});if(result.isConfirmed)form.submit();}));
    const fileModal=document.getElementById('cvFileModal'),fileBody=document.getElementById('cvFileBody');fileModal.addEventListener('hidden.bs.modal',()=>fileBody.replaceChildren());document.querySelectorAll('.cvOpenFile').forEach(button=>button.addEventListener('click',()=>{const preview=document.createElement(button.dataset.kind==='image'?'img':'iframe');preview.src=button.dataset.url;if(preview.tagName==='IMG')preview.alt='Archivo original';else preview.title='PDF original';fileBody.replaceChildren(preview);bootstrap.Modal.getOrCreateInstance(fileModal).show();}));
    <?php if ($error !== '' && ($_POST['action'] ?? '') === 'save' && ($_POST['inline_edit'] ?? '') !== '1'): ?>
    const attempted=<?= json_encode(['record_id'=>(string)($_POST['record_id']??''),'equipo'=>(string)($_POST['equipo']??''),'fecha_servicio'=>(string)($_POST['fecha_servicio']??''),'hora_inicio'=>(string)($_POST['hora_inicio']??''),'hora_final'=>(string)($_POST['hora_final']??''),'observaciones'=>(string)($_POST['observaciones']??'')],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;fields.id.value=attempted.record_id;for(const key of ['equipo','fecha_servicio','hora_inicio','hora_final','observaciones'])fields[key].value=attempted[key];document.getElementById('cvFile').required=!fields.id.value;if(!fields.id.value){formElement.classList.add('needs-file');document.getElementById('cvSelectedFile').textContent='Seleccione nuevamente el archivo para poder guardar.';}document.getElementById('cvFormTitle').textContent=fields.id.value?'Editar valorización':'Registrar valorización';updateTotal();openPanel();
    <?php endif; ?>
    <?php endif; ?>
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
