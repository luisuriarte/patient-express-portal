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
$webroot = OEGlobalsBag::getInstance()->getWebRoot();
$session = SessionWrapperFactory::getInstance()->getActiveSession();
$pid     = PatientSessionUtil::getPid();

require_once("$srcdir/api.inc.php");
require_once(__DIR__ . '/category_functions.php');

formHeader(xl("Imaging Report — View"));

$formId = (int)($_GET['id'] ?? 0);
$obj    = [];

if ($formId > 0) {
    $obj = sqlQuery(
        "SELECT * FROM form_imaging_report WHERE id = ? AND pid = ? LIMIT 1",
        [$formId, $pid]
    ) ?: [];
}

if (empty($obj)) {
    echo '<p class="text-red-600 p-4">' . xlt('Report not found or no access.') . '</p>';
    formFooter();
    exit;
}

$modalidadLabels = [
    'RX'   => xl('X-Ray (Digital Radiography)'),
    'TC'   => xl('Computed Tomography (CT)'),
    'RMN'  => xl('Magnetic Resonance Imaging (MRI)'),
    'US'   => xl('Ultrasound (US)'),
    'MG'   => xl('Mammography (MG)'),
    'DEXA' => xl('Bone Densitometry (DEXA)'),
    'OT'   => xl('Other / Not specified'),
];
$modalidadLabel = $modalidadLabels[$obj['modality'] ?? ''] ?? ($obj['modality'] ?? '—');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <?php Header::setupHeader(); ?>
    <meta charset="UTF-8">
    <title><?= xlt('Imaging Report — View') ?></title>
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
            <h1 class="text-xl font-bold"><?= xlt('Imaging Report') ?></h1>
            <p class="text-slate-300 text-sm mt-1">
                <?= xlt('Registered:') ?> <?= text(date('d/m/Y H:i', strtotime($obj['date'] ?? 'now'))) ?>
                &bull; <?= xlt('Physician:') ?> <?= text($obj['reporting_physician'] ?? $obj['user'] ?? '—') ?>
            </p>
        </div>
        <span class="px-3 py-1.5 rounded-full text-xs font-bold <?= $obj['status'] === 'finalized' ? 'badge-finalizado' : 'badge-borrador' ?>">
            <?= $obj['status'] === 'finalized' ? xlt('✔ Completed') : xlt('⏳ Draft') ?>
        </span>
    </div>

    <!-- Datos del estudio -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <h2 class="text-sm font-bold text-slate-800 mb-4 uppercase tracking-wider">📋 <?= xlt('Study Data') ?></h2>
        <div class="grid grid-cols-2 md:grid-cols-3 gap-5">
            <div>
                <p class="section-title"><?= xlt('Modality') ?></p>
                <p class="text-sm font-semibold text-slate-800"><?= text($modalidadLabel) ?></p>
            </div>
            <div>
                <p class="section-title"><?= xlt('Anatomical Region') ?></p>
                <p class="text-sm font-semibold text-slate-800"><?= text($obj['anatomical_region'] ?? '—') ?></p>
            </div>
            <div>
                <p class="section-title"><?= xlt('Report Date') ?></p>
                <p class="text-sm font-semibold text-slate-800">
                    <?= $obj['report_date'] ? text(date('d/m/Y', strtotime($obj['report_date']))) : '—' ?>
                </p>
            </div>
            <div>
                <p class="section-title"><?= xlt('Requesting Service') ?></p>
                <p class="text-sm text-slate-700"><?= text($obj['requesting_service'] ?? '—') ?></p>
            </div>
            <div>
                <p class="section-title"><?= xlt('Requesting Physician') ?></p>
                <p class="text-sm text-slate-700"><?= text($obj['requesting_physician'] ?? '—') ?></p>
            </div>
            <div>
                <p class="section-title"><?= xlt('Reporting Physician') ?></p>
                <p class="text-sm font-semibold text-slate-800"><?= text($obj['reporting_physician'] ?? '—') ?></p>
            </div>
        </div>
    </div>

    <!-- Technique / Methodology -->
    <?php if (!empty($obj['technique'])): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <p class="section-title">🔬 <?= xlt('Technique / Methodology') ?></p>
        <div class="field-content"><?= text($obj['technique']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Interpretation / Findings -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <p class="section-title">🩻 <?= xlt('Interpretation / Findings') ?></p>
        <div class="field-content"><?= text($obj['interpretation'] ?? '—') ?></div>
    </div>

    <!-- Conclusion -->
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <p class="section-title">📌 <?= xlt('Conclusion / Diagnostic Impression') ?></p>
        <div class="field-content"><?= text($obj['conclusion'] ?? '—') ?></div>
    </div>

    <!-- Observations -->
    <?php if (!empty($obj['observations'])): ?>
    <div class="bg-white rounded-2xl border border-slate-200 p-6 mb-5 shadow-sm">
        <p class="section-title">💬 <?= xlt('Observations and Suggestions') ?></p>
        <div class="field-content"><?= text($obj['observations']) ?></div>
    </div>
    <?php endif; ?>

    <!-- Botones de acción -->
    <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm flex flex-wrap gap-3 justify-end">

        <?php if (!empty($obj['pdf_document_id'])): ?>
            <span class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl text-sm text-slate-600 bg-slate-50 border border-slate-200">
                <?php if (!empty($obj['pdf_category_id'])): ?>
                    <?php
                    $catTree = imaging_get_category_tree();
                    $catName = imaging_category_name($catTree, (int)$obj['pdf_category_id']);
                    ?>
                    📂 <?= xlt('Folder:') ?> <?= $catName !== '' ? text($catName) : '—' ?>
                <?php else: ?>
                    📂 <?= xlt('Folder:') ?> <?= xlt('automatic') ?>
                <?php endif; ?>
            </span>
            <a href="<?= attr($webroot) ?>/controller.php?document&retrieve&patient_id=<?= attr_url($pid) ?>&document_id=<?= attr_url($obj['pdf_document_id']) ?>"
               target="_blank"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-teal-600 hover:bg-teal-700 text-white transition-colors shadow-sm">
                📄 <?= xlt('View PDF') ?>
            </a>
        <?php endif; ?>

        <?php if ($obj['status'] !== 'finalized'): ?>
            <a href="<?= attr($rootdir) ?>/forms/imaging_report/new.php?id=<?= attr_url($formId) ?>"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-indigo-600 hover:bg-indigo-700 text-white transition-colors shadow-sm">
                ✏️ <?= xlt('Edit Report') ?>
            </a>
        <?php endif; ?>

        <button type="button" onclick="parent.closeTab(window.name, false)"
                class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl text-sm font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 transition-colors">
            ✕ <?= xlt('Close') ?>
        </button>
    </div>

</div>
</body>
</html>
<?php formFooter(); ?>
