<?php
/**
 * Plantilla HTML A4 para el PDF institucional del Informe de Diagnóstico por Imágenes.
 * Renderizada por Dompdf en save.php.
 *
 * Variables disponibles:
 *  - $pid, $formId, $fields (array con todos los campos del informe)
 *  - $patientRow, $userRow, $encounterRow
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
    'M' => 'Masculino', 'F' => 'Femenino', default => 'No especificado'
};
$patPhone  = $patientRow['phone_cell'] ?? '';

// Datos del médico informante
$medNombre    = trim(($userRow['fname'] ?? '') . ' ' . ($userRow['lname'] ?? '')) ?: ($fields['medico_informante'] ?? '—');
$medEspecialidad = $userRow['specialty'] ?? 'Radiología / Diagnóstico por Imágenes';
$medNpi       = $userRow['npi'] ?? '';

// Modalidad con nombre completo
$modalidadLabels = [
    'RX'   => 'Rayos X — Radiografía Digital',
    'TC'   => 'Tomografía Computada (TC)',
    'RMN'  => 'Resonancia Magnética (RMN)',
    'US'   => 'Ecografía / Ultrasonido (US)',
    'MG'   => 'Mamografía',
    'DEXA' => 'Densitometría Ósea (DEXA)',
    'OT'   => 'Otro / No especificado',
];
$modalidadLabel  = $modalidadLabels[$fields['modalidad'] ?? ''] ?? ($fields['modalidad'] ?? '—');
$fechaInforme    = !empty($fields['fecha_informe'])
    ? date('d/m/Y', strtotime($fields['fecha_informe']))
    : date('d/m/Y');
$fechaImpresion  = date('d/m/Y H:i');

// Logo institucional en Base64 para embeber en el PDF
// Prioriza el logo ya resuelto por save.php ($logoBase64/$logoPath) para
// evitar depender de una ruta fija según la profundidad de despliegue.
if (empty($logoBase64)) {
    $logoBase64 = '';
    if (!empty($logoPath) && file_exists($logoPath)) {
        $logoBase64 = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
    }
}

// Datos institucionales: usamos la facility configurada en OpenEMR si está
// disponible; si no, se recae en valores por defecto institucionales.
$facilityRow = $facilityRow ?? [];
$clinicName  = trim($facilityRow['name'] ?? '') ?: 'CENTRO MÉDICO ORIGEN';
$clinicAddr  = trim(
    ($facilityRow['street'] ?? '') . ' ' .
    ($facilityRow['city'] ?? '') . ' ' .
    ($facilityRow['state'] ?? '') . ' ' .
    ($facilityRow['postal_code'] ?? '')
);
$clinicEmail = trim($facilityRow['email'] ?? '');
$clinicPhone = trim($facilityRow['phone'] ?? '');
$clinicMeta  = implode(' &bull; ', array_filter([
    $clinicAddr,
    $clinicEmail,
    $clinicPhone,
])) ?: 'Datos de contacto de la institución';
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 18mm 15mm 20mm 15mm; size: A4 portrait; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DejaVu Sans', Arial, Helvetica, sans-serif; font-size: 10pt; color: #1e293b; background: #fff; line-height: 1.5; }
    
    /* ---- Membrete / Header ---- */
    .header-table { width: 100%; border-bottom: 3px solid #0f172a; margin-bottom: 14px; padding-bottom: 12px; }
    .clinic-name { font-size: 16pt; font-weight: 700; color: #0f172a; letter-spacing: -0.5px; }
    .clinic-sub  { font-size: 9pt; color: #475569; margin-top: 2px; }
    .clinic-meta { font-size: 8pt; color: #64748b; margin-top: 4px; }
    .report-title { text-align: center; background: #0f172a; color: #fff; padding: 8px 0; font-size: 12pt; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; border-radius: 4px; margin-bottom: 14px; }
    
    /* ---- Tabla de datos del paciente ---- */
    .patient-table { width: 100%; border: 1px solid #cbd5e1; border-radius: 4px; margin-bottom: 14px; }
    .patient-table th { background: #f1f5f9; padding: 5px 8px; font-size: 8.5pt; font-weight: 700; color: #334155; text-align: left; border-bottom: 1px solid #cbd5e1; }
    .patient-table td { padding: 5px 8px; font-size: 9.5pt; color: #1e293b; border-bottom: 1px solid #f1f5f9; }
    .patient-table tr:last-child td { border-bottom: none; }
    
    /* ---- Datos del estudio ---- */
    .study-table { width: 100%; border: 1px solid #bae6fd; background: #f0f9ff; border-radius: 4px; margin-bottom: 14px; }
    .study-table td { padding: 5px 10px; font-size: 9.5pt; }
    .study-table .label { font-weight: 700; color: #0369a1; width: 160px; }
    
    /* ---- Secciones del informe ---- */
    .section { margin-bottom: 12px; }
    .section-title { background: #0f172a; color: #fff; padding: 5px 10px; font-size: 9pt; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; border-radius: 3px; margin-bottom: 6px; }
    .section-body { border: 1px solid #e2e8f0; border-radius: 4px; padding: 10px 12px; font-size: 10pt; line-height: 1.7; color: #1e293b; white-space: pre-wrap; word-break: break-word; }
    .section-conclusion .section-title { background: linear-gradient(135deg, #0369a1, #0284c7); }
    .section-conclusion .section-body { border-color: #7dd3fc; background: #f0f9ff; font-weight: 600; }
    
    /* ---- Área de firma ---- */
    .firma-section { margin-top: 20px; border-top: 1px solid #e2e8f0; padding-top: 14px; }
    .firma-box { width: 260px; text-align: center; display: inline-block; }
    .firma-line { border-bottom: 1.5px solid #1e293b; width: 220px; margin: 0 auto 4px auto; padding-top: 45px; }
    .firma-name { font-size: 10pt; font-weight: 700; color: #0f172a; }
    .firma-spec { font-size: 8.5pt; color: #64748b; }
    .firma-npi  { font-size: 8pt; color: #94a3b8; }
    
    /* ---- Footer ---- */
    .page-footer { margin-top: 14px; border-top: 1px solid #e2e8f0; padding-top: 6px; font-size: 7.5pt; color: #94a3b8; text-align: center; }
    
    /* ---- ID del informe ---- */
    .report-id { text-align: right; font-size: 8pt; color: #94a3b8; margin-bottom: 10px; }
</style>
</head>
<body>

<!-- ================================================================= -->
<!-- MEMBRETE INSTITUCIONAL                                             -->
<!-- ================================================================= -->
<table class="header-table">
    <tr>
        <td style="width:80px;">
            <?php if ($logoBase64): ?>
                <img src="<?= $logoBase64 ?>" style="max-height:60px;max-width:75px;" alt="Logo">
            <?php endif; ?>
        </td>
        <td>
            <div class="clinic-name"><?= htmlspecialchars($clinicName) ?></div>
            <div class="clinic-sub">Servicio de Diagnóstico por Imágenes</div>
            <div class="clinic-meta"><?= htmlspecialchars($clinicMeta) ?></div>
        </td>
        <td style="text-align:right;vertical-align:top;">
            <div style="font-size:8.5pt;color:#64748b;">Informe N.°</div>
            <div style="font-size:13pt;font-weight:700;color:#0f172a;"><?= htmlspecialchars('IMG-' . str_pad($formId, 6, '0', STR_PAD_LEFT)) ?></div>
            <div style="font-size:8pt;color:#94a3b8;">Fecha: <?= htmlspecialchars($fechaInforme) ?></div>
        </td>
    </tr>
</table>

<div class="report-title">Informe de Diagnóstico por Imágenes — <?= htmlspecialchars($modalidadLabel) ?></div>

<!-- ================================================================= -->
<!-- DATOS DEL PACIENTE                                                 -->
<!-- ================================================================= -->
<table class="patient-table">
    <tr>
        <th colspan="4">Datos del Paciente</th>
    </tr>
    <tr>
        <td><strong>Paciente:</strong></td>
        <td><?= htmlspecialchars($patFname ?: ('PID #' . $pid)) ?></td>
        <td><strong>DNI / Doc.:</strong></td>
        <td><?= htmlspecialchars($patDni) ?></td>
    </tr>
    <tr>
        <td><strong>Fecha Nac.:</strong></td>
        <td><?= htmlspecialchars($patDob) ?></td>
        <td><strong>Sexo:</strong></td>
        <td><?= htmlspecialchars($patSex) ?></td>
    </tr>
    <tr>
        <td><strong>Teléfono:</strong></td>
        <td><?= htmlspecialchars($patPhone ?: '—') ?></td>
        <td><strong>ID Paciente:</strong></td>
        <td><?= htmlspecialchars((string)$pid) ?></td>
    </tr>
</table>

<!-- ================================================================= -->
<!-- DATOS DEL ESTUDIO                                                  -->
<!-- ================================================================= -->
<table class="study-table">
    <tr>
        <td class="label">Modalidad:</td>
        <td><?= htmlspecialchars($modalidadLabel) ?></td>
        <td class="label">Región Anatómica:</td>
        <td><?= htmlspecialchars($fields['region_anatomica'] ?? '—') ?></td>
    </tr>
    <tr>
        <td class="label">Solicitante:</td>
        <td><?= htmlspecialchars($fields['medico_solicitante'] ?? '—') ?></td>
        <td class="label">Servicio:</td>
        <td><?= htmlspecialchars($fields['servicio_solicitante'] ?? '—') ?></td>
    </tr>
</table>

<!-- ================================================================= -->
<!-- TÉCNICA / METODOLOGÍA                                              -->
<!-- ================================================================= -->
<?php if (!empty($fields['metodologia'])): ?>
<div class="section">
    <div class="section-title">🔬 Técnica / Metodología</div>
    <div class="section-body"><?= htmlspecialchars($fields['metodologia']) ?></div>
</div>
<?php endif; ?>

<!-- ================================================================= -->
<!-- INTERPRETACIÓN / HALLAZGOS                                         -->
<!-- ================================================================= -->
<div class="section">
    <div class="section-title">🩻 Interpretación / Hallazgos</div>
    <div class="section-body"><?= htmlspecialchars($fields['interpretacion'] ?? '—') ?></div>
</div>

<!-- ================================================================= -->
<!-- CONCLUSIÓN / IMPRESIÓN DIAGNÓSTICA                                 -->
<!-- ================================================================= -->
<div class="section section-conclusion">
    <div class="section-title">📌 Conclusión / Impresión Diagnóstica</div>
    <div class="section-body"><?= htmlspecialchars($fields['conclusion'] ?? '—') ?></div>
</div>

<!-- ================================================================= -->
<!-- OBSERVACIONES                                                      -->
<!-- ================================================================= -->
<?php if (!empty($fields['observaciones'])): ?>
<div class="section">
    <div class="section-title">💬 Observaciones y Sugerencias</div>
    <div class="section-body"><?= htmlspecialchars($fields['observaciones']) ?></div>
</div>
<?php endif; ?>

<!-- ================================================================= -->
<!-- FIRMA                                                              -->
<!-- ================================================================= -->
<div class="firma-section">
    <table style="width:100%;">
        <tr>
            <td style="width:50%;"></td>
            <td style="width:50%;text-align:center;">
                <div class="firma-box">
                    <div class="firma-line"></div>
                    <div class="firma-name"><?= htmlspecialchars($medNombre) ?></div>
                    <div class="firma-spec"><?= htmlspecialchars($medEspecialidad) ?></div>
                    <?php if ($medNpi): ?>
                        <div class="firma-npi">M.P. / NPI: <?= htmlspecialchars($medNpi) ?></div>
                    <?php endif; ?>
                    <div class="firma-npi">Médico Informante</div>
                </div>
            </td>
        </tr>
    </table>
</div>

<!-- ================================================================= -->
<!-- PIE DE PÁGINA                                                      -->
<!-- ================================================================= -->
<div class="page-footer">
    Documento generado digitalmente por el Sistema de Información del Centro Médico Origen el <?= htmlspecialchars($fechaImpresion) ?>.
    Informe Nro: IMG-<?= htmlspecialchars(str_pad($formId, 6, '0', STR_PAD_LEFT)) ?> &bull; PID: <?= htmlspecialchars((string)$pid) ?> &bull; Confidencial — Solo para uso médico.
</div>

</body>
</html>
