<?php
/**
 * Informe de Diagnóstico por Imágenes - report.php
 *
 * Renderiza un sumario compacto del informe para las vistas
 * históricas de encuentros en OpenEMR (Patient History).
 *
 * @package   OpenEMR
 * @author    Centro Médico Origen
 * @license   GNU General Public License 3
 */

use OpenEMR\Core\OEGlobalsBag;

require_once(__DIR__ . '/../../globals.php');
require_once(OEGlobalsBag::getInstance()->getSrcDir() . "/api.inc.php");

/**
 * Función de reporte estándar de OpenEMR.
 * Llamada desde el historial de encuentros del paciente.
 */
function imaging_report_report(int $pid, int $encounter, int $cols, int $id): void
{
    $data = sqlQuery(
        "SELECT * FROM form_imaging_report WHERE id = ? AND pid = ? LIMIT 1",
        [$id, $pid]
    );

    if (empty($data)) {
        echo '<p class="text-muted small">Sin datos de informe.</p>';
        return;
    }

    $modalidadLabels = [
        'RX'   => 'Rayos X',
        'TC'   => 'Tomografía Computada',
        'RMN'  => 'Resonancia Magnética',
        'US'   => 'Ecografía',
        'MG'   => 'Mamografía',
        'DEXA' => 'Densitometría',
        'OT'   => 'Otro',
    ];

    $modalidad = $modalidadLabels[$data['modalidad'] ?? ''] ?? ($data['modalidad'] ?? '—');
    $estado    = $data['estado'] === 'finalizado'
        ? '<span style="color:#065f46;font-weight:600;background:#d1fae5;padding:2px 8px;border-radius:8px;font-size:11px;">Finalizado</span>'
        : '<span style="color:#92400e;font-weight:600;background:#fef3c7;padding:2px 8px;border-radius:8px;font-size:11px;">Borrador</span>';

    echo '<div style="font-family:sans-serif;font-size:13px;line-height:1.6;padding:8px 0;">';
    echo '<table style="width:100%;border-collapse:collapse;">';

    // Cabecera del resumen
    echo '<tr style="background:#f1f5f9;">';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;width:180px;">Modalidad</td>';
    echo '<td style="padding:6px 10px;color:#1e293b;">' . text($modalidad) . '</td>';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;width:180px;">Estado</td>';
    echo '<td style="padding:6px 10px;">' . $estado . '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;">Región Anatómica</td>';
    echo '<td style="padding:6px 10px;color:#1e293b;">' . text($data['region_anatomica'] ?? '—') . '</td>';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;">Fecha Informe</td>';
    echo '<td style="padding:6px 10px;color:#1e293b;">';
    echo $data['fecha_informe'] ? text(date('d/m/Y', strtotime($data['fecha_informe']))) : '—';
    echo '</td>';
    echo '</tr>';

    echo '<tr style="background:#f1f5f9;">';
    echo '<td style="padding:6px 10px;font-weight:600;color:#334155;">Médico Informante</td>';
    echo '<td colspan="3" style="padding:6px 10px;color:#1e293b;">' . text($data['medico_informante'] ?? '—') . '</td>';
    echo '</tr>';

    // Conclusión / Impresión Diagnóstica (resaltada)
    if (!empty($data['conclusion'])) {
        echo '<tr>';
        echo '<td style="padding:8px 10px;font-weight:700;color:#0f172a;vertical-align:top;">📌 Conclusión</td>';
        echo '<td colspan="3" style="padding:8px 10px;color:#1e293b;white-space:pre-wrap;background:#fffbeb;border-left:3px solid #f59e0b;">';
        echo text($data['conclusion']);
        echo '</td>';
        echo '</tr>';
    }

    // Nota breve de hallazgos (primeras 300 caracteres)
    if (!empty($data['interpretacion'])) {
        $preview = mb_strlen($data['interpretacion']) > 300
            ? mb_substr($data['interpretacion'], 0, 300) . '...'
            : $data['interpretacion'];

        echo '<tr>';
        echo '<td style="padding:6px 10px;font-weight:600;color:#334155;vertical-align:top;">🩻 Hallazgos</td>';
        echo '<td colspan="3" style="padding:6px 10px;color:#475569;white-space:pre-wrap;">' . text($preview) . '</td>';
        echo '</tr>';
    }

    // Enlace al PDF si existe
    if (!empty($data['pdf_document_id'])) {
        $rootdir = OEGlobalsBag::getInstance()->getString('rootdir');
        $pdfUrl  = attr($rootdir . '/controller.php?document&retrieve&patient_id=' . $pid . '&document_id=' . $data['pdf_document_id']);
        echo '<tr>';
        echo '<td colspan="4" style="padding:8px 10px;">';
        echo '<a href="' . $pdfUrl . '" target="_blank" style="color:#0ea5e9;font-weight:600;font-size:12px;">📄 Ver Informe PDF Completo</a>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</table>';
    echo '</div>';
}
