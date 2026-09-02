<?php
/**
 * Plantilla HTML A4 para el PDF institucional del Informe de Diagnóstico por Imágenes.
 * Renderizada por Dompdf en save.php.
 *
 * Estilo replicado de public/print_pdf.php (serie azul institucional):
 *  - Header con borde inferior azul, nombre de clínica en mayúsculas y contacto.
 *  - Título de reporte en celeste con borde azul.
 *  - Ficha de paciente en tabla clara (patient-box).
 *  - Secciones con título azul y texto justificado.
 *  - Conclusión resaltada con borde izquierdo azul.
 *  - Firma + validación electrónica institucional.
 *  - Pie de página fijo con disclaimer legal.
 *
 * Variables disponibles (provistas por save.php):
 *  - $pid, $formId, $fields (array con todos los campos del informe)
 *  - $patientRow, $userRow, $encounterRow, $facilityRow, $logoBase64, $logoPath
 *
 * @package   OpenEMR
 * @author    Centro Médico Origen
 */

// Datos del paciente con fallbacks
$patFname  = trim(($patientRow['fname'] ?? '') . ' ' . ($patientRow['mname'] ?? '') . ' ' . ($patientRow['lname'] ?? ''));
$patDni    = $patientRow['ss'] ?? 'N/A';
$patDob    = !empty($patientRow['DOB']) && $patientRow['DOB'] !== '0000-00-00'
    ? date('d/m/Y', strtotime($patientRow['DOB'])) : 'N/A';
$patSex    = match (strtoupper(substr($patientRow['sex'] ?? 'O', 0, 1))) {
    'M' => 'M', 'F' => 'F', default => 'N/E'
};
$patAge    = 'N/A';
if (!empty($patientRow['DOB']) && $patientRow['DOB'] !== '0000-00-00') {
    $dob  = new DateTime($patientRow['DOB']);
    $now  = new DateTime();
    $diff = $now->diff($dob);
    $patAge = $diff->y . xl(' years');
}
$patPhone = $patientRow['phone_cell'] ?? '';

// Datos del médico informante
$medNombre    = trim(($userRow['fname'] ?? '') . ' ' . ($userRow['lname'] ?? '')) ?: ($fields['medico_informante'] ?? '—');
$medEspecialidad = $userRow['specialty'] ?? xl('Medicine / Diagnostic Imaging');
$medNpi       = $userRow['npi'] ?? '';

// Modalidad con nombre completo
$modalidadLabels = [
    'RX'   => xl('X-Ray — Digital Radiography'),
    'TC'   => xl('Computed Tomography (CT)'),
    'RMN'  => xl('Magnetic Resonance Imaging (MRI)'),
    'US'   => xl('Ultrasound (US)'),
    'MG'   => xl('Mammography (MG)'),
    'DEXA' => xl('Bone Densitometry (DEXA)'),
    'OT'   => xl('Other / Not specified'),
];
$modalidadLabel  = $modalidadLabels[$fields['modalidad'] ?? ''] ?? ($fields['modalidad'] ?? '—');
$fechaInforme    = !empty($fields['fecha_informe'])
    ? date('d/m/Y', strtotime($fields['fecha_informe']))
    : date('d/m/Y');
$fechaImpresion  = date('d/m/Y H:i:s');

// Logo institucional en Base64 (PNG o SVG; Dompdf 3.x renderiza SVG)
if (empty($logoBase64)) {
    $logoBase64 = '';
    if (!empty($logoPath) && file_exists($logoPath)) {
        $mime = (strtolower(pathinfo($logoPath, PATHINFO_EXTENSION)) === 'svg') ? 'image/svg+xml' : 'image/png';
        $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoPath));
    }
}

// Datos institucionales desde la facility configurada en OpenEMR
$facilityRow = $facilityRow ?? [];
$clinicName  = trim($facilityRow['name'] ?? '') ?: 'CENTRO DE SALUD';
$clinicAddr  = trim(
    ($facilityRow['street'] ?? '') . ' ' .
    ($facilityRow['city'] ?? '') . ' ' .
    ($facilityRow['state'] ?? '') . ' ' .
    ($facilityRow['postal_code'] ?? '')
);
$clinicEmail = trim($facilityRow['email'] ?? '');
$clinicPhone = trim($facilityRow['phone'] ?? '');

// Identificador de reporte
$reportCode = 'IMG-' . str_pad($formId, 6, '0', STR_PAD_LEFT);
$validacionId = md5($formId . '-' . $pid . '-ORIGEN' . '-IMG');

// Código QR hacia el visor OHIF del estudio (si hay StudyInstanceUID y la
// librería BaconQrCode está disponible en el vendor de OpenEMR). El QR apunta
// directamente a imagenes.origen.ar/viewer?StudyInstanceUIDs=<uid>.
$qrDataUri = '';
$qrTarget  = trim((string)($fields['study_ohif_url'] ?? ''));
if ($qrTarget === '' && !empty($fields['study_instance_uid'])) {
    $ohifBase = defined('OHIF_VIEWER_BASE_URL') ? rtrim(OHIF_VIEWER_BASE_URL, '/') : '';
    if ($ohifBase !== '') {
        $qrTarget = $ohifBase . '?StudyInstanceUIDs=' . urlencode($fields['study_instance_uid']);
    }
}
if ($qrTarget !== '') {
    if (class_exists('BaconQrCode\Writer') && extension_loaded('gd')) {
        try {
            $renderer = new \BaconQrCode\Renderer\GDLibRenderer(480, 4, 'png', 9);
            $writer   = new \BaconQrCode\Writer($renderer);
            $qrPng    = $writer->writeString($qrTarget);
            $qrDataUri = 'data:image/png;base64,' . base64_encode($qrPng);
        } catch (\Throwable $e) {
            error_log("[imaging_report/pdf] QR falló: " . $e->getMessage());
            $qrDataUri = '';
        }
    } else {
        error_log("[imaging_report/pdf] QR no generado. BaconQrCode: " . (class_exists('BaconQrCode\Writer') ? 'ok' : 'NO') . " | GD: " . (extension_loaded('gd') ? 'ok' : 'NO'));
    }
} else {
    error_log("[imaging_report/pdf] QR sin destino (study_instance_uid vacío)");
}

/**
 * Normaliza el texto libre del informe para el PDF:
 *  - Unifica fin de línea (CRLF -> LF).
 *  - Elimina la sangría común (espacios/tabs iniciales) que el textarea del
 *    formulario agrega a cada línea, alineando los ítems al margen.
 *  - Conserva los saltos de línea reales y el sangrado relativo entre líneas.
 */
function img_norm_texto(?string $t): string
{
    if ($t === null || $t === '') {
        return '';
    }
    // Unificar fin de línea
    $t = str_replace(["\r\n", "\r"], "\n", $t);
    // Separar en líneas
    $lineas = explode("\n", $t);
    // Calcular la menor sangría (contando sólo espacios/tabs) entre líneas con contenido
    $minIndent = null;
    foreach ($lineas as $linea) {
        if (trim($linea) === '') {
            continue;
        }
        $len = strlen($linea) - strlen(ltrim($linea, " \t"));
        if ($minIndent === null || $len < $minIndent) {
            $minIndent = $len;
        }
    }
    if ($minIndent === null) {
        $minIndent = 0;
    }
    // Quitar esa sangría común a cada línea con contenido
    $out = [];
    foreach ($lineas as $linea) {
        if (trim($linea) === '') {
            $out[] = '';
        } else {
            $out[] = substr($linea, $minIndent);
        }
    }
    // Eliminar líneas vacías repetidas al fondo (trailing blank lines)
    while (($out[count($out) - 1] ?? '') === '') {
        array_pop($out);
    }
    return implode("\n", $out);
}
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= xlt('Imaging Report') ?> - <?= htmlspecialchars($patFname) ?></title>
    <style>
        @page {
            margin: 22mm 15mm 20mm 15mm;
            size: A4 portrait;
        }
        body {
            font-family: 'DejaVu Sans', 'Helvetica Neue', Helvetica, Arial, sans-serif;
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
        .header-logo-cell {
            width: 240px;
            vertical-align: middle;
        }
        .header-logo-img {
            max-height: 52px;
            max-width: 230px;
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
        .header-right {
            font-size: 10.5px;
            font-weight: bold;
            color: #0284c7;
            text-align: right;
            vertical-align: middle;
        }
        .header-right-small {
            font-size: 8.5px;
            color: #64748b;
            text-align: right;
            vertical-align: middle;
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

        /* Título de sección estilo print_pdf */
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
            text-align: left;
            background-color: #ffffff;
            padding: 6px 8px;
            border: 1px solid #f1f5f9;
            border-radius: 4px;
            margin-bottom: 6px;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .report-text-conclusion {
            font-weight: 500;
            background-color: #f8fafc;
            border-left: 3px solid #0284c7;
            white-space: pre-wrap;
            word-break: break-word;
        }

        /* Tabla de detalles del estudio (modalidad/región/solicitante) */
        .study-meta {
            width: 100%;
            border: 1px solid #cbd5e1;
            border-collapse: collapse;
            margin-bottom: 6px;
            background-color: #f8fafc;
        }
        .study-meta td {
            padding: 4px 8px;
            font-size: 9.5px;
            vertical-align: top;
        }
        .study-meta td.label {
            font-weight: bold;
            color: #475569;
            width: 22%;
        }
        .study-meta td.val {
            color: #0f172a;
            width: 28%;
        }
        .study-uid {
            font-family: 'DejaVu Sans Mono', monospace;
            font-size: 8px;
            color: #475569;
        }

        /* Firmas y validación */
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
        .val-box {
            border: 1px solid #cbd5e1;
            padding: 5px 8px;
            border-radius: 4px;
            display: inline-block;
        }
        .sig-doctor-line {
            font-size: 9px;
            width: 260px;
            text-align: center;
            color: #334155;
            padding-top: 40px;
            border-top: 1px dashed #94a3b8;
        }

        /* Pie de página fijo */
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

    <!-- ================================================================= -->
    <!-- ENCABEZADO INSTITUCIONAL                                           -->
    <!-- ================================================================= -->
    <table class="header-table">
        <tr>
            <td class="header-logo-cell">
                <?php if ($logoBase64): ?>
                    <img src="<?= $logoBase64 ?>" class="header-logo-img" alt="Logo">
                <?php endif; ?>
            </td>
            <td style="vertical-align: middle;">
                <div class="clinic-name"><?= htmlspecialchars($clinicName) ?></div>
                <div class="clinic-sub"><?= xl('Imaging and Diagnostic Services') ?></div>
                <div class="clinic-sub">
                    <?= htmlspecialchars($clinicAddr) ?>
                    <?php if ($clinicPhone): ?> | Tel: <?= htmlspecialchars($clinicPhone) ?><?php endif; ?>
                    <?php if ($clinicEmail): ?> | Email: <?= htmlspecialchars($clinicEmail) ?><?php endif; ?>
                </div>
            </td>
            <td style="width: 34%; text-align: right; vertical-align: middle;">
                <div class="header-right"><?= xl('IMAGING DIAGNOSTIC REPORT') ?></div>
                <div class="header-right-small"><?= xl('Unified Electronic Imaging Protocol') ?></div>
                <div class="header-right-small" style="margin-top: 2px;"><?= xl('Issue Date:') ?> <?= htmlspecialchars($fechaImpresion) ?></div>
            </td>
        </tr>
    </table>

    <!-- ================================================================= -->
    <!-- TÍTULO DEL REPORTE                                                 -->
    <!-- ================================================================= -->
    <div class="report-header-title">
        <?= xl('IMAGING DIAGNOSTIC REPORT') ?> - <?= htmlspecialchars($modalidadLabel) ?>
    </div>

    <!-- ================================================================= -->
    <!-- FICHA DEL PACIENTE                                                 -->
    <!-- ================================================================= -->
    <table class="patient-box">
        <tr>
            <td class="label"><?= xl('Patient:') ?></td>
            <td class="val"><strong><?= htmlspecialchars($patFname ?: ('PID #' . $pid)) ?></strong></td>
            <td class="label"><?= xl('Chart / PID:') ?></td>
            <td class="val">#<?= htmlspecialchars((string)$pid) ?></td>
        </tr>
        <tr>
            <td class="label"><?= xl('ID / Document:') ?></td>
            <td class="val"><?= htmlspecialchars($patDni) ?></td>
            <td class="label"><?= xl('Study Date:') ?></td>
            <td class="val"><?= htmlspecialchars($fechaInforme) ?></td>
        </tr>
        <tr>
            <td class="label"><?= xl('Age / Sex:') ?></td>
            <td class="val"><?= htmlspecialchars($patAge) ?> / <?= htmlspecialchars($patSex) ?></td>
            <td class="label"><?= xl('Modality:') ?></td>
            <td class="val"><?= htmlspecialchars($modalidadLabel) ?></td>
        </tr>
        <tr>
            <td class="label"><?= xl('Reporting Physician:') ?></td>
            <td class="val" colspan="3"><?= htmlspecialchars($medNombre ?: xl('Specialist Physician')) ?></td>
        </tr>
    </table>

    <!-- ================================================================= -->
    <!-- DETALLES DEL ESTUDIO (DICOM)                                       -->
    <!-- ================================================================= -->
    <table class="study-meta">
        <tr>
            <td class="label"><?= xl('Anatomical Region:') ?></td>
            <td class="val"><?= htmlspecialchars($fields['region_anatomica'] ?? '—') ?></td>
            <td class="label"><?= xl('Service:') ?></td>
            <td class="val"><?= htmlspecialchars($fields['servicio_solicitante'] ?? '—') ?></td>
        </tr>
        <tr>
            <td class="label"><?= xl('Requesting Physician:') ?></td>
            <td class="val" colspan="1"><?= htmlspecialchars($fields['medico_solicitante'] ?? '—') ?></td>
            <td class="label"><?= xl('Study UID:') ?></td>
            <td class="val"><span class="study-uid"><?= htmlspecialchars($fields['study_instance_uid'] ?? 'N/D') ?></span></td>
        </tr>
    </table>

    <!-- ================================================================= -->
    <!-- TÉCNICA / METODOLOGÍA                                              -->
    <!-- ================================================================= -->
    <?php if (!empty($fields['metodologia'])): ?>
        <div class="section-title"><?= xl('Technique / Methodology (Sequences)') ?></div>
        <div class="report-text"><?= htmlspecialchars(img_norm_texto($fields['metodologia'])) ?></div>
    <?php endif; ?>

    <!-- ================================================================= -->
    <!-- INTERPRETACIÓN / HALLAZGOS                                         -->
    <!-- ================================================================= -->
    <div class="section-title"><?= xl('Interpretation / Descriptive Findings') ?></div>
    <div class="report-text"><?= htmlspecialchars(img_norm_texto($fields['interpretacion'] ?? '—')) ?></div>

    <!-- ================================================================= -->
    <!-- CONCLUSIÓN / IMPRESIÓN DIAGNÓSTICA                                 -->
    <!-- ================================================================= -->
    <div class="section-title"><?= xl('Conclusion / Diagnostic Impression') ?></div>
    <div class="report-text report-text-conclusion"><?= htmlspecialchars(img_norm_texto($fields['conclusion'] ?? '—')) ?></div>

    <!-- ================================================================= -->
    <!-- OBSERVACIONES                                                      -->
    <!-- ================================================================= -->
    <?php if (!empty($fields['observaciones'])): ?>
        <div class="section-title"><?= xl('Observations and Suggestions') ?></div>
        <div class="report-text"><?= htmlspecialchars(img_norm_texto($fields['observaciones'])) ?></div>
    <?php endif; ?>

    <!-- ================================================================= -->
    <!-- FIRMAS Y VALIDACIÓN ELECTRÓNICA                                    -->
    <!-- ================================================================= -->
    <table class="signature-table">
        <tr>
            <td class="sig-box">
                <strong><?= htmlspecialchars($medNombre ?: xl('Reporting Physician')) ?></strong><br>
                <span><?= htmlspecialchars($medEspecialidad) ?></span><br>
                <?php if ($medNpi): ?><span>M.P. / NPI: <?= htmlspecialchars($medNpi) ?></span><br><?php endif; ?>
                <span style="font-size: 8px; color: #0284c7; font-weight: bold;"><?= xl('Digitally Signed Document') ?></span>
            </td>
            <td style="width: 10%;"></td>
            <td class="qr-placeholder">
                <div class="val-box">
                    <strong><?= xl('INSTITUTIONAL ELECTRONIC VALIDATION') ?></strong><br>
                    <span><?= xl('Report ID:') ?> <?= htmlspecialchars($validacionId) ?></span><br>
                    <span><?= xl('Report No.:') ?> <?= htmlspecialchars($reportCode) ?></span><br>
                    <span><?= xl('Verify authenticity at') ?> <?= (defined('CLINIC_WEB') && CLINIC_WEB) ? htmlspecialchars(CLINIC_WEB) : '' ?></span>
                </div>
            </td>
        </tr>
        <?php if ($qrDataUri): ?>
        <tr>
            <td colspan="3" style="text-align: center; padding-top: 16px;">
                <img src="<?= $qrDataUri ?>" alt="QR Estudio" style="width: 120px; height: 120px; display: inline-block;">
                <div style="font-size: 8px; color: #64748b; margin-top: 3px;">
                    <?= xl('Scan to view the study in the DICOM viewer (OHIF)') ?>
                </div>
            </td>
        </tr>
        <?php endif; ?>
    </table>

    <!-- ================================================================= -->
    <!-- PIE DE PÁGINA                                                      -->
    <!-- ================================================================= -->
    <div class="footer-disclaimer">
        <?= xl('This document is an official medical report issued by') ?> <?= htmlspecialchars($clinicName) ?>. <?= xl('Its validity and confidentiality are protected by current health regulations (Law 25.326 / HIPAA).') ?> <?= xl('Document generated digitally on') ?> <?= htmlspecialchars($fechaImpresion) ?> - <?= xl('Report No.:') ?> <?= htmlspecialchars($reportCode) ?> - PID: <?= htmlspecialchars((string)$pid) ?>.
    </div>

</body>
</html>
