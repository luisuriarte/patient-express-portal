<?php
/**
 * Medical Report PDF Generator and Server with Dompdf
 * Patient Express Portal - Continuous Grouping by Encounter / Analysis Date
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
$reportTitle = xl('OFFICIAL MEDICAL REPORT');

if ($type === 'lab') {
    $labService = new \App\Laboratory();
    
    if (!empty($encounter)) {
        $data = $labService->getGroupedReportDetailsByEncounter($encounter, $pid);
    } elseif (!empty($reportDate)) {
        $data = $labService->getGroupedReportDetailsByDate($reportDate, $pid);
    } elseif ($reportId > 0) {
        $data = $labService->getReportDetails($reportId, $pid);
    }

    $reportTitle = xl('COMPREHENSIVE CLINICAL LABORATORY REPORT');

} elseif ($type === 'image') {
    $imgService = new \App\Imaging();
    if ($reportId > 0) {
        $data = $imgService->getStudyReportDetails($reportId, $pid);
    }
    $reportTitle = xl('DIAGNOSTIC IMAGING REPORT');
} else {
    http_response_code(400);
    die(xl('Error: Unrecognized report type.'));
}

if (!$data) {
    http_response_code(404);
    die(xl('Error: Report not found or you do not have permission to access the requested document.'));
}

// Load Logo in base64 for Dompdf (supports SVG and PNG)
$logoBase64 = '';
$logoPathFile = defined('CLINIC_LOGO_PATH') ? CLINIC_LOGO_PATH : (dirname(__DIR__) . '/assets/img/logo-banner.svg');
if (file_exists($logoPathFile)) {
    $ext = strtolower(pathinfo($logoPathFile, PATHINFO_EXTENSION));
    $mime = ($ext === 'svg') ? 'image/svg+xml' : 'image/' . $ext;
    $imgData = file_get_contents($logoPathFile);
    $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode($imgData);
}

// Structure the HTML for the A4 template
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
        
        /* Laboratory Panels and Tables */
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

        /* Report Sections */
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

        /* Signatures and Footer */
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

    <!-- Institutional Header -->
    <table class="header-table">
        <tr>
            <td style="width: 60%; vertical-align: middle;">
                <div class="clinic-name"><?= defined('CLINIC_NAME') ? CLINIC_NAME : '' ?></div>
                <div class="clinic-sub"><?= defined('CLINIC_ADDRESS') ? CLINIC_ADDRESS : '' ?><?= defined('CLINIC_PHONE') && CLINIC_PHONE ? ' | ' . xlt('Tel') . ': ' . CLINIC_PHONE : '' ?></div>
                <div class="clinic-sub"><?= defined('CLINIC_EMAIL') && CLINIC_EMAIL ? xlt('Email') . ': ' . CLINIC_EMAIL : '' ?><?= defined('CLINIC_WEB') && CLINIC_WEB ? ' | ' . xlt('Web') . ': ' . CLINIC_WEB : '' ?></div>
            </td>
            <td style="width: 40%; text-align: right; vertical-align: middle;">
                <div style="font-size: 10.5px; font-weight: bold; color: #0284c7;"><?= xlt('BIOCHEMISTRY & DIAGNOSTIC SERVICE') ?></div>
                <div style="font-size: 8.5px; color: #64748b;"><?= xlt('Unified Electronic Protocol') ?></div>
                <div style="font-size: 8px; color: #94a3b8; margin-top: 2px;"><?= xlt('Issue Date') ?>: <?= date('d/m/Y H:i:s') ?></div>
            </td>
        </tr>
    </table>

    <!-- Report Title -->
    <div class="report-header-title">
        <?= htmlspecialchars($reportTitle) ?>
        <?php if ($type === 'lab'): ?>
            - <?= htmlspecialchars($data['encounter_label'] ?? xlt('ENCOUNTER')) ?> (<?= xlt('DATE') ?>: <?= htmlspecialchars($data['batch_date_formatted'] ?? '') ?>)
        <?php elseif ($type === 'image' && !empty($data['study_name'])): ?>
            - <?= htmlspecialchars($data['study_name']) ?>
        <?php endif; ?>
    </div>

    <!-- Patient Card -->
    <table class="patient-box">
        <tr>
            <td class="label"><?= xlt('Patient') ?>:</td>
            <td class="val"><strong><?= htmlspecialchars($data['patient']['full_name']) ?></strong></td>
            <td class="label"><?= xlt('Medical Record / PID') ?>:</td>
            <td class="val">#<?= htmlspecialchars((string)$data['patient']['pid']) ?></td>
        </tr>
        <tr>
            <td class="label"><?= xlt('ID / Document') ?>:</td>
            <td class="val"><?= htmlspecialchars($data['patient']['dni']) ?></td>
            <td class="label"><?= $type === 'lab' ? xlt('Results Date') . ':' : xlt('Study Date') . ':' ?></td>
            <td class="val"><?= htmlspecialchars($type === 'lab' ? ($data['latest_result_date'] ?? $data['batch_date_formatted'] ?? 'N/A') : ($data['date_report'] ?? 'N/A')) ?></td>
        </tr>
        <tr>
            <td class="label"><?= xlt('Age / Sex') ?>:</td>
            <td class="val"><?= htmlspecialchars($data['patient']['age']) ?> / <?= htmlspecialchars($data['patient']['sex']) ?></td>
            <td class="label"><?= $type === 'lab' ? xlt('Encounter / Total Panels') . ':' : xlt('Modality') . ':' ?></td>
            <td class="val"><?= htmlspecialchars($type === 'lab' ? (($data['encounter_label'] ?? '') . ' (' . (string)($data['total_panels'] ?? '1') . ' ' . xlt('studies') . ')') : ($data['modality'] ?? 'IMG')) ?></td>
        </tr>
        <tr>
            <td class="label"><?= xlt('Requesting Physician(s)') ?>:</td>
            <td class="val" colspan="3">
                <?= htmlspecialchars($type === 'lab' ? ($data['providers_summary'] ?: xlt('Service Physicians')) : ($data['provider']['full_name'] ?? xlt('Specialist Physician'))) ?>
            </td>
        </tr>
    </table>

    <?php if ($type === 'lab'): ?>
        <!-- ========================================================================= -->
        <!-- LABORATORY BODY GROUPED BY ENCOUNTER (CONTINUOUS LISTING) -->
        <!-- ========================================================================= -->
        <?php if (!empty($data['panels'])): ?>
            <?php foreach ($data['panels'] as $index => $panel): ?>
                <div class="panel-container">
                    
                    <!-- Analysis Panel Header -->
                    <div class="panel-header">
                        <span><?= ($index + 1) ?>. <?= htmlspecialchars($panel['panel_name']) ?></span>
                        <span class="panel-meta">
                            <?= xlt('Specimen') ?>: <strong><?= htmlspecialchars($panel['specimen_num']) ?></strong> | 
                            <?= xlt('Requested by') ?>: <strong><?= htmlspecialchars($panel['provider']['full_name']) ?></strong>
                        </span>
                    </div>

                    <!-- Results Table for This Panel -->
                    <table class="results-table">
                        <thead>
                            <tr>
                                <th style="width: 38%;"><?= xlt('Determination / Analyte') ?></th>
                                <th style="width: 20%;"><?= xlt('Result') ?></th>
                                <th style="width: 14%;"><?= xlt('Units') ?></th>
                                <th style="width: 28%;"><?= xlt('Reference Values') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($panel['results'])): ?>
                                <tr>
                                    <td colspan="4" style="text-align: center; color: #64748b; padding: 8px;">
                                         <?= xlt('Determinations in progress or without numeric breakdown.') ?>
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
                            <strong><?= xlt('Observations') ?>:</strong> <?= nl2br(htmlspecialchars($panel['notes'])) ?>
                        </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 30px; color: #64748b;">
                <?= xlt('No analyses found registered for this encounter.') ?>
            </div>
        <?php endif; ?>

    <?php else: ?>
        <!-- ========================================================================= -->
        <!-- IMAGING REPORT BODY (RADIOLOGY/CT/MRI) -->
        <!-- ========================================================================= -->
        <div style="margin-bottom: 10px;">
            <table style="width: 100%; font-size: 9.5px; border-collapse: collapse;">
                <tr>
                    <td style="width: 50%; padding: 3px 0;"><strong><?= xlt('Modality') ?>:</strong> <?= htmlspecialchars($data['modality']) ?></td>
                    <td style="width: 50%; padding: 3px 0;"><strong><?= xlt('Study UID') ?>:</strong> <span style="font-family: monospace; font-size: 8px;"><?= htmlspecialchars($data['study_uid']) ?></span></td>
                </tr>
            </table>
        </div>

        <div class="section-title"><?= xlt('Technique and Descriptive Findings') ?></div>
        <div class="report-text">
            <?= nl2br(htmlspecialchars($data['findings'])) ?>
        </div>

        <div class="section-title"><?= xlt('Conclusion / Diagnostic Impression') ?></div>
        <div class="report-text" style="font-weight: 500; background-color: #f8fafc; border-left: 3px solid #0284c7;">
            <?= nl2br(htmlspecialchars($data['conclusion'])) ?>
        </div>
    <?php endif; ?>

    <!-- Digital Signatures and Validation -->
    <table class="signature-table">
        <tr>
            <td class="qr-placeholder">
                <div style="border: 1px solid #cbd5e1; padding: 5px 8px; border-radius: 4px; display: inline-block;">
                    <strong><?= xlt('INSTITUTIONAL ELECTRONIC VALIDATION') ?></strong><br>
                    <span><?= xlt('Batch ID') ?>: <?= md5(($data['encounter_id'] ?? $reportId) . '-' . $pid . '-ORIGEN') ?></span><br>
                    <span><?= xlt('Verify authenticity at') ?> <?= defined('CLINIC_WEB') ? CLINIC_WEB : '' ?></span>
                </div>
            </td>
            <td style="width: 10%;"></td>
            <td class="sig-box">
                <strong><?= xlt('Biochemistry and Clinical Diagnostics Service') ?></strong><br>
                <span><?= defined('CLINIC_NAME') ? CLINIC_NAME : '' ?></span><br>
                <span><?= xlt('Biochemical Validation Recorded') ?></span><br>
                <span style="font-size: 8px; color: #0284c7; font-weight: bold;"><?= xlt('Digitally Signed Document') ?></span>
            </td>
        </tr>
    </table>

    <!-- Fixed Footer -->
    <div class="footer-disclaimer">
        <?= xlt('This document is an official medical protocol issued by') ?> <?= defined('CLINIC_NAME') ? CLINIC_NAME : '' ?>. <?= xlt('Its validity and confidentiality are protected by current health regulations (Law 25.326 / HIPAA).') ?>
    </div>

</body>
</html>
<?php
$html = ob_get_clean();

// Generate PDF using Dompdf if available
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
    
    // Serve inline for direct viewing in browser or iframe
    $dompdf->stream($fileName, [
        'Attachment' => 0
    ]);
    exit;
} else {
    // Direct HTML rendering for printing if dompdf is not in the environment
    header('Content-Type: text/html; charset=UTF-8');
    echo $html;
    exit;
}
