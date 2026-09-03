<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_module_access('control_personal.personal');

$sql = "SELECT w.*, c.name AS company,
        GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ', ') AS positions
        FROM workers w
        LEFT JOIN companies c ON c.id = w.company_id
        LEFT JOIN worker_positions wp ON wp.worker_id = w.id
        LEFT JOIN positions p ON p.id = wp.position_id
        GROUP BY w.id
        ORDER BY w.full_name";
$workers = db()->query($sql)->fetchAll();

function worker_progress(array $w): int
{
    $fields = ['company_id','full_name','document_type','document_number','blood_type','address','phone','email','birth_date','photo_path','signature_path','positions'];
    $done = 0;
    foreach ($fields as $field) {
        if (!empty($w[$field])) {
            $done++;
        }
    }
    return (int) round(($done / count($fields)) * 100);
}

require __DIR__ . '/../../includes/header.php';
?>
<div class="page-title">
    <div>
        <h1>Personal</h1>
        <p>Gestión de trabajadores aliados.</p>
    </div>
    <a class="btn btn-primary" href="<?= APP_URL ?>/modulos/aliados/formulario_personal.php"><i class="fa-solid fa-plus me-2"></i>Nuevo</a>
</div>
<div class="work-panel">
    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap mb-3 replica-toolbar">
        <div class="d-flex align-items-center gap-2 flex-wrap">
            <button class="btn btn-sm btn-outline-primary" type="button" id="selectAllWorkersBtn"><i class="fa-solid fa-check-double me-1"></i>Seleccionar todo</button>
            <button class="btn btn-sm btn-outline-secondary d-none" type="button" id="clearWorkerSelectionBtn">Limpiar selección</button>
            <span class="small text-muted" id="workerSelectionCount">0 seleccionados</span>
        </div>
        <button class="btn btn-primary" type="button" id="openReplicationBtn" disabled><i class="fa-solid fa-copy me-2"></i>Replicar datos a Maquirenta</button>
    </div>
    <div class="table-responsive personal-table-responsive">
        <table class="table table-hover align-middle data-table" id="personalTable">
            <thead>
            <tr>
                <th class="text-center replica-select-column" data-orderable="false" title="Seleccionar personal">Sel.</th>
                <th>Empresa</th>
                <th class="text-nowrap" title="Tipo de documento">Tipo</th>
                <th class="text-nowrap" title="Número de documento">N.º doc.</th>
                <th>Apellidos y Nombres</th>
                <th>Correo</th>
                <th>Progreso</th>
                <th>Estado</th>
                <th>Acción</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($workers as $worker): $progress = worker_progress($worker); ?>
                <tr>
                    <td class="text-center replica-select-column"><input class="form-check-input js-worker-replica" type="checkbox" value="<?= (int) $worker['id'] ?>" aria-label="Seleccionar <?= e($worker['full_name']) ?>"></td>
                    <td><?= e($worker['company'] ?? '') ?></td>
                    <td><?= e($worker['document_type']) ?></td>
                    <td><?= e($worker['document_number']) ?></td>
                    <td><?= e($worker['full_name']) ?></td>
                    <td><?= e($worker['email'] ?? '') ?></td>
                    <td>
                        <?php if ($progress === 100): ?>
                            <span class="badge text-bg-success">VALIDADO</span>
                        <?php else: ?>
                            <div class="progress progress-thin"><div class="progress-bar" style="width: <?= $progress ?>%"></div></div>
                            <span class="small"><?= $progress ?>%</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($progress === 100): ?>
                            <span class="badge text-bg-success">Activo</span>
                        <?php else: ?>
                            <span class="badge text-bg-danger">Inactivo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex gap-1">
                            <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modulos/aliados/formulario_personal.php?id=<?= (int) $worker['id'] ?>" title="Editar"><i class="fa-solid fa-pen-to-square"></i></a>
                            <button class="btn btn-sm btn-outline-danger js-eliminar-personal" type="button" data-id="<?= (int) $worker['id'] ?>" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="replicationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content replica-modal-content">
            <div class="modal-header">
                <div><h5 class="modal-title"><i class="fa-solid fa-copy me-2 text-primary"></i>Replicar datos a Empresa Maquirenta</h5><small class="text-muted">Revise el personal, los puestos y documentos PMI que serán copiados.</small></div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-primary py-2"><i class="fa-solid fa-circle-info me-2"></i>Todos los elementos aparecen seleccionados. Puede desmarcar lo que no desea replicar.</div>
                <div id="replicationPreview" class="replication-preview"></div>
            </div>
            <div class="modal-footer justify-content-between">
                <span class="small text-muted" id="replicationSummary"></span>
                <div class="d-flex gap-2"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="button" id="executeReplicationBtn"><i class="fa-solid fa-arrow-right-arrow-left me-2"></i>Proceder con la réplica</button></div>
            </div>
        </div>
    </div>
</div>
<style>
.replica-select-column{width:42px!important;min-width:42px!important;max-width:42px!important;padding-left:.25rem!important;padding-right:.25rem!important}
@media (min-width:1300px){.personal-table-responsive{overflow-x:auto}#personalTable{width:100%!important;table-layout:fixed}#personalTable th,#personalTable td{padding-left:.38rem;padding-right:.38rem;font-size:.82rem;overflow-wrap:anywhere}#personalTable th{white-space:nowrap;overflow-wrap:normal}#personalTable th:nth-child(1){width:4%!important}#personalTable th:nth-child(2){width:16%!important}#personalTable th:nth-child(3){width:7%!important}#personalTable th:nth-child(4){width:11%!important}#personalTable th:nth-child(5){width:16%!important}#personalTable th:nth-child(6){width:18%!important}#personalTable th:nth-child(7){width:13%!important}#personalTable th:nth-child(8){width:9%!important}#personalTable th:nth-child(9){width:6%!important}#personalTable td:nth-child(6){overflow:hidden;text-overflow:ellipsis;white-space:nowrap}#personalTable td:nth-child(9) .d-flex{justify-content:center;gap:.2rem!important}#personalTable td:nth-child(9) .btn{padding:.3rem .42rem}}
@media (max-width:1299.98px){#personalTable{min-width:1000px!important;table-layout:auto}.personal-table-responsive{overflow-x:auto;padding-bottom:.25rem}}.replica-toolbar{padding:.7rem .8rem;background:#f7f9fc;border:1px solid #dce5f2;border-radius:.65rem}.replication-preview{display:grid;gap:.8rem}.replica-worker{border:1px solid #d9e2ef;border-radius:.75rem;overflow:hidden}.replica-worker-head{display:flex;align-items:center;gap:.65rem;padding:.75rem 1rem;background:#f5f8fd}.replica-worker-body{padding:.75rem 1rem}.replica-position{border-left:3px solid #2f6fed;padding:.35rem 0 .35rem .75rem;margin:.35rem 0 .7rem}.replica-requirements{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:.35rem;margin:.45rem 0 0 1.6rem}.replica-requirement{padding:.45rem .55rem;background:#f8fafc;border:1px solid #e4eaf2;border-radius:.45rem}.replica-empty{color:#718096;font-size:.82rem;margin-left:1.6rem}
</style>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const selected = new Set();
    const table = document.getElementById('personalTable');
    const count = document.getElementById('workerSelectionCount');
    const openButton = document.getElementById('openReplicationBtn');
    const clearButton = document.getElementById('clearWorkerSelectionBtn');
    const preview = document.getElementById('replicationPreview');
    const summary = document.getElementById('replicationSummary');
    const executeButton = document.getElementById('executeReplicationBtn');
    const modal = new bootstrap.Modal(document.getElementById('replicationModal'));
    const esc = value => { const el=document.createElement('div'); el.textContent=value ?? ''; return el.innerHTML; };

    function syncSelection() {
        table.querySelectorAll('.js-worker-replica').forEach(box => box.checked=selected.has(box.value));
        count.textContent = `${selected.size} seleccionado${selected.size===1?'':'s'}`;
        openButton.disabled = selected.size===0;
        clearButton.classList.toggle('d-none', selected.size===0);
    }
    table.addEventListener('change', event => { if(!event.target.classList.contains('js-worker-replica')) return; event.target.checked ? selected.add(event.target.value) : selected.delete(event.target.value); syncSelection(); });
    if (window.jQuery) $('#personalTable').on('draw.dt', syncSelection);
    document.getElementById('selectAllWorkersBtn').addEventListener('click', function () {
        const api = window.jQuery && $.fn.DataTable.isDataTable('#personalTable') ? $('#personalTable').DataTable() : null;
        const boxes = api ? $(api.rows({search:'applied'}).nodes()).find('.js-worker-replica').toArray() : [...table.querySelectorAll('.js-worker-replica')];
        boxes.forEach(box => selected.add(box.value)); syncSelection();
    });
    clearButton.addEventListener('click', () => { selected.clear(); syncSelection(); });

    openButton.addEventListener('click', async function () {
        const body = new FormData(); body.append('csrf_token', csrf); selected.forEach(id => body.append('worker_ids[]', id));
        openButton.disabled=true;
        try {
            const response=await fetch(`${BASE_URL}/servicios/requisitos/previsualizar_replica_maquirenta.php`,{method:'POST',body});
            const data=await response.json(); if(!response.ok||!data.ok) throw new Error(data.message||'No se pudo preparar la réplica.');
            preview.innerHTML=data.workers.map(worker=>`<article class="replica-worker" data-worker-id="${worker.id}"><header class="replica-worker-head"><input class="form-check-input replica-worker-check" type="checkbox" checked><div><strong>${esc(worker.full_name)}</strong><div class="small text-muted">${esc(worker.document_number)} · ${esc(worker.company||'Sin empresa')}</div></div></header><div class="replica-worker-body">${worker.positions.length?worker.positions.map(position=>`<section class="replica-position" data-position-id="${position.id}"><label class="fw-semibold"><input class="form-check-input me-2 replica-position-check" type="checkbox" checked>${esc(position.name)}</label>${position.requirements.length?`<div class="replica-requirements">${position.requirements.map(req=>`<label class="replica-requirement"><input class="form-check-input me-2 replica-requirement-check" type="checkbox" value="${req.id}" checked><span>${esc(req.name)}</span><small class="d-block text-muted">${esc(req.start_date)} — ${esc(req.end_date)}${req.file_path?' · Con archivo':''}</small></label>`).join('')}</div>`:'<div class="replica-empty">Este puesto no tiene registros PMI.</div>'}</section>`).join(''):'<div class="replica-empty">Sin puestos asignados; no podrá replicarse hasta asignarle uno.</div>'}</div></article>`).join('');
            updateSummary(); modal.show();
        } catch(error) { Swal.fire('Atención',error.message,'warning'); } finally { openButton.disabled=selected.size===0; }
    });
    preview.addEventListener('change', event => {
        const worker=event.target.closest('.replica-worker'); const position=event.target.closest('.replica-position');
        if(event.target.classList.contains('replica-worker-check')) worker.querySelectorAll('input').forEach(input=>input.checked=event.target.checked);
        if(event.target.classList.contains('replica-position-check')) position.querySelectorAll('.replica-requirement-check').forEach(input=>input.checked=event.target.checked);
        updateSummary();
    });
    function collectSelection(){ return [...preview.querySelectorAll('.replica-worker')].filter(card=>card.querySelector('.replica-worker-check').checked).map(card=>({worker_id:Number(card.dataset.workerId),positions:[...card.querySelectorAll('.replica-position')].filter(section=>section.querySelector('.replica-position-check').checked).map(section=>({position_id:Number(section.dataset.positionId),requirement_ids:[...section.querySelectorAll('.replica-requirement-check:checked')].map(box=>Number(box.value))}))})).filter(worker=>worker.positions.length); }
    function updateSummary(){const payload=collectSelection();const positions=payload.reduce((n,w)=>n+w.positions.length,0);const requirements=payload.reduce((n,w)=>n+w.positions.reduce((m,p)=>m+p.requirement_ids.length,0),0);summary.textContent=`${payload.length} trabajador(es) · ${positions} puesto(s) · ${requirements} registro(s) PMI`;executeButton.disabled=payload.length===0;}
    executeButton.addEventListener('click', async function(){const selection=collectSelection();if(!selection.length)return;const confirmation=await Swal.fire({title:'¿Proceder con la réplica?',text:'Los datos seleccionados se copiarán a Empresa Maquirenta.',icon:'question',showCancelButton:true,confirmButtonText:'Sí, replicar',cancelButtonText:'Cancelar'});if(!confirmation.isConfirmed)return;const body=new FormData();body.append('csrf_token',csrf);body.append('selection',JSON.stringify(selection));executeButton.disabled=true;try{const response=await fetch(`${BASE_URL}/servicios/requisitos/replicar_datos_maquirenta.php`,{method:'POST',body});const data=await response.json();if(!response.ok||!data.ok)throw new Error(data.message||'No se pudo completar la réplica.');modal.hide();selected.clear();syncSelection();await Swal.fire('Réplica completada',`${data.stats.workers} trabajador(es), ${data.stats.positions} puesto(s) y ${data.stats.requirements} registro(s) PMI procesados.`,'success');}catch(error){Swal.fire('Atención',error.message,'warning');}finally{executeButton.disabled=false;}});
    syncSelection();
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>

