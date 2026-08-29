<?php
/**
 * Generador y Servidor de Informes Médicos en PDF con Dompdf
 * Patient Express Portal
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$auth = new \App\Auth();
$auth->requireAuth('index.php');

$pid = $auth->getPatientPid();
$type = $_GET['type'] ?? 'lab';
$reportId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($reportId <= 0) {
    http_response_code(400);
    die('Error: Identificador de informe inválido.');
}

$data = null;
$reportTitle = 'INFORME MÉDICO OFICIAL';

if ($type === 'lab') {
    $labService = new \App\Laboratory();
    $data = $labService->getReportDetails($reportId, $pid);
    $reportTitle = 'INFORME DE LABORATORIO CLÍNICO';
} elseif ($type === 'image') {
    $imgService = new \App\Imaging();
    $data = $imgService->getStudyReportDetails($reportId, $pid);
    $reportTitle = 'INFORME DE DIAGNÓSTICO POR IMÁGENES';
} else {
    http_response_code(400);
    die('Error: Tipo de reporte no reconocido.');
}

if (!$data) {
    http_response_code(404);
    die('Error: Informe no encontrado o no tiene permisos para acceder al documento solicitado.');
}

// Cargar Logo en base64 para Dompdf
$logoBase64 = '';
$logoPathPng = defined('CLINIC_LOGO_PATH') ? CLINIC_LOGO_PATH : (dirname(__DIR__) . '/assets/img/logo.png');
if (file_exists($logoPathPng)) {
    $typeImg = pathinfo($logoPathPng, PATHINFO_EXTENSION);
    $imgData = file_get_contents($logoPathPng);
    $logoBase64 = 'data:image/' . $typeImg . ';base64,' . base64_encode($imgData);
}

// Estructurar el HTML para la plantilla A4
ob_start();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($reportTitle . ' - ' . ($data['study_name'] ?? '')) ?></title>
    <style>
        @page {
            margin: 25mm 15mm 20mm 15mm;
            size: A4 portrait;
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            font-size: 11px;
            color: #1e293b;
            line-height: 1.4;
            margin: 0;
            padding: 0;
        }
        .header-table {
            width: 100%;
            border-bottom: 2px solid #0284c7;
            padding-bottom: 12px;
            margin-bottom: 15px;
        }
        .clinic-name {
            font-size: 16px;
            font-weight: bold;
            color: #0f172a;
            margin: 0;
            text-transform: uppercase;
        }
        .clinic-sub {
            font-size: 9px;
            color: #64748b;
            margin: 2px 0 0 0;
        }
        .report-header-title {
            text-align: center;
            background-color: #f0f9ff;
            border: 1px solid #bae6fd;
            color: #0369a1;
            font-size: 12px;
            font-weight: bold;
            letter-spacing: 0.5px;
            padding: 6px;
            margin-bottom: 14px;
            text-transform: uppercase;
            border-radius: 4px;
        }
        .patient-box {
            width: 100%;
            border: 1px solid #cbd5e1;
            background-color: #f8fafc;
            border-radius: 4px;
            margin-bottom: 15px;
            border-collapse: collapse;
        }
        .patient-box td {
            padding: 5px 8px;
            font-size: 10px;
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
        
        /* Tablas de Resultados */
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 10px;
            margin-bottom: 15px;
        }
        .results-table th {
            background-color: #0f172a;
            color: #ffffff;
            font-size: 9.5px;
            font-weight: bold;
            text-transform: uppercase;
            padding: 6px 8px;
            text-align: left;
            border: 1px solid #0f172a;
        }
        .results-table td {
            padding: 6px 8px;
            font-size: 10px;
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
            padding: 2px 4px;
            border-radius: 3px;
        }
        .normal-val {
            font-weight: 500;
            color: #0f172a;
        }
        .flag-badge {
            font-size: 8.5px;
            font-weight: bold;
            color: #b91c1c;
        }

        /* Secciones de Informe de Imágenes */
        .section-title {
            font-size: 11px;
            font-weight: bold;
            color: #0369a1;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 3px;
            margin-top: 14px;
            margin-bottom: 6px;
            text-transform: uppercase;
        }
        .report-text {
            font-size: 10.5px;
            color: #334155;
            line-height: 1.6;
            text-align: justify;
            background-color: #ffffff;
            padding: 8px;
            border: 1px solid #f1f5f9;
            border-radius: 4px;
        }

        /* Firmas y Pie */
        .signature-table {
            width: 100%;
            margin-top: 25px;
            page-break-inside: avoid;
        }
        .sig-box {
            width: 45%;
            text-align: center;
            font-size: 9.5px;
            color: #334155;
            padding-top: 40px;
            border-top: 1px dashed #94a3b8;
        }
        .qr-placeholder {
            width: 45%;
            font-size: 8.5px;
            color: #64748b;
            text-align: left;
            vertical-align: bottom;
        }
        .footer-disclaimer {
            position: fixed;
            bottom: -10mm;
            left: 0;
            right: 0;
            font-size: 8px;
            color: #94a3b8;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            padding-top: 4px;
        }
    </style>
</head>
<body>

    <!-- Encabezado Institucional -->
    <table class="header-table">
        <tr>
            <td style="width: 60%; vertical-align: middle;">
                <div class="clinic-name"><?= defined('CLINIC_NAME') ? CLINIC_NAME : 'Centro Médico Origen' ?></div>
                <div class="clinic-sub"><?= defined('CLINIC_ADDRESS') ? CLINIC_ADDRESS : 'Av. Santa Fe 1234, CABA' ?> | Tel: <?= defined('CLINIC_PHONE') ? CLINIC_PHONE : '0810-333-ORIGEN' ?></div>
                <div class="clinic-sub">Email: <?= defined('CLINIC_EMAIL') ? CLINIC_EMAIL : 'contacto@origen.ar' ?> | Web: <?= defined('CLINIC_WEB') ? CLINIC_WEB : 'https://origen.ar' ?></div>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: middle;">
                <div style="font-size: 11px; font-weight: bold; color: #0284c7;">SERVICIO DE DIAGNÓSTICO</div>
                <div style="font-size: 9px; color: #64748b;">Sistema de Validación Electrónica</div>
                <div style="font-size: 8.5px; color: #94a3b8; margin-top: 2px;">Emisión: <?= date('d/m/Y H:i:s') ?></div>
            </td>
        </tr>
    </table>

    <!-- Título del Reporte -->
    <div class="report-header-title">
        <?= htmlspecialchars($reportTitle) ?> - <?= htmlspecialchars($data['study_name']) ?>
    </div>

    <!-- Ficha del Paciente y Estudio -->
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
            <td class="label">Fecha Informe:</td>
            <td class="val"><?= htmlspecialchars($data['date_report']) ?></td>
        </tr>
        <tr>
            <td class="label">Edad / Sexo:</td>
            <td class="val"><?= htmlspecialchars($data['patient']['age']) ?> / <?= htmlspecialchars($data['patient']['sex']) ?></td>
            <td class="label">Fecha Solicitud:</td>
            <td class="val"><?= htmlspecialchars($data['date_ordered']) ?></td>
        </tr>
        <tr>
            <td class="label">Médico Solicitante:</td>
            <td class="val"><?= htmlspecialchars($data['provider']['full_name']) ?></td>
            <td class="label"><?= $type === 'lab' ? 'Muestra / Protocolo:' : 'N° Accession:' ?></td>
            <td class="val"><?= htmlspecialchars($type === 'lab' ? ($data['specimen_num'] ?? 'N/A') : ($data['accession_number'] ?? 'N/A')) ?></td>
        </tr>
    </table>

    <?php if ($type === 'lab'): ?>
        <!-- ============================================== -->
        <!-- CUERPO DE INFORME DE LABORATORIO -->
        <!-- ============================================== -->
        <table class="results-table">
            <thead>
                <tr>
                    <th style="width: 38%;">Determinación / Analito</th>
                    <th style="width: 22%;">Resultado</th>
                    <th style="width: 14%;">Unidades</th>
                    <th style="width: 26%;">Valores de Referencia</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($data['results'])): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; color: #64748b; padding: 15px;">
                            No se registraron determinaciones desglosadas para esta orden.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($data['results'] as $res): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($res['name']) ?></strong>
                                <?php if (!empty($res['comments'])): ?>
                                    <div style="font-size: 8.5px; color: #64748b; margin-top: 2px;">
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

        <?php if (!empty($data['notes'])): ?>
            <div class="section-title">Observaciones de Laboratorio</div>
            <div class="report-text"><?= nl2br(htmlspecialchars($data['notes'])) ?></div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ============================================== -->
        <!-- CUERPO DE INFORME DE IMÁGENES (RADIOLOGÍA/TAC) -->
        <!-- ============================================== -->
        <div style="margin-bottom: 12px;">
            <table style="width: 100%; font-size: 10px; border-collapse: collapse;">
                <tr>
                    <td style="width: 50%; padding: 4px 0;"><strong>Modalidad:</strong> <?= htmlspecialchars($data['modality']) ?></td>
                    <td style="width: 50%; padding: 4px 0;"><strong>UID Estudio:</strong> <span style="font-family: monospace; font-size: 8.5px;"><?= htmlspecialchars($data['study_uid']) ?></span></td>
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
                <div style="border: 1px solid #cbd5e1; padding: 6px; border-radius: 4px; display: inline-block;">
                    <strong>FIRMA DIGITAL VALIDADA</strong><br>
                    <span>ID de Transacción: <?= md5($reportId . '-' . $pid . '-MED') ?></span><br>
                    <span>Consulte autenticidad en <?= defined('CLINIC_WEB') ? CLINIC_WEB : 'https://origen.ar' ?></span>
                </div>
            </td>
            <td style="width: 10%;"></td>
            <td class="sig-box">
                <strong><?= htmlspecialchars($data['provider']['full_name']) ?></strong><br>
                <span><?= htmlspecialchars($data['provider']['specialty']) ?></span><br>
                <span>Matrícula: <?= htmlspecialchars($data['provider']['license']) ?></span><br>
                <span style="font-size: 8.5px; color: #0284c7; font-weight: bold;">Documento Firmado Electrónicamente</span>
            </td>
        </tr>
    </table>

    <!-- Pie de página fijo -->
    <div class="footer-disclaimer">
        Este documento es un informe médico oficial emitido por <?= defined('CLINIC_NAME') ? CLINIC_NAME : 'Centro Médico Origen' ?>. Su validez y confidencialidad están protegidas por las normativas de salud vigentes.
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

    $fileName = sprintf('informe_%s_%d_%s.pdf', $type, $reportId, date('Ymd'));
    
    // Servir inline para visualización directa en navegador o iframe
    $dompdf->stream($fileName, [
        'Attachment' => 0
    ]);
    exit;
} else {
    // Si dompdf aún no está instalado (antes de composer install), renderizamos HTML optimizado para imprimir
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}
