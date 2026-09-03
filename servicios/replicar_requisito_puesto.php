<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_role('Administrador');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_response(['ok'=>false,'message'=>'Método no permitido.'],405);
verify_csrf($_POST['csrf_token'] ?? null);
$id=(int)($_POST['id']??0);
$positionIds=array_values(array_unique(array_filter(array_map('intval',$_POST['position_ids']??[]),static fn(int $v):bool=>$v>0)));
if($id<=0||!$positionIds) json_response(['ok'=>false,'message'=>'Seleccione al menos un puesto de destino.'],422);
$pdo=db();
$createdFiles=[];
try{
 $pdo->beginTransaction();
 $stmt=$pdo->prepare('SELECT * FROM worker_requirements WHERE id=? FOR UPDATE');$stmt->execute([$id]);$source=$stmt->fetch();
 if(!$source) throw new RuntimeException('El registro original ya no existe.');
 $marks=implode(',',array_fill(0,count($positionIds),'?'));
 $params=array_merge([(int)$source['worker_id'],(int)$source['position_id']],$positionIds);
 $stmt=$pdo->prepare("SELECT p.id,p.name FROM worker_positions wp JOIN positions p ON p.id=wp.position_id WHERE wp.worker_id=? AND p.id<>? AND p.id IN ($marks)");$stmt->execute($params);$targets=$stmt->fetchAll();
 if(count($targets)!==count($positionIds)) throw new RuntimeException('Uno de los puestos seleccionados no pertenece al trabajador.');
 $created=0;$skipped=[];
 foreach($targets as $target){
  $check=$pdo->prepare('SELECT id FROM worker_requirements WHERE worker_id=? AND position_id=? AND requirement_id=?');$check->execute([(int)$source['worker_id'],(int)$target['id'],(int)$source['requirement_id']]);
  if($check->fetchColumn()){ $skipped[]=$target['name']; continue; }
  $newPath=null;$newOriginal=$source['original_file_name'];
  if(!empty($source['file_path'])){
   $storageRelative=preg_replace('#^archivos[/\\\\]#','',(string)$source['file_path']);
   $absolute=UPLOAD_PATH.DIRECTORY_SEPARATOR.str_replace(['/', '\\'],DIRECTORY_SEPARATOR,$storageRelative);
   if(is_file($absolute)){
    $relativeDir=dirname($storageRelative);$extension=pathinfo($absolute,PATHINFO_EXTENSION);$newName=bin2hex(random_bytes(16)).($extension!==''?'.'.$extension:'');
    $targetRelative=($relativeDir==='.'?'':trim(str_replace('\\','/',$relativeDir),'/').'/').$newName;$targetAbsolute=UPLOAD_PATH.DIRECTORY_SEPARATOR.str_replace('/',DIRECTORY_SEPARATOR,$targetRelative);
    if(!is_dir(dirname($targetAbsolute))) mkdir(dirname($targetAbsolute),0775,true);
    if(!copy($absolute,$targetAbsolute)) throw new RuntimeException('No se pudo duplicar el archivo adjunto.');
    $newPath='archivos/'.$targetRelative; $createdFiles[]=$newPath;
   }
  }
  $insert=$pdo->prepare('INSERT INTO worker_requirements (worker_id,position_id,requirement_id,registration_date,start_date,end_date,observations,observation_status,observation_by_user_id,observation_at,observation_resolved_by_user_id,observation_resolved_at,file_path,original_file_name,registered_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $insert->execute([(int)$source['worker_id'],(int)$target['id'],(int)$source['requirement_id'],$source['registration_date'],$source['start_date'],$source['end_date'],$source['observations'],$source['observation_status'],$source['observation_by_user_id'],$source['observation_at'],$source['observation_resolved_by_user_id'],$source['observation_resolved_at'],$newPath,$newOriginal,current_user()['id']??null]);
  $newId=(int)$pdo->lastInsertId();
  $log=$pdo->prepare('INSERT INTO worker_requirement_activity_log (worker_requirement_id,user_id,action_type,description) VALUES (?,?,?,?)');
  $log->execute([$newId,current_user()['id']??null,'requisito_replicado','Replicó el requisito desde el puesto original hacia '.$target['name'].'.']);
  $created++;
 }
 if($created===0) throw new RuntimeException('El requisito ya está registrado en todos los puestos seleccionados.');
 $pdo->commit();
 json_response(['ok'=>true,'message'=>'Registro replicado correctamente.','created'=>$created,'skipped'=>$skipped]);
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();foreach($createdFiles as $createdFile)delete_uploaded_file($createdFile);json_response(['ok'=>false,'message'=>$e->getMessage()],400);}