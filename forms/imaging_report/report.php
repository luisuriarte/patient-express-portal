<?php
/**
 * Imaging Report - report.php
 *
 * Renders a compact report summary for the encounter
 * history views in OpenEMR (Patient History).
 *
 * @package   OpenEMR
 * @author    Centro Médico Origen
 * @license   GNU General Public License 3
 */

use OpenEMR\Core\OEGlobalsBag;

require_once(__DIR__ . '/../../globals.php');
require_once(OEGlobalsBag::getInstance()->getSrcDir() . "/api.inc.php");

/**
 * Standard OpenEMR report function.
 * Called from the patient encounter history.
 */
function imaging_report_report(int $pid, int $encounter, int $cols, int $id): void
{
    $data = sqlQuery(
        "SELECT * FROM form_imaging_report WHERE id = ? AND pid = ? LIMIT 1",
        [$id, $pid]
    );

    if (empty($data)) {
        echo '<p class="text-muted small">' . xlt('No report data.') . '</p>';
        return;
    }

    $modalidadLabels = [
        'RX'   => xl('X-Ray'),
        'TC'   => xl('Computed Tomography'),
        'RMN'  => xl('Magnetic Resonance Imaging'),
        'US'   => xl('Ultrasound'),
        'MG'   => xl('Mammography'),
        'DEXA' => xl('Densitometry'),
        'OT'   => xl('Other'),
    ];

    $modalidad = $modalidadLabels[$data['modality'] ?? ''] ?? ($data['modality'] ?? '—');
    $estado    = $data['status'] === 'finalized'
        ? '<span style="color:#065f46;font-weight:600;background:#d1fae5;padding:2px 8px;border-radius:8px;font-size:11px;">' . xlt('Completed') . '</span>'
        : '<span style="color:#92400e;font-weight:600;background:#fef3c7;padding:2px 8px;border-radius:8px;font-size:11px;">' . xlt('Draft') . '</span>';

    echo '<div style="font-family:sans-serif;font-size:13px;line-height:1.6;padding:8px 0;">';
    echo '<table style="width:100%;border-collapse:collapse;">';

    // Summary header
    echo '<tr style="background:#f1f5f9;">';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;width:180px;">' . xlt('Modality') . '</td>';
    echo '<td style="padding:6px 10px;color:#1e293b;">' . text($modalidad) . '</td>';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;width:180px;">' . xlt('Status') . '</td>';
    echo '<td style="padding:6px 10px;">' . $estado . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;">' . xlt('Anatomical Region') . '</td>';
    echo '<td style="padding:6px 10px;color:#1e293b;">' . text($data['anatomical_region'] ?? '—') . '</td>';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;">' . xlt('Study Date') . '</td>';
    echo '<td style="padding:6px 10px;color:#1e293b;">';
    echo $data['study_date'] ? text(date('d/m/Y', strtotime($data['study_date']))) : '—';
    echo '</td>';
    echo '</tr>';

    echo '<tr style="background:#f1f5f9;">';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;">' . xlt('Reporting Physician') . '</td>';
    echo '<td colspan="3" style="padding:6px 10px;color:#1e293b;">' . text($data['reporting_physician'] ?? '—') . '</td>';
    echo '</tr>';

    // Conclusion / Diagnostic Impression (highlighted)
    if (!empty($data['conclusion'])) {
        echo '<tr>';
        echo '<td style="padding:8px 10px;font-weight:700;color:#0f172a;vertical-align:top;">📌 ' . xlt('Conclusion') . '</td>';
        echo '<td colspan="3" style="padding:8px 10px;color:#1e293b;white-space:pre-wrap;background:#fffbeb;border-left:3px solid #f59e0b;">';
        echo text($data['conclusion']);
        echo '</td>';
        echo '</tr>';
    }

    // Brief findings note (first 300 characters)
    if (!empty($data['interpretation'])) {
        $preview = mb_strlen($data['interpretation']) > 300
            ? mb_substr($data['interpretation'], 0, 300) . '...'
            : $data['interpretation'];

        echo '<tr>';
        echo '<td style="padding:6px 10px;font-weight:600;color:#334155;vertical-align:top;">🩻 ' . xlt('Findings') . '</td>';
        echo '<td colspan="3" style="padding:6px 10px;color:#475569;white-space:pre-wrap;">' . text($preview) . '</td>';
        echo '</tr>';
    }

    // Link to PDF if available
    if (!empty($data['pdf_document_id'])) {
        // NOTE: controller.php is at the site root, NOT in /interface.
        // That's why getWebRoot() (web root) is used, not rootdir (which includes /interface).
        $webroot = OEGlobalsBag::getInstance()->getWebRoot();
        $pdfUrl  = attr($webroot . '/controller.php?document&retrieve&patient_id=' . $pid . '&document_id=' . $data['pdf_document_id']);
        echo '<tr>';
        echo '<td colspan="4" style="padding:8px 10px;">';
        echo '<a href="' . $pdfUrl . '" target="_blank" style="color:#0ea5e9;font-weight:600;font-size:12px;">📄 ' . xlt('View Full PDF Report') . '</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</div>';
}
