<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('redaccion.whatsapp');
function whatsapp_short_name(string $name): string { $p = preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: []; return count($p) <= 2 ? implode(' ', $p) : $p[0] . ' ' . $p[count($p) - 2]; }
$clients = db()->query("SELECT id,business_name FROM clients WHERE TRIM(COALESCE(business_name,''))<>'' ORDER BY business_name,id")->fetchAll();
$workers = db()->query("SELECT w.id,w.full_name,w.document_number,GROUP_CONCAT(DISTINCT p.name ORDER BY p.name SEPARATOR ', ') AS positions FROM workers w LEFT JOIN worker_positions wp ON wp.worker_id=w.id LEFT JOIN positions p ON p.id=wp.position_id WHERE TRIM(COALESCE(w.full_name,''))<>'' GROUP BY w.id,w.full_name,w.document_number ORDER BY w.full_name,w.id")->fetchAll();
$workerPositionRows = db()->query("SELECT DISTINCT wp.worker_id, p.name FROM worker_positions wp INNER JOIN positions p ON p.id = wp.position_id ORDER BY p.name, p.id")->fetchAll();
$workerPositionsById = [];
foreach ($workerPositionRows as $workerPosition) {
    $workerId = (int) $workerPosition['worker_id'];
    $workerPositionsById[$workerId][] = (string) $workerPosition['name'];
}
$machines = db()->query("SELECT id,equipo,serie_placa,capacidad FROM maquinarias WHERE estado=1 ORDER BY equipo,serie_placa,id")->fetchAll();
require __DIR__ . '/../../includes/header.php';
?>
<style>
#whatsappComposer .composer-card{border:1px solid #8da6bf;border-radius:15px;overflow:hidden;box-shadow:0 7px 18px rgba(22,55,87,.08)}#whatsappComposer .composer-card-header{padding:.8rem 1rem;background:linear-gradient(135deg,#173a62,#294f78);color:#fff}#whatsappComposer .composer-card-header h2{margin:0;font-size:.98rem;font-weight:700}#whatsappComposer .composer-card-body{padding:1rem;background:#f6f9fc}#whatsappComposer .form-label{font-weight:700;color:#294f78;font-size:.82rem}.picker-tools{display:flex;margin-bottom:.45rem}.picker-tools .input-group-text{background:#fff;color:#52687d}.picker-list{display:flex;flex-wrap:wrap;gap:.4rem;max-height:155px;overflow:auto;padding:.48rem;border:1px solid #b8cce0;border-radius:10px;background:#fff}.picker-option{display:flex;align-items:center;gap:.38rem;margin:0;padding:.34rem .55rem;border:1px solid #c5d8ec;border-radius:8px;background:#f7fbff;color:#214c7c;font-size:.8rem;cursor:pointer}.picker-option:hover{background:#edf5ff}.picker-option input{margin:0}.picker-option.is-selected{border-color:#23864c;background:#e9f8ee;color:#176437}.picker-option.is-filtered-out{display:none!important}.picker-list#workerList{display:block;max-height:255px;padding:.3rem}.picker-list#workerList .picker-option{border:0;border-radius:8px;background:#fff;padding:.52rem .55rem}.picker-list#workerList .picker-option:hover{background:#edf5ff}.picker-list#workerList .picker-option.is-selected{background:#e9f8ee}.picker-personal{display:flex!important;align-items:flex-start!important;gap:.7rem!important;width:100%}.picker-personal input{margin-top:.2rem}.picker-personal div{min-width:0}.picker-personal strong{display:block}.picker-personal+.picker-personal{border-top:1px solid #e6eef5!important}.picker-personal small{display:block;margin:0;margin-top:.12rem;color:#627e9e}#whatsappComposer .whatsapp-role-picker{margin:-.08rem .4rem .5rem 2rem;padding:.45rem .6rem;border-left:3px solid #5b94bc;background:#eef6fb;color:#365772;font-size:.74rem}#whatsappComposer .whatsapp-role-picker[hidden],#whatsappComposer .whatsapp-role-picker.is-filtered-out{display:none!important}#whatsappComposer .whatsapp-role-picker-title{display:block;margin-bottom:.3rem;font-weight:700}#whatsappComposer .whatsapp-role-options{display:flex;flex-wrap:wrap;gap:.3rem .65rem}#whatsappComposer .whatsapp-role-option{display:inline-flex;align-items:center;gap:.3rem;margin:0;cursor:pointer;color:#294b67;font-size:.74rem}#whatsappComposer .whatsapp-role-option input{margin:0}.whatsapp-preview{border:1px solid #b8d9c1;border-radius:15px;overflow:hidden;box-shadow:0 7px 18px rgba(22,93,49,.10);position:sticky;top:1rem}.whatsapp-preview-header{background:#1f7a45;color:#fff;padding:.8rem 1rem;display:flex;align-items:center;justify-content:space-between}.whatsapp-preview-header h2{margin:0;color:#fff;font-size:.98rem;font-weight:700}.whatsapp-preview-body{padding:1.1rem;background:#f4fbf6;min-height:350px}.whatsapp-preview-content{margin:0;white-space:pre-wrap;font-family:Arial,sans-serif;font-size:.94rem;line-height:1.65;color:#183928}@media(max-width:991.98px){.whatsapp-preview{position:static}}
</style>
<div class="page-title"><div><h1>WhatsApp</h1><p>Prepare un mensaje para copiar y enviar por WhatsApp.</p></div></div>
<div class="row g-4" id="whatsappComposer">
    <div class="col-lg-6">
        <section class="composer-card mb-3"><header class="composer-card-header"><h2><i class="fa-brands fa-whatsapp me-2"></i>Datos del mensaje</h2></header><div class="composer-card-body">
            <div class="mb-3"><label class="form-label" for="whatsappDate">Fecha</label><input class="form-control" id="whatsappDate" type="date" value="<?= date('Y-m-d') ?>"></div>
            <div class="mb-3"><label class="form-label" for="whatsappClient">Cliente</label><select class="form-select" id="whatsappClient"><option value="">Seleccione el cliente</option><?php foreach($clients as $client): ?><option value="<?= (int)$client['id'] ?>"><?= e($client['business_name']) ?></option><?php endforeach; ?></select></div>
            <div><label class="form-label" for="whatsappInspector">Inspector</label><input class="form-control" id="whatsappInspector" type="text" maxlength="150" placeholder="Escriba el nombre del inspector"></div>
        </div></section>
        <section class="composer-card"><header class="composer-card-header"><h2><i class="fa-solid fa-people-group me-2"></i>Personal</h2></header><div class="composer-card-body">
            <div class="picker-tools"><div class="input-group"><button class="btn btn-outline-secondary" type="button" id="workerSearchButton" aria-label="Buscar personal"><i class="fa-solid fa-magnifying-glass"></i></button><input class="form-control" id="workerSearch" type="search" placeholder="Buscar por nombre, DNI o cargo"></div></div>
            <div class="picker-list" id="workerList">
                <?php foreach ($workers as $worker): ?>
                    <?php
                    $workerId = (int) $worker['id'];
                    $workerRoles = $workerPositionsById[$workerId] ?? [];
                    $short = whatsapp_short_name((string) $worker['full_name']);
                    $position = (string) ($worker['positions'] ?: 'Sin cargo registrado');
                    $workerInputId = 'whatsappWorker' . $workerId;
                    ?>
                    <div class="picker-option picker-personal" data-search="<?= e(mb_strtolower($worker['full_name'] . ' ' . $worker['document_number'] . ' ' . $position, 'UTF-8')) ?>">
                        <input class="form-check-input whatsapp-worker" type="checkbox" id="<?= e($workerInputId) ?>" value="<?= $workerId ?>" data-name="<?= e($short) ?>" data-document="<?= e((string) $worker['document_number']) ?>" data-position="<?= e($position) ?>">
                        <label for="<?= e($workerInputId) ?>"><strong><?= e($worker['full_name']) ?></strong><small>DNI: <?= e((string) $worker['document_number']) ?> · Cargo: <?= e($position) ?></small></label>
                    </div>
                    <?php if ($workerRoles): ?>
                        <div class="whatsapp-role-picker" data-worker-id="<?= $workerId ?>" hidden>
                            <span class="whatsapp-role-picker-title">Cargos que se incluirán en el mensaje</span>
                            <div class="whatsapp-role-options">
                                <?php foreach ($workerRoles as $roleIndex => $workerRole): ?>
                                    <?php $roleInputId = $workerInputId . 'Role' . $roleIndex; ?>
                                    <label class="whatsapp-role-option" for="<?= e($roleInputId) ?>"><input class="form-check-input whatsapp-worker-position" type="checkbox" id="<?= e($roleInputId) ?>" data-worker-id="<?= $workerId ?>" data-position="<?= e($workerRole) ?>"> <?= e($workerRole) ?></label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div></section>
        <section class="composer-card mt-3"><header class="composer-card-header"><h2><i class="fa-solid fa-truck me-2"></i>Equipos</h2></header><div class="composer-card-body">
            <div class="picker-tools"><div class="input-group"><button class="btn btn-outline-secondary" type="button" id="machineSearchButton" aria-label="Buscar equipos"><i class="fa-solid fa-magnifying-glass"></i></button><input class="form-control" id="machineSearch" type="search" placeholder="Buscar equipo, serie o placa"></div></div>
            <div class="picker-list" id="machineList"><?php foreach($machines as $machine): $label=implode(' ', array_filter(array_map('trim', [$machine['equipo'], $machine['serie_placa'] ?? '', $machine['capacidad'] ?? '']), static fn($part) => $part !== '')); ?><label class="picker-option" data-search="<?= e(mb_strtolower($label,'UTF-8')) ?>"><input class="form-check-input whatsapp-machine" type="checkbox" data-name="<?= e($label) ?>"><span><?= e($label) ?></span></label><?php endforeach; ?></div>
        </div></section>
    </div>
    <div class="col-lg-6"><article class="whatsapp-preview"><header class="whatsapp-preview-header"><h2><i class="fa-brands fa-whatsapp me-2"></i>Mensaje a mostrar</h2><div class="d-flex gap-2"><button class="btn btn-light btn-sm" type="button" id="clearWhatsappMessage"><i class="fa-solid fa-eraser me-1"></i>Limpiar</button><button class="btn btn-light btn-sm" type="button" id="copyWhatsappMessage"><i class="fa-regular fa-copy me-1"></i>Copiar</button></div></header><div class="whatsapp-preview-body"><div class="whatsapp-preview-content" id="whatsappMessagePreview" contenteditable="true" role="textbox" aria-multiline="true" aria-label="Mensaje editable"></div></div></article></div>
</div>
<script>
const whatsappDate=document.getElementById('whatsappDate'),whatsappDefaultDate=whatsappDate.value;
const whatsappDateText=()=>{const value=whatsappDate.value;if(!value)return '[fecha]';const [year,month,day]=value.split('-');return `${day}/${month}/${year}`};
const client=document.getElementById('whatsappClient'),inspector=document.getElementById('whatsappInspector'),preview=document.getElementById('whatsappMessagePreview');
const chosen=(selector,fallback)=>{const values=[...document.querySelectorAll(selector+':checked')].map(input=>input.dataset.name);return values.length?values.join(', '):fallback};
const personalMessage=()=>{const workers=[...document.querySelectorAll('.whatsapp-worker:checked')],lines=workers.flatMap(input=>{const selectedPositions=[...document.querySelectorAll('.whatsapp-worker-position:checked')].filter(position=>position.dataset.workerId===input.value).map(position=>position.dataset.position),positions=input.dataset.rolesReady?selectedPositions:[input.dataset.position||'Sin cargo registrado'];return positions.map(position=>`\u2022 ${position}: ${input.dataset.name}`)});return `*Personal*\n${lines.length?lines.join('\n'):'[personal]'}`};
function syncWhatsappWorkerRoles(input){const picker=[...document.querySelectorAll('.whatsapp-role-picker')].find(item=>item.dataset.workerId===input.value);if(!picker)return;picker.hidden=!input.checked;if(input.checked&&!input.dataset.rolesReady){picker.querySelectorAll('.whatsapp-worker-position').forEach(position=>{position.checked=true});input.dataset.rolesReady='true'}}
const equipmentMessage=()=>{const machines=[...document.querySelectorAll('.whatsapp-machine:checked')];return `*Equipo*\n${machines.length?machines.map(input=>`\u2022 ${input.dataset.name}`).join('\n'):'[equipo y serie o placa]'}`};
const message=()=>[`*Fecha: ${whatsappDateText()}*`,`Cliente: ${client.value?client.options[client.selectedIndex].textContent.trim():'[raz\u00f3n social]'}`,`Inspector: ${inspector.value.trim()||'[inspector]'}`,personalMessage(),equipmentMessage()].join('\n');
const update=()=>{preview.dataset.edited='false';preview.textContent=message()};
const filter=(inputId,buttonId,listId)=>{const input=document.getElementById(inputId),button=document.getElementById(buttonId),apply=()=>{const term=input.value.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();document.querySelectorAll('#'+listId+' .picker-option').forEach(item=>{const source=(item.dataset.search||'').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase(),hidden=!source.includes(term);item.classList.toggle('is-filtered-out',hidden);const worker=item.querySelector('.whatsapp-worker'),picker=worker?[...document.querySelectorAll('.whatsapp-role-picker')].find(rolePicker=>rolePicker.dataset.workerId===worker.value):null;if(picker)picker.classList.toggle('is-filtered-out',hidden)})};input.addEventListener('keydown',event=>{if(event.key==='Enter'){event.preventDefault();apply()}});button.addEventListener('click',apply)};
filter('workerSearch','workerSearchButton','workerList');filter('machineSearch','machineSearchButton','machineList');whatsappDate.addEventListener('change',update);client.addEventListener('change',update);inspector.addEventListener('input',update);document.querySelectorAll('.whatsapp-worker').forEach(input=>input.addEventListener('change',()=>{input.closest('.picker-option').classList.toggle('is-selected',input.checked);syncWhatsappWorkerRoles(input);update()}));document.querySelectorAll('.whatsapp-machine').forEach(input=>input.addEventListener('change',()=>{input.closest('.picker-option').classList.toggle('is-selected',input.checked);update()}));document.querySelectorAll('.whatsapp-worker-position').forEach(input=>input.addEventListener('change',update));
preview.addEventListener('input',()=>{preview.dataset.edited='true'});
function clearWhatsappComposer(){whatsappDate.value=whatsappDefaultDate;client.value='';inspector.value='';document.querySelectorAll('.whatsapp-worker,.whatsapp-machine,.whatsapp-worker-position').forEach(input=>{input.checked=false;if(input.closest('.picker-option'))input.closest('.picker-option').classList.remove('is-selected')});document.querySelectorAll('.whatsapp-worker').forEach(input=>{delete input.dataset.rolesReady});document.querySelectorAll('.whatsapp-role-picker').forEach(picker=>{picker.hidden=true});['workerSearch','machineSearch'].forEach(id=>{const input=document.getElementById(id);input.value='';document.getElementById(id==='workerSearch'?'workerSearchButton':'machineSearchButton').click()});if(window.jQuery&&jQuery(client).data('select2'))jQuery(client).val(null).trigger('change.select2');update()}
document.getElementById('clearWhatsappMessage').addEventListener('click',clearWhatsappComposer);
document.getElementById('copyWhatsappMessage').addEventListener('click',async()=>{const text=preview.dataset.edited==='true'?preview.innerText.trim():message();try{await navigator.clipboard.writeText(text)}catch(e){const area=document.createElement('textarea');area.value=text;document.body.append(area);area.select();document.execCommand('copy');area.remove()}Swal.fire({icon:'success',title:'Mensaje listo para WhatsApp',timer:1400,showConfirmButton:false})});
window.addEventListener('load',()=>{if(window.jQuery&&jQuery.fn.select2)jQuery(client).select2({theme:'bootstrap4',width:'100%',placeholder:'Buscar cliente por raz\u00f3n social',allowClear:true}).on('change',update);update()});
update();
</script>
<script src="<?= APP_URL ?>/recursos/js/redaccion_notificaciones.js?v=<?= filemtime(__DIR__ . '/../../recursos/js/redaccion_notificaciones.js') ?>"></script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
