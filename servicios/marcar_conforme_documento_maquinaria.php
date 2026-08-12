<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../config/database.php';
require_role('Administrador');verify_csrf($_POST['csrf_token']??null);
$id=(int)($_POST['id']??0);if($id<=0)json_response(['ok'=>false,'message'=>'Registro no válido.'],400);
$uid=(int)(current_user()['id']??0)?:null;$name=(string)(current_user()['name']??'Administrador');$pdo=db();
$stmt=$pdo->prepare("UPDATE maquinaria_documentos SET observation_status='approved',observation_resolved_by_user_id=:uid,observation_resolved_at=NOW() WHERE id=:id");$stmt->execute(['uid'=>$uid,'id'=>$id]);
if(!$stmt->rowCount())json_response(['ok'=>false,'message'=>'El documento no existe.'],404);
$pdo->prepare("INSERT INTO maquinaria_documento_activity_log(maquinaria_documento_id,user_id,action_type,description) VALUES(:id,:uid,'conformidad',:text)")->execute(['id'=>$id,'uid'=>$uid,'text'=>'Conformidad registrada por '.$name.'.']);
json_response(['ok'=>true]);
