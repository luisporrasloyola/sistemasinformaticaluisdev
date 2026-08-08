<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../includes/status_alerts.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.documentos');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
$postActions = ['save', 'delete', 'delete_file', 'catalog_save', 'catalog_delete', 'upload_photo'];
if (in_array($action, $postActions, true)) {
    verify_csrf($_POST['csrf_token'] ?? null);
}

if ($action === 'profile') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare('SELECT * FROM empresas_maquirenta WHERE id = :id AND status = 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['ok' => false, 'message' => 'No se encontró la empresa Maquirenta.'], 404);
    json_response(['ok' => true, 'empresa' => $row]);
}

if ($action === 'catalog') {
    $q = '%' . trim((string) ($_GET['q'] ?? '')) . '%';
    $stmt = db()->prepare('SELECT id, nombre AS text, tipo_segmentacion FROM empresa_maquirenta_documentos_catalogo WHERE estado = 1 AND nombre LIKE :q ORDER BY nombre');
    $stmt->execute(['q' => $q]);
    json_response(['results' => filter_allowed_documents('empresa_maquirenta.documentos', $stmt->fetchAll(), 'id', 'upload')]);
}

if ($action === 'list') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    $companyId = (int) ($_GET['empresa_maquirenta_id'] ?? 0);
    $stmt = db()->prepare("SELECT d.*, c.nombre AS documento, COALESCE(u.name, '') AS registered_by
        FROM empresa_maquirenta_documentos d
        JOIN empresa_maquirenta_documentos_catalogo c ON c.id = d.documento_id
        LEFT JOIN users u ON u.id = d.registered_by_user_id
        WHERE d.empresa_maquirenta_id = :company_id
        ORDER BY c.nombre, d.periodo_anio, CAST(d.segmento_clave AS UNSIGNED), d.segmento_clave");
    $stmt->execute(['company_id' => $companyId]);
    $rows = array_values(array_filter($stmt->fetchAll(), static fn(array $row): bool => current_user_can_document('empresa_maquirenta.documentos', (int) $row['documento_id'], 'view')));
    foreach ($rows as &$row) {
        $row['status'] = status_alert_document_status((string) $row['fecha_fin'], 'empresa_maquirenta.documentos', (int) $row['documento_id'], true);
        $row['segment_label'] = maquirenta_segment_display($row);
        $row['display_name'] = (string) $row['documento'] . ($row['segment_label'] !== '' ? ' - ' . $row['segment_label'] : '');
    }
    unset($row);
    json_response(['ok' => true, 'rows' => $rows]);
}

if ($action === 'get') {
    $id = (int) ($_GET['id'] ?? 0);
    $stmt = db()->prepare("SELECT d.*, c.nombre AS documento, c.tipo_segmentacion FROM empresa_maquirenta_documentos d JOIN empresa_maquirenta_documentos_catalogo c ON c.id=d.documento_id WHERE d.id=:id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['ok' => false, 'message' => 'No se encontró el documento.'], 404);
    if (!current_user_can_document('empresa_maquirenta.documentos', (int) $row['documento_id'], 'view')) json_response(['ok' => false, 'message' => 'No tiene permisos para consultar este documento.'], 403);
    json_response(['ok' => true, 'row' => $row]);
}

if ($action === 'save') {
    $id = (int) ($_POST['id'] ?? 0);
    $companyId = (int) ($_POST['empresa_maquirenta_id'] ?? 0);
    $documentId = (int) ($_POST['documento_id'] ?? 0);
    $registrationDate = (string) ($_POST['fecha_registro'] ?? '');
    $startDate = (string) ($_POST['fecha_inicio'] ?? '');
    $endDate = (string) ($_POST['fecha_fin'] ?? '');
    if (!$companyId || !$documentId || !$registrationDate || !$startDate || !$endDate) json_response(['ok' => false, 'message' => 'Complete todos los campos obligatorios.'], 422);
    if (!current_user_can_document('empresa_maquirenta.documentos', $documentId, 'upload')) json_response(['ok' => false, 'message' => 'No tiene permisos para guardar este documento.'], 403);
    if (strtotime($endDate) < strtotime($startDate)) json_response(['ok' => false, 'message' => 'La fecha fin no puede ser menor a la fecha inicio.'], 422);

    $catalogStmt = db()->prepare('SELECT tipo_segmentacion FROM empresa_maquirenta_documentos_catalogo WHERE id=:id AND estado=1');
    $catalogStmt->execute(['id' => $documentId]);
    $segmentationType = (string) $catalogStmt->fetchColumn();
    if ($segmentationType === '') json_response(['ok' => false, 'message' => 'La categoría documental no existe.'], 404);
    [$segmentKey, $segmentLabel, $periodYear] = maquirenta_normalize_segment(
        $segmentationType,
        (string) ($_POST['segmento_valor'] ?? ''),
        (int) ($_POST['periodo_anio'] ?? 0)
    );

    $newPath = null;
    try {
        $file = upload_file($_FILES['pdf'] ?? [], 'empresa_maquirenta_documentos', document_attachment_mimes());
        $newPath = $file['path'];
        if ($id > 0) {
            $currentStmt = db()->prepare('SELECT archivo_path FROM empresa_maquirenta_documentos WHERE id=:id AND empresa_maquirenta_id=:company_id');
            $currentStmt->execute(['id' => $id, 'company_id' => $companyId]);
            $current = $currentStmt->fetch();
            if (!$current) {
                if ($newPath) delete_uploaded_file($newPath);
                json_response(['ok' => false, 'message' => 'No se encontró el documento.'], 404);
            }
            $sql = 'UPDATE empresa_maquirenta_documentos SET documento_id=:documento_id, segmento_clave=:segmento_clave, segmento_etiqueta=:segmento_etiqueta, periodo_anio=:periodo_anio, fecha_registro=:fecha_registro, fecha_inicio=:fecha_inicio, fecha_fin=:fecha_fin, observaciones=:observaciones';
            $params = ['documento_id'=>$documentId, 'segmento_clave'=>$segmentKey, 'segmento_etiqueta'=>$segmentLabel ?: null, 'periodo_anio'=>$periodYear, 'fecha_registro'=>$registrationDate, 'fecha_inicio'=>$startDate, 'fecha_fin'=>$endDate, 'observaciones'=>trim((string)($_POST['observaciones'] ?? '')), 'id'=>$id, 'company_id'=>$companyId];
            if ($newPath) {
                $sql .= ', archivo_path=:archivo_path, archivo_nombre_original=:archivo_nombre_original';
                $params['archivo_path'] = $newPath;
                $params['archivo_nombre_original'] = $file['name'];
            }
            $sql .= ' WHERE id=:id AND empresa_maquirenta_id=:company_id';
            db()->prepare($sql)->execute($params);
            if ($newPath) delete_uploaded_file($current['archivo_path'] ?? null);
        } else {
            $stmt = db()->prepare('INSERT INTO empresa_maquirenta_documentos (empresa_maquirenta_id,documento_id,segmento_clave,segmento_etiqueta,periodo_anio,fecha_registro,fecha_inicio,fecha_fin,observaciones,archivo_path,archivo_nombre_original,registered_by_user_id) VALUES (:company_id,:documento_id,:segmento_clave,:segmento_etiqueta,:periodo_anio,:fecha_registro,:fecha_inicio,:fecha_fin,:observaciones,:archivo_path,:archivo_nombre_original,:user_id)');
            $stmt->execute(['company_id'=>$companyId, 'documento_id'=>$documentId, 'segmento_clave'=>$segmentKey, 'segmento_etiqueta'=>$segmentLabel ?: null, 'periodo_anio'=>$periodYear, 'fecha_registro'=>$registrationDate, 'fecha_inicio'=>$startDate, 'fecha_fin'=>$endDate, 'observaciones'=>trim((string)($_POST['observaciones'] ?? '')), 'archivo_path'=>$newPath, 'archivo_nombre_original'=>$file['name'], 'user_id'=>(int)(current_user()['id'] ?? 0) ?: null]);
        }
        json_response(['ok' => true]);
    } catch (PDOException $e) {
        if ($newPath) delete_uploaded_file($newPath);
        if ($e->getCode() === '23000') json_response(['ok' => false, 'message' => 'Este segmento ya existe dentro de la categoría seleccionada.'], 409);
        json_response(['ok' => false, 'message' => 'No se pudo guardar el documento.'], 400);
    } catch (Throwable $e) {
        if ($newPath) delete_uploaded_file($newPath);
        json_response(['ok' => false, 'message' => $e->getMessage()], 400);
    }
}

if ($action === 'delete' || $action === 'delete_file') {
    $id = (int) ($_POST['id'] ?? 0);
    $stmt = db()->prepare('SELECT documento_id, archivo_path FROM empresa_maquirenta_documentos WHERE id=:id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_response(['ok' => false, 'message' => 'No se encontró el documento.'], 404);
    if (!current_user_can_document('empresa_maquirenta.documentos', (int)$row['documento_id'], 'upload')) json_response(['ok' => false, 'message' => 'No tiene permisos para modificar este documento.'], 403);
    if ($action === 'delete') db()->prepare('DELETE FROM empresa_maquirenta_documentos WHERE id=:id')->execute(['id'=>$id]);
    else db()->prepare('UPDATE empresa_maquirenta_documentos SET archivo_path=NULL, archivo_nombre_original=NULL WHERE id=:id')->execute(['id'=>$id]);
    delete_uploaded_file($row['archivo_path'] ?? null);
    json_response(['ok' => true]);
}

if ($action === 'catalog_save') {
    if (!current_user_can_manage_scope('empresa_maquirenta.documentos')) json_response(['ok'=>false,'message'=>'No tiene permisos para agregar documentos.'],403);
    $name = trim((string)($_POST['nombre'] ?? ''));
    $segmentationType = (string) ($_POST['tipo_segmentacion'] ?? 'ninguna');
    if (!in_array($segmentationType, ['ninguna', 'numero', 'mes', 'codigo', 'texto'], true)) $segmentationType = 'ninguna';
    if ($name === '') json_response(['ok'=>false,'message'=>'Ingrese un documento.'],422);
    $exists=db()->prepare('SELECT id FROM empresa_maquirenta_documentos_catalogo WHERE LOWER(nombre)=LOWER(:nombre) LIMIT 1'); $exists->execute(['nombre'=>$name]);
    if ($exists->fetch()) json_response(['ok'=>false,'message'=>'Este documento ya existe.'],409);
    $stmt=db()->prepare('INSERT INTO empresa_maquirenta_documentos_catalogo (nombre,tipo_segmentacion,estado) VALUES (:nombre,:tipo,1)'); $stmt->execute(['nombre'=>$name,'tipo'=>$segmentationType]);
    json_response(['ok'=>true,'id'=>(int)db()->lastInsertId(),'text'=>$name,'tipo_segmentacion'=>$segmentationType]);
}

if ($action === 'catalog_delete') {
    $id=(int)($_POST['id'] ?? 0);
    if (!current_user_can_document('empresa_maquirenta.documentos',$id,'manage')) json_response(['ok'=>false,'message'=>'No tiene permisos para eliminar este documento.'],403);
    $used=db()->prepare('SELECT COUNT(*) FROM empresa_maquirenta_documentos WHERE documento_id=:id'); $used->execute(['id'=>$id]);
    if ((int)$used->fetchColumn()>0) json_response(['ok'=>false,'message'=>'No se puede eliminar porque este documento ya tiene registros asociados.'],409);
    db()->prepare('UPDATE empresa_maquirenta_documentos_catalogo SET estado=0 WHERE id=:id')->execute(['id'=>$id]);
    json_response(['ok'=>true,'message'=>'Documento eliminado del catálogo.']);
}

if ($action === 'upload_photo') {
    $companyId=(int)($_POST['empresa_maquirenta_id'] ?? 0);
    $stmt=db()->prepare('SELECT foto_path FROM empresas_maquirenta WHERE id=:id AND status=1'); $stmt->execute(['id'=>$companyId]); $current=$stmt->fetch();
    if (!$current) json_response(['ok'=>false,'message'=>'No se encontró la empresa Maquirenta.'],404);
    $photo=upload_file($_FILES['foto'] ?? [],'empresas_maquirenta',['image/jpeg','image/png','image/webp']);
    if (!$photo['path']) json_response(['ok'=>false,'message'=>'Seleccione una imagen.'],422);
    db()->prepare('UPDATE empresas_maquirenta SET foto_path=:path WHERE id=:id')->execute(['path'=>$photo['path'],'id'=>$companyId]);
    delete_uploaded_file($current['foto_path'] ?? null);
    json_response(['ok'=>true,'path'=>APP_URL.'/'.$photo['path']]);
}

if ($action === 'download') {
    $companyId=(int)($_GET['empresa_maquirenta_id'] ?? 0);
    $selectedIds=array_values(array_filter(array_map('intval',explode(',',(string)($_GET['ids'] ?? '')))));
    $sql="SELECT d.id,d.documento_id,d.segmento_etiqueta,d.periodo_anio,d.archivo_path,d.archivo_nombre_original,c.nombre AS documento,e.razon_social,e.ruc FROM empresa_maquirenta_documentos d JOIN empresa_maquirenta_documentos_catalogo c ON c.id=d.documento_id JOIN empresas_maquirenta e ON e.id=d.empresa_maquirenta_id WHERE d.empresa_maquirenta_id=:company_id AND d.archivo_path IS NOT NULL AND d.archivo_path<>''";
    $params=['company_id'=>$companyId];
    if ($selectedIds) { $holders=[]; foreach($selectedIds as $i=>$selectedId){$key='id'.$i;$holders[]=':'.$key;$params[$key]=$selectedId;} $sql.=' AND d.id IN ('.implode(',',$holders).')'; }
    $stmt=db()->prepare($sql.' ORDER BY c.nombre'); $stmt->execute($params);
    $files=[]; $uploadRoot=realpath(UPLOAD_PATH); $company=null;
    foreach($stmt->fetchAll() as $row){
        if (!current_user_can_document('empresa_maquirenta.documentos',(int)$row['documento_id'],'view')) continue;
        $fullPath=realpath(dirname(__DIR__,2).DIRECTORY_SEPARATOR.$row['archivo_path']);
        if(!$fullPath||!$uploadRoot||!str_starts_with($fullPath,$uploadRoot)||!is_file($fullPath)) continue;
        $original=(string)($row['archivo_nombre_original'] ?: basename($fullPath)); $extension=strtolower(pathinfo($original,PATHINFO_EXTENSION)) ?: 'pdf';
        $segment=maquirenta_segment_display($row); $downloadLabel=$row['documento'].($segment !== '' ? ' - '.$segment : '');
        $files[]=['path'=>$fullPath,'name'=>maquirenta_download_name($downloadLabel).'.'.$extension]; $company=$row;
    }
    if(!$files) json_response(['ok'=>false,'message'=>'No hay archivos subidos para los documentos seleccionados.'],404);
    $zipName=maquirenta_download_name($company['ruc'].'_'.$company['razon_social']).'_documentos.zip'; $zip=maquirenta_build_zip($files);
    header('Content-Type: application/zip'); header('Content-Disposition: attachment; filename="'.$zipName.'"'); header('Content-Length: '.strlen($zip)); echo $zip; exit;
}

json_response(['ok' => false, 'message' => 'Acción no válida.'], 400);

function maquirenta_download_name(string $value): string { $value=trim($value); if(function_exists('iconv')) $value=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value) ?: $value; return trim(preg_replace('/[^A-Za-z0-9._-]+/','_',$value) ?: 'documento','_') ?: 'documento'; }
function maquirenta_normalize_segment(string $type, string $value, int $year): array {
    $value = trim($value);
    if ($type === 'ninguna') return ['', '', 0];
    if ($type === 'numero') {
        if (!preg_match('/^[1-9][0-9]*$/', $value)) json_response(['ok'=>false,'message'=>'Ingrese un número correlativo válido.'],422);
        $normalized = (string) (int) $value;
        return [$normalized, $normalized, 0];
    }
    if ($type === 'mes') {
        $month = (int) $value;
        if ($month < 1 || $month > 12 || $year < 2000 || $year > 2100) json_response(['ok'=>false,'message'=>'Seleccione un mes y año válidos.'],422);
        $months = [1=>'Enero',2=>'Febrero',3=>'Marzo',4=>'Abril',5=>'Mayo',6=>'Junio',7=>'Julio',8=>'Agosto',9=>'Septiembre',10=>'Octubre',11=>'Noviembre',12=>'Diciembre'];
        return [str_pad((string)$month,2,'0',STR_PAD_LEFT),$months[$month],$year];
    }
    if ($value === '') json_response(['ok'=>false,'message'=>$type === 'codigo' ? 'Ingrese el código del documento.' : 'Ingrese la identificación del segmento.'],422);
    if (mb_strlen($value) > 80) json_response(['ok'=>false,'message'=>'La identificación no puede superar 80 caracteres.'],422);
    return [mb_strtoupper($value,'UTF-8'),$value,0];
}
function maquirenta_segment_display(array $row): string {
    $label = trim((string)($row['segmento_etiqueta'] ?? ''));
    $year = (int)($row['periodo_anio'] ?? 0);
    return $label . ($label !== '' && $year > 0 ? ' ' . $year : '');
}
function maquirenta_build_zip(array $files): string {
    $data='';$central='';$offset=0;$used=[];
    foreach($files as $file){$name=$file['name'];$base=pathinfo($name,PATHINFO_FILENAME);$ext=pathinfo($name,PATHINFO_EXTENSION);$n=2;while(isset($used[$name])){$name=$base.'_'.$n++.($ext?'.'.$ext:'');}$used[$name]=true;$content=file_get_contents($file['path']);if($content===false)continue;$crc=crc32($content);$size=strlen($content);$p=getdate((int)filemtime($file['path']));$time=($p['hours']<<11)|($p['minutes']<<5)|((int)($p['seconds']/2));$date=(($p['year']-1980)<<9)|($p['mon']<<5)|$p['mday'];$local=pack('VvvvvvVVVvv',0x04034b50,20,0,0,$time,$date,$crc,$size,$size,strlen($name),0).$name;$data.=$local.$content;$central.=pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0,0,$time,$date,$crc,$size,$size,strlen($name),0,0,0,0,32,$offset).$name;$offset+=strlen($local)+$size;}
    return $data.$central.pack('VvvvvVVv',0x06054b50,0,0,count($used),count($used),strlen($central),strlen($data),0);
}
