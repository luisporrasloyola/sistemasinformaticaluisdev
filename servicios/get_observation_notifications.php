<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/security.php';
require_once __DIR__.'/../config/database.php';
require_login();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');header('Pragma: no-cache');
$userId=(int)(current_user()['id']??0);$notifications=[];
if($userId<=0)json_response(['ok'=>true,'unread_count'=>0,'notifications'=>[]]);
$columnExists=static function(string $table,string $column):bool{$s=db()->prepare('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=:t AND COLUMN_NAME=:c LIMIT 1');$s->execute(['t'=>$table,'c'=>$column]);return(bool)$s->fetchColumn();};
try{
 if($columnExists('worker_requirements','observation_status')){
  $s=db()->prepare("SELECT wr.id,wr.observations,wr.observation_status,wr.observation_at,wr.created_at,wr.updated_at,w.full_name,rc.name requirement_name,ob.name observed_by,reg.name registered_by FROM worker_requirements wr JOIN workers w ON w.id=wr.worker_id JOIN requirements_catalog rc ON rc.id=wr.requirement_id LEFT JOIN users ob ON ob.id=wr.observation_by_user_id LEFT JOIN users reg ON reg.id=wr.registered_by_user_id WHERE ((wr.observation_status='observed' OR (COALESCE(wr.observation_status,'none')='none' AND TRIM(COALESCE(wr.observations,''))<>'')) AND (wr.registered_by_user_id=:uid OR :admin_view=1)) OR (wr.observation_status='corrected' AND wr.observation_by_user_id=:reviewer) ORDER BY COALESCE(wr.observation_at,wr.updated_at,wr.created_at) DESC LIMIT 30");$s->execute(['uid'=>$userId,'reviewer'=>$userId,'admin_view'=>is_admin()?1:0]);
  foreach($s->fetchAll() as $r)$notifications[]=['id'=>(int)$r['id'],'source'=>'personal','source_label'=>'PERSONAL','status'=>'observed','status_label'=>'Observado','full_name'=>(string)$r['full_name'],'requirement'=>(string)$r['requirement_name'],'observation'=>trim((string)$r['observations']),'observed_by'=>(string)($r['observed_by']?:$r['registered_by']),'registered_by'=>(string)$r['registered_by'],'created_at'=>(string)($r['observation_at']?:($r['updated_at']?:$r['created_at']))];
 }
 if($columnExists('maquinaria_documentos','observation_status')){
  $s=db()->prepare("SELECT md.id,md.review_observation AS observaciones,md.observation_at,md.updated_at,md.created_at,m.equipo,m.serie_placa,mdc.nombre documento,ob.name observed_by,reg.name registered_by FROM maquinaria_documentos md JOIN maquinarias m ON m.id=md.maquinaria_id JOIN maquinaria_documentos_catalogo mdc ON mdc.id=md.documento_id LEFT JOIN users ob ON ob.id=md.observation_by_user_id LEFT JOIN users reg ON reg.id=md.registered_by_user_id WHERE md.observation_status='observed' AND (md.registered_by_user_id=:uid OR :admin_view=1) ORDER BY COALESCE(md.observation_at,md.updated_at,md.created_at) DESC LIMIT 30");$s->execute(['uid'=>$userId,'admin_view'=>is_admin()?1:0]);
  foreach($s->fetchAll() as $r)$notifications[]=['id'=>(int)$r['id'],'source'=>'maquinaria','source_label'=>'MAQUINARIA','status'=>'observed','status_label'=>'Observado','full_name'=>(string)$r['equipo'],'series'=>(string)$r['serie_placa'],'requirement'=>(string)$r['documento'],'observation'=>(string)$r['observaciones'],'observed_by'=>(string)($r['observed_by']?:$r['registered_by']),'registered_by'=>(string)$r['registered_by'],'created_at'=>(string)($r['observation_at']?:($r['updated_at']?:$r['created_at']))];
 }
 usort($notifications,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));$notifications=array_slice($notifications,0,40);json_response(['ok'=>true,'unread_count'=>count($notifications),'notifications'=>$notifications]);
}catch(Throwable $e){json_response(['ok'=>false,'message'=>'No se pudieron cargar las observaciones.'],500);}
