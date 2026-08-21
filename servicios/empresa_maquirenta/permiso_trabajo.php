<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../includes/upload.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('empresa_maquirenta.permiso_trabajo');

$action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');
if (in_array($action, ['save', 'upload', 'extend', 'close', 'delete'], true)) verify_csrf($_POST['csrf_token'] ?? null);

$allowedMimes = array_merge(document_attachment_mimes(), [
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/octet-stream',
]);

$validDate = static fn(string $value): bool => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $value);
$statusSql = "CASE WHEN v.fecha_cierre IS NOT NULL AND EXISTS(SELECT 1 FROM empresa_maquirenta_permiso_archivos ax WHERE ax.vigencia_id=v.id) THEN 'cerrado' WHEN CURDATE() BETWEEN v.fecha_inicio AND v.fecha_vencimiento THEN 'vigente' ELSE 'no_apto' END";

if ($action === 'list') {
    $search = mb_substr(trim((string) ($_GET['search'] ?? '')), 0, 100);
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $allowedPerPage = [10, 20, 50];
    $perPage = (int) ($_GET['per_page'] ?? 10);
    if (!in_array($perPage, $allowedPerPage, true)) $perPage = 10;
    $where = '';
    $params = [];
    if ($search !== '') {
        $where = ' WHERE p.permiso_nombre LIKE :search';
        $params['search'] = '%' . $search . '%';
    }
    $countStmt = db()->prepare('SELECT COUNT(*) FROM empresa_maquirenta_permisos_trabajo p' . $where);
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;
    $sql = "SELECT p.id,p.permiso_nombre,p.fecha_registro,p.registered_by_user_id,
        v.id AS vigencia_id,v.fecha_inicio,v.fecha_vencimiento,v.fecha_cierre,v.observaciones,v.created_at AS vigencia_created_at,
        COALESCE(u.name,'') AS registered_by,
        (SELECT COUNT(*) FROM empresa_maquirenta_permiso_archivos a WHERE a.vigencia_id=v.id) AS archivo_count,
        (SELECT COUNT(*) FROM empresa_maquirenta_permiso_archivos ad WHERE ad.vigencia_id=v.id) AS documento_count,
        $statusSql AS estado,
        (SELECT COUNT(*) FROM empresa_maquirenta_permiso_vigencias vh WHERE vh.permiso_trabajo_id=p.id) AS vigencia_count,
        (SELECT COUNT(*) FROM empresa_maquirenta_permiso_archivos ta JOIN empresa_maquirenta_permiso_vigencias tv ON tv.id=ta.vigencia_id WHERE tv.permiso_trabajo_id=p.id) AS total_documentos
      FROM empresa_maquirenta_permisos_trabajo p
      JOIN empresa_maquirenta_permiso_vigencias v ON v.id=(
          SELECT v2.id FROM empresa_maquirenta_permiso_vigencias v2
          WHERE v2.permiso_trabajo_id=p.id ORDER BY v2.fecha_vencimiento DESC,v2.id DESC LIMIT 1
      )
      LEFT JOIN users u ON u.id=p.registered_by_user_id
      $where
      ORDER BY p.fecha_registro DESC,p.id DESC
      LIMIT :limit OFFSET :offset";
    $stmt = db()->prepare($sql);
    if ($search !== '') $stmt->bindValue(':search', $params['search'], PDO::PARAM_STR);
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    json_response(['ok'=>true,'rows'=>$stmt->fetchAll(),'pagination'=>[
        'page'=>$page,'per_page'=>$perPage,'total'=>$total,'total_pages'=>$totalPages,
        'from'=>$total>0?$offset+1:0,'to'=>min($offset+$perPage,$total)
    ]]);
}
if ($action === 'history') {
    $permitId=(int)($_GET['id']??0);
    $stmt=db()->prepare("SELECT v.id,v.fecha_inicio,v.fecha_vencimiento,v.fecha_cierre,v.observaciones,v.created_at,p.fecha_registro AS permiso_fecha_registro,
        COALESCE(u.name,'') AS registered_by,
        (SELECT COUNT(*) FROM empresa_maquirenta_permiso_archivos a WHERE a.vigencia_id=v.id) AS archivo_count,
        (SELECT COUNT(*) FROM empresa_maquirenta_permiso_archivos ad WHERE ad.vigencia_id=v.id) AS documento_count,
        $statusSql AS estado
      FROM empresa_maquirenta_permiso_vigencias v
      JOIN empresa_maquirenta_permisos_trabajo p ON p.id=v.permiso_trabajo_id
      LEFT JOIN users u ON u.id=v.registered_by_user_id
      WHERE v.permiso_trabajo_id=:id ORDER BY v.fecha_vencimiento DESC,v.id DESC");
    $stmt->execute(['id'=>$permitId]);
    $vigencias=$stmt->fetchAll();
    $fileStmt=db()->prepare("SELECT a.id,a.vigencia_id,a.archivo_nombre_original,a.tipo_archivo,a.created_at,COALESCE(u.name,'') uploaded_by FROM empresa_maquirenta_permiso_archivos a LEFT JOIN users u ON u.id=a.uploaded_by_user_id JOIN empresa_maquirenta_permiso_vigencias v ON v.id=a.vigencia_id WHERE v.permiso_trabajo_id=:id ORDER BY a.created_at,a.id");
    $fileStmt->execute(['id'=>$permitId]);
    $filesByVigencia=[];
    foreach($fileStmt->fetchAll() as $file)$filesByVigencia[(int)$file['vigencia_id']][]=$file;
    foreach($vigencias as &$vigencia)$vigencia['archivos']=$filesByVigencia[(int)$vigencia['id']]??[];
    unset($vigencia);
    json_response(['ok'=>true,'vigencias'=>$vigencias]);
}

if ($action === 'save') {
    $id=(int)($_POST['id']??0);
    $name=trim((string)($_POST['permiso_nombre']??''));
    $registration=(string)($_POST['fecha_registro']??'');
    $start=(string)($_POST['fecha_inicio']??'');
    $end=(string)($_POST['fecha_vencimiento']??'');
    if($name==='')json_response(['ok'=>false,'message'=>'Ingrese el nombre del permiso de trabajo.'],422);
    if(!$validDate($registration)||!$validDate($start)||!$validDate($end))json_response(['ok'=>false,'message'=>'Complete correctamente las fechas.'],422);
    if($start>$end)json_response(['ok'=>false,'message'=>'La fecha de inicio no puede ser posterior al vencimiento.'],422);

    $uploaded=[];
    try{
        $uploaded=permit_upload_files($_FILES['adjuntos']??[],2,$allowedMimes);
        $pdo=db();$pdo->beginTransaction();
        if($id>0){
            $latest=permit_latest_vigencia($id,true);
            if(!$latest)throw new RuntimeException('No se encontró el permiso de trabajo.');
            if((int)$latest['archivo_count']>0)throw new DomainException('La vigencia está cerrada. Use la opción Ampliar.');
            $pdo->prepare('UPDATE empresa_maquirenta_permisos_trabajo SET permiso_nombre=:name,fecha_registro=:registration,fecha_inicio=:start,fecha_vencimiento=:end,observaciones=:obs WHERE id=:id')->execute(['name'=>$name,'registration'=>$registration,'start'=>$start,'end'=>$end,'obs'=>trim((string)($_POST['observaciones']??'')),'id'=>$id]);
            $pdo->prepare('UPDATE empresa_maquirenta_permiso_vigencias SET fecha_inicio=:start,fecha_vencimiento=:end,observaciones=:obs WHERE id=:id')->execute(['start'=>$start,'end'=>$end,'obs'=>trim((string)($_POST['observaciones']??'')),'id'=>(int)$latest['id']]);
            permit_store_files((int)$latest['id'],$uploaded);
        }else{
            $first=$uploaded[0]??null;
            $pdo->prepare('INSERT INTO empresa_maquirenta_permisos_trabajo(permiso_nombre,fecha_registro,fecha_inicio,fecha_vencimiento,observaciones,archivo_path,archivo_nombre_original,registered_by_user_id) VALUES(:name,:registration,:start,:end,:obs,:path,:original,:user)')->execute(['name'=>$name,'registration'=>$registration,'start'=>$start,'end'=>$end,'obs'=>trim((string)($_POST['observaciones']??'')),'path'=>$first['path']??null,'original'=>$first['name']??null,'user'=>(int)(current_user()['id']??0)?:null]);
            $permitId=(int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO empresa_maquirenta_permiso_vigencias(permiso_trabajo_id,fecha_inicio,fecha_vencimiento,observaciones,registered_by_user_id) VALUES(:permit,:start,:end,:obs,:user)')->execute(['permit'=>$permitId,'start'=>$start,'end'=>$end,'obs'=>trim((string)($_POST['observaciones']??'')),'user'=>(int)(current_user()['id']??0)?:null]);
            permit_store_files((int)$pdo->lastInsertId(),$uploaded);
        }
        $pdo->commit();json_response(['ok'=>true]);
    }catch(DomainException $error){if(db()->inTransaction())db()->rollBack();permit_delete_new_files($uploaded);json_response(['ok'=>false,'message'=>$error->getMessage()],409);
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();permit_delete_new_files($uploaded);json_response(['ok'=>false,'message'=>'No se pudo guardar el permiso de trabajo.'],400);}
}

if ($action === 'upload') {
    $permitId=(int)($_POST['id']??0);
    $current=permit_latest_vigencia($permitId,true);
    if(!$current)json_response(['ok'=>false,'message'=>'No se encontró el permiso de trabajo.'],404);
    if(!empty($current['fecha_cierre']))json_response(['ok'=>false,'message'=>'No se pueden agregar documentos a una vigencia cerrada.'],409);
    $limit=(int)$current['vigencia_count']===1?2:3;
    $remaining=$limit-(int)$current['documento_count'];
    if($remaining<=0)json_response(['ok'=>false,'message'=>'Esta vigencia ya alcanzó el máximo de documentos.'],409);
    $uploaded=[];
    try{
        $uploaded=permit_upload_files($_FILES['adjuntos']??[],$remaining,$allowedMimes,true);
        $newTotal=(int)$current['documento_count']+count($uploaded);
        $mustClose=$newTotal>=$limit;
        $wantsClose=$mustClose||((int)($_POST['cerrar']??0)===1);
        $closeDate=(string)($_POST['fecha_cierre']??'');
        if($wantsClose){
            if(!$validDate($closeDate))throw new InvalidArgumentException('Seleccione la fecha de cierre.');
            if($closeDate<$current['fecha_inicio'])throw new InvalidArgumentException('La fecha de cierre no puede ser anterior al inicio de la vigencia.');
            if($closeDate>date('Y-m-d'))throw new InvalidArgumentException('La fecha de cierre no puede ser futura.');
        }
        $pdo=db();$pdo->beginTransaction();
        permit_store_files((int)$current['id'],$uploaded,'vigencia');
        if($wantsClose)$pdo->prepare('UPDATE empresa_maquirenta_permiso_vigencias SET fecha_cierre=:date,closed_by_user_id=:user WHERE id=:id')->execute(['date'=>$closeDate,'user'=>(int)(current_user()['id']??0)?:null,'id'=>(int)$current['id']]);
        $pdo->commit();json_response(['ok'=>true,'closed'=>$wantsClose,'forced'=>$mustClose]);
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();permit_delete_new_files($uploaded);json_response(['ok'=>false,'message'=>$error->getMessage()?:'No se pudieron cargar los documentos.'],422);}
}
if ($action === 'extend') {
    $permitId=(int)($_POST['id']??0);$newEnd=(string)($_POST['fecha_vencimiento']??'');
    if(!$validDate($newEnd))json_response(['ok'=>false,'message'=>'Seleccione la nueva fecha de vencimiento.'],422);
    $current=permit_latest_vigencia($permitId,true);
    if(!$current)json_response(['ok'=>false,'message'=>'No se encontró el permiso de trabajo.'],404);
    if(!empty($current['fecha_cierre']))json_response(['ok'=>false,'message'=>'Un permiso cerrado ya no puede ampliarse.'],409);
    if($newEnd<=$current['fecha_vencimiento'])json_response(['ok'=>false,'message'=>'La nueva fecha debe ser posterior al vencimiento actual.'],422);
    $newStart=date('Y-m-d',strtotime((string)$current['fecha_vencimiento'].' +1 day'));
    $uploaded=[];
    try{
        $uploaded=permit_upload_files($_FILES['adjuntos']??[],3,$allowedMimes);
        $pdo=db();$pdo->beginTransaction();
        $pdo->prepare('INSERT INTO empresa_maquirenta_permiso_vigencias(permiso_trabajo_id,fecha_inicio,fecha_vencimiento,observaciones,registered_by_user_id) VALUES(:permit,:start,:end,:obs,:user)')->execute(['permit'=>$permitId,'start'=>$newStart,'end'=>$newEnd,'obs'=>trim((string)($_POST['observaciones']??'')),'user'=>(int)(current_user()['id']??0)?:null]);
        $vigenciaId=(int)$pdo->lastInsertId();permit_store_files($vigenciaId,$uploaded);
        $pdo->prepare('UPDATE empresa_maquirenta_permisos_trabajo SET fecha_vencimiento=:end WHERE id=:id')->execute(['end'=>$newEnd,'id'=>$permitId]);
        $pdo->commit();json_response(['ok'=>true]);
    }catch(PDOException $error){if(db()->inTransaction())db()->rollBack();permit_delete_new_files($uploaded);json_response(['ok'=>false,'message'=>'Ya existe una vigencia con esta fecha de vencimiento.'],409);
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();permit_delete_new_files($uploaded);json_response(['ok'=>false,'message'=>'No se pudo ampliar la vigencia.'],400);}
}

if ($action === 'close') {
    $permitId=(int)($_POST['id']??0);
    $closeDate=(string)($_POST['fecha_cierre']??'');
    $current=permit_latest_vigencia($permitId,true);
    if(!$current)json_response(['ok'=>false,'message'=>'No se encontró el permiso de trabajo.'],404);
    if(!empty($current['fecha_cierre']))json_response(['ok'=>false,'message'=>'Esta vigencia ya se encuentra cerrada.'],409);
    if(!$validDate($closeDate))json_response(['ok'=>false,'message'=>'Seleccione la fecha de cierre.'],422);
    if($closeDate<$current['fecha_inicio'])json_response(['ok'=>false,'message'=>'La fecha de cierre no puede ser anterior al inicio de la vigencia.'],422);
    if($closeDate>date('Y-m-d'))json_response(['ok'=>false,'message'=>'La fecha de cierre no puede ser futura.'],422);
    $limit=(int)$current['vigencia_count']===1?2:3;
    if((int)$current['documento_count']>=$limit)json_response(['ok'=>false,'message'=>'Se alcanzó el máximo de documentos; el cierre debe confirmarse durante la última carga.'],409);
    $uploaded=[];
    try{
        $uploaded=permit_upload_files($_FILES['adjuntos']??[],1,$allowedMimes,true);
        $pdo=db();$pdo->beginTransaction();
        permit_store_files((int)$current['id'],$uploaded,'cierre');
        $pdo->prepare('UPDATE empresa_maquirenta_permiso_vigencias SET fecha_cierre=:date,closed_by_user_id=:user WHERE id=:id')->execute(['date'=>$closeDate,'user'=>(int)(current_user()['id']??0)?:null,'id'=>(int)$current['id']]);
        $pdo->commit();json_response(['ok'=>true]);
    }catch(Throwable $error){if(db()->inTransaction())db()->rollBack();permit_delete_new_files($uploaded);json_response(['ok'=>false,'message'=>$error->getMessage()?:'No se pudo cerrar la vigencia.'],422);}
}
if ($action === 'delete') {
    $permitId=(int)($_POST['id']??0);
    $stmt=db()->prepare('SELECT a.archivo_path FROM empresa_maquirenta_permiso_archivos a JOIN empresa_maquirenta_permiso_vigencias v ON v.id=a.vigencia_id WHERE v.permiso_trabajo_id=:id UNION SELECT archivo_path FROM empresa_maquirenta_permisos_trabajo WHERE id=:id2 AND archivo_path IS NOT NULL');
    $stmt->execute(['id'=>$permitId,'id2'=>$permitId]);$paths=$stmt->fetchAll(PDO::FETCH_COLUMN);
    $deleted=db()->prepare('DELETE FROM empresa_maquirenta_permisos_trabajo WHERE id=:id');$deleted->execute(['id'=>$permitId]);
    if(!$deleted->rowCount())json_response(['ok'=>false,'message'=>'No se encontró el permiso de trabajo.'],404);
    foreach(array_unique($paths) as $path)delete_uploaded_file($path?:null);json_response(['ok'=>true]);
}

if ($action === 'file') {
    $stmt=db()->prepare('SELECT archivo_path,archivo_nombre_original FROM empresa_maquirenta_permiso_archivos WHERE id=:id');$stmt->execute(['id'=>(int)($_GET['id']??0)]);$row=$stmt->fetch();
    $root=realpath(UPLOAD_PATH);$path=$row?realpath(dirname(__DIR__,2).DIRECTORY_SEPARATOR.$row['archivo_path']):false;
    if(!$path||!$root||!str_starts_with($path,$root)||!is_file($path)){http_response_code(404);exit('Archivo no encontrado.');}
    header('Content-Type: '.(mime_content_type($path)?:'application/octet-stream'));header('Content-Disposition: attachment; filename="'.basename((string)($row['archivo_nombre_original']?:$path)).'"');readfile($path);exit;
}

json_response(['ok'=>false,'message'=>'Acción no válida.'],400);

function permit_latest_vigencia(int $permitId,bool $withCounts=false):array|false{
    $sql="SELECT v.*,(SELECT COUNT(*) FROM empresa_maquirenta_permiso_archivos a WHERE a.vigencia_id=v.id) archivo_count,(SELECT COUNT(*) FROM empresa_maquirenta_permiso_archivos ad WHERE ad.vigencia_id=v.id) documento_count,(SELECT COUNT(*) FROM empresa_maquirenta_permiso_vigencias vx WHERE vx.permiso_trabajo_id=v.permiso_trabajo_id) vigencia_count FROM empresa_maquirenta_permiso_vigencias v WHERE v.permiso_trabajo_id=:id ORDER BY v.fecha_vencimiento DESC,v.id DESC LIMIT 1";
    $stmt=db()->prepare($sql);$stmt->execute(['id'=>$permitId]);return$stmt->fetch();
}
function permit_upload_files(array $input,int $limit,array $mimes,bool $required=false):array{
    if(!isset($input['name'])||!is_array($input['name'])){if($required)throw new InvalidArgumentException('Seleccione al menos un archivo para cerrar la vigencia.');return[];}
    $indexes=[];foreach($input['name'] as $i=>$name)if(($input['error'][$i]??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE)$indexes[]=$i;
    if($required&&!$indexes)throw new InvalidArgumentException('Seleccione al menos un archivo para cerrar la vigencia.');
    if(count($indexes)>$limit)throw new InvalidArgumentException("Puede subir como máximo $limit archivos en esta vigencia.");
    $uploaded=[];try{foreach($indexes as $i)$uploaded[]=upload_file(['name'=>$input['name'][$i],'type'=>$input['type'][$i]??'','tmp_name'=>$input['tmp_name'][$i]??'','error'=>$input['error'][$i]??UPLOAD_ERR_NO_FILE,'size'=>$input['size'][$i]??0],'empresa_maquirenta_permisos_trabajo',$mimes);return$uploaded;}catch(Throwable $e){permit_delete_new_files($uploaded);throw$e;}
}
function permit_store_files(int $vigenciaId,array $files,string $type='vigencia'):void{
    if(!$files)return;$stmt=db()->prepare('INSERT INTO empresa_maquirenta_permiso_archivos(vigencia_id,archivo_path,archivo_nombre_original,tipo_archivo,uploaded_by_user_id) VALUES(:vigencia,:path,:name,:type,:user)');foreach($files as $file)$stmt->execute(['vigencia'=>$vigenciaId,'path'=>$file['path'],'name'=>$file['name'],'type'=>$type,'user'=>(int)(current_user()['id']??0)?:null]);
}
function permit_delete_new_files(array $files):void{foreach($files as $file)delete_uploaded_file($file['path']??null);}