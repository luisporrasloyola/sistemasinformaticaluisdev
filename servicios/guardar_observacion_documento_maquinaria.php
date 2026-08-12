<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_login();verify_csrf($_POST['csrf_token']??null);
$id=(int)($_POST['id']??0);$text=trim((string)($_POST['observation']??''));
if($id<=0||$text==='')json_response(['ok'=>false,'message'=>'Escriba la observación.'],422);
if(mb_strlen($text)>3000)json_response(['ok'=>false,'message'=>'La observación no puede superar 3000 caracteres.'],422);
$pdo=db();$stmt=$pdo->prepare("SELECT md.documento_id,md.registered_by_user_id,u.role registered_role FROM maquinaria_documentos md LEFT JOIN users u ON u.id=md.registered_by_user_id WHERE md.id=:id");$stmt->execute(['id'=>$id]);$row=$stmt->fetch();
if(!$row)json_response(['ok'=>false,'message'=>'El documento no existe.'],404);
$registeredAdmin=in_array(mb_strtolower((string)$row['registered_role'],'UTF-8'),['admin','administrador'],true);
if(!is_admin()&&($registeredAdmin||!current_user_can_document('maquinaria.documentos',(int)$row['documento_id'],'upload')))json_response(['ok'=>false,'message'=>'No tiene permisos para observar este documento.'],403);
$uid=(int)(current_user()['id']??0)?:null;
try{$pdo->beginTransaction();$pdo->prepare("UPDATE maquinaria_documentos SET review_observation=:text,observation_status='observed',observation_by_user_id=:uid,observation_at=NOW(),observation_resolved_by_user_id=NULL,observation_resolved_at=NULL WHERE id=:id")->execute(['text'=>$text,'uid'=>$uid,'id'=>$id]);$pdo->prepare("INSERT INTO maquinaria_documento_activity_log(maquinaria_documento_id,user_id,action_type,description) VALUES(:id,:uid,'observacion_registrada',:text)")->execute(['id'=>$id,'uid'=>$uid,'text'=>$text]);$pdo->commit();json_response(['ok'=>true,'message'=>'Observación registrada.']);}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();json_response(['ok'=>false,'message'=>'No se pudo registrar la observación.'],500);}
