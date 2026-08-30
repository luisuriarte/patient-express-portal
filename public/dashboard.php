<?php
/**
 * Dashboard Principal - Hub de Acceso Rápido para Pacientes
 * Patient Express Portal - OpenEMR Native Integration
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$auth = new \App\Auth();
$auth->requireAuth('index.php');

$patient = $auth->getCurrentPatient();
$pid = $auth->getPatientPid();

$labService = new \App\Laboratory();
$imgService = new \App\Imaging();

// 1. Obtener lotes de laboratorio agrupados por Encuentro
$labBatches = $labService->getReportsGroupedByEncounter($pid);
$totalLabBatches = count($labBatches);
$totalIndividualLabs = 0;
foreach ($labBatches as $b) {
    $totalIndividualLabs += $b['total_studies'];
}

// 2. Obtener estudios de imágenes clasificados (DICOM vs estándar)
$imagingStudies = $imgService->getStudiesByPatient($pid, $patient['dni'] ?? null);
$totalImages = count($imagingStudies);

// Calcular edad del paciente
$patientAge = 'N/A';
if (!empty($patient['dob']) && $patient['dob'] !== '0000-00-00') {
    try {
        $dobDate = new \DateTime($patient['dob']);
        $now = new \DateTime();
        $patientAge = $now->diff($dobDate)->y . ' años';
    } catch (\Throwable $e) {
        $patientAge = 'N/A';
    }
}

$pageTitle = 'Mis Estudios y Resultados | Portal Express Centro Médico Origen';
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6 md:py-8 space-y-6">
    
    <!-- Banner de Bienvenida y Ficha Rápida del Paciente -->
    <div class="relative overflow-hidden rounded-3xl bg-gradient-to-br from-slate-900 via-slate-800 to-sky-950 text-white p-6 sm:p-8 shadow-xl shadow-slate-900/10">
        
        <!-- Elementos decorativos -->
        <div class="absolute top-0 right-0 -mt-12 -mr-12 w-64 h-64 bg-sky-500/20 rounded-full blur-3xl pointer-events-none"></div>
        <div class="absolute bottom-0 right-1/4 -mb-8 w-48 h-48 bg-teal-500/20 rounded-full blur-2xl pointer-events-none"></div>

        <div class="relative z-10 flex flex-col md:flex-row md:items-center justify-between gap-6">
            
            <!-- Datos del Paciente -->
            <div class="space-y-3">
                <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-sky-500/20 border border-sky-400/30 text-sky-300 text-xs font-semibold">
                    <i data-lucide="user-check" class="w-3.5 h-3.5"></i>
                    <span>Paciente Activo en Sistema</span>
                </div>

                <div>
                    <h1 class="text-2xl sm:text-3xl font-extrabold font-heading tracking-tight text-white">
                        Hola, <?= htmlspecialchars($patient['full_name'] ?? 'Estimado Paciente') ?>
                    </h1>
                    <p class="text-xs sm:text-sm text-slate-300 mt-1">
                        Bienvenido a tu centro de consulta médica. Aquí puedes revisar tus estudios y protocolos de diagnóstico al instante.
                    </p>
                </div>

                <!-- Chips Demográficos -->
                <div class="flex flex-wrap items-center gap-2 pt-1">
                    <span class="inline-flex items-center text-xs bg-slate-800/90 border border-slate-700 px-3 py-1 rounded-lg text-slate-200">
                        <strong class="text-slate-400 font-medium mr-1.5">DNI:</strong> <?= htmlspecialchars($patient['dni'] ?: 'No registrado') ?>
                    </span>
                    <span class="inline-flex items-center text-xs bg-slate-800/90 border border-slate-700 px-3 py-1 rounded-lg text-slate-200">
                        <strong class="text-slate-400 font-medium mr-1.5">Historia / PID:</strong> #<?= htmlspecialchars((string)$pid) ?>
                    </span>
                    <span class="inline-flex items-center text-xs bg-slate-800/90 border border-slate-700 px-3 py-1 rounded-lg text-slate-200">
                        <strong class="text-slate-400 font-medium mr-1.5">Edad:</strong> <?= htmlspecialchars($patientAge) ?>
                    </span>
                    <span class="inline-flex items-center text-xs bg-slate-800/90 border border-slate-700 px-3 py-1 rounded-lg text-slate-200">
                        <strong class="text-slate-400 font-medium mr-1.5">Sexo:</strong> <?= htmlspecialchars($patient['sex'] ?: 'No especificado') ?>
                    </span>
                </div>
            </div>

            <!-- Accesos rápidos / Estadísticas en Banner -->
            <div class="grid grid-cols-2 gap-3 min-w-[240px]">
                <div class="bg-white/10 backdrop-blur-md border border-white/10 rounded-2xl p-4 text-center">
                    <span class="block text-2xl sm:text-3xl font-extrabold font-heading text-sky-300"><?= $totalLabBatches ?></span>
                    <span class="text-xs text-slate-300 font-medium">Encuentros de Lab</span>
                </div>
                <div class="bg-white/10 backdrop-blur-md border border-white/10 rounded-2xl p-4 text-center">
                    <span class="block text-2xl sm:text-3xl font-extrabold font-heading text-teal-300"><?= $totalImages ?></span>
                    <span class="text-xs text-slate-300 font-medium">Estudios Imagen</span>
                </div>
            </div>

        </div>
    </div>

    <!-- Navegación por Pestañas / Tabs -->
    <div class="bg-white rounded-2xl p-2 shadow-xs border border-slate-200 flex flex-wrap sm:flex-nowrap gap-1">
        
        <button type="button" 
                onclick="switchTab('tab-laboratories', this)" 
                id="btn-tab-laboratories"
                class="tab-btn flex-1 flex items-center justify-center space-x-2 py-3 px-4 rounded-xl text-xs sm:text-sm font-heading font-bold transition-all duration-150 bg-sky-600 text-white shadow-sm cursor-pointer">
            <i data-lucide="test-tube" class="w-4 h-4"></i>
            <span>Resultados de Laboratorio</span>
            <span class="ml-1.5 py-0.5 px-2 rounded-full text-[11px] bg-white/20 text-white"><?= $totalLabBatches ?></span>
        </button>

        <button type="button" 
                onclick="switchTab('tab-imaging', this)" 
                id="btn-tab-imaging"
                class="tab-btn flex-1 flex items-center justify-center space-x-2 py-3 px-4 rounded-xl text-xs sm:text-sm font-heading font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition-all duration-150 cursor-pointer">
            <i data-lucide="scan" class="w-4 h-4"></i>
            <span>Diagnóstico por Imágenes & DICOM</span>
            <span class="ml-1.5 py-0.5 px-2 rounded-full text-[11px] bg-slate-200 text-slate-700"><?= $totalImages ?></span>
        </button>

        <button type="button" 
                onclick="switchTab('tab-fullportal', this)" 
                id="btn-tab-fullportal"
                class="tab-btn flex-1 flex items-center justify-center space-x-2 py-3 px-4 rounded-xl text-xs sm:text-sm font-heading font-semibold text-slate-600 hover:text-slate-900 hover:bg-slate-100 transition-all duration-150 cursor-pointer">
            <i data-lucide="layout-grid" class="w-4 h-4"></i>
            <span>Portal Completo OpenEMR</span>
            <i data-lucide="external-link" class="w-3.5 h-3.5 ml-1 text-slate-400"></i>
        </button>

    </div>

    <!-- ========================================================================= -->
    <!-- TAB 1: RESULTADOS DE LABORATORIO (AGRUPADO POR ENCUENTRO) -->
    <!-- ========================================================================= -->
    <div id="tab-laboratories" class="tab-pane active space-y-4">
        
        <!-- Barra de Búsqueda y Filtros de Laboratorio -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input type="text" 
                       id="searchLabInput" 
                       placeholder="Buscar por encuentro, fecha, análisis o médico..." 
                       class="w-full pl-10 pr-4 py-2 text-xs md:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-sky-500 transition-colors">
            </div>
            <div class="text-xs text-slate-500 font-medium">
                Mostrando <span id="labCountText" class="font-bold text-slate-800"><?= $totalLabBatches ?></span> encuentros (<?= $totalIndividualLabs ?> estudios en total)
            </div>
        </div>

        <?php if (empty($labBatches)): ?>
            <!-- Estado Vacío -->
            <div class="bg-white rounded-3xl p-12 text-center border border-slate-200 space-y-4 shadow-xs">
                <div class="w-16 h-16 rounded-2xl bg-sky-50 text-sky-600 flex items-center justify-center mx-auto">
                    <i data-lucide="file-question" class="w-8 h-8"></i>
                </div>
                <div class="space-y-1 max-w-md mx-auto">
                    <h3 class="font-heading font-bold text-lg text-slate-900">No se encontraron análisis de laboratorio</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">
                        Aún no tienes informes de análisis clínicos registrados bajo procedimientos válidos. Cuando el laboratorio procese y valide tus muestras, aparecerán agrupados por encuentro en esta sección.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <!-- Listado de Lotes de Laboratorio Agrupados por Encuentro -->
            <div class="grid grid-cols-1 gap-4" id="labReportsList">
                <?php foreach ($labBatches as $batch): ?>
                    <?php 
                        $searchableText = strtolower(
                            $batch['encounter_label'] . ' ' . 
                            $batch['date_formatted'] . ' ' . 
                            $batch['date_display'] . ' ' . 
                            implode(' ', $batch['study_names']) . ' ' . 
                            implode(' ', $batch['providers']) . ' ' . 
                            implode(' ', $batch['specimens'])
                        );
                    ?>
                    <div class="lab-card bg-white rounded-2xl p-5 sm:p-6 border border-slate-200/90 hover:border-sky-300 hover:shadow-md transition-all duration-200 space-y-4"
                         data-search="<?= htmlspecialchars($searchableText) ?>">
                        
                        <!-- Encabezado de la Tarjeta de Lote -->
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-3 border-b border-slate-100">
                            
                            <div class="flex items-center space-x-3.5">
                                <div class="w-12 h-12 rounded-2xl bg-sky-50 text-sky-600 border border-sky-100 flex items-center justify-center flex-shrink-0">
                                    <i data-lucide="calendar-check" class="w-6 h-6"></i>
                                </div>

                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <h3 class="font-heading font-bold text-lg text-slate-900">
                                            <?= htmlspecialchars($batch['encounter_label']) ?> &bull; <?= htmlspecialchars($batch['date_display']) ?>
                                        </h3>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-semibold bg-sky-50 text-sky-700 border border-sky-200">
                                            <?= $batch['total_studies'] ?> <?= $batch['total_studies'] === 1 ? 'análisis' : 'análisis agrupados' ?>
                                        </span>
                                        <?php if ($batch['has_abnormals']): ?>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200" title="Contiene determinaciones fuera del rango de referencia habitual">
                                                <i data-lucide="alert-circle" class="w-3 h-3 mr-1"></i>
                                                <?= $batch['abnormal_count'] ?> valores a interpretar
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <p class="text-xs text-slate-500 mt-0.5">
                                        Fecha Resultados: <strong class="text-slate-700 font-medium"><?= htmlspecialchars($batch['date_formatted']) ?></strong> &bull; Solicitante(s): <?= htmlspecialchars(implode(', ', $batch['providers'])) ?>
                                    </p>
                                </div>
                            </div>

                            <!-- Botones de Acción de Lote Completo -->
                            <?php if (empty($batch['has_documents_only'])): ?>
                                <div class="flex items-center space-x-2 self-end sm:self-center">
                                    <button type="button" 
                                            onclick="openPdfModal('print_pdf.php?type=lab&encounter=<?= urlencode((string)$batch['encounter_key']) ?>', 'Protocolo de Laboratorio - <?= htmlspecialchars(addslashes($batch['encounter_label'])) ?>')"
                                            class="inline-flex items-center space-x-2 bg-sky-600 hover:bg-sky-700 text-white font-heading font-semibold text-xs px-4 py-2.5 rounded-xl shadow-xs transition-all duration-150 cursor-pointer">
                                        <i data-lucide="file-text" class="w-4 h-4"></i>
                                        <span>Ver / Bajar PDF</span>
                                    </button>

                                    <a href="print_pdf.php?type=lab&encounter=<?= urlencode((string)$batch['encounter_key']) ?>" 
                                    target="_blank" 
                                    rel="noopener noreferrer"
                                    title="Abrir PDF en pestaña independiente"
                                    class="p-2.5 text-slate-500 hover:text-sky-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors">
                                        <i data-lucide="external-link" class="w-4 h-4"></i>
                                    </a>
                                </div>
                            <?php endif; ?>

                        </div>

                        <!-- Desglose de Estudios Incluidos en este Encuentro -->
                        <div class="space-y-2">
                            <span class="text-[11px] font-bold text-slate-400 uppercase tracking-wider block">Estudios incluidos en este encuentro:</span>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-2.5">
                                <?php foreach ($batch['reports'] as $report): ?>
                                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200/80 flex items-center justify-between gap-2">
                                        <div class="flex items-center space-x-2 min-w-0">
                                            <i data-lucide="flask-conical" class="w-4 h-4 text-sky-500 flex-shrink-0"></i>
                                            <div class="min-w-0">
                                                <span class="text-xs font-semibold text-slate-800 truncate block" title="<?= htmlspecialchars($report['title']) ?>">
                                                    <?= htmlspecialchars($report['title']) ?>
                                                </span>
                                                <span class="text-[10px] text-slate-400 block">
                                                    <?= htmlspecialchars($report['date_result']) ?>
                                                </span>
                                            </div>
                                        </div>
                                            <?php if (($report['type'] ?? 'procedure') === 'document'): ?>
                                                <a href="<?= htmlspecialchars($report['view_url']) ?>" target="_blank" rel="noopener noreferrer"
                                                class="text-[10px] px-2 py-1 rounded bg-rose-50 border border-rose-200 text-rose-700 flex-shrink-0 font-semibold hover:bg-rose-100">
                                                    Ver Documento
                                                </a>
                                            <?php else: ?>
                                                <span class="text-[10px] px-2 py-0.5 rounded bg-white border border-slate-200 text-slate-600 flex-shrink-0">
                                                    <?= $report['total_results'] ?> determ.
                                                </span>
                                            <?php endif; ?>                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- ========================================================================= -->
    <!-- TAB 2: DIAGNÓSTICO POR IMÁGENES (OHIF PARA DICOM / VISOR DIRECTO PARA JPG/PNG) -->
    <!-- ========================================================================= -->
    <div id="tab-imaging" class="tab-pane space-y-4">
        
        <!-- Filtros y buscador de Imágenes -->
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-3">
            <div class="relative w-full sm:w-80">
                <i data-lucide="search" class="w-4 h-4 absolute left-3.5 top-1/2 -translate-y-1/2 text-slate-400"></i>
                <input type="text" 
                       id="searchImgInput" 
                       placeholder="Buscar estudio, modalidad o archivo..." 
                       class="w-full pl-10 pr-4 py-2 text-xs md:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-teal-500 transition-colors">
            </div>
            <div class="text-xs text-slate-500 font-medium">
                Mostrando <span id="imgCountText" class="font-bold text-slate-800"><?= $totalImages ?></span> estudios de imágenes y documentos
            </div>
        </div>

        <?php if (empty($imagingStudies)): ?>
            <!-- Estado Vacío -->
            <div class="bg-white rounded-3xl p-12 text-center border border-slate-200 space-y-4 shadow-xs">
                <div class="w-16 h-16 rounded-2xl bg-teal-50 text-teal-600 flex items-center justify-center mx-auto">
                    <i data-lucide="scan" class="w-8 h-8"></i>
                </div>
                <div class="space-y-1 max-w-md mx-auto">
                    <h3 class="font-heading font-bold text-lg text-slate-900">No se registran estudios de imágenes</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">
                        Aún no tienes estudios de radiología, tomografía, resonancia, ecografía o imágenes cargadas en el sistema.
                    </p>
                </div>
            </div>
        <?php else: ?>
            <!-- Listado de Estudios de Imágenes (DICOM y Estándar) -->
            <div class="grid grid-cols-1 gap-3.5" id="imagingStudiesList">
                <?php foreach ($imagingStudies as $study): ?>
                    <?php 
                        $isDicom = ($study['format_type'] ?? '') === 'dicom';
                        $isStandardImg = ($study['format_type'] ?? '') === 'image';
                        $isStandardPdf = ($study['format_type'] ?? '') === 'pdf';
                    ?>
                    <div class="img-card bg-white rounded-2xl p-5 border border-slate-200/90 hover:border-teal-300 hover:shadow-md transition-all duration-200 flex flex-col lg:flex-row items-start lg:items-center justify-between gap-4"
                         data-search="<?= strtolower(htmlspecialchars($study['title'] . ' ' . $study['modality'] . ' ' . $study['provider_name'] . ' ' . $study['date_study'] . ' ' . ($study['accession_number'] ?? ''))) ?>">
                        
                        <!-- Icono y Datos del Estudio -->
                        <div class="flex items-start space-x-4">
                            <div class="w-11 h-11 rounded-2xl <?= $isDicom ? 'bg-teal-50 text-teal-600 border-teal-100' : ($isStandardImg ? 'bg-indigo-50 text-indigo-600 border-indigo-100' : 'bg-rose-50 text-rose-600 border-rose-100') ?> border flex items-center justify-center flex-shrink-0 mt-0.5">
                                <?php if ($isDicom): ?>
                                    <i data-lucide="film" class="w-5 h-5"></i>
                                <?php elseif ($isStandardImg): ?>
                                    <i data-lucide="image" class="w-5 h-5"></i>
                                <?php else: ?>
                                    <i data-lucide="file-text" class="w-5 h-5"></i>
                                <?php endif; ?>
                            </div>

                            <div class="space-y-1.5">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h4 class="font-heading font-bold text-base text-slate-900 leading-snug">
                                        <?= htmlspecialchars($study['title']) ?>
                                    </h4>
                                    
                                    <!-- Modalidad Badge -->
                                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[11px] font-bold bg-teal-50 text-teal-800 border border-teal-200">
                                        <i data-lucide="tag" class="w-3 h-3 mr-1 text-teal-600"></i>
                                        <?= htmlspecialchars($study['modality']) ?>
                                    </span>

                                    <!-- Badge de Tipo de Archivo -->
                                    <?php if ($isDicom): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            DICOM PACS
                                        </span>
                                    <?php elseif ($isStandardImg): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                            IMAGEN (JPG/PNG)
                                        </span>
                                    <?php elseif ($isStandardPdf): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            DOCUMENTO PDF
                                        </span>
                                    <?php endif; ?>
                                </div>

                                <div class="flex flex-wrap items-center gap-y-1 gap-x-4 text-xs text-slate-500">
                                    <span class="flex items-center gap-1.5">
                                        <i data-lucide="calendar" class="w-3.5 h-3.5 text-slate-400"></i>
                                        Fecha: <strong class="text-slate-700 font-medium"><?= htmlspecialchars($study['date_study']) ?></strong>
                                    </span>
                                    <span class="flex items-center gap-1.5">
                                        <i data-lucide="user" class="w-3.5 h-3.5 text-slate-400"></i>
                                        Médico: <span class="text-slate-700 font-medium"><?= htmlspecialchars($study['provider_name']) ?></span>
                                    </span>
                                    <?php if (!empty($study['accession_number'])): ?>
                                        <span class="flex items-center gap-1.5">
                                            <i data-lucide="barcode" class="w-3.5 h-3.5 text-slate-400"></i>
                                            Ref: <span class="text-slate-600"><?= htmlspecialchars($study['accession_number']) ?></span>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Botones de Acción según formato -->
                        <div class="flex flex-wrap items-center gap-2 w-full lg:w-auto justify-end pt-2 lg:pt-0 border-t lg:border-t-0 border-slate-100">
                            
                            <?php if ($isDicom): ?>
                                <!-- Caso a) Estudio DICOM en Orthanc: Visor OHIF -->
                                <?php if ($study['has_report'] && !empty($study['report_id'])): ?>
                                    <button type="button" 
                                            onclick="openPdfModal('print_pdf.php?type=image&id=<?= $study['report_id'] ?>', 'Informe Radiológico - <?= htmlspecialchars(addslashes($study['title'])) ?>')"
                                            class="inline-flex items-center space-x-1.5 bg-slate-100 hover:bg-slate-200 text-slate-800 font-heading font-semibold text-xs px-3.5 py-2.5 rounded-xl transition-colors cursor-pointer border border-slate-200">
                                        <i data-lucide="file-text" class="w-4 h-4 text-slate-600"></i>
                                        <span>Informe PDF</span>
                                    </button>
                                <?php endif; ?>

                                <a href="<?= htmlspecialchars($study['viewer_url']) ?>" 
                                   target="_blank" 
                                   rel="noopener noreferrer"
                                   class="inline-flex items-center space-x-2 bg-teal-600 hover:bg-teal-700 text-white font-heading font-semibold text-xs px-4 py-2.5 rounded-xl shadow-xs transition-all duration-150">
                                    <i data-lucide="monitor" class="w-4 h-4"></i>
                                    <span>Ver Imagen DICOM (OHIF)</span>
                                    <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 opacity-80"></i>
                                </a>

                                <?php if (!empty($study['stone_url'])): ?>
                                    <a href="<?= htmlspecialchars($study['stone_url']) ?>" 
                                       target="_blank" 
                                       rel="noopener noreferrer"
                                       title="Abrir en Visor Orthanc Stone WebViewer"
                                       class="inline-flex items-center space-x-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 border border-slate-200 font-heading font-semibold text-xs px-3 py-2.5 rounded-xl transition-colors">
                                        <i data-lucide="eye" class="w-3.5 h-3.5 text-teal-600"></i>
                                        <span>Stone Viewer</span>
                                    </a>
                                <?php endif; ?>

                            <?php elseif ($isStandardImg): ?>
                                <!-- Caso b1) Imagen Estándar (JPG/PNG): Visor directo en portal -->
                                <button type="button" 
                                        onclick="openImageModal('<?= htmlspecialchars($study['viewer_url']) ?>', '<?= htmlspecialchars(addslashes($study['title'])) ?>', '<?= htmlspecialchars($study['download_url'] ?? $study['viewer_url']) ?>')"
                                        class="inline-flex items-center space-x-2 bg-indigo-600 hover:bg-indigo-700 text-white font-heading font-semibold text-xs px-4 py-2.5 rounded-xl shadow-xs transition-all duration-150 cursor-pointer">
                                    <i data-lucide="eye" class="w-4 h-4"></i>
                                    <span>Ver Imagen</span>
                                </button>

                                <?php if (!empty($study['has_ohif']) && !empty($study['ohif_url'])): ?>
                                    <a href="<?= htmlspecialchars($study['ohif_url']) ?>" 
                                        target="_blank" 
                                        rel="noopener noreferrer"
                                        title="Visualizar en Visor Radiológico Avanzado OHIF"
                                        class="inline-flex items-center space-x-1.5 bg-teal-50 hover:bg-teal-100 text-teal-800 border border-teal-200 font-heading font-semibold text-xs px-3.5 py-2.5 rounded-xl transition-colors">
                                        <i data-lucide="monitor" class="w-4 h-4 text-teal-600"></i>
                                        <span>Visor OHIF</span>
                                        <i data-lucide="arrow-up-right" class="w-3.5 h-3.5 text-teal-500"></i>
                                    </a>
                                <?php endif; ?>

                                <a href="<?= htmlspecialchars($study['download_url'] ?? $study['viewer_url']) ?>" 
                                   download
                                   class="p-2.5 text-slate-600 hover:text-indigo-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors"
                                   title="Descargar archivo">
                                    <i data-lucide="download" class="w-4 h-4"></i>
                                </a>

                            <?php elseif ($isStandardPdf): ?>
                                <!-- Caso b2) Documento PDF: Visor PDF directo en portal sin OHIF -->
                                <button type="button" 
                                        onclick="openPdfModal('<?= htmlspecialchars($study['viewer_url']) ?>', '<?= htmlspecialchars(addslashes($study['title'])) ?>')"
                                        class="inline-flex items-center space-x-2 bg-rose-600 hover:bg-rose-700 text-white font-heading font-semibold text-xs px-4 py-2.5 rounded-xl shadow-xs transition-all duration-150 cursor-pointer">
                                    <i data-lucide="file-text" class="w-4 h-4"></i>
                                    <span>Ver Documento PDF</span>
                                </button>

                                <a href="<?= htmlspecialchars($study['download_url'] ?? $study['viewer_url']) ?>" 
                                   download
                                   class="p-2.5 text-slate-600 hover:text-rose-600 bg-slate-100 hover:bg-slate-200 rounded-xl transition-colors"
                                   title="Descargar PDF">
                                    <i data-lucide="download" class="w-4 h-4"></i>
                                </a>
                            <?php endif; ?>

                        </div>

                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

    </div>

    <!-- ========================================================================= -->
    <!-- TAB 3: ACCESO AL PORTAL COMPLETO OPENEMR -->
    <!-- ========================================================================= -->
    <div id="tab-fullportal" class="tab-pane">
        
        <div class="bg-white rounded-3xl border border-slate-200 p-6 sm:p-10 shadow-xs space-y-8">
            
            <div class="max-w-3xl space-y-3">
                <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-indigo-50 border border-indigo-200 text-indigo-700 text-xs font-bold">
                    <i data-lucide="sparkles" class="w-3.5 h-3.5"></i>
                    <span>Historia Clínica Digital Integral</span>
                </div>
                <h2 class="text-2xl sm:text-3xl font-extrabold font-heading text-slate-900 tracking-tight">
                    Accede a todas las funcionalidades médicas en el Portal OpenEMR
                </h2>
                <p class="text-sm text-slate-600 leading-relaxed">
                    El Portal Express te permite acceder de forma ultrarrápida a tus informes y visores. Si necesitas interactuar con tu equipo de salud, solicitar turnos o descargar documentación clínica complementaria, utiliza el portal centralizado.
                </p>
            </div>

            <!-- Grid de Características del Portal Completo -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                
                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-2">
                    <div class="w-10 h-10 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center">
                        <i data-lucide="calendar" class="w-5 h-5"></i>
                    </div>
                    <h4 class="font-heading font-bold text-sm text-slate-900">Gestión de Turnos</h4>
                    <p class="text-xs text-slate-500 leading-normal">
                        Solicita, reprograma y consulta tus próximas citas con especialistas y estudios programados.
                    </p>
                </div>

                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-2">
                    <div class="w-10 h-10 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center">
                        <i data-lucide="message-square" class="w-5 h-5"></i>
                    </div>
                    <h4 class="font-heading font-bold text-sm text-slate-900">Mensajería Segura</h4>
                    <p class="text-xs text-slate-500 leading-normal">
                        Comunícate de manera privada con tus médicos y recibe indicaciones post-consulta.
                    </p>
                </div>

                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200/80 space-y-2">
                    <div class="w-10 h-10 rounded-xl bg-indigo-100 text-indigo-700 flex items-center justify-center">
                        <i data-lucide="pill" class="w-5 h-5"></i>
                    </div>
                    <h4 class="font-heading font-bold text-sm text-slate-900">Recetas & Medicación</h4>
                    <p class="text-xs text-slate-500 leading-normal">
                        Revisa tu historial de prescripciones médicas, dosis e indicaciones de tratamientos continuos.
                    </p>
                </div>

            </div>

            <!-- CTA Card hacia el Portal Completo -->
            <div class="p-6 rounded-2xl bg-gradient-to-r from-slate-900 via-indigo-950 to-slate-900 text-white flex flex-col sm:flex-row items-center justify-between gap-6 shadow-lg">
                <div class="space-y-1 text-center sm:text-left">
                    <h3 class="font-heading font-bold text-lg text-white">¿Listo para ingresar al Portal Integral?</h3>
                    <p class="text-xs text-slate-300">Se abrirá la plataforma OpenEMR en una nueva pestaña segura.</p>
                </div>
                <a href="goto_portal.php" 
                   target="_blank" 
                   rel="noopener noreferrer"
                   class="inline-flex items-center space-x-2 bg-indigo-600 hover:bg-indigo-500 text-white font-heading font-bold text-xs sm:text-sm px-6 py-3.5 rounded-xl shadow-md transition-all duration-150 whitespace-nowrap cursor-pointer">
                    <i data-lucide="sparkles" class="w-4 h-4"></i>
                    <span>Ingresar Directo a OpenEMR Portal</span>
                    <i data-lucide="arrow-up-right" class="w-4 h-4"></i>
                </a>
            </div>

        </div>

    </div>

</div>

<!-- JavaScript para Switch de Tabs y Búsqueda en Vivo -->
<script>
    function switchTab(tabId, btnElement) {
        document.querySelectorAll('.tab-pane').forEach(pane => {
            pane.classList.remove('active');
        });

        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.classList.remove('bg-sky-600', 'text-white', 'shadow-sm');
            btn.classList.add('text-slate-600', 'hover:text-slate-900', 'hover:bg-slate-100');
        });

        const targetPane = document.getElementById(tabId);
        if (targetPane) {
            targetPane.classList.add('active');
        }

        if (btnElement) {
            btnElement.classList.remove('text-slate-600', 'hover:text-slate-900', 'hover:bg-slate-100');
            btnElement.classList.add('bg-sky-600', 'text-white', 'shadow-sm');
        }

        if (typeof lucide !== 'undefined') lucide.createIcons();
    }

    // Buscador en vivo de Laboratorios
    const labInput = document.getElementById('searchLabInput');
    if (labInput) {
        labInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.lab-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const searchData = card.getAttribute('data-search') || '';
                if (searchData.includes(query)) {
                    card.style.display = 'block';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            const countEl = document.getElementById('labCountText');
            if (countEl) countEl.innerText = visibleCount;
        });
    }

    // Buscador en vivo de Imágenes
    const imgInput = document.getElementById('searchImgInput');
    if (imgInput) {
        imgInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const cards = document.querySelectorAll('.img-card');
            let visibleCount = 0;

            cards.forEach(card => {
                const searchData = card.getAttribute('data-search') || '';
                if (searchData.includes(query)) {
                    card.style.display = 'flex';
                    visibleCount++;
                } else {
                    card.style.display = 'none';
                }
            });

            const countEl = document.getElementById('imgCountText');
            if (countEl) countEl.innerText = visibleCount;
        });
    }
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
