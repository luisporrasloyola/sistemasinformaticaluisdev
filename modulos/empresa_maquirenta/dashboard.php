<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/status_alerts.php';
require_module_access('empresa_maquirenta.dashboard');

$informeEquipos = db()->query("SELECT m.id,m.nombre,COUNT(i.id) total,
 SUM(CASE WHEN COALESCE(i.archivo_path,'')<>'' THEN 1 ELSE 0 END) con_archivo
 FROM empresa_maquirenta_informes_maquinarias m
 JOIN empresa_maquirenta_informes i ON i.maquinaria_tipo_id=m.id
 WHERE m.estado=1 GROUP BY m.id,m.nombre ORDER BY m.id")->fetchAll();

$charlaEquipos = db()->query("SELECT m.id,m.nombre,COUNT(c.id) total,
 SUM(CASE WHEN COALESCE(c.archivo_path,'')<>'' THEN 1 ELSE 0 END) con_archivo
 FROM empresa_maquirenta_charla_preoperacional_maquinarias m
 JOIN empresa_maquirenta_charla_preoperacional c ON c.maquinaria_tipo_id=m.id
 WHERE m.estado=1 GROUP BY m.id,m.nombre ORDER BY m.id")->fetchAll();

$informeDetalle = db()->query("SELECT 'informes' modulo,i.maquinaria_tipo_id maquinaria_id,m.nombre maquinaria,i.id registro_id,i.archivo_path,i.archivo_nombre_original,
 CONCAT('PMS ',i.nro_pms) referencia,
 CONCAT(DATE_FORMAT(i.rango_inicio,'%d/%m/%Y'),' al ',DATE_FORMAT(i.rango_fin,'%d/%m/%Y')) periodo,
 CASE WHEN COALESCE(i.archivo_path,'')<>'' THEN 'apto' ELSE 'no_apto' END estado,
 COALESCE(u.name,'Sin usuario') registrado_por
 FROM empresa_maquirenta_informes i
 JOIN empresa_maquirenta_informes_maquinarias m ON m.id=i.maquinaria_tipo_id
 LEFT JOIN users u ON u.id=i.registered_by_user_id
 ORDER BY m.nombre,i.nro_pms DESC")->fetchAll();

$charlaDetalle = db()->query("SELECT 'charlas' modulo,c.maquinaria_tipo_id maquinaria_id,m.nombre maquinaria,c.id registro_id,c.archivo_path,c.archivo_nombre_original,
 DATE_FORMAT(c.fecha,'%d/%m/%Y') referencia,'Fecha de charla' periodo,
 CASE WHEN COALESCE(c.archivo_path,'')<>'' THEN 'apto' ELSE 'no_apto' END estado,
 COALESCE(u.name,'Sin usuario') registrado_por
 FROM empresa_maquirenta_charla_preoperacional c
 JOIN empresa_maquirenta_charla_preoperacional_maquinarias m ON m.id=c.maquinaria_tipo_id
 LEFT JOIN users u ON u.id=c.registered_by_user_id
 ORDER BY m.nombre,c.fecha DESC")->fetchAll();
$modalRecords=array_merge($informeDetalle,$charlaDetalle);

$pms = db()->query("SELECT COUNT(*) total,
 SUM(CASE WHEN COALESCE(archivo_path,'')<>'' THEN 1 ELSE 0 END) con_archivo,
 MAX(nro_pms) ultimo_pms
 FROM empresa_maquirenta_cventanilla_pms")->fetch() ?: [];
$pmsLatest = db()->query("SELECT nro_pms,rango_inicio,rango_fin,archivo_path FROM empresa_maquirenta_cventanilla_pms ORDER BY nro_pms DESC,id DESC LIMIT 1")->fetch() ?: [];
$pmsTotal=(int)($pms['total']??0);$pmsDocumented=(int)($pms['con_archivo']??0);$pmsPercent=$pmsTotal?(int)round($pmsDocumented*100/$pmsTotal):0;

$pmsDetalle = db()->query("SELECT 'pms' modulo,0 maquinaria_id,'Central Térmica Ventanilla' maquinaria,p.id registro_id,p.archivo_path,p.archivo_nombre_original,
 CONCAT('PMS ',p.nro_pms) referencia,
 CONCAT(DATE_FORMAT(p.rango_inicio,'%d/%m/%Y'),' al ',DATE_FORMAT(p.rango_fin,'%d/%m/%Y')) periodo,
 CASE WHEN COALESCE(p.archivo_path,'')<>'' THEN 'apto' ELSE 'no_apto' END estado,
 COALESCE(u.name,'Sin usuario') registrado_por
 FROM empresa_maquirenta_cventanilla_pms p
 LEFT JOIN users u ON u.id=p.registered_by_user_id
 ORDER BY p.nro_pms DESC")->fetchAll();
$modalRecords=array_merge($modalRecords,$pmsDetalle);
$pmsPending=max(0,$pmsTotal-$pmsDocumented);

$permit = db()->query("SELECT COUNT(*) total,
 SUM(v.fecha_cierre IS NOT NULL AND EXISTS(SELECT 1 FROM empresa_maquirenta_permiso_archivos a WHERE a.vigencia_id=v.id)) cerrados,
 SUM(v.fecha_cierre IS NULL AND CURDATE() BETWEEN v.fecha_inicio AND v.fecha_vencimiento) vigentes,
 SUM(v.fecha_cierre IS NULL AND CURDATE() NOT BETWEEN v.fecha_inicio AND v.fecha_vencimiento) no_aptos
 FROM empresa_maquirenta_permisos_trabajo p JOIN empresa_maquirenta_permiso_vigencias v ON v.id=(
 SELECT v2.id FROM empresa_maquirenta_permiso_vigencias v2 WHERE v2.permiso_trabajo_id=p.id ORDER BY v2.fecha_vencimiento DESC,v2.id DESC LIMIT 1)")->fetch() ?: [];
$permisoDetalle = db()->query("SELECT 'permisos' modulo,0 maquinaria_id,'Central Térmica Ventanilla' maquinaria,v.id vigencia_id,
 p.permiso_nombre referencia,
 CONCAT(DATE_FORMAT(v.fecha_inicio,'%d/%m/%Y'),' al ',DATE_FORMAT(v.fecha_vencimiento,'%d/%m/%Y')) periodo,
 DATE_FORMAT(v.fecha_inicio,'%d/%m/%Y') fecha_inicio_modal,
 DATE_FORMAT(v.fecha_vencimiento,'%d/%m/%Y') fecha_vencimiento_modal,
 CASE WHEN v.fecha_cierre IS NOT NULL AND EXISTS(SELECT 1 FROM empresa_maquirenta_permiso_archivos a WHERE a.vigencia_id=v.id) THEN 'cerrado'
      WHEN v.fecha_cierre IS NULL AND CURDATE() BETWEEN v.fecha_inicio AND v.fecha_vencimiento THEN 'vigente'
      ELSE 'no_apto' END estado,
 COALESCE(u.name,'Sin usuario') registrado_por
 FROM empresa_maquirenta_permisos_trabajo p
 JOIN empresa_maquirenta_permiso_vigencias v ON v.id=(
   SELECT v2.id FROM empresa_maquirenta_permiso_vigencias v2 WHERE v2.permiso_trabajo_id=p.id ORDER BY v2.fecha_vencimiento DESC,v2.id DESC LIMIT 1)
 LEFT JOIN users u ON u.id=p.registered_by_user_id
 ORDER BY p.fecha_registro DESC,p.id DESC")->fetchAll();
$modalRecords=array_merge($modalRecords,$permisoDetalle);
$permitFiles=[];
foreach(db()->query("SELECT id,vigencia_id,archivo_nombre_original FROM empresa_maquirenta_permiso_archivos ORDER BY created_at,id")->fetchAll() as $file){
 $permitFiles[(int)$file['vigencia_id']][]=[
  'name'=>(string)($file['archivo_nombre_original']?:'Documento'),
  'url'=>APP_URL.'/servicios/empresa_maquirenta/permiso_trabajo.php?action=file&id='.(int)$file['id'].'&download=1',
 ];
}
$documentServices=[
 'informes'=>'informes.php',
 'charlas'=>'charla_preoperacional.php',
 'pms'=>'pms.php',
];
foreach($modalRecords as &$record){
 $record['documentos']=[];
 if($record['modulo']==='permisos'){
  $record['documentos']=$permitFiles[(int)($record['vigencia_id']??0)]??[];
 }elseif(trim((string)($record['archivo_path']??''))!==''){
  $record['documentos'][]=[
   'name'=>(string)($record['archivo_nombre_original']?:'Documento'),
   'url'=>APP_URL.'/servicios/empresa_maquirenta/'.$documentServices[$record['modulo']].'?action=file&id='.(int)$record['registro_id'].'&download=1',
  ];
 }
 unset($record['archivo_path'],$record['archivo_nombre_original']);
}
unset($record);


/* Indicadores independientes: Central Térmica Santa Rosa */
$srInformeEquipos = db()->query("SELECT m.id,m.nombre,COUNT(i.id) total,
 SUM(CASE WHEN COALESCE(i.archivo_path,'')<>'' THEN 1 ELSE 0 END) con_archivo
 FROM empresa_maquirenta_santa_rosa_informes_maquinarias m
 JOIN empresa_maquirenta_santa_rosa_informes i ON i.maquinaria_tipo_id=m.id
 WHERE m.estado=1 GROUP BY m.id,m.nombre ORDER BY m.id")->fetchAll();

$srCharlaEquipos = db()->query("SELECT m.id,m.nombre,COUNT(c.id) total,
 SUM(CASE WHEN COALESCE(c.archivo_path,'')<>'' THEN 1 ELSE 0 END) con_archivo
 FROM empresa_maquirenta_santa_rosa_charla_preoperacional_maquinarias m
 JOIN empresa_maquirenta_santa_rosa_charla_preoperacional c ON c.maquinaria_tipo_id=m.id
 WHERE m.estado=1 GROUP BY m.id,m.nombre ORDER BY m.id")->fetchAll();

$srInformeDetalle = db()->query("SELECT 'sr_informes' modulo,i.maquinaria_tipo_id maquinaria_id,m.nombre maquinaria,i.id registro_id,i.archivo_path,i.archivo_nombre_original,
 CONCAT('PMS ',i.nro_pms) referencia,
 CONCAT(DATE_FORMAT(i.rango_inicio,'%d/%m/%Y'),' al ',DATE_FORMAT(i.rango_fin,'%d/%m/%Y')) periodo,
 CASE WHEN COALESCE(i.archivo_path,'')<>'' THEN 'apto' ELSE 'no_apto' END estado,
 COALESCE(u.name,'Sin usuario') registrado_por
 FROM empresa_maquirenta_santa_rosa_informes i
 JOIN empresa_maquirenta_santa_rosa_informes_maquinarias m ON m.id=i.maquinaria_tipo_id
 LEFT JOIN users u ON u.id=i.registered_by_user_id
 ORDER BY m.nombre,i.nro_pms DESC")->fetchAll();

$srCharlaDetalle = db()->query("SELECT 'sr_charlas' modulo,c.maquinaria_tipo_id maquinaria_id,m.nombre maquinaria,c.id registro_id,c.archivo_path,c.archivo_nombre_original,
 DATE_FORMAT(c.fecha,'%d/%m/%Y') referencia,'Fecha de charla' periodo,
 CASE WHEN COALESCE(c.archivo_path,'')<>'' THEN 'apto' ELSE 'no_apto' END estado,
 COALESCE(u.name,'Sin usuario') registrado_por
 FROM empresa_maquirenta_santa_rosa_charla_preoperacional c
 JOIN empresa_maquirenta_santa_rosa_charla_preoperacional_maquinarias m ON m.id=c.maquinaria_tipo_id
 LEFT JOIN users u ON u.id=c.registered_by_user_id
 ORDER BY m.nombre,c.fecha DESC")->fetchAll();

$srPms = db()->query("SELECT COUNT(*) total,
 SUM(CASE WHEN COALESCE(archivo_path,'')<>'' THEN 1 ELSE 0 END) con_archivo
 FROM empresa_maquirenta_csantarosa_pms")->fetch() ?: [];
$srPmsLatest = db()->query("SELECT nro_pms,rango_inicio,rango_fin,archivo_path FROM empresa_maquirenta_csantarosa_pms ORDER BY nro_pms DESC,id DESC LIMIT 1")->fetch() ?: [];
$srPmsTotal=(int)($srPms['total']??0);$srPmsDocumented=(int)($srPms['con_archivo']??0);$srPmsPending=max(0,$srPmsTotal-$srPmsDocumented);$srPmsPercent=$srPmsTotal?(int)round($srPmsDocumented*100/$srPmsTotal):0;
$srPmsDetalle = db()->query("SELECT 'sr_pms' modulo,0 maquinaria_id,'Central Térmica Santa Rosa' maquinaria,p.id registro_id,p.archivo_path,p.archivo_nombre_original,
 CONCAT('PMS ',p.nro_pms) referencia,
 CONCAT(DATE_FORMAT(p.rango_inicio,'%d/%m/%Y'),' al ',DATE_FORMAT(p.rango_fin,'%d/%m/%Y')) periodo,
 CASE WHEN COALESCE(p.archivo_path,'')<>'' THEN 'apto' ELSE 'no_apto' END estado,
 COALESCE(u.name,'Sin usuario') registrado_por
 FROM empresa_maquirenta_csantarosa_pms p
 LEFT JOIN users u ON u.id=p.registered_by_user_id
 ORDER BY p.nro_pms DESC")->fetchAll();

$srPermit = db()->query("SELECT COUNT(*) total,
 SUM(v.fecha_cierre IS NOT NULL AND EXISTS(SELECT 1 FROM empresa_maquirenta_santa_rosa_permiso_archivos a WHERE a.vigencia_id=v.id)) cerrados,
 SUM(v.fecha_cierre IS NULL AND CURDATE() BETWEEN v.fecha_inicio AND v.fecha_vencimiento) vigentes,
 SUM(v.fecha_cierre IS NULL AND CURDATE() NOT BETWEEN v.fecha_inicio AND v.fecha_vencimiento) no_aptos
 FROM empresa_maquirenta_santa_rosa_permisos_trabajo p JOIN empresa_maquirenta_santa_rosa_permiso_vigencias v ON v.id=(
 SELECT v2.id FROM empresa_maquirenta_santa_rosa_permiso_vigencias v2 WHERE v2.permiso_trabajo_id=p.id ORDER BY v2.fecha_vencimiento DESC,v2.id DESC LIMIT 1)")->fetch() ?: [];
$srPermisoDetalle = db()->query("SELECT 'sr_permisos' modulo,0 maquinaria_id,'Central Térmica Santa Rosa' maquinaria,v.id vigencia_id,
 p.permiso_nombre referencia,
 CONCAT(DATE_FORMAT(v.fecha_inicio,'%d/%m/%Y'),' al ',DATE_FORMAT(v.fecha_vencimiento,'%d/%m/%Y')) periodo,
 DATE_FORMAT(v.fecha_inicio,'%d/%m/%Y') fecha_inicio_modal,
 DATE_FORMAT(v.fecha_vencimiento,'%d/%m/%Y') fecha_vencimiento_modal,
 CASE WHEN v.fecha_cierre IS NOT NULL AND EXISTS(SELECT 1 FROM empresa_maquirenta_santa_rosa_permiso_archivos a WHERE a.vigencia_id=v.id) THEN 'cerrado'
      WHEN v.fecha_cierre IS NULL AND CURDATE() BETWEEN v.fecha_inicio AND v.fecha_vencimiento THEN 'vigente'
      ELSE 'no_apto' END estado,
 COALESCE(u.name,'Sin usuario') registrado_por
 FROM empresa_maquirenta_santa_rosa_permisos_trabajo p
 JOIN empresa_maquirenta_santa_rosa_permiso_vigencias v ON v.id=(
   SELECT v2.id FROM empresa_maquirenta_santa_rosa_permiso_vigencias v2 WHERE v2.permiso_trabajo_id=p.id ORDER BY v2.fecha_vencimiento DESC,v2.id DESC LIMIT 1)
 LEFT JOIN users u ON u.id=p.registered_by_user_id
 ORDER BY p.fecha_registro DESC,p.id DESC")->fetchAll();

$srPermitFiles=[];
foreach(db()->query("SELECT id,vigencia_id,archivo_nombre_original FROM empresa_maquirenta_santa_rosa_permiso_archivos ORDER BY created_at,id")->fetchAll() as $file){
 $srPermitFiles[(int)$file['vigencia_id']][]=[
  'name'=>(string)($file['archivo_nombre_original']?:'Documento'),
  'url'=>APP_URL.'/servicios/empresa_maquirenta/permiso_trabajo_santa_rosa.php?action=file&id='.(int)$file['id'].'&download=1',
 ];
}
$srDocumentServices=['sr_informes'=>'informes_santa_rosa.php','sr_charlas'=>'charla_preoperacional_santa_rosa.php','sr_pms'=>'pms_santa_rosa.php'];
$srModalRecords=array_merge($srInformeDetalle,$srCharlaDetalle,$srPmsDetalle,$srPermisoDetalle);
foreach($srModalRecords as &$record){
 $record['documentos']=[];
 if($record['modulo']==='sr_permisos'){
  $record['documentos']=$srPermitFiles[(int)($record['vigencia_id']??0)]??[];
 }elseif(trim((string)($record['archivo_path']??''))!==''){
  $record['documentos'][]=[
   'name'=>(string)($record['archivo_nombre_original']?:'Documento'),
   'url'=>APP_URL.'/servicios/empresa_maquirenta/'.$srDocumentServices[$record['modulo']].'?action=file&id='.(int)$record['registro_id'].'&download=1',
  ];
 }
 unset($record['archivo_path'],$record['archivo_nombre_original']);
}
unset($record);
$modalRecords=array_merge($modalRecords,$srModalRecords);
$srPermitTotal=(int)($srPermit['total']??0);$srPermitClosed=(int)($srPermit['cerrados']??0);$srPermitCurrent=(int)($srPermit['vigentes']??0);$srPermitExpired=(int)($srPermit['no_aptos']??0);
$permitTotal=(int)($permit['total']??0);$permitClosed=(int)($permit['cerrados']??0);$permitCurrent=(int)($permit['vigentes']??0);$permitExpired=(int)($permit['no_aptos']??0);
$fmtDate=static fn(?string $date):string=>$date?date('d/m/Y',strtotime($date)):'Sin registros';
$percent=static fn(int $value,int $total):int=>$total?(int)round($value*100/$total):0;

/* Formatos: estados calculados con la configuración oficial de alertas */
$formatoRows = db()->query("SELECT d.id registro_id,d.documento_id,d.fecha_inicio,d.fecha_fin,d.archivo_path,d.archivo_nombre_original,
 c.nombre documento,e.razon_social empresa,DATE_FORMAT(d.fecha_registro,'%d/%m/%Y') fecha_registro_modal,
 DATE_FORMAT(d.fecha_inicio,'%d/%m/%Y') fecha_inicio_modal,DATE_FORMAT(d.fecha_fin,'%d/%m/%Y') fecha_fin_modal,COALESCE(u.name,'Sin usuario') registrado_por
 FROM empresa_maquirenta_formatos_documentos d
 JOIN empresa_maquirenta_formatos_catalogo c ON c.id=d.documento_id
 JOIN empresas_maquirenta e ON e.id=d.empresa_maquirenta_id
 LEFT JOIN users u ON u.id=d.registered_by_user_id
 WHERE c.estado=1
 ORDER BY d.fecha_fin,c.nombre,e.razon_social")->fetchAll();
$formatoCounts=['apto'=>0,'por_vencer'=>0,'no_apto'=>0];
$formatoDetalle=[];
foreach($formatoRows as $row){
 if(!current_user_can_document('empresa_maquirenta.formatos',(int)$row['documento_id'],'view')) continue;
 $status=status_alert_document_status((string)$row['fecha_fin'],'empresa_maquirenta.formatos',(int)$row['documento_id'],true);
 $state=['verde'=>'apto','amarillo'=>'por_vencer','rojo'=>'no_apto'][(string)($status['key']??'rojo')]??'no_apto';
 $formatoCounts[$state]++;
 $documents=[];
 if(trim((string)($row['archivo_path']??''))!==''){
  $documents[]=['name'=>(string)($row['archivo_nombre_original']?:'Documento'),'url'=>APP_URL.'/'.ltrim((string)$row['archivo_path'],'/')];
 }
 $formatoDetalle[]=[
  'modulo'=>'formatos','maquinaria_id'=>0,'maquinaria'=>'Formatos','registro_id'=>(int)$row['registro_id'],
  'referencia'=>(string)$row['documento'],
  'periodo'=>(string)$row['empresa'].' · Vence '.$fmtDate((string)$row['fecha_fin']),
  'fecha_registro_modal'=>(string)$row['fecha_registro_modal'],'fecha_inicio_modal'=>(string)$row['fecha_inicio_modal'],'fecha_fin_modal'=>(string)$row['fecha_fin_modal'],
  'estado'=>$state,'registrado_por'=>(string)$row['registrado_por'],'documentos'=>$documents,
 ];
}
$modalRecords=array_merge($modalRecords,$formatoDetalle);
require __DIR__ . '/../../includes/header.php';
?>

<section class="vent-dashboard">
 <header class="vent-heading"><div><small><i class="fa-solid fa-industry"></i> EMPRESA MAQUIRENTA</small><h2>Central Térmica Ventanilla</h2><p>Indicadores organizados por cada submódulo.</p></div><span><b>4</b> submódulos</span></header>

 <div class="row g-3">
  <div class="col-xl-6">
   <article class="module-panel module-blue">
    <div class="module-head"><div><small>INFORMES</small><h3>Informes por maquinaria</h3><p>Cantidad de registros APTOS y NO APTOS por maquinaria.</p></div><a href="<?=APP_URL?>/modulos/empresa_maquirenta/informes.php" title="Ir a Informes"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
    <div class="equipment-list detailed-list">
    <?php if(!$informeEquipos):?><div class="empty-visual"><i class="fa-solid fa-folder-open"></i><span>No existen informes registrados.</span></div><?php endif;?>
    <?php foreach($informeEquipos as $item):$total=(int)$item['total'];$aptos=(int)$item['con_archivo'];$noAptos=max(0,$total-$aptos);$pct=$percent($aptos,$total);?>
     <div class="equipment-card">
      <div class="equipment-card-head"><span class="equipment-icon"><i class="fa-solid fa-truck-pickup"></i></span><div><strong><?=e($item['nombre'])?></strong><small><?=$total?> informe<?=$total===1?'':'s'?> registrado<?=$total===1?'':'s'?></small></div><div class="status-counts"><button class="count-ok dashboard-status-btn" type="button" data-module="informes" data-machine="<?= (int)$item['id'] ?>" data-machine-name="<?=e($item['nombre'])?>" data-state="apto"><b><?=$aptos?></b> APTOS</button><button class="count-bad dashboard-status-btn" type="button" data-module="informes" data-machine="<?= (int)$item['id'] ?>" data-machine-name="<?=e($item['nombre'])?>" data-state="no_apto"><b><?=$noAptos?></b> NO APTOS</button></div></div>
      <div class="split-progress"><i class="split-ok" style="width:<?=$pct?>%"></i><i class="split-bad" style="width:<?=100-$pct?>%"></i></div>
     </div>
    <?php endforeach;?>
    </div>
   </article>
  </div>
  <div class="col-xl-6">
   <article class="module-panel module-purple">
    <div class="module-head"><div><small>CHARLA PREOPERACIONAL</small><h3>Charlas por maquinaria</h3><p>Cantidad de registros APTOS y NO APTOS por maquinaria.</p></div><a href="<?=APP_URL?>/modulos/empresa_maquirenta/charla_preoperacional.php" title="Ir a Charla preoperacional"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
    <div class="equipment-list detailed-list">
    <?php if(!$charlaEquipos):?><div class="empty-visual"><i class="fa-solid fa-folder-open"></i><span>No existen charlas registradas.</span></div><?php endif;?>
    <?php foreach($charlaEquipos as $item):$total=(int)$item['total'];$aptos=(int)$item['con_archivo'];$noAptos=max(0,$total-$aptos);$pct=$percent($aptos,$total);?>
     <div class="equipment-card">
      <div class="equipment-card-head"><span class="equipment-icon"><i class="fa-solid fa-person-chalkboard"></i></span><div><strong><?=e($item['nombre'])?></strong><small><?=$total?> charla<?=$total===1?'':'s'?> registrada<?=$total===1?'':'s'?></small></div><div class="status-counts"><button class="count-ok dashboard-status-btn" type="button" data-module="charlas" data-machine="<?= (int)$item['id'] ?>" data-machine-name="<?=e($item['nombre'])?>" data-state="apto"><b><?=$aptos?></b> APTOS</button><button class="count-bad dashboard-status-btn" type="button" data-module="charlas" data-machine="<?= (int)$item['id'] ?>" data-machine-name="<?=e($item['nombre'])?>" data-state="no_apto"><b><?=$noAptos?></b> NO APTOS</button></div></div>
      <div class="split-progress"><i class="split-ok" style="width:<?=$pct?>%"></i><i class="split-bad" style="width:<?=100-$pct?>%"></i></div>
     </div>
    <?php endforeach;?>
    </div>
   </article>
  </div>
  <div class="col-xl-6">
   <article class="module-panel module-cyan">
    <div class="module-head"><div><small>PMS</small><h3>Control de PMS</h3><p>Avance de registros y archivos cargados.</p></div><a href="<?=APP_URL?>/modulos/empresa_maquirenta/pms.php" title="Ir a PMS"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
    <div class="pms-visual pms-dashboard-visual">
     <div class="radial-progress" style="--progress:<?=$pmsPercent?>"><div><strong><?=$pmsPercent?>%</strong><span>documentado</span></div></div>
     <div class="pms-summary">
      <small>ÚLTIMO REGISTRO</small>
      <strong><?=$pmsLatest?'PMS '.(int)$pmsLatest['nro_pms']:'Sin PMS'?></strong>
      <span><?=$pmsLatest?$fmtDate($pmsLatest['rango_inicio']).' al '.$fmtDate($pmsLatest['rango_fin']):'No hay registros'?></span>
      <div class="pms-status-grid">
       <button class="pms-status-card pms-status-ok dashboard-status-btn" type="button" data-module="pms" data-machine="0" data-machine-name="Central Térmica Ventanilla" data-state="apto"><span><i class="fa-solid fa-circle-check"></i> APTOS</span><strong><?=$pmsDocumented?></strong><small>con archivo</small></button>
       <button class="pms-status-card pms-status-bad dashboard-status-btn" type="button" data-module="pms" data-machine="0" data-machine-name="Central Térmica Ventanilla" data-state="no_apto"><span><i class="fa-solid fa-circle-xmark"></i> NO APTOS</span><strong><?=$pmsPending?></strong><small>sin archivo</small></button>
      </div>
     </div>
    </div>
   </article>
  </div>

  <div class="col-xl-6">
   <article class="module-panel module-orange">
    <div class="module-head"><div><small>PERMISO DE TRABAJO</small><h3>Estado de permisos</h3><p>Estado real de la última vigencia de cada permiso.</p></div><a href="<?=APP_URL?>/modulos/empresa_maquirenta/permiso_trabajo.php" title="Ir a Permiso de trabajo"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
    <div class="permit-visual">
     <div class="permit-total"><span>Total</span><strong><?=$permitTotal?></strong><small>permisos</small></div>
     <div class="permit-states">
      <?php foreach([['VIGENTE',$permitCurrent,'#198754','fa-circle-check','vigente'],['NO APTO',$permitExpired,'#dc3545','fa-circle-xmark','no_apto'],['CERRADO',$permitClosed,'#1d4ed8','fa-lock','cerrado']] as $state):?>
       <button class="permit-state permit-state-button dashboard-status-btn" type="button" data-module="permisos" data-machine="0" data-machine-name="Central Térmica Ventanilla" data-state="<?=$state[4]?>"><span><i class="fa-solid <?=$state[3]?>" style="color:<?=$state[2]?>"></i><?=$state[0]?></span><b><?=$state[1]?></b><div><i style="background:<?=$state[2]?>;width:<?=$percent((int)$state[1],$permitTotal)?>%"></i></div><small><?=$percent((int)$state[1],$permitTotal)?>% del total</small></button>
      <?php endforeach;?>
     </div>
    </div>
   </article>
  </div>
 </div>
</section>

<section class="vent-dashboard mt-4">
 <header class="vent-heading santa-rosa-heading"><div><small><i class="fa-solid fa-industry"></i> EMPRESA MAQUIRENTA</small><h2>Central Térmica Santa Rosa</h2><p>Indicadores organizados por cada submódulo.</p></div><span><b>4</b> submódulos</span></header>
 <div class="row g-3">
  <div class="col-xl-6"><article class="module-panel module-blue">
   <div class="module-head"><div><small>INFORMES</small><h3>Informes por maquinaria</h3><p>Cantidad de registros APTOS y NO APTOS por maquinaria.</p></div><a href="<?=APP_URL?>/modulos/empresa_maquirenta/informes_santa_rosa.php" title="Ir a Informes"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
   <div class="equipment-list detailed-list">
   <?php if(!$srInformeEquipos):?><div class="empty-visual"><i class="fa-solid fa-folder-open"></i><span>No existen informes registrados.</span></div><?php endif;?>
   <?php foreach($srInformeEquipos as $item):$total=(int)$item['total'];$aptos=(int)$item['con_archivo'];$noAptos=max(0,$total-$aptos);$pct=$percent($aptos,$total);?>
    <div class="equipment-card"><div class="equipment-card-head"><div class="equipment-icon"><i class="fa-solid fa-truck-pickup"></i></div><div><strong><?=e($item['nombre'])?></strong><small><?=$total?> <?=($total===1?'informe registrado':'informes registrados')?></small></div><div class="status-counts"><button class="dashboard-status-btn count-ok" type="button" data-module="sr_informes" data-machine="<?=(int)$item['id']?>" data-machine-name="<?=e($item['nombre'])?>" data-state="apto"><b><?=$aptos?></b> APTOS</button><button class="dashboard-status-btn count-bad" type="button" data-module="sr_informes" data-machine="<?=(int)$item['id']?>" data-machine-name="<?=e($item['nombre'])?>" data-state="no_apto"><b><?=$noAptos?></b> NO APTOS</button></div></div><div class="split-progress"><i class="split-ok" style="width:<?=$pct?>%"></i><i class="split-bad" style="width:<?=100-$pct?>%"></i></div></div>
   <?php endforeach;?>
   </div>
  </article></div>
  <div class="col-xl-6"><article class="module-panel module-purple">
   <div class="module-head"><div><small>CHARLA PREOPERACIONAL</small><h3>Charlas por maquinaria</h3><p>Cantidad de registros APTOS y NO APTOS por maquinaria.</p></div><a href="<?=APP_URL?>/modulos/empresa_maquirenta/charla_preoperacional_santa_rosa.php" title="Ir a Charla preoperacional"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
   <div class="equipment-list detailed-list">
   <?php if(!$srCharlaEquipos):?><div class="empty-visual"><i class="fa-solid fa-folder-open"></i><span>No existen charlas registradas.</span></div><?php endif;?>
   <?php foreach($srCharlaEquipos as $item):$total=(int)$item['total'];$aptos=(int)$item['con_archivo'];$noAptos=max(0,$total-$aptos);$pct=$percent($aptos,$total);?>
    <div class="equipment-card"><div class="equipment-card-head"><div class="equipment-icon"><i class="fa-solid fa-person-chalkboard"></i></div><div><strong><?=e($item['nombre'])?></strong><small><?=$total?> <?=($total===1?'charla registrada':'charlas registradas')?></small></div><div class="status-counts"><button class="dashboard-status-btn count-ok" type="button" data-module="sr_charlas" data-machine="<?=(int)$item['id']?>" data-machine-name="<?=e($item['nombre'])?>" data-state="apto"><b><?=$aptos?></b> APTOS</button><button class="dashboard-status-btn count-bad" type="button" data-module="sr_charlas" data-machine="<?=(int)$item['id']?>" data-machine-name="<?=e($item['nombre'])?>" data-state="no_apto"><b><?=$noAptos?></b> NO APTOS</button></div></div><div class="split-progress"><i class="split-ok" style="width:<?=$pct?>%"></i><i class="split-bad" style="width:<?=100-$pct?>%"></i></div></div>
   <?php endforeach;?>
   </div>
  </article></div>
  <div class="col-xl-6"><article class="module-panel module-cyan">
   <div class="module-head"><div><small>PMS</small><h3>Control de PMS</h3><p>Avance de registros y archivos cargados.</p></div><a href="<?=APP_URL?>/modulos/empresa_maquirenta/pms_santa_rosa.php" title="Ir a PMS"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
   <div class="pms-visual pms-dashboard-visual"><div class="radial-progress" style="--progress:<?=$srPmsPercent?>"><div><strong><?=$srPmsPercent?>%</strong><span>documentado</span></div></div><div class="pms-summary"><small>ÚLTIMO REGISTRO</small><strong><?=$srPmsLatest?'PMS '.(int)$srPmsLatest['nro_pms']:'Sin PMS'?></strong><span><?=$srPmsLatest?$fmtDate($srPmsLatest['rango_inicio']).' al '.$fmtDate($srPmsLatest['rango_fin']):'No hay registros'?></span><div class="pms-status-grid"><button class="pms-status-card pms-status-ok dashboard-status-btn" type="button" data-module="sr_pms" data-machine="0" data-machine-name="Central Térmica Santa Rosa" data-state="apto"><span><i class="fa-solid fa-circle-check"></i> APTOS</span><strong><?=$srPmsDocumented?></strong><small>con archivo</small></button><button class="pms-status-card pms-status-bad dashboard-status-btn" type="button" data-module="sr_pms" data-machine="0" data-machine-name="Central Térmica Santa Rosa" data-state="no_apto"><span><i class="fa-solid fa-circle-xmark"></i> NO APTOS</span><strong><?=$srPmsPending?></strong><small>sin archivo</small></button></div></div></div>
  </article></div>
  <div class="col-xl-6"><article class="module-panel module-orange">
   <div class="module-head"><div><small>PERMISO DE TRABAJO</small><h3>Estado de permisos</h3><p>Estado real de la última vigencia de cada permiso.</p></div><a href="<?=APP_URL?>/modulos/empresa_maquirenta/permiso_trabajo_santa_rosa.php" title="Ir a Permiso de trabajo"><i class="fa-solid fa-arrow-up-right-from-square"></i></a></div>
   <div class="permit-visual"><div class="permit-total"><span>Total</span><strong><?=$srPermitTotal?></strong><small>permisos</small></div><div class="permit-states">
   <?php foreach([['VIGENTE',$srPermitCurrent,'#198754','fa-circle-check','vigente'],['NO APTO',$srPermitExpired,'#dc3545','fa-circle-xmark','no_apto'],['CERRADO',$srPermitClosed,'#1d4ed8','fa-lock','cerrado']] as $state):?>
    <button class="permit-state permit-state-button dashboard-status-btn" type="button" data-module="sr_permisos" data-machine="0" data-machine-name="Central Térmica Santa Rosa" data-state="<?=$state[4]?>"><span><i class="fa-solid <?=$state[3]?>" style="color:<?=$state[2]?>"></i><?=$state[0]?></span><b><?=$state[1]?></b><div><i style="background:<?=$state[2]?>;width:<?=$percent((int)$state[1],$srPermitTotal)?>%"></i></div><small><?=$percent((int)$state[1],$srPermitTotal)?>% del total</small></button>
   <?php endforeach;?>
   </div></div>
  </article></div>
 </div>
</section>

<section class="formatos-dashboard mt-4">
 <header class="vent-heading formatos-heading"><div><small><i class="fa-solid fa-file-invoice"></i> EMPRESA MAQUIRENTA</small><h2>Formatos</h2><p>Indicadores organizados por estado documental.</p></div><span><b>3</b> estados</span></header>
 <div class="row g-3">
 <?php foreach([
  ['apto','TOTAL EN VERDE','Documentos aptos','fa-check','#15925f','format-card-green'],
  ['por_vencer','TOTAL EN AMARILLO','Documentos por vencer','fa-clock','#f59e0b','format-card-yellow'],
  ['no_apto','TOTAL EN ROJO','Documentos vencidos','fa-triangle-exclamation','#e52f45','format-card-red']
 ] as $item):?>
  <div class="col-lg-4">
   <button class="format-status-card dashboard-status-btn <?=$item[5]?>" type="button" data-module="formatos" data-machine="0" data-machine-name="Formatos" data-state="<?=$item[0]?>">
    <span class="format-status-icon"><i class="fa-solid <?=$item[3]?>"></i></span>
    <span class="format-status-copy"><small><?=$item[1]?></small><strong><?=$formatoCounts[$item[0]]?></strong><span><?=$item[2]?></span></span>
    <i class="fa-solid fa-chevron-right format-status-arrow"></i>
   </button>
  </div>
 <?php endforeach;?>
 </div>
</section>
<div class="modal fade" id="ventanillaStatusModal" tabindex="-1" aria-hidden="true">
 <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
  <div class="modal-content status-modal-content">
   <div class="modal-header status-modal-header"><div><small id="statusModalModule"></small><h5 class="modal-title" id="statusModalTitle">Detalle de registros</h5></div><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
   <div class="modal-body">
    <div class="status-modal-summary" id="statusModalSummary"></div>
    <div class="table-responsive"><table class="table align-middle status-modal-table"><thead><tr><th id="statusReferenceHead">Registro</th><th id="statusDetailHead">Detalle</th><th id="statusEndDateHead" class="d-none">Fecha de vencimiento</th><th id="statusThirdDateHead" class="d-none">F. Fin</th><th>Estado</th><th>Registrado por</th><th id="statusDocumentsHead">Documentos</th></tr></thead><tbody id="statusModalBody"></tbody></table></div>
    <div class="status-modal-empty d-none" id="statusModalEmpty"><i class="fa-solid fa-folder-open"></i><span>No existen registros para este estado.</span></div>
   </div>
   <div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button><a class="btn btn-primary" id="statusModalGo" href="#"><i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Ir al submódulo</a></div>
  </div>
 </div>
</div>
<style>
.vent-heading{align-items:center;background:linear-gradient(135deg,#0f172a,#1e3a8a);border-radius:14px;color:#fff;display:flex;justify-content:space-between;margin-bottom:1rem;padding:1rem 1.2rem}.vent-heading small{color:#93c5fd;font-size:.7rem;font-weight:800;letter-spacing:.06em}.vent-heading h2{font-size:1.25rem;margin:.15rem 0}.vent-heading p{color:#bfdbfe;margin:0}.vent-heading>span{background:#ffffff1f;border:1px solid #ffffff2e;border-radius:10px;padding:.5rem .7rem;white-space:nowrap}.vent-heading>span b{font-size:1.1rem;margin-right:.3rem}.module-panel{--accent:#2563eb;--soft:#eff6ff;background:#fff;border:1px solid #dbe3ef;border-top:4px solid var(--accent);border-radius:14px;height:100%;min-height:275px;padding:1rem 1.1rem}.module-blue{--accent:#2563eb;--soft:#eff6ff}.module-purple{--accent:#7c3aed;--soft:#f5f3ff}.module-cyan{--accent:#0891b2;--soft:#ecfeff}.module-orange{--accent:#ea580c;--soft:#fff7ed}.module-head{align-items:flex-start;display:flex;justify-content:space-between;margin-bottom:.85rem}.module-head small{color:var(--accent);font-size:.68rem;font-weight:900;letter-spacing:.08em}.module-head h3{font-size:1.05rem;margin:.12rem 0}.module-head p{color:#64748b;font-size:.76rem;margin:0}.module-head>a{align-items:center;background:var(--soft);border-radius:9px;color:var(--accent);display:flex;height:34px;justify-content:center;width:34px}.equipment-list{display:grid;gap:.6rem}.equipment-row{align-items:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:10px;display:grid;gap:.65rem;grid-template-columns:38px minmax(120px,1fr) auto auto;padding:.65rem}.equipment-icon{align-items:center;background:var(--soft);border-radius:9px;color:var(--accent);display:flex;height:36px;justify-content:center}.equipment-data>div:first-child{display:flex;justify-content:space-between;gap:.5rem}.equipment-data strong{font-size:.82rem}.equipment-data span{color:#64748b;font-size:.68rem}.mini-progress{background:#e2e8f0;border-radius:99px;height:5px;margin-top:.36rem;overflow:hidden}.mini-progress i{background:var(--accent);display:block;height:100%}.pms-number{background:#e0e7ff;border-radius:7px;color:#3730a3;font-size:.71rem;font-weight:800;padding:.3rem .45rem;white-space:nowrap}.state-badge{border-radius:999px;font-size:.65rem;font-weight:900;padding:.3rem .48rem;white-space:nowrap}.state-ok{background:#dcfce7;color:#15803d}.state-bad{background:#fee2e2;color:#dc2626}.date-value{color:#0f172a;font-size:.72rem;font-weight:700;text-align:right;white-space:nowrap}.date-value small{color:#64748b;display:block;font-size:.61rem}.pms-visual{align-items:center;background:linear-gradient(135deg,var(--soft),#fff);border:1px solid #cffafe;border-radius:12px;display:grid;gap:1.2rem;grid-template-columns:130px 1fr auto;min-height:175px;padding:1rem}.radial-progress{align-items:center;background:conic-gradient(var(--accent) calc(var(--progress)*1%),#dbeafe 0);border-radius:50%;display:flex;height:112px;justify-content:center;width:112px}.radial-progress:before{background:#fff;border-radius:50%;content:"";height:82px;position:absolute;width:82px}.radial-progress div{display:flex;flex-direction:column;position:relative;text-align:center}.radial-progress strong{font-size:1.25rem}.radial-progress span{color:#64748b;font-size:.62rem}.pms-summary{display:flex;flex-direction:column}.pms-summary>small{color:var(--accent);font-size:.65rem;font-weight:900}.pms-summary>strong{font-size:1.5rem}.pms-summary>span{color:#64748b;font-size:.75rem}.summary-stats{display:flex;gap:1rem;margin-top:.65rem}.summary-stats b{font-size:1rem}.summary-stats small{color:#64748b;font-size:.65rem;font-weight:600}.permit-visual{align-items:center;background:linear-gradient(135deg,#fff7ed,#fff);border:1px solid #fed7aa;border-radius:12px;display:grid;gap:1rem;grid-template-columns:105px 1fr;min-height:175px;padding:1rem}.permit-total{align-items:center;border-right:1px solid #fed7aa;display:flex;flex-direction:column}.permit-total span,.permit-total small{color:#9a3412;font-size:.7rem}.permit-total strong{color:#c2410c;font-size:2rem}.permit-states{display:grid;gap:.65rem;grid-template-columns:repeat(3,1fr)}.permit-state>span{font-size:.68rem;font-weight:800}.permit-state>span i{margin-right:.28rem}.permit-state>b{display:block;font-size:1.25rem}.permit-state>div{background:#e2e8f0;border-radius:99px;height:5px;overflow:hidden}.permit-state>div i{display:block;height:100%}.permit-state>small{color:#64748b;font-size:.62rem}.empty-visual{color:#64748b;padding:2rem;text-align:center}@media(max-width:767px){.vent-heading{align-items:flex-start;gap:.7rem}.equipment-row{grid-template-columns:36px 1fr}.pms-number,.state-badge,.date-value{justify-self:start;margin-left:46px}.pms-visual{grid-template-columns:1fr;text-align:center}.radial-progress{margin:auto}.permit-visual{grid-template-columns:1fr}.permit-total{border-bottom:1px solid #fed7aa;border-right:0;padding-bottom:.6rem}.permit-states{grid-template-columns:1fr}}

.detailed-list{max-height:190px;overflow:auto;padding-right:.2rem}.equipment-card{background:#f8fafc;border:1px solid #e2e8f0;border-radius:11px;padding:.72rem}.equipment-card-head{align-items:center;display:grid;gap:.65rem;grid-template-columns:38px minmax(110px,1fr) auto}.equipment-card-head>div:nth-child(2){display:flex;flex-direction:column}.equipment-card-head strong{font-size:.84rem}.equipment-card-head small{color:#64748b;font-size:.67rem}.status-counts{display:flex;gap:.4rem}.status-counts span{border-radius:7px;font-size:.63rem;font-weight:800;padding:.3rem .42rem;white-space:nowrap}.status-counts b{font-size:.8rem}.count-ok{background:#dcfce7;color:#15803d}.count-bad{background:#fee2e2;color:#dc2626}.split-progress{background:#e2e8f0;border-radius:99px;display:flex;height:6px;margin:.6rem 0;overflow:hidden}.split-progress i{display:block;height:100%}.split-ok{background:#16a34a}.split-bad{background:#ef4444}.visual-items>small{color:#64748b;font-size:.61rem;font-weight:800;letter-spacing:.05em}.visual-chips{display:flex;flex-wrap:wrap;gap:.3rem;margin-top:.3rem}.visual-chips span{align-items:center;border:1px solid;border-radius:999px;display:inline-flex;font-size:.64rem;font-weight:800;gap:.25rem;padding:.24rem .42rem}.chip-ok{background:#f0fdf4;border-color:#86efac!important;color:#15803d}.chip-bad{background:#fff1f2;border-color:#fda4af!important;color:#dc2626}.empty-visual{align-items:center;display:flex;flex-direction:column;gap:.45rem}.empty-visual i{color:#94a3b8;font-size:1.4rem}@media(max-width:767px){.equipment-card-head{grid-template-columns:36px 1fr}.status-counts{grid-column:1/-1;margin-left:46px}}

.status-counts button{border:0;cursor:pointer;transition:.18s}.status-counts button:hover{box-shadow:0 0 0 2px currentColor;transform:translateY(-1px)}.status-counts button:focus-visible{outline:2px solid #2563eb;outline-offset:2px}.status-modal-content{border:0;border-radius:15px;box-shadow:0 22px 60px rgba(15,23,42,.25);overflow:hidden}.status-modal-header{background:linear-gradient(135deg,#f8fafc,#eff6ff);border-bottom:1px solid #dbeafe}.status-modal-header small{color:#2563eb;font-size:.68rem;font-weight:900;letter-spacing:.08em}.status-modal-header h5{font-weight:800}.status-modal-summary{align-items:center;border-radius:11px;display:flex;gap:.7rem;margin-bottom:1rem;padding:.75rem .9rem}.status-modal-summary>i{font-size:1.35rem}.status-modal-summary div{display:flex;flex-direction:column}.status-modal-summary span{font-size:.75rem}.summary-ok{background:#dcfce7;color:#15803d}.summary-bad{background:#fee2e2;color:#dc2626}.status-modal-table thead th{background:#f8fafc;color:#475569;font-size:.7rem;text-transform:uppercase}.status-modal-table td{font-size:.82rem}.status-modal-empty{align-items:center;color:#64748b;display:flex;flex-direction:column;gap:.5rem;padding:2rem}.status-modal-empty i{font-size:1.5rem}

/* Contadores interactivos con la misma apariencia de los estados de PMS */
.status-counts{align-items:center;gap:.45rem}
.status-counts .dashboard-status-btn{align-items:center;border:1px solid transparent;border-radius:999px;display:inline-flex;font-size:.65rem;font-weight:900;justify-content:center;line-height:1;min-height:27px;padding:.38rem .58rem;white-space:nowrap}
.status-counts .dashboard-status-btn b{font-size:.72rem;margin-right:.2rem}
.status-counts .count-ok{background:#dcfce7;border-color:#bbf7d0;color:#15803d}
.status-counts .count-bad{background:#fee2e2;border-color:#fecaca;color:#dc2626}
.status-counts .count-ok:hover{background:#bbf7d0;box-shadow:0 3px 9px rgba(21,128,61,.18)}
.status-counts .count-bad:hover{background:#fecaca;box-shadow:0 3px 9px rgba(220,38,38,.18)}

.pms-dashboard-visual{grid-template-columns:130px 1fr}.pms-status-grid{display:grid;gap:.55rem;grid-template-columns:repeat(2,minmax(105px,1fr));margin-top:.8rem}.pms-status-card{align-items:flex-start;background:#fff;border:1px solid;border-radius:10px;cursor:pointer;display:flex;flex-direction:column;padding:.55rem .65rem;text-align:left;transition:.18s}.pms-status-card>span{font-size:.64rem;font-weight:900}.pms-status-card>strong{font-size:1.2rem;line-height:1.15}.pms-status-card>small{font-size:.64rem}.pms-status-ok{background:#f0fdf4;border-color:#86efac;color:#15803d}.pms-status-bad{background:#fff1f2;border-color:#fda4af;color:#dc2626}.pms-status-card:hover{box-shadow:0 4px 12px rgba(15,23,42,.12);transform:translateY(-1px)}@media(max-width:767px){.pms-dashboard-visual{grid-template-columns:1fr}.pms-status-grid{grid-template-columns:repeat(2,1fr)}}
.permit-state-button{background:transparent;border:1px solid transparent;border-radius:9px;color:#0f172a;cursor:pointer;padding:.42rem;text-align:left;transition:.18s}.permit-state-button:hover{background:#fff;border-color:#fed7aa;box-shadow:0 4px 12px rgba(154,52,18,.1);transform:translateY(-1px)}.permit-state-button:focus-visible{outline:2px solid #ea580c;outline-offset:2px}.summary-closed{background:#dbeafe;color:#1e40af}.state-closed{background:#dbeafe;color:#1e40af}.modal-documents{display:flex;flex-wrap:wrap;gap:.3rem}.modal-download-btn{align-items:center;background:#ecfdf5;border:1px solid #86efac;border-radius:7px;color:#047857;display:inline-flex;font-size:.68rem;font-weight:800;gap:.3rem;padding:.32rem .46rem;text-decoration:none;white-space:nowrap}.modal-download-btn:hover{background:#d1fae5;color:#065f46}.no-document{color:#94a3b8;font-size:.72rem;font-style:italic}
/* Documentos compactos en una sola fila para todos los objetos del Dashboard */
.status-modal-table th:last-child,.status-modal-table td:last-child{min-width:190px;white-space:nowrap}
.modal-documents{align-items:center;flex-wrap:nowrap!important;gap:.25rem;white-space:nowrap}
.modal-download-btn{font-size:.64rem;padding:.3rem .4rem}

.formatos-section-head{align-items:center;display:flex;gap:1rem;justify-content:space-between;margin-bottom:.85rem}.formatos-section-head small{color:#2563eb;font-size:.68rem;font-weight:900;letter-spacing:.08em}.formatos-section-head h2{font-size:1.25rem;margin:.1rem 0}.formatos-section-head p{color:#64748b;font-size:.78rem;margin:0}.formatos-section-head>a{align-items:center;border:1px solid #cbd5e1;border-radius:9px;color:#1e40af;display:flex;font-size:.8rem;font-weight:700;gap:.45rem;padding:.55rem .75rem;text-decoration:none}.format-status-card{align-items:center;border:1px solid;border-radius:14px;cursor:pointer;display:flex;gap:1rem;min-height:120px;padding:1rem 1.2rem;position:relative;text-align:left;transition:.18s;width:100%}.format-status-card:hover{box-shadow:0 8px 20px rgba(15,23,42,.12);transform:translateY(-2px)}.format-status-card:focus-visible{outline:3px solid rgba(37,99,235,.2);outline-offset:2px}.format-status-icon{align-items:center;border-radius:50%;color:#fff;display:flex;flex:0 0 54px;font-size:1.25rem;height:54px;justify-content:center}.format-status-copy{display:flex;flex-direction:column}.format-status-copy small{font-size:.68rem;font-weight:900;letter-spacing:.05em}.format-status-copy strong{color:#0f172a;font-size:2rem;line-height:1.05}.format-status-copy>span{color:#334155;font-size:.82rem}.format-status-arrow{opacity:0;position:absolute;right:1rem;transition:.18s}.format-status-card:hover .format-status-arrow{opacity:.65;transform:translateX(2px)}.format-card-green{background:#effcf5;border-color:#b9ead1;color:#087c4b}.format-card-green .format-status-icon{background:#15925f}.format-card-yellow{background:#fffbeb;border-color:#fde2a8;color:#d97706}.format-card-yellow .format-status-icon{background:#f59e0b}.format-card-red{background:#fff1f3;border-color:#fecdd3;color:#d9273d}.format-card-red .format-status-icon{background:#e52f45}.state-warning{background:#fef3c7;color:#b45309}.summary-warning{background:#fef3c7;color:#b45309}@media(max-width:767px){.formatos-section-head{align-items:flex-start;flex-direction:column}.formatos-section-head>a{align-self:stretch;justify-content:center}}
</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
 const records=<?=json_encode($modalRecords,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>,element=document.getElementById('ventanillaStatusModal'),modal=bootstrap.Modal.getOrCreateInstance(element),body=document.getElementById('statusModalBody'),empty=document.getElementById('statusModalEmpty'),esc=value=>{const node=document.createElement('div');node.textContent=String(value??'');return node.innerHTML};
 document.querySelectorAll('.dashboard-status-btn').forEach(button=>button.addEventListener('click',()=>{
  const module=button.dataset.module,machine=button.dataset.machine,state=button.dataset.state;
  const rows=records.filter(row=>row.modulo===module&&String(row.maquinaria_id)===machine&&row.estado===state);
  const isSanta=module.startsWith('sr_'),isReports=module==='informes'||module==='sr_informes',isPms=module==='pms'||module==='sr_pms',isPermits=module==='permisos'||module==='sr_permisos',isFormats=module==='formatos';
  const isPositive=state==='apto'||state==='vigente',isClosed=state==='cerrado',isWarning=state==='por_vencer';
  const statusLabel=isClosed?'CERRADOS':(isWarning?'POR VENCER':(isPositive?(state==='vigente'?'VIGENTES':'APTOS'):'NO APTOS'));
  document.getElementById('statusModalModule').textContent=isFormats?'FORMATOS':(isReports?'INFORMES':(isPms?'PMS':(isPermits?'PERMISO DE TRABAJO':'CHARLA PREOPERACIONAL')));
  document.getElementById('statusModalTitle').textContent='Registros '+statusLabel+' - '+(button.dataset.machineName||'');
  document.getElementById('statusReferenceHead').textContent=isFormats?'Documentos':((isReports||isPms)?'Nro. PMS':(isPermits?'Permiso de trabajo':'Fecha'));
  document.getElementById('statusDetailHead').textContent=isPermits?'Fecha de inicio':(isFormats?'F. Registro':'Detalle');
  const secondDateHead=document.getElementById('statusEndDateHead');
  secondDateHead.textContent=isFormats?'F. Inicio':'Fecha de vencimiento';
  secondDateHead.classList.toggle('d-none',!(isPermits||isFormats));
  document.getElementById('statusThirdDateHead').classList.toggle('d-none',!isFormats);
  document.getElementById('statusDocumentsHead').textContent=isFormats?'Adjunto':'Documentos';
  const summary=document.getElementById('statusModalSummary');
  summary.className='status-modal-summary '+(isClosed?'summary-closed':(isWarning?'summary-warning':(isPositive?'summary-ok':'summary-bad')));
  const description=isFormats?(isWarning?'Registro próximo a vencer':(isPositive?'Registro vigente':'Registro vencido')):(isPermits?(isClosed?'Vigencia cerrada con documento':(isPositive?'Registro dentro de fecha':'Registro fuera de fecha')):(isPositive?'Registro con archivo adjunto':'Registro sin archivo adjunto'));
  summary.innerHTML='<i class="fa-solid '+(isClosed?'fa-lock':(isWarning?'fa-clock':(isPositive?'fa-circle-check':'fa-circle-xmark')))+'"></i><div><strong>'+rows.length+' '+(rows.length===1?'registro':'registros')+'</strong><span>'+description+'</span></div>';
  body.innerHTML=rows.map(row=>{const badgeClass=isClosed?'state-closed':(isWarning?'state-warning':(isPositive?'state-ok':'state-bad')),label=isClosed?'CERRADO':(isWarning?'POR VENCER':(state==='vigente'?'VIGENTE':(state==='apto'?'APTO':'NO APTO'))),documents=(row.documentos||[]).map((doc,index)=>'<a class="modal-download-btn" href="'+esc(doc.url)+'" title="'+esc(doc.name)+'"><i class="fa-solid fa-download"></i><span>'+(isPermits?'Doc. '+(index+1):'Doc.')+'</span></a>').join('');return '<tr><td><strong>'+esc(row.referencia)+'</strong></td><td>'+esc(isPermits?row.fecha_inicio_modal:(isFormats?row.fecha_registro_modal:row.periodo))+'</td>'+(isPermits?'<td>'+esc(row.fecha_vencimiento_modal)+'</td>':(isFormats?'<td>'+esc(row.fecha_inicio_modal)+'</td><td>'+esc(row.fecha_fin_modal)+'</td>':''))+'<td><span class="state-badge '+badgeClass+'">'+label+'</span></td><td>'+esc(row.registrado_por)+'</td><td><div class="modal-documents">'+(documents||'<span class="no-document">Sin documento</span>')+'</div></td></tr>'}).join('');
  empty.classList.toggle('d-none',rows.length>0);body.closest('.table-responsive').classList.toggle('d-none',rows.length===0);
  const targetBase=isFormats?'formatos':(isReports?'informes':(isPms?'pms':(isPermits?'permiso_trabajo':'charla_preoperacional')));
  document.getElementById('statusModalGo').href='<?=APP_URL?>/modulos/empresa_maquirenta/'+targetBase+(isSanta?'_santa_rosa':'')+'.php';
  modal.show();
 }));
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
