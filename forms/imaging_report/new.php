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

formHeader("Informe de Diagnóstico por Imágenes");
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
$estado = $obj['estado'] ?? 'borrador';

// Opciones de modalidad
$modalidades = [
    ''    => '-- Seleccione Modalidad --',
    'RX'  => 'Rayos X (Radiografía Digital)',
    'TC'  => 'Tomografía Computada (TC)',
    'RMN' => 'Resonancia Magnética (RMN)',
    'US'  => 'Ecografía / Ultrasonido (US)',
    'MG'  => 'Mamografía (MG)',
    'DEXA' => 'Densitometría Ósea (DEXA)',
    'OT'  => 'Otro / No especificado',
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
$valorMedicoSolicitante = trim((string)($obj['medico_solicitante'] ?? ''));
$keyMedicoSolicitante = array_search($valorMedicoSolicitante, $medicosOpenEMR, true);
$esMedicoOpenEMR = ($keyMedicoSolicitante !== false);

// Árbol de categorías de documentos de pacientes (selector de carpeta destino)
require_once(__DIR__ . '/category_functions.php');
$categoryTree = imaging_get_category_tree();
$selectedCategoryId = (int)($obj['pdf_category_id'] ?? 0);
if ($selectedCategoryId <= 0) {
    $selectedCategoryId = imaging_default_category_id($obj['modalidad'] ?? '');
}
$categoryTreeHtml = imaging_render_category_tree($categoryTree, $selectedCategoryId, $selectedCategoryId);
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php Header::setupHeader(); ?>
    <meta charset="UTF-8">
    <title>Informe de Diagnóstico por Imágenes</title>
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
                <h1 class="text-xl font-bold tracking-tight">Informe de Diagnóstico por Imágenes</h1>
                <p class="text-slate-300 text-sm mt-1">Formulario Clínico Institucional — OpenEMR</p>
            </div>
            <?php if ($modoEdicion): ?>
                <span class="px-3 py-1 rounded-full text-xs font-bold <?= $estado === 'finalizado' ? 'badge-finalizado' : 'badge-borrador' ?>">
                    <?= $estado === 'finalizado' ? '✔ Finalizado' : '⏳ Borrador' ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Selector de plantilla rápida -->
    <div class="bg-white rounded-2xl border border-slate-200 p-4 mb-5 shadow-sm">
        <p class="text-xs font-bold text-slate-500 uppercase tracking-wider mb-3">⚡ Plantillas Rápidas — Informe Normal</p>
        <div class="flex flex-wrap gap-2" id="template-buttons">
            <button type="button" class="template-btn" data-tpl="RX">Rx Normal</button>
            <button type="button" class="template-btn" data-tpl="TC">TC Normal</button>
            <button type="button" class="template-btn" data-tpl="RMN">RMN Normal</button>
            <button type="button" class="template-btn" data-tpl="US">Eco Normal</button>
            <button type="button" class="template-btn" data-tpl="MG">Mamografía Normal</button>
            <button type="button" class="template-btn" data-tpl="DEXA">DEXA Normal</button>
        </div>
    </div>

    <!-- Formulario principal -->
    <form method="POST"
          action="<?= attr($rootdir) ?>/forms/imaging_report/save.php?mode=<?= $modoEdicion ? 'update&id=' . attr_url($formId) : 'new' ?>"
          name="my_form" id="my_form">

        <input type="hidden" name="csrf_token_form" value="<?= CsrfUtils::collectCsrfToken(session: $session) ?>">
        <input type="hidden" name="accion" value="borrador" id="input_accion">
        <input type="hidden" name="category_id" id="input_category_id" value="<?= attr($selectedCategoryId) ?>">

        <!-- Sección 1: Datos del Estudio -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header">📋 Datos del Estudio</div>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">

                <div>
                    <label class="form-label" for="modalidad">Modalidad *</label>
                    <select name="modalidad" id="modalidad" class="form-control" required>
                        <?php foreach ($modalidades as $val => $label): ?>
                            <option value="<?= attr($val) ?>"
                                <?= (($obj['modalidad'] ?? '') === $val) ? 'selected' : '' ?>>
                                <?= text($label) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="region_anatomica">Región / Área Anatómica *</label>
                    <select name="region_anatomica" id="region_anatomica" class="form-control" required>
                        <option value="">-- Seleccione Región... --</option>
                        <?php foreach ($regionesAnatomicas as $k => $titulo): ?>
                            <option value="<?= attr($titulo) ?>"
                                <?= (($obj['region_anatomica'] ?? '') === $titulo) ? 'selected' : '' ?>>
                                <?= text($titulo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (!isset($obj['region_anatomica']) || trim($obj['region_anatomica'] ?? '') === ''): ?>
                    <?php elseif (!in_array($obj['region_anatomica'], array_values($regionesAnatomicas), true)): ?>
                        <p class="text-xs text-amber-600 mt-1">Valor existente no está en la lista: "<?= text($obj['region_anatomica']) ?>"</p>
                    <?php endif; ?>
                </div>

                <div>
                    <label class="form-label" for="servicio_solicitante">Servicio / Solicitante</label>
                    <select name="servicio_solicitante" id="servicio_solicitante" class="form-control">
                        <option value="">-- Seleccione Servicio... --</option>
                        <?php foreach ($servicios as $k => $titulo): ?>
                            <option value="<?= attr($titulo) ?>"
                                <?= (($obj['servicio_solicitante'] ?? '') === $titulo) ? 'selected' : '' ?>>
                                <?= text($titulo) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="form-label" for="medico_solicitante">Médico Solicitante</label>
                    <select name="medico_solicitante_custom" id="medico_solicitante_select" class="form-control">
                        <option value="" <?= !$esMedicoOpenEMR && $valorMedicoSolicitante === '' ? 'selected' : '' ?>>
                            -- Nuevo Médico del centro u Otro lugar... --
                        </option>
                        <option value="__otro__"
                            <?= ($keyMedicoSolicitante === false && $valorMedicoSolicitante !== '') ? 'selected' : '' ?>>
                            Otro médico (escribir nombre)
                        </option>
                        <?php foreach ($medicosOpenEMR as $mid => $nombre): ?>
                            <option value="<?= attr('med_' . $mid) ?>"
                                <?= ($keyMedicoSolicitante === (string)$mid || $keyMedicoSolicitante === $mid) ? 'selected' : '' ?>>
                                <?= text($nombre) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" name="medico_solicitante" id="medico_solicitante"
                           value="<?= attr($obj['medico_solicitante'] ?? '') ?>">
                    <div id="medico_otro_container" class="mt-2 <?= ($keyMedicoSolicitante === false && $valorMedicoSolicitante !== '') ? '' : ' hidden' ?>">
                        <input type="text" id="medico_otro_input" class="form-control"
                               placeholder="Nombre del médico solicitante"
                               value="<?= attr($valorMedicoSolicitante) ?>">
                    </div>
                </div>

                <div>
                    <label class="form-label" for="medico_informante">Médico Informante</label>
                    <input type="text" name="medico_informante" id="medico_informante"
                           class="form-control"
                           value="<?= attr($obj['medico_informante'] ?? $medicoInformante) ?>">
                </div>

                <div>
                    <label class="form-label" for="fecha_informe">Fecha del Informe</label>
                    <input type="date" name="fecha_informe" id="fecha_informe"
                           class="form-control"
                           value="<?= attr($obj['fecha_informe'] ?? date('Y-m-d')) ?>">
                </div>
            </div>
        </div>

        <!-- Sección 2: Técnica / Metodología -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header">🔬 Técnica / Metodología</div>
            <textarea name="metodologia" id="metodologia" class="form-control" rows="4"
                      placeholder="Describa el protocolo, secuencias o proyecciones utilizadas..."><?= text($obj['metodologia'] ?? '') ?></textarea>
        </div>

        <!-- Sección 3: Interpretación / Hallazgos -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header">🩻 Interpretación / Hallazgos</div>
            <textarea name="interpretacion" id="interpretacion" class="form-control" rows="10"
                      placeholder="Describa en detalle los hallazgos observados en el estudio..."><?= text($obj['interpretacion'] ?? '') ?></textarea>
        </div>

        <!-- Sección 4: Conclusión -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header">📌 Conclusión / Impresión Diagnóstica</div>
            <textarea name="conclusion" id="conclusion" class="form-control" rows="5"
                      placeholder="Síntesis diagnóstica final del estudio..."><?= text($obj['conclusion'] ?? '') ?></textarea>
        </div>

        <!-- Sección 5: Observaciones -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header">💬 Observaciones y Sugerencias</div>
            <textarea name="observaciones" id="observaciones" class="form-control" rows="4"
                      placeholder="Recomendaciones de seguimiento, correlación clínica, estudios adicionales..."><?= text($obj['observaciones'] ?? '') ?></textarea>
        </div>

        <!-- Sección 6: Carpeta destino del PDF -->
        <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
            <div class="section-header">📂 Carpeta Destino del PDF</div>
            <label class="form-label">Seleccioná la carpeta donde se guardará el PDF en el legajo (Documentos del paciente)</label>
            <div class="imr-tree-container" id="categoryTree">
                <?= $categoryTreeHtml ?>
            </div>
            <div class="imr-selection-display<?= $selectedCategoryId > 0 ? ' imr-has-selection' : '' ?>" id="selectionDisplay">
                <?= $selectedCategoryId > 0 ? '📁 ' . text(imaging_category_name($categoryTree, $selectedCategoryId)) : '— No se seleccionó carpeta (se usará la automática) —' ?>
            </div>
            <p class="text-xs text-slate-400 mt-2"><?= text('Si no se elige carpeta, se usará la categoría automática según la modalidad (ej: RMN → Resonancia Magnética).') ?></p>
        </div>

        <!-- Botones de acción -->
        <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex flex-col sm:flex-row items-center justify-between gap-4">
            <button type="button" class="btn-cancel inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold text-slate-600 bg-slate-100 hover:bg-slate-200 border border-slate-200 transition-colors">
                ✕ Cancelar
            </button>
            <div class="flex items-center gap-3">
                <button type="button" id="btn-draft"
                        class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold btn-save-draft transition-all">
                    💾 Guardar Borrador
                </button>
                <button type="button" id="btn-finalize"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-xl text-sm font-semibold btn-finalize shadow-md transition-all">
                    📄 Guardar y Generar PDF
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
    document.getElementById('input_accion').value = 'borrador';
    top.restoreSession();
    document.getElementById('my_form').submit();
});

document.getElementById('btn-finalize').addEventListener('click', function() {
    const modal = document.getElementById('modalidad');
    const region = document.getElementById('region_anatomica');
    const interpretacion = document.getElementById('interpretacion');
    const conclusion = document.getElementById('conclusion');

    if (!modal.value || !region.value.trim() || !interpretacion.value.trim() || !conclusion.value.trim()) {
        alert('Por favor complete: Modalidad, Región Anatómica, Interpretación/Hallazgos y Conclusión antes de finalizar el informe.');
        return;
    }

    if (!confirm('¿Confirma finalizar el informe y generar el PDF? Una vez finalizado, el estado cambiará a "Finalizado".')) return;

    document.getElementById('input_accion').value = 'finalizar';
    top.restoreSession();
    document.getElementById('my_form').submit();
});

document.querySelector('.btn-cancel').addEventListener('click', function() {
    parent.closeTab(window.name, false);
});

// ============================================================
// Médico Solicitante: sincronizar dropdown + campo "otro" + hidden
// ============================================================
(function () {
    const select = document.getElementById('medico_solicitante_select');
    const hidden = document.getElementById('medico_solicitante');
    const otroContainer = document.getElementById('medico_otro_container');
    const otroInput = document.getElementById('medico_otro_input');

    function updateMedico() {
        const val = select.value;
        if (val === '__otro__') {
            otroContainer.classList.remove('hidden');
            const nombre = (otroInput.value || '').trim();
            hidden.value = nombre;
            otroInput.focus();
        } else if (val === '') {
            otroContainer.classList.add('hidden');
            hidden.value = '';
        } else if (val.startsWith('med_')) {
            otroContainer.classList.add('hidden');
            const label = select.options[select.selectedIndex].textContent.trim();
            hidden.value = label;
        }
    }

    otroInput.addEventListener('input', function () {
        hidden.value = otroInput.value.trim();
    });

    select.addEventListener('change', updateMedico);
    updateMedico();
})();

// ============================================================
// Botones de plantilla rápida
// ============================================================
function applyImagingTemplate(tpl) {
    if (typeof IMAGING_TEMPLATES === 'undefined' || !IMAGING_TEMPLATES[tpl]) {
        console.warn('[imaging_report] Plantilla no disponible:', tpl);
        return;
    }
    const t = IMAGING_TEMPLATES[tpl];

    // Seleccionar la modalidad correspondiente
    const modalidadSelect = document.getElementById('modalidad');
    if (modalidadSelect) modalidadSelect.value = tpl;

    if (t.metodologia)  document.getElementById('metodologia').value  = t.metodologia;
    if (t.interpretacion) document.getElementById('interpretacion').value = t.interpretacion;
    if (t.conclusion)   document.getElementById('conclusion').value   = t.conclusion;
    if (t.observaciones) document.getElementById('observaciones').value = t.observaciones;
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
