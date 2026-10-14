<?php
require_once __DIR__ . '/../../includes/security.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/status_alerts.php';
require_module_access('clientes.pmi');

$clientId = max(0, (int) ($_GET['cliente_id'] ?? 0));
$requestedTab = $_GET['tab'] ?? 'requisitos';
$activeTab = in_array($requestedTab, ['requisitos', 'formatos', 'personal'], true) ? $requestedTab : 'requisitos';
$client = null;
$contacts = [];
$general = ['personal' => [], 'equipos' => []];
$activeGeneralCategory = ($_GET['categoria'] ?? '') === 'equipos' ? 'equipos' : 'personal';
$baseDocuments = [];
$baseFolders = [];
$formatDocuments = [];
$formatFolders = [];
if ($clientId) {
    $stmt = db()->prepare('SELECT id, business_name, ruc, photo_path FROM clients WHERE id = :id');
    $stmt->execute(['id' => $clientId]);
    $client = $stmt->fetch();
    if (!$client) { http_response_code(404); exit('Cliente no encontrado.'); }
    if ($activeTab === 'personal') require __DIR__ . '/pmi_habilitaciones_data.php';
    $stmt = db()->prepare('SELECT name, position, phone, email FROM client_contacts WHERE client_id = :id ORDER BY sort_order, id');
    $stmt->execute(['id' => $clientId]);
    $contacts = $stmt->fetchAll();
    if ($activeTab === 'requisitos') {
    $stmt = db()->prepare("SELECT gi.category, gi.source_id, rc.name AS personal_name, dc.nombre AS equipment_document_name
        FROM client_pmi_general_items gi
        LEFT JOIN requirements_catalog rc ON gi.category = 'personal' AND rc.id = gi.source_id
        LEFT JOIN maquinaria_documentos_catalogo dc ON gi.category = 'equipos' AND dc.id = gi.source_id
        WHERE gi.client_id = :id ORDER BY gi.category, COALESCE(rc.name, dc.nombre), gi.source_id");
    $stmt->execute(['id' => $clientId]);
    foreach ($stmt->fetchAll() as $row) {
        if ($row['category'] === 'personal' && $row['personal_name'] !== null) { $general['personal'][] = $row; }
        if ($row['category'] === 'equipos' && $row['equipment_document_name'] !== null) { $general['equipos'][] = $row; }
    }
    $stmt = db()->prepare('SELECT d.id, d.name, bc.id AS catalog_id, d.registration_date, d.start_date, d.end_date, d.file_path, d.created_at, COALESCE(u.name, \'—\') AS registered_by, fi.folder_id FROM client_pmi_base_documents d LEFT JOIN users u ON u.id = d.registered_by_user_id LEFT JOIN client_pmi_base_folder_items fi ON fi.document_id = d.id LEFT JOIN client_pmi_base_catalog bc ON bc.client_id = d.client_id AND bc.name = d.name WHERE d.client_id = :id ORDER BY d.name, d.id');
    $stmt->execute(['id' => $clientId]);
    $baseDocuments = $stmt->fetchAll();
    $stmt = db()->prepare('SELECT id, name FROM client_pmi_base_folders WHERE client_id = :id ORDER BY name, id');
    $stmt->execute(['id' => $clientId]);
    $baseFolders = $stmt->fetchAll();
    }
    if ($activeTab === 'formatos') {
    try {
        $stmt = db()->prepare("SELECT d.id, d.catalog_id, c.name, d.registration_date, d.start_date, d.end_date, d.file_path, d.link_url, d.created_at, COALESCE(u.name, '—') AS registered_by, fi.folder_id FROM client_pmi_format_documents d JOIN client_pmi_format_catalog c ON c.id = d.catalog_id LEFT JOIN users u ON u.id = d.registered_by_user_id LEFT JOIN client_pmi_format_folder_items fi ON fi.document_id = d.id WHERE d.client_id = :id ORDER BY c.name, d.id");
        $stmt->execute(['id' => $clientId]);
        $formatDocuments = $stmt->fetchAll();
        $stmt = db()->prepare('SELECT id, name FROM client_pmi_format_folders WHERE client_id = :id ORDER BY name, id');
        $stmt->execute(['id' => $clientId]);
        $formatFolders = $stmt->fetchAll();
    } catch (PDOException $e) {
        // La sección permanece vacía hasta ejecutar database/pmi_clientes_formatos.sql.
    }
    }
}
require __DIR__ . '/../../includes/header.php';
?>
<style>
.client-pmi-tabs{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.client-pmi-tabs button{min-height:34px;border-radius:18px;font-weight:700;font-size:.74rem;letter-spacing:.015em;padding:6px 15px;background:#fff;transition:background .15s ease,box-shadow .15s ease,transform .15s ease}
.client-pmi-tabs button:hover{transform:translateY(-1px);box-shadow:0 3px 9px rgba(29,61,117,.1)}
.client-pmi-tabs button:focus-visible{outline:3px solid #9cc5ff;outline-offset:2px}
.client-pmi-tabs button[data-tab="requisitos"]{border:1.5px solid #8955ff;color:#743adf}.client-pmi-tabs button[data-tab="formatos"]{border:1.5px solid #21a6fa;color:#1286d4}.client-pmi-tabs button[data-tab="personal"]{border:1.5px solid #198b66;color:#147655}
.client-pmi-tabs button.active[data-tab="requisitos"]{background:#8353ee;color:white}.client-pmi-tabs button.active[data-tab="formatos"]{background:#1495e0;color:white}.client-pmi-tabs button.active[data-tab="personal"]{background:#168764;color:white}
.client-pmi-heading{background:#f3f6fb;color:#284672;font-size:.82rem;font-weight:750;letter-spacing:.045em;padding:11px 14px;border-left:3px solid #9bb7e8;border-radius:7px;margin:20px 0 12px}
.client-pmi-profile{min-width:0;overflow:hidden}
.client-pmi-profile-photo{display:block;width:auto;height:auto;max-width:100%;max-height:150px;margin-inline:auto;object-fit:contain;border-radius:10px;border:1px solid #d1dbea;background:#eef2f8}
.client-pmi-details{margin:0;display:grid;gap:11px}
.client-pmi-details>div{min-width:0;display:grid;grid-template-columns:minmax(82px,41%) minmax(0,1fr);align-items:start;gap:8px}
.client-pmi-details dt{min-width:0;color:#536783;font-size:.77rem;font-weight:650;line-height:1.38}
.client-pmi-details dd{min-width:0;margin:0;color:#142742;font-size:.8rem;font-weight:700;line-height:1.38;overflow-wrap:anywhere}
.client-pmi-contacts-section{margin-top:17px;padding-top:14px;border-top:1px solid #dfe8f3}
.client-pmi-contacts-label{padding:8px 10px;border-radius:6px;background:#eaf7f2}
.client-pmi-contacts-label dt{color:#166b55}
.client-pmi-contact-list{display:grid;gap:9px;min-width:0;margin-top:10px}
.client-pmi-contact{min-width:0;border:1px solid #d7e9e2;border-radius:9px;padding:11px;background:#fff;box-shadow:0 2px 6px rgba(22,107,85,.04);overflow-wrap:anywhere;transition:border-color .15s ease,box-shadow .15s ease}
.client-pmi-contact:hover{border-color:#9ccdbd;box-shadow:0 4px 11px rgba(22,107,85,.08)}
.client-pmi-contact-head{min-width:0;margin-bottom:8px}
.client-pmi-contact-name{min-width:0;line-height:1.3}
.client-pmi-contact strong{display:block;color:#185d4b;font-size:.8rem;font-weight:750;overflow-wrap:anywhere}
.client-pmi-contact-role{display:block;margin-top:2px;color:#657994;font-size:.72rem;overflow-wrap:anywhere}
.client-pmi-contact-line{display:flex;align-items:flex-start;gap:7px;margin-top:4px;color:#4c627f;font-size:.74rem;line-height:1.35;overflow-wrap:anywhere}
.client-pmi-contact-line i{flex:0 0 12px;margin-top:2px;color:#5eaa91;font-size:.68rem}
.client-pmi-contact-line span{min-width:0;overflow-wrap:anywhere}
.client-pmi-base-toolbar{display:flex;justify-content:flex-end;align-items:center;flex-wrap:wrap;gap:7px;margin:0 0 10px}
.client-pmi-base-toolbar .btn{min-height:36px;padding:6px 10px;font-size:.76rem;font-weight:650;white-space:nowrap}
.client-pmi-general-section,.client-pmi-base-section{margin:20px 0 16px;border:1px solid #dce6f2;border-radius:11px;overflow:hidden;background:#fff;box-shadow:0 2px 8px rgba(36,65,105,.04)}
.work-panel .client-pmi-general-section .client-pmi-heading,.work-panel .client-pmi-base-section .client-pmi-heading{margin:0!important;padding:8px 15px;border:0;border-bottom:1px solid #e0e8f2;border-radius:0}
.client-pmi-general-inner{padding:10px 15px 12px}
.client-pmi-base-section{margin-top:16px}
.client-pmi-base-inner{padding:10px 15px 12px}
.client-pmi-base-inner .table{margin-bottom:0}
.client-pmi-general-tabs{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.client-pmi-general-tab-group{display:inline-flex;align-items:stretch;border:1.5px solid #8c78a3;border-radius:18px;overflow:hidden;background:#fff;color:#67547d}
.client-pmi-general-tab-group[data-category="equipos"]{border-color:#eb9b51;color:#b7621f}
.client-pmi-general-tab-group.is-active{background:#f1edf6}.client-pmi-general-tab-group.is-active[data-category="equipos"]{background:#fff4e9}
.client-pmi-general-tab,.client-pmi-general-add{border:0;background:transparent;color:inherit;font-size:.78rem;font-weight:700;line-height:1.2}
.client-pmi-general-tab{padding:8px 13px}.client-pmi-general-add{padding:8px 11px;border-left:1px solid currentColor}
.client-pmi-general-tab:hover,.client-pmi-general-add:hover{background:rgba(100,82,157,.09)}
.client-pmi-general-tab:focus-visible,.client-pmi-general-add:focus-visible{outline:2px solid #2563eb;outline-offset:-2px}
.client-pmi-general-panel{min-height:118px;padding:4px 13px 12px;border:1px solid #e0e7f1;border-radius:10px;background:#fff}
.client-pmi-general-content ul{list-style:none;margin:0;padding:0}.client-pmi-general-content li{display:flex;align-items:flex-start;gap:9px;padding:7px 3px;color:#334a68;font-size:.84rem;font-weight:550;line-height:1.35;border-bottom:1px solid #f0f3f8}
.client-pmi-general-content li:last-child{border-bottom:0}
.client-pmi-general-content li::before{content:"";width:6px;height:6px;flex:0 0 6px;border-radius:50%;background:#8c78a3;margin-top:.42em}
.client-pmi-general-content[data-category-body="equipos"] li::before{background:#e6a86f}
.client-pmi-general-content[hidden],.client-pmi-view[hidden]{display:none!important}
.client-pmi-pick-list{max-height:360px;overflow:auto}.client-pmi-pick-row{display:flex;gap:9px;align-items:center;padding:8px 10px;border-bottom:1px solid #edf1f7;cursor:pointer}
.client-pmi-pick-row[hidden]{display:none}
.client-pmi-folder-dialog{max-width:600px}
.client-pmi-folder-modal .modal-content{border:0;border-radius:7px;padding:24px 28px 22px}
.client-pmi-folder-modal .modal-title{text-align:center;color:#555;font-size:1.75rem;font-weight:700;margin-bottom:20px}
.client-pmi-folder-modal .form-label{font-weight:700;color:#344259}
.client-pmi-folder-modal .form-control{min-height:48px}
.client-pmi-folder-select-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:16px 0 8px;color:#555;font-size:1.1rem;font-weight:700}
.client-pmi-folder-select-heading label{font-size:.9rem;font-weight:400;white-space:nowrap}
.client-pmi-folder-list{display:grid;gap:8px;max-height:280px;overflow:auto}
.client-pmi-folder-choice{display:flex;align-items:center;gap:9px;border:1px solid #d9e1eb;border-radius:6px;padding:8px 10px;cursor:pointer;color:#555}
.client-pmi-folder-choice:hover{border-color:#a9bce0;background:#f8faff}
.client-pmi-folder-choice[hidden]{display:none!important}
.client-pmi-folder-choice .form-check-input{margin:0;flex:none}
.client-pmi-folder-actions{display:flex;justify-content:center;gap:10px;margin-top:24px}
.client-pmi-folder-actions .btn{min-height:42px;font-weight:650}
.client-pmi-folder-actions .btn-primary{background:#7060df;border-color:#7060df}
.client-pmi-folder-header .client-pmi-folder-heading{background:#e8f1ff!important;padding:.6rem .85rem!important;border-left:4px solid #3b6fec;border-top:1px solid #cbdcff;border-right:1px solid #cbdcff;border-bottom:1px solid #cbdcff;border-radius:.6rem}
.client-pmi-folder-header.is-open .client-pmi-folder-heading{border-radius:.6rem .6rem 0 0}
.client-pmi-folder-toggle{display:inline-flex;align-items:center;gap:.65rem;padding:0;border:0;background:transparent;color:#173b7a;text-align:left}
.client-pmi-folder-icon{display:inline-grid;place-items:center;flex:none;width:2rem;height:2rem;border-radius:.45rem;background:#d5e4ff;color:#2459bf}
.client-pmi-folder-label{display:flex;flex-direction:column;gap:.05rem}
.client-pmi-folder-label strong{font-size:.9rem}.client-pmi-folder-label small{font-size:.73rem;color:#49658d}
.client-pmi-folder-toggle:hover strong{text-decoration:underline}
.client-pmi-folder-item>td{background:#f6f9ff!important;border-bottom-color:#dce8fb!important}
.client-pmi-folder-item>td:first-child{border-left:4px solid #3b6fec}
.client-pmi-folder-item>td:last-child{border-right:1px solid #cbdcff}
.client-pmi-folder-item.is-last>td{border-bottom:1px solid #cbdcff!important}
.client-pmi-folder-gap>td{height:.65rem;padding:0!important;border:0!important;background:#fff!important}
#clientBaseTable .client-pmi-folder-header:hover>td:first-child,#clientBaseTable .client-pmi-folder-item:hover>td:first-child,#clientFormatTable .client-pmi-folder-header:hover>td:first-child,#clientFormatTable .client-pmi-folder-item:hover>td:first-child{box-shadow:none!important}
#clientBaseTable tr[hidden],#clientFormatTable tr[hidden]{display:none!important}
.client-pmi-preview-body{min-height:260px;background:#f4f7fb;text-align:center}
.client-pmi-preview-body iframe{display:block;width:100%;height:min(72vh,850px);border:0;background:#fff}
.client-pmi-preview-body img{display:block;max-width:100%;max-height:72vh;margin:auto;object-fit:contain}
.habilitation-position-row[hidden]{display:none!important}
.habilitation-card{--habilitation-bg:#f3f9ff;--habilitation-header:#e7f3ff;--habilitation-border:#cfe2f4;background:var(--habilitation-bg);border-color:var(--habilitation-border)!important;box-shadow:0 2px 9px rgba(34,63,97,.05)}
.habilitation-card-tone-1{--habilitation-bg:#f7f5ff;--habilitation-header:#eeeaff;--habilitation-border:#ddd5f3}
.habilitation-card-tone-2{--habilitation-bg:#f2faf6;--habilitation-header:#e3f4eb;--habilitation-border:#cde8da}
.habilitation-card-tone-3{--habilitation-bg:#fff9f2;--habilitation-header:#fff0e1;--habilitation-border:#f1ddca}
.habilitation-card-header{background:var(--habilitation-header)}
.habilitation-card .habilitation-info-panel{background:rgba(255,255,255,.66);border-color:var(--habilitation-border)}
.habilitation-card .habilitation-download-toolbar{background:transparent;border-top-color:var(--habilitation-border)}
.habilitation-card .habilitation-doc-table{--bs-table-bg:var(--habilitation-bg);--bs-table-hover-bg:rgba(255,255,255,.8);table-layout:fixed;min-width:760px}
.habilitation-card .habilitation-doc-table th:nth-child(1){width:5%}
.habilitation-card .habilitation-doc-table th:nth-child(2){width:18%}
.habilitation-card .habilitation-doc-table th:nth-child(3),.habilitation-card .habilitation-doc-table th:nth-child(4),.habilitation-card .habilitation-doc-table th:nth-child(5),.habilitation-card .habilitation-doc-table th:nth-child(6){width:12%}
.habilitation-card .habilitation-doc-table th:nth-child(7),.habilitation-card .habilitation-doc-table th:nth-child(8){width:14.5%}
.habilitation-card .habilitation-doc-table thead th{background:var(--habilitation-header);border-bottom-color:var(--habilitation-border)}
.habilitation-formats{margin:0 0 14px;border:0;border-radius:0;background:transparent}
.habilitation-formats-title{margin:0;padding:9px 12px;border:0;background:transparent;color:#284672;font-size:.82rem;font-weight:750;letter-spacing:.035em}
.habilitation-info-panel{margin:9px 13px 10px;padding:10px 12px;background:#eff8ff;border:1px solid #d8eafa;border-radius:9px}
.habilitation-position-tabs{column-gap:6px!important;row-gap:6px!important}
.habilitation-position-tabs .habilitation-position-tab{height:27px;min-height:27px;max-width:100%;padding:3px 8px!important;font-size:.7rem!important;font-weight:650!important;line-height:19px;white-space:nowrap;text-align:center;overflow:hidden;text-overflow:ellipsis;flex:0 0 auto}
.habilitation-validity{display:flex;align-items:center;flex-wrap:wrap;column-gap:14px;row-gap:4px;margin-top:9px;padding-top:8px;border-top:1px solid #d8eafa;color:#526b83;font-size:.75rem;line-height:1.35}
.habilitation-validity strong{color:#275273;font-weight:700;margin-right:2px}.habilitation-validity b{color:#31475e;font-weight:600}
.habilitation-info-panel>.habilitation-validity:first-child{margin-top:0;padding-top:0;border-top:0}
.habilitation-download-toolbar{padding-top:7px;border-top:1px solid #edf2f7;background:#fbfdff}
.habilitation-download-toolbar .btn{min-height:31px;padding:4px 9px;font-size:.73rem;font-weight:650}
.habilitation-doc-table thead th{background:#f5f8fc;color:#344762;font-size:.75rem;font-weight:700;border-bottom:1px solid #d8e3f0}
.habilitation-doc-table tbody td{border-bottom-color:#e5edf6}
.work-panel .client-pmi-base-section .client-pmi-habilitation-heading{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:39px;padding:4px 12px!important}
.work-panel .client-pmi-habilitation-heading h2{margin:0!important;font-size:1.1rem!important;font-weight:600!important;letter-spacing:-.01em!important;line-height:1.2;color:#000!important}
.client-pmi-habilitation-heading .btn{min-height:27px;padding:3px 9px;font-size:.72rem;line-height:19px;letter-spacing:0;white-space:nowrap}
.habilitation-heading-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;min-width:0;margin-left:auto}
.habilitation-search{display:flex;align-items:center;height:27px;margin:0;border:1px solid #2563eb;border-radius:6px;overflow:hidden;background:#fff}
.habilitation-search button{display:grid;place-items:center;flex:0 0 29px;width:29px;height:25px;padding:0;border:0;background:#eaf2ff;color:#3265ad}
.habilitation-search button:hover{color:#194da0;background:#dceaff}
.habilitation-search button:focus-visible{outline:2px solid #2563eb;outline-offset:1px}
.habilitation-search button i{font-size:.72rem}
.habilitation-search .form-control{width:191px;max-width:100%;height:25px;min-height:25px;padding:3px 7px;font-size:.72rem;line-height:19px;border:0;border-radius:0;background:#fff;box-shadow:none}
.habilitation-search .form-control:focus{border:0;box-shadow:none;outline:none}
.habilitation-card[hidden],#habilitationNoResults[hidden]{display:none!important}
.habilitation-form-modal .modal-content{border:1px solid #d8e4f2;border-radius:14px;box-shadow:0 18px 50px rgba(15,36,69,.18);overflow:hidden}
.habilitation-form-modal .modal-header{padding:16px 20px;background:#f7faff;border-bottom-color:#e2eaf5}
.habilitation-form-modal .modal-body{padding:20px}
.habilitation-form-modal .modal-footer{padding:12px 20px;background:#fbfcff;border-top-color:#e2eaf5}
.habilitation-form-modal .form-label{margin-bottom:6px;color:#304463;font-size:.8rem;font-weight:700}
.habilitation-positions-box{padding:14px 16px;border:1px solid #cbdcf4;border-radius:11px;background:linear-gradient(135deg,#f2f7ff,#f9fbff);box-shadow:0 3px 12px rgba(44,92,168,.06)}
.habilitation-positions-heading{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:9px 16px;margin-bottom:12px}
.habilitation-positions-title{display:flex;align-items:center;flex-wrap:wrap;gap:7px;color:#143968;font-size:.9rem}
.habilitation-positions-title i{display:grid;place-items:center;width:27px;height:27px;border-radius:7px;background:#dceafe;color:#2162b5;font-size:.73rem}
.habilitation-positions-count{padding:3px 8px;border-radius:999px;background:#e6efff;color:#2458a5;font-size:.68rem;font-weight:700}
.habilitation-positions-heading p{margin:4px 0 0;color:#5d7190;font-size:.73rem}
.habilitation-positions-select-all{display:flex;align-items:center;gap:7px;margin:0;padding:6px 9px;border:1px solid #b9d1f4;border-radius:7px;background:#fff;color:#235193;font-size:.73rem;font-weight:700;cursor:pointer;white-space:nowrap}
.habilitation-positions-select-all:hover{background:#eaf3ff}
.habilitation-positions-select-all .form-check-input{margin:0;cursor:pointer}
.habilitation-positions-list{display:flex;flex-wrap:wrap;gap:8px;min-height:36px;align-items:center;color:#6c7d95;font-size:.78rem}
.habilitation-position-option{display:inline-flex;align-items:center;gap:7px;max-width:100%;padding:8px 10px;border:1px solid #ccdaeb;border-radius:8px;background:#fff;color:#31445e;font-size:.77rem;font-weight:600;line-height:1.25;cursor:pointer;transition:background .15s,border-color .15s,box-shadow .15s}
.habilitation-position-option:hover{border-color:#89b4ef;background:#f5f9ff}
.habilitation-position-option:has(input:checked){border-color:#4387e8;background:#e9f2ff;color:#154684;box-shadow:0 0 0 1px rgba(67,135,232,.13)}
.habilitation-position-option:has(input:focus-visible){outline:2px solid #2563eb;outline-offset:2px}
.habilitation-position-option .form-check-input{flex:none;margin:0;cursor:pointer}
.habilitation-positions-error{display:block;margin-top:9px;color:#b42332;font-size:.75rem;font-weight:600}
@media(max-width:680px){.habilitation-form-modal .modal-body{padding:16px}.habilitation-positions-box{padding:12px}.habilitation-positions-heading{align-items:stretch}.habilitation-positions-select-all{width:max-content}}
@media(max-width:680px){.work-panel .client-pmi-base-section .client-pmi-habilitation-heading{flex-wrap:wrap}.habilitation-heading-actions{width:100%;justify-content:space-between}.habilitation-search{flex:1;min-width:0}.habilitation-search .form-control{width:100%}}
</style>
<div class="page-title"><div><h1>Requisitos PMI Clientes</h1><p>Control documentario por cliente.</p></div></div>
<div class="work-panel mb-3">
    <label class="form-label" for="clientSearch">Buscar por razón social o RUC</label>
    <select class="form-select" id="clientSearch" aria-label="Buscar cliente por razón social o RUC">
        <option></option>
        <?php if ($client): ?><option value="<?= (int) $client['id'] ?>" selected><?= e($client['business_name'] . ' - ' . $client['ruc']) ?></option><?php endif; ?>
    </select>
</div>
<?php if ($client): ?>
<div class="row g-3">
    <div class="col-lg-3">
        <div class="work-panel h-100 client-pmi-profile">
            <div class="text-center mb-3"><img class="client-pmi-profile-photo" src="<?= $client['photo_path'] ? APP_URL . '/' . e($client['photo_path']) : APP_URL . '/recursos/imagen_referencial.php' ?>" alt="Foto del cliente"></div>
            <dl class="client-pmi-details"><div><dt>Razón social</dt><dd><?= e($client['business_name']) ?></dd></div><div><dt>RUC</dt><dd><?= e($client['ruc']) ?></dd></div></dl>
            <div class="client-pmi-contacts-section">
                <dl class="client-pmi-details"><div class="client-pmi-contacts-label"><dt>Contactos</dt></div></dl>
                <div class="client-pmi-contact-list">
            <?php foreach ($contacts as $contact): ?>
                <div class="client-pmi-contact">
                    <div class="client-pmi-contact-head"><div class="client-pmi-contact-name"><strong><?= e($contact['name']) ?></strong><span class="client-pmi-contact-role"><?= e($contact['position']) ?></span></div></div>
                    <div class="client-pmi-contact-line"><i class="fa-solid fa-phone" aria-hidden="true"></i><span><?= e($contact['phone']) ?></span></div>
                    <div class="client-pmi-contact-line"><i class="fa-solid fa-envelope" aria-hidden="true"></i><span><?= e($contact['email']) ?></span></div>
                </div>
            <?php endforeach; ?>
            <?php if (!$contacts): ?><p class="small text-muted">Sin contactos registrados.</p><?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-lg-9">
        <div class="work-panel h-100">
            <div class="client-pmi-tabs" role="tablist" aria-label="Secciones PMI del cliente">
                <button type="button" class="<?= $activeTab === 'requisitos' ? 'active' : '' ?>" data-tab="requisitos" role="tab" aria-selected="<?= $activeTab === 'requisitos' ? 'true' : 'false' ?>">REQUISITOS</button>
                <button type="button" class="<?= $activeTab === 'formatos' ? 'active' : '' ?>" data-tab="formatos" role="tab" aria-selected="<?= $activeTab === 'formatos' ? 'true' : 'false' ?>">FORMATOS</button>
                <button type="button" class="<?= $activeTab === 'personal' ? 'active' : '' ?>" data-tab="personal" role="tab" aria-selected="<?= $activeTab === 'personal' ? 'true' : 'false' ?>">PERSONAL HABILITADO</button>
            </div>
            <?php if ($activeTab === 'requisitos'): ?>
            <section class="client-pmi-view" data-view="requisitos">
                <div class="client-pmi-general-section">
                <h2 class="client-pmi-heading h6">DOCUMENTOS GENERALES</h2>
                <div class="client-pmi-general-inner">
                <div class="client-pmi-general-tabs" aria-label="Tipo de documento general">
                <?php foreach (['personal' => 'Personal', 'equipos' => 'Equipos'] as $category => $label): ?>
                <div class="client-pmi-general-tab-group <?= $activeGeneralCategory === $category ? 'is-active' : '' ?>" data-category="<?= $category ?>">
                    <button class="client-pmi-general-tab" type="button" data-category-tab="<?= $category ?>" aria-pressed="<?= $activeGeneralCategory === $category ? 'true' : 'false' ?>"><?= $label ?></button>
                    <button class="client-pmi-general-add" type="button" data-open-picker="<?= $category ?>" title="Agregar <?= strtolower($label) ?>" aria-label="Agregar <?= strtolower($label) ?>"><i class="fa-solid fa-plus"></i></button>
                </div>
                <?php endforeach; ?>
                </div>
                <div class="client-pmi-general-panel">
                <?php foreach (['personal' => 'Personal', 'equipos' => 'Equipos'] as $category => $label): ?>
                    <div class="client-pmi-general-content" data-category-body="<?= $category ?>" <?= $activeGeneralCategory === $category ? '' : 'hidden' ?>>
                        <?php if ($general[$category]): ?><ul><?php foreach ($general[$category] as $item): ?><li><?= e($category === 'personal' ? $item['personal_name'] : $item['equipment_document_name']) ?></li><?php endforeach; ?></ul><?php else: ?><p class="text-muted small mb-0 py-3">Aún no hay <?= $category === 'personal' ? 'requisitos de personal' : 'documentos de equipos' ?> seleccionados. Usa el botón + para agregarlos.</p><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                </div>
                </div>
                </div>
                <div class="client-pmi-base-section">
                <h2 class="client-pmi-heading h6">DOCUMENTOS BASE</h2>
                <div class="client-pmi-base-inner">
                <div class="client-pmi-base-toolbar">
                    <div class="d-flex flex-wrap gap-2">
                        <button class="btn btn-sm btn-outline-primary" type="button" id="downloadSelectedBase"><i class="fa-solid fa-file-arrow-down me-1"></i>Descargar seleccionados</button>
                        <a class="btn btn-sm btn-outline-primary" href="<?= APP_URL ?>/modulos/clientes/pmi_descargar.php?cliente_id=<?= $clientId ?>" id="downloadAllBase" data-has-files="<?= array_filter($baseDocuments, static fn(array $doc): bool => !empty($doc['file_path'])) ? '1' : '0' ?>"><i class="fa-solid fa-download me-1"></i>Descargar todo</a>
                        <button class="btn btn-sm btn-outline-primary" type="button" id="createBaseFolder"><i class="fa-solid fa-folder-plus me-1"></i>Crear carpeta</button>
                        <button class="btn btn-sm btn-primary" type="button" id="addBaseDocument"><i class="fa-solid fa-plus me-1"></i>Agregar documentos</button>
                    </div>
                </div>
                <div class="table-responsive"><table class="table table-hover align-middle" id="clientBaseTable"><thead><tr><th><input class="form-check-input" id="selectAllBase" type="checkbox" aria-label="Seleccionar todos los requisitos"></th><th>Requisito</th><th>F. Registro</th><th>F. Inicio</th><th>F. Fin</th><th>Estado</th><th>Registrado por</th><th>Acciones</th></tr></thead><tbody>
                    <?php foreach ($baseFolders as $folder): ?>
                    <?php $folderDocs = array_values(array_filter($baseDocuments, static fn(array $doc): bool => (int) ($doc['folder_id'] ?? 0) === (int) $folder['id'])); ?>
                    <tr class="client-pmi-folder-header"><td colspan="8" class="client-pmi-folder-heading"><div class="d-flex align-items-center justify-content-between gap-2"><button class="client-pmi-folder-toggle folder-toggle" type="button" data-folder-id="<?= (int) $folder['id'] ?>" aria-expanded="false"><span class="client-pmi-folder-icon"><i class="fa-solid fa-folder"></i></span><span class="client-pmi-folder-label"><strong><?= e($folder['name']) ?></strong><small><?= count($folderDocs) ?> documento<?= count($folderDocs) === 1 ? '' : 's' ?> dentro de la carpeta</small></span><i class="fa-solid fa-chevron-down ms-2" aria-hidden="true"></i></button><div class="d-flex gap-2"><button class="btn btn-sm btn-outline-primary edit-base-folder" type="button" data-folder-id="<?= (int) $folder['id'] ?>" data-folder-name="<?= e($folder['name']) ?>" title="Editar carpeta" aria-label="Editar carpeta <?= e($folder['name']) ?>"><i class="fa-solid fa-pen"></i></button><form method="post" action="<?= APP_URL ?>/modulos/clientes/pmi_guardar.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="delete_folder"><input type="hidden" name="client_id" value="<?= $clientId ?>"><input type="hidden" name="folder_id" value="<?= (int) $folder['id'] ?>"><button class="btn btn-sm btn-outline-danger" type="submit" title="Eliminar carpeta" aria-label="Eliminar carpeta <?= e($folder['name']) ?>"><i class="fa-solid fa-trash"></i></button></form></div></div></td></tr>
                    <?php foreach ($folderDocs as $index => $document): ?><tr class="client-base-row client-pmi-folder-item <?= $index === count($folderDocs) - 1 ? 'is-last' : '' ?>" data-folder-id="<?= (int) $folder['id'] ?>" hidden><?php require __DIR__ . '/pmi_base_row.php'; ?></tr><?php endforeach; ?>
                    <tr class="client-pmi-folder-gap"><td colspan="8"></td></tr>
                    <?php endforeach; ?>
                    <?php foreach ($baseDocuments as $document): if (!empty($document['folder_id'])) continue; ?><tr class="client-base-row"><?php require __DIR__ . '/pmi_base_row.php'; ?></tr><?php endforeach; ?>
                    <?php if (!$baseDocuments && !$baseFolders): ?><tr><td colspan="8" class="text-center text-muted py-4">No hay requisitos registrados para este cliente.</td></tr><?php endif; ?>
                </tbody></table></div>
                </div>
                </div>
            </section>
            <?php elseif ($activeTab === 'formatos'): require __DIR__ . '/pmi_formatos_view.php'; ?>
            <?php else: require __DIR__ . '/pmi_habilitaciones_view.php'; endif; ?>
        </div>
    </div>
</div>
<?php if ($activeTab === 'requisitos'): ?>
<div class="modal fade" id="generalPickerModal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable"><form class="modal-content" method="post" action="<?= APP_URL ?>/modulos/clientes/pmi_guardar.php"><div class="modal-header"><h2 class="modal-title h5" id="generalPickerTitle">Seleccionar requisitos</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button></div><div class="modal-body"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input type="hidden" name="action" value="save_general"><input type="hidden" name="client_id" value="<?= $clientId ?>"><input type="hidden" name="category" id="pickerCategory"><input class="form-control mb-3" id="pickerSearch" placeholder="Buscar en la lista" aria-label="Buscar requisito"><label class="form-check mb-2"><input class="form-check-input" type="checkbox" id="pickerSelectAll"><span class="form-check-label">Seleccionar todos los visibles</span></label><div class="client-pmi-pick-list" id="pickerList"></div></div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-primary" type="submit">Guardar selección</button></div></form></div></div>
<?php require __DIR__ . '/pmi_base_modal.php'; ?>
<?php elseif ($activeTab === 'personal'): require __DIR__ . '/pmi_habilitaciones_modal.php'; ?>
<?php else: $renderFormatModals = true; require __DIR__ . '/pmi_formatos_view.php'; endif; ?>
<?php if ($activeTab !== 'personal'): ?>
<div class="modal fade" id="baseFilePreviewModal" tabindex="-1" aria-labelledby="baseFilePreviewTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h2 class="modal-title h5" id="baseFilePreviewTitle">Vista previa del archivo</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Cerrar"></button></div>
        <div class="modal-body client-pmi-preview-body" id="baseFilePreviewBody"></div>
        <div class="modal-footer"><a class="btn btn-outline-primary" id="baseFilePreviewOpen" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square me-2"></i>Abrir en otra pestaña</a><a class="btn btn-primary" id="baseFilePreviewDownload"><i class="fa-solid fa-download me-2"></i>Descargar</a><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cerrar</button></div>
    </div></div>
</div>
<?php endif; ?>
<?php if ($activeTab === 'requisitos'): ?>
<div class="modal fade client-pmi-folder-modal" id="baseFolderModal" tabindex="-1" aria-labelledby="baseFolderTitle" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered client-pmi-folder-dialog">
        <form class="modal-content" method="post" action="<?= APP_URL ?>/modulos/clientes/pmi_guardar.php" id="baseFolderForm">
            <h2 class="modal-title" id="baseFolderTitle">Crear carpeta de documentos</h2>
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" id="baseFolderAction" value="create_folder">
            <input type="hidden" name="client_id" value="<?= $clientId ?>">
            <input type="hidden" name="folder_id" id="baseFolderId" value="">
            <label class="form-label" for="baseFolderName">Nombre de la carpeta</label>
            <input class="form-control" id="baseFolderName" name="folder_name" maxlength="120" placeholder="Ej.: Documentos de seguridad" required>
            <div class="client-pmi-folder-select-heading">
                <span>Registros que se mostrarán</span>
                <label for="baseFolderSelectAll"><input class="form-check-input me-1" type="checkbox" id="baseFolderSelectAll"> Marcar todos</label>
            </div>
            <div class="client-pmi-folder-list" id="baseFolderSelection">
                <?php foreach ($baseDocuments as $document): ?>
                    <label class="client-pmi-folder-choice"><input class="form-check-input base-folder-document-check" type="checkbox" name="document_ids[]" value="<?= (int) $document['id'] ?>" data-folder-id="<?= (int) ($document['folder_id'] ?? 0) ?>"><span><?= e($document['name']) ?></span></label>
                <?php endforeach; ?>
            </div>
            <div class="client-pmi-folder-actions">
                <button class="btn btn-primary" type="submit" id="baseFolderSubmit">Crear carpeta</button>
                <button class="btn btn-secondary" type="button" data-bs-dismiss="modal">Cancelar</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const previewModal = document.getElementById('baseFilePreviewModal');
    const previewBody = document.getElementById('baseFilePreviewBody');
    if (previewModal && previewBody) previewModal.addEventListener('hidden.bs.modal', () => previewBody.replaceChildren());
    document.addEventListener('click', event => {
        const button = event.target.closest('.preview-base-document');
        if (!button || !previewModal || !previewBody) return;
        const path = button.dataset.filePath || '';
        if (!/^archivos\/clientes_pmi(?:_formatos)?\/[A-Za-z0-9_-]+\.(?:pdf|jpe?g|png|webp)$/i.test(path)) {
            Swal.fire('Vista previa no disponible', 'La ruta del archivo no es válida.', 'warning');
            return;
        }
        const url = <?= json_encode(APP_URL) ?> + '/' + path.split('/').map(encodeURIComponent).join('/');
        const name = button.dataset.documentName || 'Archivo';
        const isPdf = /\.pdf$/i.test(path);
        const preview = document.createElement(isPdf ? 'iframe' : 'img');
        preview.src = url;
        if (isPdf) preview.title = 'Vista previa de ' + name;
        else preview.alt = 'Archivo adjunto de ' + name;
        previewBody.replaceChildren(preview);
        document.getElementById('baseFilePreviewTitle').textContent = name;
        document.getElementById('baseFilePreviewOpen').href = url;
        document.getElementById('baseFilePreviewDownload').href = <?= json_encode(APP_URL) ?> + '/modulos/clientes/pmi_descargar.php?cliente_id=<?= $clientId ?>&tipo=' + encodeURIComponent(button.dataset.downloadType || 'base') + '&ids[]=' + encodeURIComponent(button.dataset.documentId);
        bootstrap.Modal.getOrCreateInstance(previewModal).show();
    });
    document.addEventListener('click', event => {
        const button = event.target.closest('.edit-base-folder');
        if (!button) return;
        const id = button.dataset.folderId;
        const choices = Array.from(document.querySelectorAll('.base-folder-document-check'));
        choices.forEach(check => {
            const available = check.dataset.folderId === '0' || check.dataset.folderId === id;
            check.disabled = !available;
            check.closest('.client-pmi-folder-choice').hidden = !available;
            check.checked = check.dataset.folderId === id;
        });
        const available = choices.filter(check => !check.disabled);
        const selectAll = document.getElementById('baseFolderSelectAll');
        selectAll.checked = available.length > 0 && available.every(check => check.checked);
        selectAll.indeterminate = available.some(check => check.checked) && !selectAll.checked;
        document.getElementById('baseFolderAction').value = 'update_folder';
        document.getElementById('baseFolderId').value = id;
        document.getElementById('baseFolderTitle').textContent = 'Editar carpeta de documentos';
        document.getElementById('baseFolderSubmit').textContent = 'Guardar cambios';
        document.getElementById('baseFolderName').value = button.dataset.folderName;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('baseFolderModal')).show();
    });
    document.addEventListener('submit', async event => {
        const action = event.target.querySelector('input[name="action"]')?.value;
        if (action !== 'delete_base' && action !== 'delete_folder') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const folderName = event.target.closest('.client-pmi-folder-header')?.querySelector('.client-pmi-folder-label strong')?.textContent?.trim() || '';
        const formatFolder = (event.target.getAttribute('action') || '').includes('pmi_formatos_guardar.php');
        const result = await Swal.fire(action === 'delete_folder'
            ? {titleText: `Eliminar carpeta ${folderName}`, text: formatFolder ? 'Los formatos y sus archivos se conservarán en la lista general.' : 'Los requisitos y sus archivos se conservarán en la lista general.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Eliminar carpeta', cancelButtonText: 'Cancelar'}
            : {titleText: '¿Eliminar requisito?', text: 'Esta acción eliminará el requisito y su archivo adjunto.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Sí, eliminar', cancelButtonText: 'Cancelar', confirmButtonColor: '#dc3545'});
        if (result.isConfirmed) HTMLFormElement.prototype.submit.call(event.target);
    }, true);
    <?php if (!empty($_GET['error'])): ?>
    Swal.fire({ title: 'Atención', text: <?= json_encode((string) $_GET['error'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, icon: 'warning', confirmButtonText: 'Aceptar' });
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete('error');
    window.history.replaceState(null, '', cleanUrl);
    <?php endif; ?>
    const search = $('#clientSearch');
    search.select2({theme:'bootstrap4',width:'100%',placeholder:'Escriba la razón social o el RUC',ajax:{url:'<?= APP_URL ?>/modulos/clientes/buscar.php',dataType:'json',delay:250,data:params=>({q:params.term||''}),processResults:data=>({results:data.results||[]})}});
    search.on('select2:select', event => { window.location.href = '<?= APP_URL ?>/modulos/clientes/pmi.php?cliente_id=' + encodeURIComponent(event.params.data.id); });
    document.querySelectorAll('.client-pmi-tabs button').forEach(button=>button.addEventListener('click',()=>{if(button.classList.contains('active'))return;const url=new URL(window.location.href);url.searchParams.set('tab',button.dataset.tab);url.searchParams.delete('categoria');url.searchParams.delete('habilitacion_buscar');url.searchParams.delete('habilitacion_pagina');window.location.href=url.href}));
    function showGeneralCategory(category) {
        document.querySelectorAll('.client-pmi-general-tab-group').forEach(group => group.classList.toggle('is-active', group.dataset.category === category));
        document.querySelectorAll('[data-category-tab]').forEach(tab => tab.setAttribute('aria-pressed', tab.dataset.categoryTab === category ? 'true' : 'false'));
        document.querySelectorAll('[data-category-body]').forEach(body => { body.hidden = body.dataset.categoryBody !== category; });
        const url = new URL(window.location.href);
        if (category === 'personal') url.searchParams.delete('categoria'); else url.searchParams.set('categoria', category);
        window.history.replaceState(null, '', url);
    }
    document.querySelectorAll('[data-category-tab]').forEach(button => button.addEventListener('click', () => showGeneralCategory(button.dataset.categoryTab)));
    const pickerModal=document.getElementById('generalPickerModal');if(!pickerModal)return;
    const pickerList=document.getElementById('pickerList');const pickerSearch=document.getElementById('pickerSearch');const selectAll=document.getElementById('pickerSelectAll');
    const selected={personal:new Set(<?= json_encode(array_map('intval',array_column($general['personal'],'source_id'))) ?>),equipos:new Set(<?= json_encode(array_map('intval',array_column($general['equipos'],'source_id'))) ?>)};
    function filterPicker(){const term=pickerSearch.value.toLocaleLowerCase();pickerList.querySelectorAll('.client-pmi-pick-row').forEach(row=>{row.hidden=!row.textContent.toLocaleLowerCase().includes(term)});selectAll.checked=false}
    pickerSearch.addEventListener('input',filterPicker);
    selectAll.addEventListener('change',()=>pickerList.querySelectorAll('.client-pmi-pick-row:not([hidden]) input').forEach(input=>{input.checked=selectAll.checked}));
    document.querySelectorAll('[data-open-picker]').forEach(button=>button.addEventListener('click',async()=>{
        const category=button.dataset.openPicker;showGeneralCategory(category);document.getElementById('pickerCategory').value=category;document.getElementById('generalPickerTitle').textContent='Seleccionar '+(category==='personal'?'requisitos de Personal':'documentos de Equipos');pickerSearch.value='';pickerList.textContent='Cargando…';selectAll.checked=false;
        bootstrap.Modal.getOrCreateInstance(pickerModal).show();
        try{const response=await fetch('<?= APP_URL ?>/modulos/clientes/pmi_catalogo.php?categoria='+category);if(!response.ok)throw new Error('No se pudo cargar el catálogo.');const data=await response.json();pickerList.replaceChildren();if(!data.items.length){pickerList.textContent='No hay registros disponibles.';return}data.items.forEach(item=>{const label=document.createElement('label');label.className='client-pmi-pick-row';const input=document.createElement('input');input.type='checkbox';input.className='form-check-input';input.name='source_ids[]';input.value=item.id;input.checked=selected[category].has(Number(item.id));const span=document.createElement('span');span.textContent=item.text;label.append(input,span);pickerList.append(label)})}catch(error){pickerList.textContent=error.message}
    }));
    const baseModal = document.getElementById('baseDocumentModal');
    const baseForm = baseModal.querySelector('form');
    const baseCatalog = $('#baseDocumentCatalog');
    const catalogUrl = '<?= APP_URL ?>/modulos/clientes/pmi_catalogo_base.php';
    const csrf = baseForm.querySelector('[name="csrf_token"]').value;
    const catalogDialog = async options => {
        const focusTrap = bootstrap.Modal.getOrCreateInstance(baseModal)?._focustrap;
        focusTrap?.deactivate?.();
        try {
            return await Swal.fire({...options, didOpen: () => Swal.getInput()?.focus()});
        } finally {
            setTimeout(() => focusTrap?.activate?.(), 0);
        }
    };
    baseCatalog.select2({
        theme: 'bootstrap4', width: '100%', dropdownParent: $('#baseDocumentModal'),
        placeholder: 'Buscar documento',
        ajax: {url: catalogUrl, dataType: 'json', delay: 250,
            data: params => ({client_id: '<?= $clientId ?>', q: params.term || ''}),
            processResults: data => ({results: data.results || []})}
    });
    baseCatalog.on('select2:open', () => document.querySelector('.select2-container--open .select2-search__field')?.focus());
    const clearBaseModal = () => {
        baseForm.reset();
        baseCatalog.val(null).trigger('change');
        document.getElementById('baseDocumentId').value = '';
        document.getElementById('baseObservationHistory').classList.add('d-none');
        document.getElementById('baseObservationList').replaceChildren();
        document.getElementById('baseCurrentFile').classList.add('d-none');
        document.getElementById('baseCurrentFile').replaceChildren();
    };
    const today = () => {const date = new Date(); return [date.getFullYear(), String(date.getMonth()+1).padStart(2,'0'), String(date.getDate()).padStart(2,'0')].join('-');};
    document.getElementById('addBaseDocument').addEventListener('click', () => {
        clearBaseModal();
        document.getElementById('baseDocumentRegistration').value = today();
        document.getElementById('baseDocumentTitle').textContent = 'Agregar documentos';
        bootstrap.Modal.getOrCreateInstance(baseModal).show();
    });
    document.querySelectorAll('.edit-base-document').forEach(button => button.addEventListener('click', async () => {
        const item = JSON.parse(button.dataset.document);
        clearBaseModal();
        document.getElementById('baseDocumentId').value = item.id;
        if (item.catalog_id) baseCatalog.append(new Option(item.name, item.catalog_id, true, true)).trigger('change');
        document.getElementById('baseDocumentRegistration').value = item.registration_date || item.created_at.substring(0,10);
        document.getElementById('baseDocumentStart').value = item.start_date || '';
        document.getElementById('baseDocumentEnd').value = item.end_date || '';
        document.getElementById('baseDocumentTitle').textContent = 'Editar documento';
        const currentFile = document.getElementById('baseCurrentFile');
        if (item.file_path) {const link = document.createElement('a'); link.href = '<?= APP_URL ?>/' + item.file_path; link.target = '_blank'; link.rel = 'noopener'; link.textContent = 'Ver archivo actual'; currentFile.append(link); currentFile.classList.remove('d-none');}
        bootstrap.Modal.getOrCreateInstance(baseModal).show();
        try {
            const response = await fetch('<?= APP_URL ?>/modulos/clientes/pmi_observaciones.php?client_id=<?= $clientId ?>&document_id=' + encodeURIComponent(item.id));
            if (!response.ok) return;
            const data = await response.json();
            if (!data.observations?.length) return;
            const list = document.getElementById('baseObservationList');
            data.observations.forEach(entry => {const box = document.createElement('div'); box.className = 'border-top py-2'; const head = document.createElement('strong'); head.textContent = entry.user_name + ' · ' + entry.created_at; const note = document.createElement('div'); note.textContent = entry.note; box.append(head, note); list.append(box);});
            document.getElementById('baseObservationHistory').classList.remove('d-none');
        } catch (error) { /* El documento sigue siendo editable si falla el historial. */ }
    }));
    document.getElementById('addBaseCatalog').addEventListener('click', async () => {
        const result = await catalogDialog({title: 'Nuevo requisito', input: 'text', inputPlaceholder: 'Nombre del requisito', showCancelButton: true, confirmButtonText: 'Agregar', cancelButtonText: 'Cancelar', inputValidator: value => !value?.trim() ? 'Ingrese el nombre del requisito.' : undefined});
        if (!result.isConfirmed) return;
        const body = new FormData(); body.set('csrf_token', csrf); body.set('client_id', '<?= $clientId ?>'); body.set('action', 'add'); body.set('name', result.value.trim());
        try {const response = await fetch(catalogUrl, {method:'POST', body}); const data = await response.json(); if (!data.ok) throw new Error(data.message || 'No se pudo crear.'); baseCatalog.append(new Option(data.item.text, data.item.id, true, true)).trigger('change');} catch (error) {Swal.fire('Atención', error.message, 'warning');}
    });
    document.getElementById('deleteBaseCatalog').addEventListener('click', async () => {
        const id = baseCatalog.val();
        if (!id) {Swal.fire('Atención','Seleccione primero un documento.','warning'); return;}
        const answer = await catalogDialog({title:'¿Eliminar nombre del catálogo?',text:'Solo se puede eliminar si no tiene registros guardados.',icon:'warning',showCancelButton:true,confirmButtonText:'Sí, eliminar',cancelButtonText:'Cancelar'});
        if (!answer.isConfirmed) return;
        const body = new FormData(); body.set('csrf_token', csrf); body.set('client_id', '<?= $clientId ?>'); body.set('action', 'delete'); body.set('id', id);
        try {const response = await fetch(catalogUrl,{method:'POST',body}); const data = await response.json(); if (!data.ok) throw new Error(data.message || 'No se pudo eliminar.'); baseCatalog.find('option').filter((_, option) => option.value === String(id)).remove(); baseCatalog.val(null).trigger('change');} catch(error) {Swal.fire('Atención',error.message,'warning');}
    });
    const baseChecks=()=>Array.from(document.querySelectorAll('.base-document-check'));
    document.getElementById('downloadAllBase').addEventListener('click',event=>{if(event.currentTarget.dataset.hasFiles==='0'){event.preventDefault();Swal.fire('Atención','No hay archivos adjuntos disponibles para descargar.','warning')}});
    const selectAllBase=document.getElementById('selectAllBase');
    const syncSelectAllBase=()=>{const items=baseChecks();selectAllBase.checked=items.length>0&&items.every(check=>check.checked);selectAllBase.indeterminate=items.some(check=>check.checked)&&!selectAllBase.checked};
    selectAllBase.addEventListener('change',event=>{baseChecks().forEach(check=>{check.checked=event.target.checked});selectAllBase.indeterminate=false});
    baseChecks().forEach(check=>check.addEventListener('change',syncSelectAllBase));
    document.querySelectorAll('.folder-toggle').forEach(button=>button.addEventListener('click',()=>{const expand=button.getAttribute('aria-expanded')!=='true';document.querySelectorAll('.client-base-row[data-folder-id="'+button.dataset.folderId+'"]').forEach(row=>{row.hidden=!expand});button.setAttribute('aria-expanded',expand?'true':'false');button.closest('.client-pmi-folder-header').classList.toggle('is-open',expand);button.querySelector('.fa-chevron-down,.fa-chevron-up')?.classList.toggle('fa-chevron-up',expand);button.querySelector('.fa-chevron-down,.fa-chevron-up')?.classList.toggle('fa-chevron-down',!expand)}));
    const folderChoices=()=>Array.from(document.querySelectorAll('.base-folder-document-check:not(:disabled)'));
    const folderSelectAll=document.getElementById('baseFolderSelectAll');
    const syncFolderSelectAll=()=>{const choices=folderChoices();folderSelectAll.checked=choices.length>0&&choices.every(check=>check.checked);folderSelectAll.indeterminate=choices.some(check=>check.checked)&&!folderSelectAll.checked};
    folderSelectAll.addEventListener('change',()=>folderChoices().forEach(check=>{check.checked=folderSelectAll.checked}));
    folderChoices().forEach(check=>check.addEventListener('change',syncFolderSelectAll));
    document.getElementById('createBaseFolder').addEventListener('click',()=>{
        document.querySelectorAll('.base-folder-document-check').forEach(check=>{check.disabled=check.dataset.folderId!=='0';check.closest('.client-pmi-folder-choice').hidden=check.disabled});
        if(!folderChoices().length){Swal.fire('Atención','No hay documentos sin carpeta para organizar.','warning');return}
        const selected=new Set(baseChecks().filter(check=>check.checked&&!check.closest('tr').dataset.folderId).map(check=>check.value));
        folderChoices().forEach(check=>{check.checked=selected.has(check.value)});
        syncFolderSelectAll();
        document.getElementById('baseFolderAction').value='create_folder';
        document.getElementById('baseFolderId').value='';
        document.getElementById('baseFolderTitle').textContent='Crear carpeta de documentos';
        document.getElementById('baseFolderSubmit').textContent='Crear carpeta';
        document.getElementById('baseFolderName').value='';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('baseFolderModal')).show();
    });
    document.getElementById('baseFolderForm').addEventListener('submit',event=>{if(folderChoices().some(check=>check.checked))return;event.preventDefault();Swal.fire('Atención','Seleccione al menos un documento para la carpeta.','warning')});
    document.getElementById('downloadSelectedBase').addEventListener('click',()=>{const checks=baseChecks().filter(check=>check.checked);if(!checks.length){Swal.fire('Atención','Seleccione al menos un requisito.','warning');return}const ids=checks.filter(check=>check.dataset.hasFile==='1').map(check=>check.value);if(!ids.length){Swal.fire('Atención','Los requisitos seleccionados no tienen archivos adjuntos.','warning');return}const params=new URLSearchParams({cliente_id:'<?= $clientId ?>'});ids.forEach(id=>params.append('ids[]',id));window.location.href='<?= APP_URL ?>/modulos/clientes/pmi_descargar.php?'+params.toString()});
});
</script>
<?php if ($client && $activeTab === 'formatos'): require __DIR__ . '/pmi_formatos_script.php'; endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
