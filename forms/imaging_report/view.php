<?php
/**
 * Informe de Diagnóstico por Imágenes - view.php
 *
 * Vista de lectura del informe dentro del encuentro clínico.
 * Muestra los datos guardados y permite editar o ver el PDF.
 *
 * @package   OpenEMR
 * @author    Centro Médico Origen
 * @license   GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\Header;
use OpenEMR\Core\OEGlobalsBag;

$srcdir  = OEGlobalsBag::getInstance()->getSrcDir();
$rootdir = OEGlobalsBag::getInstance()->getString('rootdir');
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$pid     = PatientSessionUtil::getPid();

require_once("$srcdir/api.inc.php");

formHeader("Informe de Diagnóstico por Imágenes — Vista");

$formId = (int)($_GET['id'] ?? 0);
$obj    = [];

if ($formId > 0) {
    $obj = sqlQuery(
        "SELECT * FROM form_imaging_report WHERE id = ? AND pid = ? LIMIT 1",
        [$formId, $pid]
    ) ?: [];
}

if (empty($obj)) {
    echo '<p class="text-red-600 p-4">Informe no encontrado o sin acceso.</p>';
    formFooter();
    exit;
}

$modalidadLabels = [
    'RX'   => 'Rayos X (Radiografía Digital)',
    'TC'   => 'Tomografía Computada (TC)',
    'RMN'  => 'Resonancia Magnética (RMN)',
    'US'   => 'Ecografía / Ultrasonido (US)',
    'MG'   => 'Mamografía (MG)',
    'DEXA' => 'Densitometría Ósea (DEXA)',
    'OT'   => 'Otro / No especificado',
];
$modalidadLabel = $modalidadLabels[$obj['modalidad'] ?? ''] ?? ($obj['modalidad'] ?? '—');
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <?php Header::setupHeader(); ?>
    <meta charset="UTF-8">
    <title>Informe de Imágenes — Vista</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; background: #f8fafc; }
        .section-title { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.08em; color: #64748b; margin-bottom: 6px; }
        .field-content { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 14px; font-size: 14px; color: #1e293b; line-height: 1.7; white-space: pre-wrap; word-break: break-word; }
        .badge-borrador { background: #fef3c7; color: #92400e; }
        .badge-finalizado { background: #d1fae5; color: #065f46; }
    </style>
</head>
<body>
<div class="max-w-4xl mx-auto px-4 py-6">

    <!-- Cabecera -->
    <div class="bg-gradient-to-r from-slate-900 to-slate-700 text-white rounded-2xl p-6 mb-6 shadow-lg flex items-center justify-between">
        <div>
            <h1 class="text-xl font-bold">Informe de Diagnóstico por Imágenes</h1>
            <p class="text-slate-300 text-sm mt-1">
                Registrado: <?= text(date('d/m/Y H:i', strtotime($obj['date'] ?? 'now'))) ?>
                &bull; Médico: <?= text($obj['medico_informante'] ?? $obj['user'] ?? '—') ?>
            </p>
        </div>
        <span class="px-3 py-1.5 rounded-full text-xs font-bold <?= $obj['estado'] === 'finalizado' ? 'badge-finalizado' : 'badge-borrador' ?>">
            <?= $obj['estado'] === 'finalizado' ? '✔ Finalizado' : '⏳ Borrador' ?>
        </span>
    </div>

    <!-- Datos del estudio -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <h2 class="text-sm font-bold text-slate-800 mb-4 uppercase tracking-wider">📋 Datos del Estudio</h2>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-5">
            <div>
                <p class="section-title">Modalidad</p>
                <p class="text-sm font-semibold text-slate-800"><?= text($modalidadLabel) ?></p>
            </div>
            <div>
                <p class="section-title">Región Anatómica</p>
                <p class="text-sm font-semibold text-slate-800"><?= text($obj['region_anatomica'] ?? '—') ?></p>
            </div>
            <div>
                <p class="section-title">Fecha del Informe</p>
                <p class="text-sm font-semibold text-slate-800">
                    <?= $obj['fecha_informe'] ? text(date('d/m/Y', strtotime($obj['fecha_informe']))) : '—' ?>
                </p>
            </div>
            <div>
                <p class="section-title">Servicio Solicitante</p>
                <p class="text-sm text-slate-700"><?= text($obj['servicio_solicitante'] ?? '—') ?></p>
            </div>
            <div>
                <p class="section-title">Médico Solicitante</p>
                <p class="text-sm text-slate-700"><?= text($obj['medico_solicitante'] ?? '—') ?></p>
            </div>
            <div>
                <p class="section-title">Médico Informante</p>
                <p class="text-sm font-semibold text-slate-800"><?= text($obj['medico_informante'] ?? '—') ?></p>
            </div>
        </div>
    </div>

    <!-- Metodología -->
    <?php if (!empty($obj['metodologia'])): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <p class="section-title">🔬 Técnica / Metodología</p>
        <div class="field-content"><?= text($obj['metodologia']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Interpretación / Hallazgos -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <p class="section-title">🩻 Interpretación / Hallazgos</p>
        <div class="field-content"><?= text($obj['interpretacion'] ?? '—') ?></div>
    </div>

    <!-- Conclusión -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <p class="section-title">📌 Conclusión / Impresión Diagnóstica</p>
        <div class="field-content"><?= text($obj['conclusion'] ?? '—') ?></div>
    </div>

    <!-- Observaciones -->
    <?php if (!empty($obj['observaciones'])): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <p class="section-title">💬 Observaciones y Sugerencias</p>
        <div class="field-content"><?= text($obj['observaciones']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Botones de acción -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex flex-wrap gap-3 justify-end">

        <?php if (!empty($obj['pdf_document_id'])): ?>
            <a href="<?= attr($rootdir) ?>/controller.php?document&retrieve&patient_id=<?= attr_url($pid) ?>&document_id=<?= attr_url($obj['pdf_document_id']) ?>"
               target="_blank"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-teal-600 hover:bg-teal-700 text-white transition-colors shadow-sm">
                📄 Ver PDF
            </a>
        <?php endif; ?>

        <?php if ($obj['estado'] !== 'finalizado'): ?>
            <a href="<?= attr($rootdir) ?>/forms/imaging_report/new.php?id=<?= attr_url($formId) ?>"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-indigo-600 hover:bg-indigo-700 text-white transition-colors shadow-sm">
                ✏️ Editar Informe
            </a>
        <?php endif; ?>

        <button type="button" onclick="parent.closeTab(window.name, false)"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 transition-colors">
            ✕ Cerrar
        </button>
    </div>

</div>
</body>
</html>
<?php formFooter(); ?>
