<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/attendance_projects.php';
require_module_access('control_personal.control_asistencia');
verify_csrf($_POST['csrf_token'] ?? null);
ensure_quick_attendance_marking_schema();

$workerId=(int)($_POST['worker_id']??0);
if(is_personal_role()) $workerId=(int)current_user_worker_id();
$type=(string)($_POST['mark_type']??'');
$locationId=(int)($_POST['location_id']??0);
$scheduleId=(int)($_POST['schedule_id']??0);
$projectId=(int)($_POST['project_id']??0);
$latitude=(float)($_POST['latitude']??0); $longitude=(float)($_POST['longitude']??0); $accuracy=(float)($_POST['accuracy']??0);
$address=trim((string)($_POST['address']??'')); $observations=trim((string)($_POST['observations']??''));
$photoData=(string)($_POST['photo_data']??'');
if($workerId<=0 || !in_array($type,['entrada','salida'],true) || $locationId<=0 || $scheduleId<=0 || $projectId<=0) json_response(['ok'=>false,'message'=>'Seleccione lugar de marcación, horario y proyecto.'],400);
if($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 || $accuracy<=0) json_response(['ok'=>false,'message'=>'Ubicación GPS no válida.'],400);

function quick_mark_distance(float $lat1,float $lon1,float $lat2,float $lon2):float { $r=6371000;$dLat=deg2rad($lat2-$lat1);$dLon=deg2rad($lon2-$lon1);$a=sin($dLat/2)**2+cos(deg2rad($lat1))*cos(deg2rad($lat2))*sin($dLon/2)**2;return $r*2*atan2(sqrt($a),sqrt(1-$a)); }
function quick_mark_photo(string $dataUrl):string { if(!preg_match('/^data:image\/(png|jpeg|jpg|webp);base64,/',$dataUrl,$m)) throw new RuntimeException('Debe capturar una fotografía para marcar asistencia.');$binary=base64_decode(substr($dataUrl,strpos($dataUrl,',')+1),true);if($binary===false||strlen($binary)>MAX_UPLOAD_SIZE) throw new RuntimeException('No se pudo procesar la fotografía.');$dir=UPLOAD_PATH.DIRECTORY_SEPARATOR.'marcaciones';if(!is_dir($dir)) mkdir($dir,0755,true);$ext=$m[1]==='jpeg'?'jpg':$m[1];$name=bin2hex(random_bytes(16)).'.'.$ext;if(file_put_contents($dir.DIRECTORY_SEPARATOR.$name,$binary)===false) throw new RuntimeException('No se pudo guardar la fotografía.');return 'archivos/marcaciones/'.$name; }

$catalog=db()->prepare("SELECT l.name location_name,l.latitude,l.longitude,l.radius_meters,s.name schedule_name,p.name project_name
 FROM attendance_locations l JOIN attendance_schedules s ON s.id=:schedule_id AND s.status=1
 JOIN attendance_projects p ON p.id=:project_id AND p.status=1 WHERE l.id=:location_id AND l.status=1 LIMIT 1");
$catalog->execute(['schedule_id'=>$scheduleId,'project_id'=>$projectId,'location_id'=>$locationId]); $selected=$catalog->fetch();
if(!$selected) json_response(['ok'=>false,'message'=>'Alguna de las opciones seleccionadas ya no está disponible.'],409);
$day=(int)date('N');$dayStmt=db()->prepare('SELECT * FROM attendance_schedule_days WHERE schedule_id=:schedule_id AND day_of_week=:day AND status=1 LIMIT 1');$dayStmt->execute(['schedule_id'=>$scheduleId,'day'=>$day]);$scheduleDay=$dayStmt->fetch();
if(!$scheduleDay) json_response(['ok'=>false,'message'=>'El horario seleccionado no tiene una jornada configurada para hoy.'],409);
$today=date('Y-m-d');$now=date('Y-m-d H:i:s');$time=date('H:i:s');
$state=db()->prepare('SELECT mark_type,location_id FROM attendance_marks WHERE worker_id=:worker AND mark_date=:date ORDER BY marked_at,id');$state->execute(['worker'=>$workerId,'date'=>$today]);$markState=$state->fetchAll();
$types=array_column($markState,'mark_type');
$hasEntry=in_array('entrada',$types,true);$hasExit=in_array('salida',$types,true);
$lastEntryLocationId=0;
foreach(array_reverse($markState) as $registeredMark){if((string)$registeredMark['mark_type']==='entrada'){$lastEntryLocationId=(int)$registeredMark['location_id'];break;}}
if($hasExit) json_response(['ok'=>false,'message'=>'La jornada de hoy ya fue finalizada.'],409);
if($type==='salida'&&!$hasEntry) json_response(['ok'=>false,'message'=>'Primero debe registrar una entrada.'],409);
if($type==='entrada'&&$hasEntry&&$lastEntryLocationId===$locationId) json_response(['ok'=>false,'message'=>'Ya se encuentra en este lugar. Seleccione un lugar de marcación diferente.'],409);
if($type==='entrada'&&!empty($scheduleDay['entry_start'])) { $available=strtotime($today.' '.$scheduleDay['entry_start']);$official=$scheduleDay['entry_time']??$scheduleDay['entry_end']??$scheduleDay['entry_start'];if(strtotime($scheduleDay['entry_start'])>strtotime($official))$available=strtotime($today.' '.$scheduleDay['entry_start'].' -1 day');if($available!==false&&time()<$available)json_response(['ok'=>false,'message'=>'Este horario permite marcar entrada desde las '.date('H:i',$available).'.'],409); }
$recent=db()->prepare("SELECT 1 FROM attendance_marks WHERE worker_id=:worker AND mark_date=:date AND mark_type='entrada' AND location_id=:location AND schedule_id=:schedule AND project_id=:project AND marked_at>=DATE_SUB(NOW(),INTERVAL 2 MINUTE) LIMIT 1");$recent->execute(['worker'=>$workerId,'date'=>$today,'location'=>$locationId,'schedule'=>$scheduleId,'project'=>$projectId]);if($type==='entrada'&&$recent->fetchColumn())json_response(['ok'=>false,'message'=>'Esta llegada ya fue registrada recientemente.'],409);
$distance=quick_mark_distance($latitude,$longitude,(float)$selected['latitude'],(float)$selected['longitude']);$within=$distance<=(float)$selected['radius_meters'];if(!$within)json_response(['ok'=>false,'title'=>'Marcación no registrada','message'=>'Está fuera del área de '.$selected['location_name'].'. Distancia actual: '.number_format($distance,2).' m; radio permitido: '.(int)$selected['radius_meters'].' m.'],400);
$status='puntual';if($type==='entrada'){$limit=strtotime($today.' '.($scheduleDay['entry_end']??$scheduleDay['entry_time']));$status=time()<=$limit?'puntual':'tardanza';}else{$limit=strtotime($today.' '.($scheduleDay['exit_time']??$scheduleDay['exit_start']));$status=time()>=$limit?'salida_valida':'salida_anticipada';}

$assignment=db()->prepare('SELECT id FROM attendance_assignments WHERE worker_id=:worker AND location_id=:location AND schedule_id=:schedule AND valid_from<=:date AND (valid_until IS NULL OR valid_until>=:date_until) ORDER BY status DESC,id DESC LIMIT 1');$assignment->execute(['worker'=>$workerId,'location'=>$locationId,'schedule'=>$scheduleId,'date'=>$today,'date_until'=>$today]);$assignmentId=(int)$assignment->fetchColumn();
if(!$assignmentId){$create=db()->prepare('INSERT INTO attendance_assignments(worker_id,location_id,schedule_id,activity,instructions,valid_from,valid_until,status,created_by_user_id) VALUES(:worker,:location,:schedule,:activity,:instructions,:date,:date_until,0,:user)');$create->execute(['worker'=>$workerId,'location'=>$locationId,'schedule'=>$scheduleId,'activity'=>$selected['project_name'],'instructions'=>'Registro generado desde marcación directa.','date'=>$today,'date_until'=>$today,'user'=>(int)(current_user()['id']??0)?:null]);$assignmentId=(int)db()->lastInsertId();}
try{$photo=quick_mark_photo($photoData);$insert=db()->prepare('INSERT INTO attendance_marks(assignment_id,program_id,worker_id,location_id,schedule_id,project_id,mark_type,mark_date,mark_time,marked_at,latitude,longitude,accuracy_meters,address,distance_meters,within_radius,schedule_status,location_status,final_status,photo_path,observations) VALUES(:assignment,NULL,:worker,:location,:schedule,:project,:type,:date,:time,:marked_at,:latitude,:longitude,:accuracy,:address,:distance,1,:schedule_status,\'dentro_del_radio\',:final_status,:photo,:observations)');$insert->execute(['assignment'=>$assignmentId,'worker'=>$workerId,'location'=>$locationId,'schedule'=>$scheduleId,'project'=>$projectId,'type'=>$type,'date'=>$today,'time'=>$time,'marked_at'=>$now,'latitude'=>$latitude,'longitude'=>$longitude,'accuracy'=>$accuracy,'address'=>$address?:null,'distance'=>round($distance,2),'schedule_status'=>$status,'final_status'=>$status,'photo'=>$photo,'observations'=>$observations?:null]);json_response(['ok'=>true,'message'=>$type==='entrada'?'Entrada registrada en '.$selected['location_name'].'.':'Salida registrada. La jornada ha finalizado.','status'=>$status]);}catch(Throwable $e){json_response(['ok'=>false,'message'=>$e->getMessage()],400);}