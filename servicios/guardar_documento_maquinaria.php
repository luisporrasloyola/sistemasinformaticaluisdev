<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../config/database.php';
require_login();
verify_csrf($_POST['csrf_token'] ?? null);
$id=(int)($_POST['id']??0);$maquinariaId=(int)($_POST['maquinaria_id']??0);$documentoId=(int)($_POST['documento_id']??0);
$fechaRegistro=(string)($_POST['fecha_registro']??'');$fechaInicio=(string)($_POST['fecha_inicio']??'');$fechaFin=(string)($_POST['fecha_fin']??'');$observation=trim((string)($_POST['observaciones']??''));
if(!$maquinariaId||!$documentoId||!$fechaRegistro||!$fechaInicio||!$fechaFin)json_response(['ok'=>false,'message'=>'Complete todos los campos obligatorios.'],400);
if(!current_user_can_document('maquinaria.documentos',$documentoId,'upload'))json_response(['ok'=>false,'message'=>'No tiene permisos para guardar este documento.'],403);
if(strtotime($fechaFin)<strtotime($fechaInicio))json_response(['ok'=>false,'message'=>'La fecha fin no puede ser menor a la fecha inicio.'],400);
$pdo=db();$userId=(int)(current_user()['id']??0)?:null;
try{
 $pdf=upload_file($_FILES['pdf']??[],'maquinaria_documentos',document_attachment_mimes());$pdo->beginTransaction();
 if($id>0){
  $currentStmt=$pdo->prepare('SELECT archivo_path,observaciones FROM maquinaria_documentos WHERE id=:id FOR UPDATE');$currentStmt->execute(['id'=>$id]);$current=$currentStmt->fetch();if(!$current){$pdo->rollBack();json_response(['ok'=>false,'message'=>'No se encontró el documento.'],404);}
  $sql='UPDATE maquinaria_documentos SET documento_id=:documento_id,fecha_registro=:fecha_registro,fecha_inicio=:fecha_inicio,fecha_fin=:fecha_fin,observaciones=:observaciones';
  $params=['documento_id'=>$documentoId,'fecha_registro'=>$fechaRegistro,'fecha_inicio'=>$fechaInicio,'fecha_fin'=>$fechaFin,'observaciones'=>$observation!==''?$observation:(string)($current['observaciones']??''),'id'=>$id];
  if($pdf['path']){$sql.=',archivo_path=:archivo_path,archivo_nombre_original=:archivo_nombre_original';$params['archivo_path']=$pdf['path'];$params['archivo_nombre_original']=$pdf['name'];}
  $sql.=' WHERE id=:id';$pdo->prepare($sql)->execute($params);
  if($observation!==''&&$observation!==trim((string)($current['observaciones']??'')))register_machine_form_observation($pdo,$id,$observation,$userId);
  if($pdf['path'])delete_uploaded_file($current['archivo_path']??null);
 }else{
  $status=$observation!==''?'observed':'none';
  $stmt=$pdo->prepare('INSERT INTO maquinaria_documentos(maquinaria_id,documento_id,fecha_registro,fecha_inicio,fecha_fin,observaciones,review_observation,observation_status,observation_by_user_id,observation_at,archivo_path,archivo_nombre_original,registered_by_user_id) VALUES(:maquinaria_id,:documento_id,:fecha_registro,:fecha_inicio,:fecha_fin,:observaciones,:review_observation,:observation_status,:observation_by_user_id,:observation_at,:archivo_path,:archivo_nombre_original,:registered_by_user_id)');
  $stmt->execute(['maquinaria_id'=>$maquinariaId,'documento_id'=>$documentoId,'fecha_registro'=>$fechaRegistro,'fecha_inicio'=>$fechaInicio,'fecha_fin'=>$fechaFin,'observaciones'=>$observation,'review_observation'=>$observation!==''?$observation:null,'observation_status'=>$status,'observation_by_user_id'=>$observation!==''?$userId:null,'observation_at'=>$observation!==''?date('Y-m-d H:i:s'):null,'archivo_path'=>$pdf['path'],'archivo_nombre_original'=>$pdf['name'],'registered_by_user_id'=>$userId]);
  $id=(int)$pdo->lastInsertId();if($observation!=='')insert_machine_observation_history($pdo,$id,$observation,$userId);
 }
 $pdo->commit();json_response(['ok'=>true]);
}catch(PDOException $e){if($pdo->inTransaction())$pdo->rollBack();if($e->getCode()==='23000')json_response(['ok'=>false,'message'=>'Este documento ya existe para la maquinaria seleccionada.'],409);json_response(['ok'=>false,'message'=>'No se pudo guardar el documento.'],400);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>$e->getMessage()],400);}
function register_machine_form_observation(PDO $pdo,int $id,string $text,?int $userId):void{$pdo->prepare("UPDATE maquinaria_documentos SET review_observation=:text,observation_status='observed',observation_by_user_id=:uid,observation_at=NOW(),observation_resolved_by_user_id=NULL,observation_resolved_at=NULL WHERE id=:id")->execute(['text'=>$text,'uid'=>$userId,'id'=>$id]);insert_machine_observation_history($pdo,$id,$text,$userId);}
function insert_machine_observation_history(PDO $pdo,int $id,string $text,?int $userId):void{$pdo->prepare("INSERT INTO maquinaria_documento_activity_log(maquinaria_documento_id,user_id,action_type,description) VALUES(:id,:uid,'observacion_registrada',:text)")->execute(['id'=>$id,'uid'=>$userId,'text'=>$text]);}
