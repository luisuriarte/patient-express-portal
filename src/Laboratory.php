<?php
/**
 * Gestión de Laboratorios y Resultados Clínicos
 * Patient Express Portal - Integración Nativa con Funciones OpenEMR
 */

namespace App;

class Laboratory
{
    /**
     * Obtiene la lista de informes de laboratorio agrupados por DATE(date_collected)
     * usando sqlStatement() y sqlFetchArray() de OpenEMR.
     * 
     * @param int $pid ID del paciente en OpenEMR
     * @return array Lotes agrupados por fecha
     */
    public function getReportsGroupedByDate(int $pid): array
    {
        $sql = "SELECT 
                    pr.procedure_report_id,
                    pr.procedure_order_id,
                    pr.date_report,
                    pr.date_collected,
                    DATE(COALESCE(pr.date_collected, pr.date_report, po.date_ordered)) AS date_collected_iso,
                    pr.report_status,
                    pr.review_status,
                    pr.report_notes,
                    pr.specimen_num,
                    po.date_ordered,
                    poc.procedure_name,
                    poc.procedure_code,
                    u.id AS provider_id,
                    u.fname AS provider_fname,
                    u.lname AS provider_lname,
                    u.title AS provider_title,
                    u.specialty AS provider_specialty,
                    COUNT(pres.procedure_result_id) AS total_results,
                    SUM(CASE WHEN pres.abnormal IN ('high', 'low', 'abnormal', 'yes', 'critical', 'H', 'L', 'A') THEN 1 ELSE 0 END) AS abnormal_count
                FROM procedure_report pr
                INNER JOIN procedure_order po ON pr.procedure_order_id = po.procedure_order_id
                LEFT JOIN procedure_order_code poc ON (po.procedure_order_id = poc.procedure_order_id AND (pr.procedure_order_seq = poc.procedure_order_seq OR poc.procedure_order_seq = 1))
                LEFT JOIN users u ON po.provider_id = u.id
                LEFT JOIN procedure_result pres ON pr.procedure_report_id = pres.procedure_report_id
                WHERE po.patient_id = ?
                GROUP BY pr.procedure_report_id
                ORDER BY date_collected_iso DESC, COALESCE(pr.date_report, po.date_ordered) DESC, pr.procedure_report_id DESC";

        $res = sqlStatement($sql, [$pid]);
        if (!$res) {
            return [];
        }

        $grouped = [];
        while ($row = sqlFetchArray($res)) {
            $dateIso = $row['date_collected_iso'] ?: date('Y-m-d');
            $providerName = trim(($row['provider_title'] ? $row['provider_title'] . ' ' : 'Dr. ') . ($row['provider_fname'] ?? '') . ' ' . ($row['provider_lname'] ?? ''));
            if (trim($providerName) === 'Dr.' || empty(trim($providerName))) {
                $providerName = 'Médico Solicitante';
            }

            $studyTitle = !empty($row['procedure_name']) 
                ? $row['procedure_name'] 
                : (!empty($row['procedure_code']) ? 'Análisis ' . $row['procedure_code'] : 'Panel de Laboratorio');

            $reportItem = [
                'id'              => (int)$row['procedure_report_id'],
                'order_id'        => (int)$row['procedure_order_id'],
                'title'           => $studyTitle,
                'date_report'     => $row['date_report'] ? date('d/m/Y H:i', strtotime($row['date_report'])) : ($row['date_ordered'] ? date('d/m/Y', strtotime($row['date_ordered'])) : 'Sin fecha'),
                'date_raw'        => $row['date_report'] ?? $row['date_ordered'],
                'date_collected'  => $row['date_collected'] ? date('d/m/Y H:i', strtotime($row['date_collected'])) : null,
                'provider_name'   => $providerName,
                'provider_spec'   => $row['provider_specialty'] ?? 'Medicina General',
                'status'          => $this->mapStatus($row['report_status'] ?? 'complete'),
                'status_raw'      => $row['report_status'] ?? 'complete',
                'specimen_num'    => $row['specimen_num'] ?: 'N/A',
                'total_results'   => (int)$row['total_results'],
                'has_abnormals'   => ((int)$row['abnormal_count']) > 0,
                'abnormal_count'  => (int)$row['abnormal_count'],
                'notes'           => $row['report_notes'] ?? ''
            ];

            if (!isset($grouped[$dateIso])) {
                $grouped[$dateIso] = [
                    'date_iso'          => $dateIso,
                    'date_formatted'    => date('d/m/Y', strtotime($dateIso)),
                    'date_display'      => $this->formatDateDisplay($dateIso),
                    'total_studies'     => 0,
                    'total_results'     => 0,
                    'abnormal_count'    => 0,
                    'has_abnormals'     => false,
                    'study_names'       => [],
                    'providers'         => [],
                    'specimens'         => [],
                    'reports'           => []
                ];
            }

            $grouped[$dateIso]['total_studies']++;
            $grouped[$dateIso]['total_results'] += (int)$row['total_results'];
            $grouped[$dateIso]['abnormal_count'] += (int)$row['abnormal_count'];
            if ((int)$row['abnormal_count'] > 0) {
                $grouped[$dateIso]['has_abnormals'] = true;
            }

            if (!in_array($studyTitle, $grouped[$dateIso]['study_names'], true)) {
                $grouped[$dateIso]['study_names'][] = $studyTitle;
            }
            if (!in_array($providerName, $grouped[$dateIso]['providers'], true)) {
                $grouped[$dateIso]['providers'][] = $providerName;
            }
            if (!empty($row['specimen_num']) && !in_array($row['specimen_num'], $grouped[$dateIso]['specimens'], true)) {
                $grouped[$dateIso]['specimens'][] = $row['specimen_num'];
            }

            $grouped[$dateIso]['reports'][] = $reportItem;
        }

        return array_values($grouped);
    }

    /**
     * Obtiene el desglose consolidado de todos los paneles y analitos de una fecha
     * 
     * @param string $date Fecha YYYY-MM-DD
     * @param int $pid ID de paciente
     * @return array|null
     */
    public function getGroupedReportDetailsByDate(string $date, int $pid): ?array
    {
        // 1. Datos del paciente
        $sqlPatient = "SELECT pid, pubpid, fname, lname, mname, DOB, sex, ss, phone_cell, email, street, city, postal_code
                       FROM patient_data 
                       WHERE pid = ? 
                       LIMIT 1";
        $patientRow = sqlQuery($sqlPatient, [$pid]);
        if (!$patientRow) {
            return null;
        }

        // 2. Reportes de la fecha
        $sqlReports = "SELECT 
                            pr.procedure_report_id,
                            pr.procedure_order_id,
                            pr.date_report,
                            pr.date_collected,
                            DATE(COALESCE(pr.date_collected, pr.date_report, po.date_ordered)) AS date_collected_iso,
                            pr.report_status,
                            pr.review_status,
                            pr.report_notes,
                            pr.specimen_num,
                            po.date_ordered,
                            po.patient_instructions,
                            poc.procedure_name,
                            poc.procedure_code,
                            u.fname AS provider_fname,
                            u.lname AS provider_lname,
                            u.title AS provider_title,
                            u.specialty AS provider_specialty,
                            u.npi AS provider_license
                       FROM procedure_report pr
                       INNER JOIN procedure_order po ON pr.procedure_order_id = po.procedure_order_id
                       LEFT JOIN procedure_order_code poc ON (po.procedure_order_id = poc.procedure_order_id AND (pr.procedure_order_seq = poc.procedure_order_seq OR poc.procedure_order_seq = 1))
                       LEFT JOIN users u ON po.provider_id = u.id
                       WHERE po.patient_id = ? 
                         AND DATE(COALESCE(pr.date_collected, pr.date_report, po.date_ordered)) = ?
                       ORDER BY pr.procedure_report_id ASC";

        $resReports = sqlStatement($sqlReports, [$pid, $date]);
        if (!$resReports) {
            return null;
        }

        $panels = [];
        $totalAbnormals = 0;
        $allProviders = [];

        while ($rRow = sqlFetchArray($resReports)) {
            $reportId = (int)$rRow['procedure_report_id'];

            // 3. Resultados de analitos
            $sqlResults = "SELECT 
                                pres.procedure_result_id,
                                pres.result_code,
                                pres.result_text,
                                pres.units,
                                pres.range AS reference_range,
                                pres.abnormal,
                                pres.comments,
                                pres.date AS result_date,
                                pres.facility
                           FROM procedure_result pres
                           WHERE pres.procedure_report_id = ?
                           ORDER BY pres.procedure_result_id ASC";

            $resResults = sqlStatement($sqlResults, [$reportId]);
            $panelResults = [];

            if ($resResults) {
                while ($r = sqlFetchArray($resResults)) {
                    $isAbnormal = in_array(strtolower((string)$r['abnormal']), ['high', 'low', 'abnormal', 'yes', 'critical', 'h', 'l', 'a'], true);
                    if ($isAbnormal) {
                        $totalAbnormals++;
                    }
                    $panelResults[] = [
                        'id'               => (int)$r['procedure_result_id'],
                        'code'             => $r['result_code'] ?? '',
                        'name'             => $r['result_code'] ?? 'Determinación',
                        'value'            => $r['result_text'] ?? '-',
                        'units'            => $r['units'] ?? '',
                        'reference_range'  => $r['reference_range'] ?? 'No especificado',
                        'abnormal'         => $r['abnormal'] ?? 'normal',
                        'is_abnormal'      => $isAbnormal,
                        'abnormal_flag'    => $this->getAbnormalFlag($r['abnormal'] ?? ''),
                        'comments'         => $r['comments'] ?? '',
                        'date'             => $r['result_date'] ? date('d/m/Y', strtotime($r['result_date'])) : ''
                    ];
                }
            }

            $providerFullName = trim(($rRow['provider_title'] ? $rRow['provider_title'] . ' ' : 'Dr. ') . ($rRow['provider_fname'] ?? '') . ' ' . ($rRow['provider_lname'] ?? ''));
            if (trim($providerFullName) === 'Dr.' || empty(trim($providerFullName))) {
                $providerFullName = 'Médico de Cabecera';
            }

            if (!in_array($providerFullName, $allProviders, true)) {
                $allProviders[] = $providerFullName;
            }

            $studyTitle = !empty($rRow['procedure_name']) 
                ? $rRow['procedure_name'] 
                : (!empty($rRow['procedure_code']) ? 'Análisis ' . $rRow['procedure_code'] : 'Panel de Laboratorio');

            $panels[] = [
                'report_id'            => $reportId,
                'order_id'             => (int)$rRow['procedure_order_id'],
                'panel_name'           => $studyTitle,
                'procedure_code'       => $rRow['procedure_code'] ?? '',
                'specimen_num'         => $rRow['specimen_num'] ?: 'N/A',
                'date_report'          => $rRow['date_report'] ? date('d/m/Y H:i', strtotime($rRow['date_report'])) : 'N/A',
                'date_collected'       => $rRow['date_collected'] ? date('d/m/Y H:i', strtotime($rRow['date_collected'])) : date('d/m/Y', strtotime($date)),
                'date_ordered'         => $rRow['date_ordered'] ? date('d/m/Y', strtotime($rRow['date_ordered'])) : 'N/A',
                'report_status'        => $this->mapStatus($rRow['report_status'] ?? 'complete'),
                'notes'                => $rRow['report_notes'] ?? '',
                'patient_instructions' => $rRow['patient_instructions'] ?? '',
                'provider'             => [
                    'full_name'  => $providerFullName,
                    'specialty'  => $rRow['provider_specialty'] ?? 'Medicina General',
                    'license'    => $rRow['provider_license'] ?? 'M.N. / M.P. Registrada'
                ],
                'results'              => $panelResults
            ];
        }

        if (empty($panels)) {
            return null;
        }

        $patientFullName = trim(($patientRow['fname'] ?? '') . ' ' . ($patientRow['mname'] ?? '') . ' ' . ($patientRow['lname'] ?? ''));
        if (empty($patientFullName)) {
            $patientFullName = 'Paciente #' . $patientRow['pid'];
        }

        return [
            'batch_date'             => $date,
            'batch_date_formatted'   => date('d/m/Y', strtotime($date)),
            'batch_date_display'     => $this->formatDateDisplay($date),
            'total_panels'           => count($panels),
            'total_abnormals'        => $totalAbnormals,
            'has_abnormals'          => $totalAbnormals > 0,
            'providers_summary'      => implode(', ', $allProviders),
            'patient'                => [
                'pid'            => (int)$patientRow['pid'],
                'pubpid'         => $patientRow['pubpid'] ?: (string)$patientRow['pid'],
                'full_name'      => $patientFullName,
                'dni'            => $patientRow['ss'] ?? 'N/A',
                'dob'            => $patientRow['DOB'] ? date('d/m/Y', strtotime($patientRow['DOB'])) : 'N/A',
                'age'            => $this->calculateAge($patientRow['DOB'] ?? ''),
                'sex'            => $this->formatSex($patientRow['sex'] ?? ''),
                'phone'          => $patientRow['phone_cell'] ?? '',
                'email'          => $patientRow['email'] ?? '',
                'address'        => trim(($patientRow['street'] ?? '') . ', ' . ($patientRow['city'] ?? ''))
            ],
            'panels'                 => $panels
        ];
    }

    /**
     * Obtiene el reporte individual localizando su fecha de lote
     */
    public function getReportDetails(int $reportId, int $pid): ?array
    {
        $sql = "SELECT DATE(COALESCE(pr.date_collected, pr.date_report, po.date_ordered)) AS date_collected_iso
                FROM procedure_report pr
                INNER JOIN procedure_order po ON pr.procedure_order_id = po.procedure_order_id
                WHERE pr.procedure_report_id = ? AND po.patient_id = ?
                LIMIT 1";

        $row = sqlQuery($sql, [$reportId, $pid]);
        if ($row && !empty($row['date_collected_iso'])) {
            return $this->getGroupedReportDetailsByDate($row['date_collected_iso'], $pid);
        }

        return null;
    }

    private function formatDateDisplay(string $dateIso): string
    {
        $months = [
            1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
            5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
            9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
        ];

        $time = strtotime($dateIso);
        $day = (int)date('d', $time);
        $month = (int)date('m', $time);
        $year = date('Y', $time);

        return sprintf('%d de %s, %s', $day, $months[$month] ?? date('F', $time), $year);
    }

    private function mapStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'complete', 'final', 'reviewed' => 'Completado',
            'received', 'preliminary', 'pending' => 'Preliminar / En proceso',
            'cancelled' => 'Cancelado',
            default => ucfirst($status)
        };
    }

    private function getAbnormalFlag(string $flag): string
    {
        return match (strtolower(trim($flag))) {
            'high', 'h' => 'ALTO (H)',
            'low', 'l' => 'BAJO (L)',
            'abnormal', 'a', 'yes' => 'ANORMAL',
            'critical', 'c' => 'CRÍTICO',
            default => 'Normal'
        };
    }

    private function calculateAge(?string $dob): string
    {
        if (empty($dob) || $dob === '0000-00-00') {
            return 'N/A';
        }
        try {
            $birthDate = new \DateTime($dob);
            $now = new \DateTime();
            $interval = $now->diff($birthDate);
            return $interval->y . ' años';
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    private function formatSex(string $sex): string
    {
        return match (strtoupper(trim($sex))) {
            'M', 'MALE', 'MASCULINO' => 'Masculino',
            'F', 'FEMALE', 'FEMENINO' => 'Femenino',
            default => 'Otro / No especificado'
        };
    }
}
