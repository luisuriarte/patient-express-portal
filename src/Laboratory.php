<?php
/**
 * Gestión de Laboratorios y Resultados Clínicos
 * Patient Express Portal - Integración Nativa con OpenEMR 8.2.0
 * 
 * Estructura de tablas OpenEMR:
 * procedure_order -> procedure_order_code (pc.procedure_order_seq)
 * procedure_order -> procedure_report (pr.procedure_order_seq = pc.procedure_order_seq)
 * procedure_report -> procedure_result (pres.procedure_report_id)
 */

namespace App;

class Laboratory
{
    /**
     * Obtiene la lista de informes de laboratorio agrupados por Encuentro (encounter_id)
     * filtrando solo los que tengan procedure_order_type = 'procedure'
     * y tomando la fecha de la tabla procedure_result (date).
     * 
     * @param int $pid ID del paciente en OpenEMR
     * @return array Lotes agrupados por encounter_id
     */
    public function getReportsGroupedByEncounter(int $pid): array
    {
        // Consulta alineada con OpenEMR 8.2.0:
        // Vincula de forma exacta cada procedure_order_code con su procedure_report
        // mediante procedure_order_id Y procedure_order_seq
        $sql = "SELECT 
                    po.encounter_id,
                    po.procedure_order_id,
                    pc.procedure_order_seq,
                    pc.procedure_code,
                    COALESCE(
                        NULLIF(TRIM(pc.procedure_name), ''),
                        NULLIF(TRIM(pt.name), ''),
                        NULLIF(TRIM(pt.description), ''),
                        NULLIF(TRIM(pc.procedure_code), ''),
                        'Panel de Laboratorio'
                    ) AS procedure_name,
                    pr.procedure_report_id,
                    pr.date_report,
                    pr.date_collected,
                    pr.report_status,
                    pr.review_status,
                    pr.report_notes,
                    pr.specimen_num,
                    po.date_ordered,
                    u.id AS provider_id,
                    u.fname AS provider_fname,
                    u.lname AS provider_lname,
                    u.title AS provider_title,
                    u.specialty AS provider_specialty,
                    MAX(pres.date) AS max_result_date,
                    MIN(pres.date) AS min_result_date,
                    DATE(COALESCE(MAX(pres.date), MIN(pres.date), pr.date_report, po.date_ordered)) AS result_date_iso,
                    COUNT(DISTINCT pres.procedure_result_id) AS total_results,
                    SUM(CASE WHEN pres.abnormal IN ('high', 'low', 'abnormal', 'yes', 'critical', 'H', 'L', 'A') THEN 1 ELSE 0 END) AS abnormal_count
                FROM procedure_order po
                JOIN procedure_order_code pc ON pc.procedure_order_id = po.procedure_order_id
                LEFT JOIN procedure_type pt ON pt.procedure_code = pc.procedure_code
                LEFT JOIN procedure_report pr ON pr.procedure_order_id = po.procedure_order_id 
                     AND (pr.procedure_order_seq = pc.procedure_order_seq OR pr.procedure_order_seq = 0 OR pr.procedure_order_seq IS NULL)
                LEFT JOIN users u ON po.provider_id = u.id
                LEFT JOIN procedure_result pres ON pr.procedure_report_id = pres.procedure_report_id
                WHERE po.patient_id = ?
                  AND po.procedure_order_type = 'procedure'
                GROUP BY po.encounter_id, po.procedure_order_id, pc.procedure_order_seq, pr.procedure_report_id
                ORDER BY result_date_iso DESC, po.encounter_id DESC, pc.procedure_order_seq ASC";

        $res = sqlStatement($sql, [$pid]);
        if (!$res) {
            return [];
        }

        $grouped = [];
        $seenStudiesPerEncounter = [];

        while ($row = sqlFetchArray($res)) {
            $encounterId = (int)($row['encounter_id'] ?? 0);
            $encounterKey = $encounterId > 0 ? (string)$encounterId : ('order_' . $row['procedure_order_id']);
            $resultDateIso = $row['result_date_iso'] ?: date('Y-m-d');

            $providerName = trim(($row['provider_title'] ? $row['provider_title'] . ' ' : 'Dr. ') . ($row['provider_fname'] ?? '') . ' ' . ($row['provider_lname'] ?? ''));
            if (trim($providerName) === 'Dr.' || empty(trim($providerName))) {
                $providerName = 'Médico Solicitante';
            }

            $studyTitle = $row['procedure_name'] ?: 'Panel de Laboratorio';
            $uniqueStudyKey = $row['procedure_order_id'] . '_' . $row['procedure_order_seq'] . '_' . ($row['procedure_report_id'] ?? '0');

            // Evitar duplicación si hay joins redundantes
            if (isset($seenStudiesPerEncounter[$encounterKey][$uniqueStudyKey])) {
                continue;
            }
            $seenStudiesPerEncounter[$encounterKey][$uniqueStudyKey] = true;

            $resultDateFormatted = !empty($row['max_result_date']) 
                ? date('d/m/Y H:i', strtotime($row['max_result_date'])) 
                : ($row['date_report'] ? date('d/m/Y H:i', strtotime($row['date_report'])) : date('d/m/Y', strtotime($resultDateIso)));

            $reportItem = [
                'id'              => (int)($row['procedure_report_id'] ?: $row['procedure_order_id']),
                'report_id'       => (int)($row['procedure_report_id'] ?? 0),
                'order_id'        => (int)$row['procedure_order_id'],
                'order_seq'       => (int)$row['procedure_order_seq'],
                'encounter_id'    => $encounterId,
                'title'           => $studyTitle,
                'procedure_code'  => $row['procedure_code'] ?? '',
                'date_result'     => $resultDateFormatted,
                'date_raw'        => $row['max_result_date'] ?? $row['date_report'] ?? $row['date_ordered'],
                'date_report'     => $row['date_report'] ? date('d/m/Y H:i', strtotime($row['date_report'])) : null,
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

            if (!isset($grouped[$encounterKey])) {
                $grouped[$encounterKey] = [
                    'encounter_key'     => $encounterKey,
                    'encounter_id'      => $encounterId,
                    'encounter_label'   => $encounterId > 0 ? ('Encuentro #' . $encounterId) : ('Orden #' . $row['procedure_order_id']),
                    'date_iso'          => $resultDateIso,
                    'date_formatted'    => date('d/m/Y', strtotime($resultDateIso)),
                    'date_display'      => $this->formatDateDisplay($resultDateIso),
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

            $grouped[$encounterKey]['total_studies']++;
            $grouped[$encounterKey]['total_results'] += (int)$row['total_results'];
            $grouped[$encounterKey]['abnormal_count'] += (int)$row['abnormal_count'];
            if ((int)$row['abnormal_count'] > 0) {
                $grouped[$encounterKey]['has_abnormals'] = true;
            }

            if (!in_array($studyTitle, $grouped[$encounterKey]['study_names'], true)) {
                $grouped[$encounterKey]['study_names'][] = $studyTitle;
            }
            if (!in_array($providerName, $grouped[$encounterKey]['providers'], true)) {
                $grouped[$encounterKey]['providers'][] = $providerName;
            }
            if (!empty($row['specimen_num']) && !in_array($row['specimen_num'], $grouped[$encounterKey]['specimens'], true)) {
                $grouped[$encounterKey]['specimens'][] = $row['specimen_num'];
            }

            $grouped[$encounterKey]['reports'][] = $reportItem;
        }

        return array_values($grouped);
    }

    /**
     * Alias retrocompatible
     */
    public function getReportsGroupedByDate(int $pid): array
    {
        return $this->getReportsGroupedByEncounter($pid);
    }

    /**
     * Obtiene el desglose consolidado de todos los paneles y analitos de un Encuentro
     * 
     * @param int|string $encounterId ID de encuentro (o clave de orden)
     * @param int $pid ID de paciente
     * @return array|null
     */
    public function getGroupedReportDetailsByEncounter($encounterId, int $pid): ?array
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

        // 2. Consulta de códigos de procedimiento y reportes del encuentro
        $isNumericEncounter = is_numeric($encounterId) && (int)$encounterId > 0;
        
        $whereClause = $isNumericEncounter ? "po.encounter_id = ?" : "po.procedure_order_id = ?";
        $paramVal = $isNumericEncounter ? (int)$encounterId : (int)str_replace('order_', '', (string)$encounterId);

        $sqlReports = "SELECT 
                            po.encounter_id,
                            po.procedure_order_id,
                            pc.procedure_order_seq,
                            pc.procedure_code,
                            COALESCE(
                                NULLIF(TRIM(pc.procedure_name), ''),
                                NULLIF(TRIM(pt.name), ''),
                                NULLIF(TRIM(pt.description), ''),
                                NULLIF(TRIM(pc.procedure_code), ''),
                                'Panel de Laboratorio'
                            ) AS procedure_name,
                            pc.diagnoses,
                            pr.procedure_report_id,
                            pr.date_report,
                            pr.date_collected,
                            pr.report_status,
                            pr.review_status,
                            pr.report_notes,
                            pr.specimen_num,
                            po.date_ordered,
                            po.patient_instructions,
                            u.fname AS provider_fname,
                            u.lname AS provider_lname,
                            u.title AS provider_title,
                            u.specialty AS provider_specialty,
                            u.npi AS provider_license
                       FROM procedure_order po
                       JOIN procedure_order_code pc ON pc.procedure_order_id = po.procedure_order_id
                       LEFT JOIN procedure_type pt ON pt.procedure_code = pc.procedure_code
                       LEFT JOIN procedure_report pr ON pr.procedure_order_id = po.procedure_order_id 
                            AND (pr.procedure_order_seq = pc.procedure_order_seq OR pr.procedure_order_seq = 0 OR pr.procedure_order_seq IS NULL)
                       LEFT JOIN users u ON po.provider_id = u.id
                       WHERE po.patient_id = ? 
                         AND {$whereClause}
                         AND po.procedure_order_type = 'procedure'
                       ORDER BY pc.procedure_order_seq ASC, pr.procedure_report_id ASC";

        $resReports = sqlStatement($sqlReports, [$pid, $paramVal]);
        if (!$resReports) {
            return null;
        }

        $panels = [];
        $totalAbnormals = 0;
        $allProviders = [];
        $latestResultDate = null;
        $seenPanels = [];

        while ($rRow = sqlFetchArray($resReports)) {
            $reportId = (int)($rRow['procedure_report_id'] ?? 0);
            $panelKey = $rRow['procedure_order_id'] . '_' . $rRow['procedure_order_seq'] . '_' . $reportId;

            if (isset($seenPanels[$panelKey])) {
                continue;
            }
            $seenPanels[$panelKey] = true;

            $panelResults = [];

            // 3. Obtener determinaciones de procedure_result
            if ($reportId > 0) {
                $sqlResults = "SELECT 
                                    pres.procedure_result_id,
                                    pres.result_code,
                                    pres.result_text,
                                    pres.result,
                                    pres.units,
                                    pres.range AS reference_range,
                                    pres.abnormal,
                                    pres.comments,
                                    pres.date AS result_date,
                                    pres.facility
                               FROM procedure_result pres
                               WHERE pres.procedure_report_id = ?
                               ORDER BY pres.date DESC, pres.procedure_result_id ASC";

                $resResults = sqlStatement($sqlResults, [$reportId]);

                if ($resResults) {
                    while ($r = sqlFetchArray($resResults)) {
                        $isAbnormal = in_array(strtolower((string)$r['abnormal']), ['high', 'low', 'abnormal', 'yes', 'critical', 'h', 'l', 'a'], true);
                        if ($isAbnormal) {
                            $totalAbnormals++;
                        }

                        if (!empty($r['result_date'])) {
                            if ($latestResultDate === null || strtotime($r['result_date']) > strtotime($latestResultDate)) {
                                $latestResultDate = $r['result_date'];
                            }
                        }

                        // Determinar nombre y valor del resultado
                        $val = ($r['result'] !== null && $r['result'] !== '') ? $r['result'] : ($r['result_text'] ?? '-');
                        $name = !empty($r['result_text']) && $r['result_text'] !== $val ? $r['result_text'] : (!empty($r['result_code']) ? $r['result_code'] : 'Determinación');

                        $panelResults[] = [
                            'id'               => (int)$r['procedure_result_id'],
                            'code'             => $r['result_code'] ?? '',
                            'name'             => $name,
                            'value'            => $val,
                            'units'            => $r['units'] ?? '',
                            'reference_range'  => $r['reference_range'] ?? 'No especificado',
                            'abnormal'         => $r['abnormal'] ?? 'normal',
                            'is_abnormal'      => $isAbnormal,
                            'abnormal_flag'    => $this->getAbnormalFlag($r['abnormal'] ?? ''),
                            'comments'         => $r['comments'] ?? '',
                            'date'             => $r['result_date'] ? date('d/m/Y H:i', strtotime($r['result_date'])) : ''
                        ];
                    }
                }
            }

            $providerFullName = trim(($rRow['provider_title'] ? $rRow['provider_title'] . ' ' : 'Dr. ') . ($rRow['provider_fname'] ?? '') . ' ' . ($rRow['provider_lname'] ?? ''));
            if (trim($providerFullName) === 'Dr.' || empty(trim($providerFullName))) {
                $providerFullName = 'Médico de Cabecera';
            }

            if (!in_array($providerFullName, $allProviders, true)) {
                $allProviders[] = $providerFullName;
            }

            $studyTitle = $rRow['procedure_name'] ?: 'Panel de Laboratorio';

            $panels[] = [
                'report_id'            => $reportId,
                'order_id'             => (int)$rRow['procedure_order_id'],
                'order_seq'            => (int)$rRow['procedure_order_seq'],
                'encounter_id'         => (int)$rRow['encounter_id'],
                'panel_name'           => $studyTitle,
                'procedure_code'       => $rRow['procedure_code'] ?? '',
                'specimen_num'         => $rRow['specimen_num'] ?: 'N/A',
                'date_report'          => $rRow['date_report'] ? date('d/m/Y H:i', strtotime($rRow['date_report'])) : 'N/A',
                'date_collected'       => $rRow['date_collected'] ? date('d/m/Y H:i', strtotime($rRow['date_collected'])) : 'N/A',
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

        $finalDateIso = $latestResultDate ? date('Y-m-d', strtotime($latestResultDate)) : date('Y-m-d');
        $encounterLabel = $isNumericEncounter ? ('Encuentro #' . $encounterId) : ('Protocolo ' . $encounterId);

        return [
            'encounter_id'           => $encounterId,
            'encounter_label'        => $encounterLabel,
            'batch_date'             => $finalDateIso,
            'batch_date_formatted'   => date('d/m/Y', strtotime($finalDateIso)),
            'batch_date_display'     => $this->formatDateDisplay($finalDateIso),
            'latest_result_date'     => $latestResultDate ? date('d/m/Y H:i', strtotime($latestResultDate)) : date('d/m/Y', strtotime($finalDateIso)),
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
     * Retrocompatibilidad para búsqueda por fecha
     */
    public function getGroupedReportDetailsByDate(string $date, int $pid): ?array
    {
        $sql = "SELECT po.encounter_id, po.procedure_order_id
                FROM procedure_order po
                INNER JOIN procedure_report pr ON po.procedure_order_id = pr.procedure_order_id
                LEFT JOIN procedure_result pres ON pr.procedure_report_id = pres.procedure_report_id
                WHERE po.patient_id = ?
                  AND po.procedure_order_type = 'procedure'
                  AND DATE(COALESCE(pres.date, pr.date_report, po.date_ordered)) = ?
                LIMIT 1";
        $row = sqlQuery($sql, [$pid, $date]);
        if ($row) {
            $enc = (int)($row['encounter_id'] ?? 0);
            return $this->getGroupedReportDetailsByEncounter($enc > 0 ? $enc : 'order_' . $row['procedure_order_id'], $pid);
        }
        return null;
    }

    /**
     * Obtiene el reporte individual localizando su encuentro
     */
    public function getReportDetails(int $reportId, int $pid): ?array
    {
        $sql = "SELECT po.encounter_id, po.procedure_order_id
                FROM procedure_report pr
                INNER JOIN procedure_order po ON pr.procedure_order_id = po.procedure_order_id
                WHERE pr.procedure_report_id = ? AND po.patient_id = ? AND po.procedure_order_type = 'procedure'
                LIMIT 1";

        $row = sqlQuery($sql, [$reportId, $pid]);
        if ($row) {
            $enc = (int)($row['encounter_id'] ?? 0);
            return $this->getGroupedReportDetailsByEncounter($enc > 0 ? $enc : 'order_' . $row['procedure_order_id'], $pid);
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
