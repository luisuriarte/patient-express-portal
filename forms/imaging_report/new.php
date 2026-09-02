<?php
/**
 * Informe de Diagnóstico por Imágenes - new.php
 *
 * Formulario de creación / edición de informe radiológico institucional.
 * Formulario clínico de OpenEMR (Clinical Encounter Form).
 *
 * @package   OpenEMR
 * @author    Centro Médico Origen
 * @license   GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;

$srcdir    = OEGlobalsBag::getInstance()->getSrcDir();
$rootdir   = OEGlobalsBag::getInstance()->getString('rootdir');
$session   = SessionWrapperFactory::getInstance()->getActiveSession();
$pid       = PatientSessionUtil::getPid();
$encounter = EncounterSessionUtil::getEncounter();
$authUser  = $session->get('authUser');

require_once("$srcdir/api.inc.php");

formHeader(xl("Imaging Report"));
$returnurl = 'encounter_top.php';

// Cargar datos del médico informante actual
$providerData = sqlQuery(
    "SELECT fname, lname, specialty, npi FROM users WHERE username = ? LIMIT 1",
    [$authUser]
);
$medicoInformante = trim(
    ($providerData['fname'] ?? '') . ' ' . ($providerData['lname'] ?? '')
) ?: $authUser;

// Edición: cargar datos existentes si hay un id
$obj = [];
$formId = (int)($_GET['id'] ?? 0);
if ($formId > 0) {
    $obj = sqlQuery("SELECT * FROM form_imaging_report WHERE id = ? AND pid = ? LIMIT 1", [$formId, $pid]) ?: [];
}

$modoEdicion = !empty($obj);
$estado = $obj['status'] ?? 'draft';

// Opciones de modalidad
$modalidades = [
    ''    => xl('-- Select Modality --'),
    'RX'  => xl('X-Ray (Digital Radiography)'),
    'TC'  => xl('Computed Tomography (CT)'),
    'RMN' => xl('Magnetic Resonance Imaging (MRI)'),
    'US'  => xl('Ultrasound (US)'),
    'MG'  => xl('Mammography (MG)'),
    'DEXA' => xl('Bone Densitometry (DEXA)'),
    'OT'  => xl('Other / Not specified'),
];

// Listado de Servicios Solicitantes (dropdown normalizado desde list_options)
$servicios = [];
$resServicios = sqlStatement(
    "SELECT option_id, title FROM list_options
      WHERE list_id = 'imaging_report_services' AND activity = 1
      ORDER BY seq ASC, title ASC"
);
while ($svc = sqlFetchArray($resServicios)) {
    $servicios[$svc['option_id']] = $svc['title'];
}

// Listado de Región / Área Anatómica (dropdown normalizado desde list_options)
$regionesAnatomicas = [];
$resRegiones = sqlStatement(
    "SELECT option_id, title FROM list_options
      WHERE list_id = 'imaging_report_anatomy' AND activity = 1
      ORDER BY seq ASC, title ASC"
);
while ($reg = sqlFetchArray($resRegiones)) {
    $regionesAnatomicas[$reg['option_id']] = $reg['title'];
}

// Médico Solicitante: médicos de OpenEMR habilitados para autorizar (authorized=1)
$medicosOpenEMR = [];
$resMedicos = sqlStatement(
    "SELECT id, CONCAT_WS(' ', lname, fname) AS nombre
       FROM users
      WHERE authorized = 1 AND active = 1
      ORDER BY lname ASC, fname ASC"
);
while ($med = sqlFetchArray($resMedicos)) {
    $medicosOpenEMR[$med['id']] = trim((string)$med['nombre']);
}

// Normaliza el valor del médico: si coincide con un médico de OpenEMR, se usa el
// nombre completo; en caso contrario (médico externo escrito a mano) se conserva tal cual.
$valorMedicoSolicitante = trim((string)($obj['requesting_physician'] ?? ''));
$keyMedicoSolicitante = array_search($valorMedicoSolicitante, $medicosOpenEMR, true);
$esMedicoOpenEMR = ($keyMedicoSolicitante !== false);

// Árbol de categorías de documentos de pacientes (selector de carpeta destino)
require_once(__DIR__ . '/category_functions.php');
$categoryTree = imaging_get_category_tree();
$selectedCategoryId = (int)($obj['pdf_category_id'] ?? 0);
if ($selectedCategoryId <= 0) {
    $selectedCategoryId = imaging_default_category_id($obj['modality'] ?? '');
}
$categoryTreeHtml = imaging_render_category_tree($categoryTree, $selectedCategoryId, $selectedCategoryId);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php Header::setupHeader(); ?>
    <meta charset="UTF-8">
    <title><?= xlt('Imaging Report') ?></title>
    <!-- Tailwind CSS via CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= attr($rootdir) ?>/forms/imaging_report/assets/css/style.css">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; }
        .form-label { @apply block text-sm font-semibold text-slate-700 mb-1; }
        .form-control { width: 100%; border: 1px solid #cbd5e1; border-radius: 8px; padding: 8px 12px; font-size: 14px; color: #1e293b; background: #fff; transition: border-color 0.15s, box-shadow 0.15s; }
        .form-control:focus { outline: none; border-color: #0ea5e9; box-shadow: 0 0 0 3px rgba(14,165,233,0.15); }
        textarea.form-control { resize: vertical; line-height: 1.6; }
        select.form-control { height: 40px; padding: 8px 12px; line-height: 1.5; }
        input[type="text"].form-control { line-height: 1.5; height: 40px; }
        .section-header { background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); color: white; padding: 10px 16px; border-radius: 10px; font-weight: 700; font-size: 14px; letter-spacing: 0.05em; text-transform: uppercase; margin-bottom: 14px; }
        .template-btn { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 8px; font-size: 12px; font-weight: 600; cursor: pointer; border: 1px solid #cbd5e1; background: #f1f5f9; color: #334155; transition: all 0.15s; }
        .template-btn:hover { background: #e2e8f0; border-color: #94a3b8; }
        .btn-save-draft { background: #f1f5f9; color: #334155; border: 1px solid #cbd5e1; }
        .btn-save-draft:hover { background: #e2e8f0; }
        .btn-finalize { background: linear-gradient(135deg, #0ea5e9, #0284c7); color: white; border: none; }
        .btn-finalize:hover { background: linear-gradient(135deg, #0284c7, #0369a1); }
        .badge-borrador { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .badge-finalizado { background: #d1fae5; color: #065f46; border: 1px solid #6ee7b7; }
        /* ---- Árbol de categorías ---- */
        .imr-tree-container { border: 1px solid #dbe2ec; border-radius: 10px; max-height: 320px; overflow-y: auto; background: #fff; }
        .imr-tree-empty { padding: 16px; color: #64748b; font-size: 13px; }
        .imr-tree-node { display: flex; align-items: center; gap: 6px; padding: 7px 12px; cursor: pointer; border-bottom: 1px solid #f1f5f9; transition: background 0.15s; font-size: 13px; color: #334155; }
        .imr-tree-node:hover { background: #f8fafc; }
        .imr-tree-node.imr-selected { background: #eff6ff; color: #1d4ed8; font-weight: 600; border-left: 3px solid #3b82f6; }
        .imr-tree-toggle { width: 14px; color: #94a3b8; font-size: 11px; display: inline-flex; transition: transform 0.15s; }
        .imr-tree-toggle.imr-collapsed { transform: rotate(-90deg); }
        .imr-tree-toggle.imr-hidden-toggle { visibility: hidden; }
        .imr-tree-icon { color: #f59e0b; width: 16px; text-align: center; }
        .imr-tree-children.imr-hidden { display: none; }
        .imr-selection-display { margin-top: 10px; padding: 8px 12px; border-radius: 8px; border: 1px dashed #cbd5e1; font-size: 13px; min-height: 38px; display: flex; align-items: center; color: #64748b; }
        .imr-selection-display.imr-has-selection { border-color: #3b82f6; background: #f0f7ff; color: #1e40af; font-weight: 500; }
    </style>
</head>
<body>
<div class="max-w-4xl mx-auto px-4 py-6">

    <!-- Encabezado del formulario -->
    <div class="bg-gradient-to-r from-slate-900 to-slate-700 text-white rounded-2xl p-6 mb-6 shadow-lg">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-xl font-bold tracking-tight"><?= xlt('Imaging Report') ?></h1>
                <p class="text-slate-300 text-sm mt-1"><?= xlt('Institutional Clinical Form — OpenEMR') ?></p>
            </div>
            <?php if ($modoEdicion): ?>
                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $estado === 'finalized' ? 'badge-finalizado' : 'badge-borrador' ?>">
                    <?= $estado === 'finalized' ? xlt('✔ Completed') : xlt('⏳ Draft') ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Selector de plantilla rápida -->
    <div class="bg-white rounded-2xl border border-slate-200 p-4 mb-5 shadow-sm">
        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3"><?= xlt('⚡ Quick Templates — Normal Report') ?></p>
        <div class="flex flex-wrap gap-2" id="template-buttons">
            <button type="button" class="template-btn" data-tpl="RX"><?= xlt('Rx Normal') ?></button>
            <button type="button" class="template-btn" data-tpl="TC"><?= xlt('TC Normal') ?></button>
            <button type="button" class="template-btn" data-tpl="RMN"><?= xlt('MRI Normal') ?></button>
            <button type="button" class="template-btn" data-tpl="US"><?= xlt('US Normal') ?></button>
            <button type="button" class="template-btn" data-tpl="MG"><?= xlt('Mammography Normal') ?></button>
            <button type="button" class="template-btn" data-tpl="DEXA"><?= xlt('DEXA Normal') ?></button>
        </div>
    </div>

    <!-- Formulario principal -->
    <form method="POST"
          action="<?= attr($rootdir) ?>/forms/imaging_report/save.php?mode=<?= $modoEdicion ? 'update&id=' . attr_url($formId) : 'new' ?>"
          name="my_form" id="my_form">

        <input type="hidden" name="csrf_token_form" value="<?= CsrfUtils::collectCsrfToken(session: $session) ?>">
        <input type="hidden" name="action" value="draft" id="input_action">
        <input type="hidden" name="category_id" id="input_category_id" value="<?= attr($selectedCategoryId) ?>">

        <!-- Sección 1: Datos del Estudio -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header"><?= xlt('📋 Study Data') ?></div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                <div>
                    <label class="form-label" for="modality"><?= xlt('Modality *') ?></label>
                    <select name="modality" id="modality" class="form-control" required>
                        <?php foreach ($modalidades as $val => $label): ?>
                            <option value="<?= attr($val) ?>"
                                <?= (($obj['modality'] ?? '') === $val) ? 'selected' : '' ?>>
                                <?= text($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="anatomical_region"><?= xlt('Region / Anatomical Area *') ?></label>
                    <select name="anatomical_region" id="anatomical_region" class="form-control" required>
                        <option value=""><?= xlt('-- Select Region... --') ?></option>
                        <?php foreach ($regionesAnatomicas as $k => $titulo): ?>
                            <option value="<?= attr($titulo) ?>"
                                <?= (($obj['anatomical_region'] ?? '') === $titulo) ? 'selected' : '' ?>>
                                <?= text($titulo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!isset($obj['anatomical_region']) || trim($obj['anatomical_region'] ?? '') === ''): ?>
                    <?php elseif (!in_array($obj['anatomical_region'], array_values($regionesAnatomicas), true)): ?>
                        <p class="text-xs text-amber-600 mt-1"><?= xlt('Existing value not in list:') ?> "<?= text($obj['anatomical_region']) ?>"</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="requesting_service"><?= xlt('Service / Requester') ?></label>
                    <select name="requesting_service" id="requesting_service" class="form-control">
                        <option value=""><?= xlt('-- Select Service... --') ?></option>
                        <?php foreach ($servicios as $k => $titulo): ?>
                            <option value="<?= attr($titulo) ?>"
                                <?= (($obj['requesting_service'] ?? '') === $titulo) ? 'selected' : '' ?>>
                                <?= text($titulo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="requesting_physician"><?= xlt('Requesting Physician') ?></label>
                    <select name="requesting_physician_custom" id="requesting_physician_select" class="form-control">
                        <option value="" <?= !$esMedicoOpenEMR && $valorMedicoSolicitante === '' ? 'selected' : '' ?>>
                            <?= xlt('-- New In-house Physician or Other... --') ?>
                        </option>
                        <option value="__otro__"
                            <?= ($keyMedicoSolicitante === false && $valorMedicoSolicitante !== '') ? 'selected' : '' ?>>
                            <?= xlt('Other physician (type name)') ?>
                        </option>
                        <?php foreach ($medicosOpenEMR as $mid => $nombre): ?>
                            <option value="<?= attr('med_' . $mid) ?>"
                                <?= ($keyMedicoSolicitante === (string)$mid || $keyMedicoSolicitante === $mid) ? 'selected' : '' ?>>
                                <?= text($nombre) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="requesting_physician" id="requesting_physician"
                           value="<?= attr($obj['requesting_physician'] ?? '') ?>">
                    <div id="requesting_physician_other_container" class="mt-2 <?= ($keyMedicoSolicitante === false && $valorMedicoSolicitante !== '') ? '' : ' hidden' ?>">
                        <input type="text" id="requesting_physician_other_input" class="form-control"
                               placeholder="<?= xla('Requesting physician name') ?>"
                               value="<?= attr($valorMedicoSolicitante) ?>">
                    </div>
                </div>

                <div>
                    <label class="form-label" for="reporting_physician"><?= xlt('Reporting Physician') ?></label>
                    <input type="text" name="reporting_physician" id="reporting_physician"
                           class="form-control"
                           value="<?= attr($obj['reporting_physician'] ?? $medicoInformante) ?>">
                </div>

                <div>
                    <label class="form-label" for="report_date"><?= xlt('Report Date') ?></label>
                    <input type="date" name="report_date" id="report_date"
                           class="form-control"
                           value="<?= attr($obj['report_date'] ?? date('Y-m-d')) ?>">
                </div>
            </div>
        </div>

        <!-- Sección 2: Técnica / Metodología -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header"><?= xlt('🔬 Technique / Methodology') ?></div>
            <textarea name="technique" id="technique" class="form-control" rows="4"
                      placeholder="<?= xla('Describe the protocol, sequences or projections used...') ?>"><?= text($obj['technique'] ?? '') ?></textarea>
        </div>

        <!-- Sección 3: Interpretación / Hallazgos -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header"><?= xlt('🩻 Interpretation / Findings') ?></div>
            <textarea name="interpretation" id="interpretation" class="form-control" rows="10"
                      placeholder="<?= xla('Describe in detail the findings observed in the study...') ?>"><?= text($obj['interpretation'] ?? '') ?></textarea>
        </div>

        <!-- Sección 4: Conclusión -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header"><?= xlt('📌 Conclusion / Diagnostic Impression') ?></div>
            <textarea name="conclusion" id="conclusion" class="form-control" rows="5"
                      placeholder="<?= xla('Final diagnostic synthesis of the study...') ?>"><?= text($obj['conclusion'] ?? '') ?></textarea>
        </div>

        <!-- Sección 5: Observaciones -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header"><?= xlt('💬 Observations and Suggestions') ?></div>
            <textarea name="observations" id="observations" class="form-control" rows="4"
                      placeholder="<?= xla('Follow-up recommendations, clinical correlation, additional studies...') ?>"><?= text($obj['observations'] ?? '') ?></textarea>
        </div>

        <!-- Sección 6: Carpeta destino del PDF -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header"><?= xlt('📂 PDF Destination Folder') ?></div>
            <label class="form-label"><?= xlt('Select the folder where the PDF will be saved in the patient chart (Patient Documents)') ?></label>
            <div class="imr-tree-container" id="categoryTree">
                <?= $categoryTreeHtml ?>
            </div>
            <div class="imr-selection-display<?= $selectedCategoryId > 0 ? ' imr-has-selection' : '' ?>" id="selectionDisplay">
                <?= $selectedCategoryId > 0 ? '📁 ' . text(imaging_category_name($categoryTree, $selectedCategoryId)) : xlt('— No folder selected (automatic will be used) —') ?>
            </div>
            <p class="text-xs text-slate-400 mt-2"><?= xlt('If no folder is selected, the automatic category will be used according to the modality (e.g.: MRI → Magnetic Resonance).') ?></p>
        </div>

        <!-- Botones de acción -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
            <button type="button" class="btn-cancel inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-colors">
                ✕ <?= xlt('Cancel') ?>
            </button>
            <div class="flex items-center gap-3">
                <button type="button" id="btn-draft"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold btn-save-draft transition-all">
                    💾 <?= xlt('Save Draft') ?>
                </button>
                <button type="button" id="btn-finalize"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-semibold btn-finalize shadow-md transition-all">
                    📄 <?= xlt('Save and Generate PDF') ?>
                </button>
            </div>
        </div>

    </form>
</div>

<script src="<?= attr($rootdir) ?>/forms/imaging_report/assets/js/templates.js"></script>
<script>
// ============================================================
// Botones de acción
// ============================================================
document.getElementById('btn-draft').addEventListener('click', function() {
    document.getElementById('input_action').value = 'draft';
    top.restoreSession();
    document.getElementById('my_form').submit();
});

document.getElementById('btn-finalize').addEventListener('click', function() {
    const modal = document.getElementById('modality');
    const region = document.getElementById('anatomical_region');
    const interpretation = document.getElementById('interpretation');
    const conclusion = document.getElementById('conclusion');

    if (!modal.value || !region.value.trim() || !interpretation.value.trim() || !conclusion.value.trim()) {
        alert(xl('Please complete: Modality, Anatomical Region, Interpretation/Findings and Conclusion before finalizing the report.'));
        return;
    }

    if (!confirm(xl('Are you sure you want to finalize the report and generate the PDF? Once finalized, the status will change to "Completed".'))) return;

    document.getElementById('input_action').value = 'finalize';
    top.restoreSession();
    document.getElementById('my_form').submit();
});

document.querySelector('.btn-cancel').addEventListener('click', function() {
    parent.closeTab(window.name, false);
});

// ============================================================
// Requesting Physician: sync dropdown + "other" field + hidden
// ============================================================
(function () {
    const select = document.getElementById('requesting_physician_select');
    const hidden = document.getElementById('requesting_physician');
    const otherContainer = document.getElementById('requesting_physician_other_container');
    const otherInput = document.getElementById('requesting_physician_other_input');

    function updatePhysician() {
        const val = select.value;
        if (val === '__otro__') {
            otherContainer.classList.remove('hidden');
            const name = (otherInput.value || '').trim();
            hidden.value = name;
            otherInput.focus();
        } else if (val === '') {
            otherContainer.classList.add('hidden');
            hidden.value = '';
        } else if (val.startsWith('med_')) {
            otherContainer.classList.add('hidden');
            const label = select.options[select.selectedIndex].textContent.trim();
            hidden.value = label;
        }
    }

    otherInput.addEventListener('input', function () {
        hidden.value = otherInput.value.trim();
    });

    select.addEventListener('change', updatePhysician);
    updatePhysician();
})();

// ============================================================
// Quick template buttons
// ============================================================
function applyImagingTemplate(tpl) {
    if (typeof IMAGING_TEMPLATES === 'undefined' || !IMAGING_TEMPLATES[tpl]) {
        console.warn('[imaging_report] Template not available:', tpl);
        return;
    }
    const t = IMAGING_TEMPLATES[tpl];

    // Select the matching modality
    const modalitySelect = document.getElementById('modality');
    if (modalitySelect) modalitySelect.value = tpl;

    if (t.metodologia)  document.getElementById('technique').value  = t.metodologia;
    if (t.interpretacion) document.getElementById('interpretation').value = t.interpretacion;
    if (t.conclusion)   document.getElementById('conclusion').value   = t.conclusion;
    if (t.observaciones) document.getElementById('observations').value = t.observaciones;
}

// Delegación de eventos: funciona aunque los botones se re-rendericen
document.addEventListener('click', function (e) {
    const btn = e.target.closest('.template-btn');
    if (!btn) return;
    applyImagingTemplate(btn.getAttribute('data-tpl'));
});

// ============================================================
// Selector de carpeta (árbol de categorías)
// ============================================================
(function () {
    const tree = document.getElementById('categoryTree');
    const hidden = document.getElementById('input_category_id');
    const display = document.getElementById('selectionDisplay');

    function setSelection(id, name) {
        hidden.value = id;
        display.textContent = '📁 ' + name;
        display.classList.add('imr-has-selection');
        tree.querySelectorAll('.imr-tree-node.imr-selected').forEach(function (n) {
            n.classList.remove('imr-selected');
        });
        const node = tree.querySelector('.imr-tree-node[data-id="' + id + '"]');
        if (node) node.classList.add('imr-selected');
    }

    tree.addEventListener('click', function (e) {
        const node = e.target.closest('.imr-tree-node');
        if (!node) return;
        const id = Number(node.getAttribute('data-id'));
        const name = node.getAttribute('data-name');
        const hasChildren = node.classList.contains('imr-has-children');

        if (hasChildren) {
            const children = document.getElementById('imr-children-' + id);
            const toggle = node.querySelector('.imr-tree-toggle');
            if (children) {
                const hiddenNow = children.classList.toggle('imr-hidden');
                toggle && toggle.classList.toggle('imr-collapsed', hiddenNow);
            }
        }

        // Una carpeta con hijos también puede ser el destino seleccionado.
        setSelection(id, name);
    });

    // Accesibilidad: permitir seleccionar con Enter/Espacio.
    tree.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
            const node = e.target.closest('.imr-tree-node');
            if (node) { e.preventDefault(); node.click(); }
        }
    });
})();
</script>
</body>
</html>
<?php formFooter(); ?>
