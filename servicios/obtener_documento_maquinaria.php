<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../config/database.php';
require_login();
$id=(int)($_GET['id']??0);
$stmt=db()->prepare("SELECT md.*,mdc.nombre documento,ob.name observation_by,ob.role observation_by_role,rb.name observation_resolved_by,reg.role registered_by_role FROM maquinaria_documentos md JOIN maquinaria_documentos_catalogo mdc ON mdc.id=md.documento_id LEFT JOIN users ob ON ob.id=md.observation_by_user_id LEFT JOIN users rb ON rb.id=md.observation_resolved_by_user_id LEFT JOIN users reg ON reg.id=md.registered_by_user_id WHERE md.id=:id");$stmt->execute(['id'=>$id]);$row=$stmt->fetch();if(!$row)json_response(['ok'=>false],404);
$log=db()->prepare("SELECT l.action_type,l.description,l.created_at,u.name user_name,u.role user_role FROM maquinaria_documento_activity_log l LEFT JOIN users u ON u.id=l.user_id WHERE l.maquinaria_documento_id=:id ORDER BY l.created_at DESC,l.id DESC");$log->execute(['id'=>$id]);
$registeredAdmin=in_array(mb_strtolower((string)($row['registered_by_role']??''),'UTF-8'),['admin','administrador'],true);$canObserve=is_admin()||(is_gestor_role()&&!$registeredAdmin&&current_user_can_document('maquinaria.documentos',(int)$row['documento_id'],'upload'));
$canEdit=current_user_can_document('maquinaria.documentos',(int)$row['documento_id'],'upload');json_response(['ok'=>true,'row'=>$row,'activity'=>$log->fetchAll(),'can_observe'=>$canObserve,'can_edit'=>$canEdit]);
