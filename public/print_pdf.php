<?php
/**
 * Generador y Servidor de Informes Médicos en PDF con Dompdf
 * Patient Express Portal - Agrupación Continua por Encuentro / Fecha de Análisis
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$auth = new \App\Auth();
$auth->requireAuth('index.php');

$pid = $auth->getPatientPid();
$type = $_GET['type'] ?? 'lab';
$encounter = isset($_GET['encounter']) ? trim((string)$_GET['encounter']) : '';
$reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$reportDate = isset($_GET['date']) ? trim((string)$_GET['date']) : '';

$data = null;
$reportTitle = 'INFORME MÉDICO OFICIAL';

if ($type === 'lab') {
    $labService = new \App\Laboratory();
    
    if (!empty($encounter)) {
        $data = $labService->getGroupedReportDetailsByEncounter($encounter, $pid);
    } elseif (!empty($reportDate)) {
        $data = $labService->getGroupedReportDetailsByDate($reportDate, $pid);
    } elseif ($reportId > 0) {
        $data = $labService->getReportDetails($reportId, $pid);
    }

    $reportTitle = 'INFORME INTEGRAL DE LABORATORIO CLÍNICO';

} elseif ($type === 'image') {
    $imgService = new \App\Imaging();
    if ($reportId > 0) {
        $data = $imgService->getStudyReportDetails($reportId, $pid);
    }
    $reportTitle = 'INFORME DE DIAGNÓSTICO POR IMÁGENES';
} else {
    http_response_code(400);
    die('Error: Tipo de reporte no reconocido.');
}

if (!$data) {
    http_response_code(404);
    die('Error: Informe no encontrado o no tiene permisos para acceder al documento solicitado.');
}

// Cargar Logo en base64 para Dompdf (soporta SVG y PNG)
$logoBase64 = '';
$logoPathFile = defined('CLINIC_LOGO_PATH') ? CLINIC_LOGO_PATH : (dirname(__DIR__) . '/assets/img/logo-banner.svg');
if (file_exists($logoPathFile)) {
    $ext = strtolower(pathinfo($logoPathFile, PATHINFO_EXTENSION));
    $mime = ($ext === 'svg') ? 'image/svg+xml' : 'image/' . $ext;
    $imgData = file_get_contents($logoPathFile);
    $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($imgData);
}

// Estructurar el HTML para la plantilla A4
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($reportTitle . ' - ' . ($data['patient']['full_name'] ?? '')) ?></title>
    <style>
        @page {
            margin: 22mm 15mm 20mm 15mm;
            size: A4 portrait;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 10.5px;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 10px;
            margin-bottom: 12px;
        }
        .clinic-name {
            font-size: 15px;
            font-weight: bold;
            color: #0f172a;
            margin: 0;
            text-transform: uppercase;
        }
        .clinic-sub {
            font-size: 8.5px;
            color: #64748b;
            margin: 2px 0 0 0;
        }
        .report-header-title {
            text-align: center;
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            color: #0369a1;
            font-size: 11.5px;
            font-weight: bold;
            letter-spacing: 0.5px;
            padding: 5px;
            margin-bottom: 12px;
            text-transform: uppercase;
            border-radius: 4px;
        }
        .patient-box {
            width: 100%;
            border: 1px solid #cbd5e1;
            background-color: #f8fafc;
            border-radius: 4px;
            margin-bottom: 14px;
            border-collapse: collapse;
        }
        .patient-box td {
            padding: 4px 8px;
            font-size: 9.5px;
            vertical-align: top;
        }
        .patient-box td.label {
            font-weight: bold;
            color: #475569;
            width: 18%;
        }
        .patient-box td.val {
            color: #0f172a;
            width: 32%;
        }
        
        /* Paneles y Tablas de Laboratorio */
        .panel-container {
            margin-bottom: 16px;
            page-break-inside: avoid;
        }
        .panel-header {
            background-color: #f1f5f9;
            border-left: 3px solid #0284c7;
            border-top: 1px solid #cbd5e1;
            border-right: 1px solid #cbd5e1;
            border-bottom: 1px solid #cbd5e1;
            padding: 5px 8px;
            font-size: 10px;
            font-weight: bold;
            color: #0f172a;
            text-transform: uppercase;
        }
        .panel-meta {
            font-size: 8.5px;
            color: #64748b;
            font-weight: normal;
            float: right;
            text-transform: none;
        }
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 0;
            margin-bottom: 8px;
        }
        .results-table th {
            background-color: #1e293b;
            color: #ffffff;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 5px 8px;
            text-align: left;
            border: 1px solid #1e293b;
        }
        .results-table td {
            padding: 4.5px 8px;
            font-size: 9.5px;
            border-bottom: 1px solid #e2e8f0;
            border-left: 1px solid #f1f5f9;
            border-right: 1px solid #f1f5f9;
        }
        .results-table tr:nth-child(even) {
            background-color: #f8fafc;
        }
        .abnormal-val {
            font-weight: bold;
            color: #b91c1c;
            background-color: #fef2f2;
            padding: 1px 3px;
            border-radius: 2px;
        }
        .normal-val {
            font-weight: 500;
            color: #0f172a;
        }
        .flag-badge {
            font-size: 8px;
            font-weight: bold;
            color: #b91c1c;
            margin-left: 2px;
        }

        /* Secciones de Informe */
        .section-title {
            font-size: 10.5px;
            font-weight: bold;
            color: #0369a1;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 2px;
            margin-top: 10px;
            margin-bottom: 4px;
            text-transform: uppercase;
        }
        .report-text {
            font-size: 9.5px;
            color: #334155;
            line-height: 1.5;
            text-align: justify;
            background-color: #ffffff;
            padding: 6px 8px;
            border: 1px solid #f1f5f9;
            border-radius: 4px;
            margin-bottom: 6px;
        }

        /* Firmas y Pie */
        .signature-table {
            width: 100%;
            margin-top: 20px;
            page-break-inside: avoid;
        }
        .sig-box {
            width: 45%;
            text-align: center;
            font-size: 9px;
            color: #334155;
            padding-top: 35px;
            border-top: 1px dashed #94a3b8;
        }
        .qr-placeholder {
            width: 45%;
            font-size: 8px;
            color: #64748b;
            text-align: left;
            vertical-align: bottom;
        }
        .footer-disclaimer {
            position: fixed;
            bottom: -12mm;
            left: 0;
            right: 0;
            font-size: 7.5px;
            color: #94a3b8;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            padding-top: 3px;
        }
    </style>
</head>
<body>

    <!-- Encabezado Institucional -->
    <table class="header-table">
        <tr>
            <td style="width: 60%; vertical-align: middle;">
                <div class="clinic-name"><?= defined('CLINIC_NAME') ? CLINIC_NAME : '' ?></div>
                <div class="clinic-sub"><?= defined('CLINIC_ADDRESS') ? CLINIC_ADDRESS : '' ?><?= defined('CLINIC_PHONE') && CLINIC_PHONE ? ' | Tel: ' . CLINIC_PHONE : '' ?></div>
                <div class="clinic-sub"><?= defined('CLINIC_EMAIL') && CLINIC_EMAIL ? 'Email: ' . CLINIC_EMAIL : '' ?><?= defined('CLINIC_WEB') && CLINIC_WEB ? ' | Web: ' . CLINIC_WEB : '' ?></div>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: middle;">
                <div style="font-size: 10.5px; font-weight: bold; color: #0284c7;">SERVICIO DE BIOQUÍMICA & DIAGNÓSTICO</div>
                <div style="font-size: 8.5px; color: #64748b;">Protocolo Electrónico Unificado</div>
                <div style="font-size: 8px; color: #94a3b8; margin-top: 2px;">Fecha Emisión: <?= date('d/m/Y H:i:s') ?></div>
            </td>
        </tr>
    </table>

    <!-- Título del Reporte -->
    <div class="report-header-title">
        <?= htmlspecialchars($reportTitle) ?>
        <?php if ($type === 'lab'): ?>
            - <?= htmlspecialchars($data['encounter_label'] ?? 'ENCUENTRO') ?> (FECHA: <?= htmlspecialchars($data['batch_date_formatted'] ?? '') ?>)
        <?php elseif ($type === 'image' && !empty($data['study_name'])): ?>
            - <?= htmlspecialchars($data['study_name']) ?>
        <?php endif; ?>
    </div>

    <!-- Ficha del Paciente -->
    <table class="patient-box">
        <tr>
            <td class="label">Paciente:</td>
            <td class="val"><strong><?= htmlspecialchars($data['patient']['full_name']) ?></strong></td>
            <td class="label">Historia / PID:</td>
            <td class="val">#<?= htmlspecialchars((string)$data['patient']['pid']) ?></td>
        </tr>
        <tr>
            <td class="label">DNI / Documento:</td>
            <td class="val"><?= htmlspecialchars($data['patient']['dni']) ?></td>
            <td class="label"><?= $type === 'lab' ? 'Fecha de Resultados:' : 'Fecha de Estudio:' ?></td>
            <td class="val"><?= htmlspecialchars($type === 'lab' ? ($data['latest_result_date'] ?? $data['batch_date_formatted'] ?? 'N/A') : ($data['date_report'] ?? 'N/A')) ?></td>
        </tr>
        <tr>
            <td class="label">Edad / Sexo:</td>
            <td class="val"><?= htmlspecialchars($data['patient']['age']) ?> / <?= htmlspecialchars($data['patient']['sex']) ?></td>
            <td class="label"><?= $type === 'lab' ? 'Encuentro / Total Paneles:' : 'Modalidad:' ?></td>
            <td class="val"><?= htmlspecialchars($type === 'lab' ? (($data['encounter_label'] ?? '') . ' (' . (string)($data['total_panels'] ?? '1') . ' estudios)') : ($data['modality'] ?? 'IMG')) ?></td>
        </tr>
        <tr>
            <td class="label">Médico(s) Solicitante(s):</td>
            <td class="val" colspan="3">
                <?= htmlspecialchars($type === 'lab' ? ($data['providers_summary'] ?: 'Médicos del Servicio') : ($data['provider']['full_name'] ?? 'Médico Especialista')) ?>
            </td>
        </tr>
    </table>

    <?php if ($type === 'lab'): ?>
        <!-- ========================================================================= -->
        <!-- CUERPO DE LABORATORIO AGRUPADO POR ENCUENTRO (LISTADO CONTINUO) -->
        <!-- ========================================================================= -->
        <?php if (!empty($data['panels'])): ?>
            <?php foreach ($data['panels'] as $index => $panel): ?>
                <div class="panel-container">
                    
                    <!-- Encabezado del Panel de Análisis -->
                    <div class="panel-header">
                        <span><?= ($index + 1) ?>. <?= htmlspecialchars($panel['panel_name']) ?></span>
                        <span class="panel-meta">
                            Muestra: <strong><?= htmlspecialchars($panel['specimen_num']) ?></strong> | 
                            Solicitó: <strong><?= htmlspecialchars($panel['provider']['full_name']) ?></strong>
                        </span>
                    </div>

                    <!-- Tabla de Resultados de este Panel -->
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th style="width: 38%;">Determinación / Analito</th>
                                <th style="width: 20%;">Resultado</th>
                                <th style="width: 14%;">Unidades</th>
                                <th style="width: 28%;">Valores de Referencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($panel['results'])): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #64748b; padding: 8px;">
                                        Determinaciones en proceso o sin desglose numérico.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($panel['results'] as $res): ?>
                                    <tr>
                                        <td>
                                            <strong><?= htmlspecialchars($res['name']) ?></strong>
                                            <?php if (!empty($res['comments'])): ?>
                                                <div style="font-size: 8px; color: #64748b; margin-top: 1px;">
                                                    <?= htmlspecialchars($res['comments']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($res['is_abnormal']): ?>
                                                <span class="abnormal-val"><?= htmlspecialchars($res['value']) ?></span>
                                                <span class="flag-badge"><?= htmlspecialchars($res['abnormal_flag']) ?></span>
                                            <?php else: ?>
                                                <span class="normal-val"><?= htmlspecialchars($res['value']) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($res['units'] ?: '-') ?></td>
                                        <td><?= htmlspecialchars($res['reference_range']) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>

                    <?php if (!empty($panel['notes'])): ?>
                        <div style="font-size: 8.5px; color: #475569; background-color: #f8fafc; padding: 4px 8px; border: 1px solid #e2e8f0; border-radius: 3px; margin-bottom: 6px;">
                            <strong>Observaciones:</strong> <?= nl2br(htmlspecialchars($panel['notes'])) ?>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 30px; color: #64748b;">
                No se encontraron análisis registrados para este encuentro.
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ========================================================================= -->
        <!-- CUERPO DE INFORME DE IMÁGENES (RADIOLOGÍA/TAC/RMN) -->
        <!-- ========================================================================= -->
        <div style="margin-bottom: 10px;">
            <table style="width: 100%; font-size: 9.5px; border-collapse: collapse;">
                <tr>
                    <td style="width: 50%; padding: 3px 0;"><strong>Modalidad:</strong> <?= htmlspecialchars($data['modality']) ?></td>
                    <td style="width: 50%; padding: 3px 0;"><strong>UID Estudio:</strong> <span style="font-family: monospace; font-size: 8px;"><?= htmlspecialchars($data['study_uid']) ?></span></td>
                </tr>
            </table>
        </div>

        <div class="section-title">Técnica y Hallazgos Descriptivos</div>
        <div class="report-text">
            <?= nl2br(htmlspecialchars($data['findings'])) ?>
        </div>

        <div class="section-title">Conclusión / Impresión Diagnóstica</div>
        <div class="report-text" style="font-weight: 500; background-color: #f8fafc; border-left: 3px solid #0284c7;">
            <?= nl2br(htmlspecialchars($data['conclusion'])) ?>
        </div>
    <?php endif; ?>

    <!-- Firmas Digitales y Validación -->
    <table class="signature-table">
        <tr>
            <td class="qr-placeholder">
                <div style="border: 1px solid #cbd5e1; padding: 5px 8px; border-radius: 4px; display: inline-block;">
                    <strong>VALIDACIÓN ELECTRÓNICA INSTITUCIONAL</strong><br>
                    <span>ID de Lote: <?= md5(($data['encounter_id'] ?? $reportId) . '-' . $pid . '-ORIGEN') ?></span><br>
                    <span>Verifique autenticidad en <?= defined('CLINIC_WEB') ? CLINIC_WEB : '' ?></span>
                </div>
            </td>
            <td style="width: 10%;"></td>
            <td class="sig-box">
                <strong>Servicio de Bioquímica y Diagnóstico Clínico</strong><br>
                <span><?= defined('CLINIC_NAME') ? CLINIC_NAME : '' ?></span><br>
                <span>Validación Bioquímica Registrada</span><br>
                <span style="font-size: 8px; color: #0284c7; font-weight: bold;">Documento Firmado Digitalmente</span>
            </td>
        </tr>
    </table>

    <!-- Pie de página fijo -->
    <div class="footer-disclaimer">
        Este documento es un protocolo médico oficial emitido por <?= defined('CLINIC_NAME') ? CLINIC_NAME : '' ?>. Su validez y confidencialidad están protegidas por las normativas de salud vigentes (Ley 25.326 / HIPAA).
    </div>

</body>
</html>
<?php
$html = ob_get_clean();

// Generar PDF usando Dompdf si está disponible
if (class_exists(\Dompdf\Dompdf::class)) {
    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', true);
    $options->set('defaultFont', 'Helvetica');

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $encounterTag = !empty($data['encounter_id']) ? ('enc_' . $data['encounter_id']) : date('Ymd');
    $fileName = sprintf('protocolo_%s_%d_%s.pdf', $type, $pid, $encounterTag);
    
    // Servir inline para visualización directa en navegador o iframe
    $dompdf->stream($fileName, [
        'Attachment' => 0
    ]);
    exit;
} else {
    // Renderizado HTML directo para impresión si dompdf no está en el entorno
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}
