<?php
/**
 * Clase de Gestión de Laboratorios y Resultados Clínicos
 * Patient Express Portal - Integración OpenEMR
 */

namespace App;

use PDO;
use PDOException;

class Laboratory
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getDbConnection();
    }

    /**
     * Obtiene la lista de informes de laboratorio de un paciente específico
     * 
     * @param int $pid ID de paciente en OpenEMR
     * @return array Lista de reportes
     */
    public function getReportsByPatient(int $pid): array
    {
        try {
            $sql = "SELECT 
                        pr.procedure_report_id,
                        pr.procedure_order_id,
                        pr.date_report,
                        pr.date_collected,
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
                    WHERE po.patient_id = :pid
                    GROUP BY pr.procedure_report_id
                    ORDER BY COALESCE(pr.date_report, po.date_ordered) DESC, pr.procedure_report_id DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':pid' => $pid]);
            $reports = $stmt->fetchAll();

            // Formatear datos para la vista
            return array_map(function ($row) {
                $providerName = trim(($row['provider_title'] ? $row['provider_title'] . ' ' : 'Dr. ') . ($row['provider_fname'] ?? '') . ' ' . ($row['provider_lname'] ?? ''));
                if (trim($providerName) === 'Dr.' || empty(trim($providerName))) {
                    $providerName = 'Médico de Cabecera';
                }

                $studyTitle = !empty($row['procedure_name']) ? $row['procedure_name'] : (!empty($row['procedure_code']) ? 'Análisis ' . $row['procedure_code'] : 'Panel de Laboratorio General');

                return [
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
                    'specimen_num'    => $row['specimen_num'] ?? 'N/A',
                    'total_results'   => (int)$row['total_results'],
                    'has_abnormals'   => ((int)$row['abnormal_count']) > 0,
                    'abnormal_count'  => (int)$row['abnormal_count'],
                    'notes'           => $row['report_notes'] ?? ''
                ];
            }, $reports);

        } catch (PDOException $e) {
            error_log('Error en Laboratory::getReportsByPatient: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Obtiene el detalle completo de un informe de laboratorio (con resultados desglosados)
     * Verificando que pertenezca al paciente autenticado ($pid)
     * 
     * @param int $reportId ID de procedure_report
     * @param int $pid ID del paciente
     * @return array|null Detalle del informe o null si no existe/no pertenece
     */
    public function getReportDetails(int $reportId, int $pid): ?array
    {
        try {
            // 1. Obtener encabezado del reporte y validar ownership
            $sqlHeader = "SELECT 
                            pr.procedure_report_id,
                            pr.procedure_order_id,
                            pr.date_report,
                            pr.date_collected,
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
                            u.npi AS provider_license,
                            pd.pid,
                            pd.fname AS patient_fname,
                            pd.lname AS patient_lname,
                            pd.mname AS patient_mname,
                            pd.DOB AS patient_dob,
                            pd.sex AS patient_sex,
                            pd.ss AS patient_dni,
                            pd.phone_cell AS patient_phone,
                            pd.email AS patient_email,
                            pd.street AS patient_street,
                            pd.city AS patient_city
                        FROM procedure_report pr
                        INNER JOIN procedure_order po ON pr.procedure_order_id = po.procedure_order_id
                        LEFT JOIN procedure_order_code poc ON (po.procedure_order_id = poc.procedure_order_id)
                        LEFT JOIN users u ON po.provider_id = u.id
                        INNER JOIN patient_data pd ON po.patient_id = pd.pid
                        WHERE pr.procedure_report_id = :report_id AND po.patient_id = :pid
                        LIMIT 1";

            $stmtHeader = $this->db->prepare($sqlHeader);
            $stmtHeader->execute([
                ':report_id' => $reportId,
                ':pid'       => $pid
            ]);

            $header = $stmtHeader->fetch();

            if (!$header) {
                return null;
            }

            // 2. Obtener resultados de cada analito
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
                        WHERE pres.procedure_report_id = :report_id
                        ORDER BY pres.procedure_result_id ASC";

            $stmtResults = $this->db->prepare($sqlResults);
            $stmtResults->execute([':report_id' => $reportId]);
            $rawResults = $stmtResults->fetchAll();

            $results = array_map(function ($r) {
                $isAbnormal = in_array(strtolower((string)$r['abnormal']), ['high', 'low', 'abnormal', 'yes', 'critical', 'h', 'l', 'a'], true);
                return [
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
            }, $rawResults);

            $patientFullName = trim(($header['patient_fname'] ?? '') . ' ' . ($header['patient_mname'] ?? '') . ' ' . ($header['patient_lname'] ?? ''));
            $providerFullName = trim(($header['provider_title'] ? $header['provider_title'] . ' ' : 'Dr. ') . ($header['provider_fname'] ?? '') . ' ' . ($header['provider_lname'] ?? ''));

            return [
                'report_id'          => (int)$header['procedure_report_id'],
                'order_id'           => (int)$header['procedure_order_id'],
                'study_name'         => !empty($header['procedure_name']) ? $header['procedure_name'] : 'Análisis Clínicos de Laboratorio',
                'procedure_code'     => $header['procedure_code'] ?? '',
                'date_report'        => $header['date_report'] ? date('d/m/Y H:i', strtotime($header['date_report'])) : 'N/A',
                'date_report_raw'    => $header['date_report'],
                'date_ordered'       => $header['date_ordered'] ? date('d/m/Y', strtotime($header['date_ordered'])) : 'N/A',
                'date_collected'     => $header['date_collected'] ? date('d/m/Y H:i', strtotime($header['date_collected'])) : 'N/A',
                'specimen_num'       => $header['specimen_num'] ?? 'N/A',
                'report_status'      => $this->mapStatus($header['report_status'] ?? 'complete'),
                'notes'              => $header['report_notes'] ?? '',
                'patient_instructions' => $header['patient_instructions'] ?? '',
                'patient'            => [
                    'pid'            => (int)$header['pid'],
                    'full_name'      => $patientFullName,
                    'dni'            => $header['patient_dni'] ?? 'N/A',
                    'dob'            => $header['patient_dob'] ? date('d/m/Y', strtotime($header['patient_dob'])) : 'N/A',
                    'age'            => $this->calculateAge($header['patient_dob'] ?? ''),
                    'sex'            => $this->formatSex($header['patient_sex'] ?? ''),
                    'phone'          => $header['patient_phone'] ?? '',
                    'email'          => $header['patient_email'] ?? '',
                    'address'        => trim(($header['patient_street'] ?? '') . ', ' . ($header['patient_city'] ?? ''))
                ],
                'provider'           => [
                    'full_name'      => $providerFullName,
                    'specialty'      => $header['provider_specialty'] ?? 'Medicina General',
                    'license'        => $header['provider_license'] ?? 'M.N. / M.P. Registrada'
                ],
                'results'            => $results
            ];

        } catch (PDOException $e) {
            error_log('Error en Laboratory::getReportDetails: ' . $e->getMessage());
            return null;
        }
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
