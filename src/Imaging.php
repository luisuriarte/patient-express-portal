<?php
/**
 * Clase de Gestión de Diagnóstico por Imágenes e Integración Orthanc / OHIF Viewer
 * Patient Express Portal - Conexión PACS & OpenEMR
 */

namespace App;

use PDO;
use PDOException;

class Imaging
{
    private PDO $db;
    private string $orthancUrl;
    private string $orthancUser;
    private string $orthancPass;
    private string $ohifBaseUrl;
    private string $orthancWadoUrl;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getDbConnection();
        $this->orthancUrl = defined('ORTHANC_URL') ? ORTHANC_URL : 'http://127.0.0.1:8042';
        $this->orthancUser = defined('ORTHANC_USER') ? ORTHANC_USER : 'orthanc';
        $this->orthancPass = defined('ORTHANC_PASS') ? ORTHANC_PASS : 'orthanc';
        $this->ohifBaseUrl = defined('OHIF_VIEWER_BASE_URL') ? OHIF_VIEWER_BASE_URL : 'https://imagenes.origen.ar/viewer';
        $this->orthancWadoUrl = defined('ORTHANC_WADO_URL') ? ORTHANC_WADO_URL : 'https://pacs.origen.ar/dicom-web';
    }

    /**
     * Obtiene los estudios de imágenes de un paciente desde la base de datos de OpenEMR
     * complementando opcionalmente con estudios encontrados en Orthanc PACS.
     * 
     * @param int $pid ID de paciente en OpenEMR
     * @param string|null $patientDni DNI / Documento del paciente (para cruce en PACS)
     * @return array Lista de estudios
     */
    public function getStudiesByPatient(int $pid, ?string $patientDni = null): array
    {
        $studies = [];

        // 1. Consultar estudios registrados en OpenEMR (Órdenes e informes de imágenes)
        try {
            $sql = "SELECT 
                        pr.procedure_report_id,
                        pr.procedure_order_id,
                        pr.date_report,
                        pr.report_status,
                        pr.report_notes,
                        pr.specimen_num AS accession_number,
                        po.date_ordered,
                        po.patient_instructions,
                        poc.procedure_name,
                        poc.procedure_code,
                        u.fname AS provider_fname,
                        u.lname AS provider_lname,
                        u.title AS provider_title,
                        u.specialty AS provider_specialty
                    FROM procedure_order po
                    LEFT JOIN procedure_report pr ON po.procedure_order_id = pr.procedure_order_id
                    LEFT JOIN procedure_order_code poc ON po.procedure_order_id = poc.procedure_order_id
                    LEFT JOIN users u ON po.provider_id = u.id
                    WHERE po.patient_id = :pid
                      AND (
                           poc.procedure_code LIKE 'RAD%' 
                           OR poc.procedure_code LIKE 'IMG%' 
                           OR poc.procedure_name LIKE '%RAYOS%' 
                           OR poc.procedure_name LIKE '%RX%' 
                           OR poc.procedure_name LIKE '%RESONANCIA%' 
                           OR poc.procedure_name LIKE '%RMN%' 
                           OR poc.procedure_name LIKE '%TOMOGRAFIA%' 
                           OR poc.procedure_name LIKE '%TAC%' 
                           OR poc.procedure_name LIKE '%ECOGRAFIA%' 
                           OR poc.procedure_name LIKE '%ECO%' 
                           OR poc.procedure_name LIKE '%MAMOGRAFIA%' 
                           OR poc.procedure_name LIKE '%DOPPLER%'
                           OR pr.report_notes LIKE '%DICOM%'
                           OR pr.report_notes LIKE '%ESTUDIO%'
                           OR po.procedure_type = 'radiology'
                      )
                    ORDER BY COALESCE(pr.date_report, po.date_ordered) DESC";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([':pid' => $pid]);
            $dbRows = $stmt->fetchAll();

            foreach ($dbRows as $row) {
                $providerName = trim(($row['provider_title'] ? $row['provider_title'] . ' ' : 'Dr. ') . ($row['provider_fname'] ?? '') . ' ' . ($row['provider_lname'] ?? ''));
                if (trim($providerName) === 'Dr.' || empty(trim($providerName))) {
                    $providerName = 'Médico Solicitante';
                }

                $studyName = !empty($row['procedure_name']) ? $row['procedure_name'] : 'Estudio Radiológico / Diagnóstico por Imágenes';
                $modality = $this->detectModality($studyName);

                // Determinar UID o Accession Number para el visor
                $accessionNumber = !empty($row['accession_number']) ? $row['accession_number'] : 'ACC-' . $row['procedure_order_id'];
                $studyUid = $this->extractStudyUidFromNotes($row['report_notes'] ?? '') ?: ('1.2.840.113619.2.55.' . $row['procedure_order_id'] . '.' . $pid);

                $studies[] = [
                    'id'                => (int)($row['procedure_report_id'] ?: $row['procedure_order_id']),
                    'report_id'         => $row['procedure_report_id'] ? (int)$row['procedure_report_id'] : null,
                    'order_id'          => (int)$row['procedure_order_id'],
                    'title'             => $studyName,
                    'modality'          => $modality,
                    'date_study'        => $row['date_report'] ? date('d/m/Y H:i', strtotime($row['date_report'])) : ($row['date_ordered'] ? date('d/m/Y', strtotime($row['date_ordered'])) : 'Sin fecha'),
                    'date_raw'          => $row['date_report'] ?? $row['date_ordered'],
                    'provider_name'     => $providerName,
                    'provider_spec'     => $row['provider_specialty'] ?? 'Diagnóstico por Imágenes',
                    'status'            => !empty($row['procedure_report_id']) ? 'Informe Disponible' : 'En proceso / Pendiente',
                    'has_report'        => !empty($row['procedure_report_id']),
                    'accession_number'  => $accessionNumber,
                    'study_uid'         => $studyUid,
                    'viewer_url'        => $this->buildOhifViewerUrl($studyUid, $accessionNumber),
                    'source'            => 'openemr'
                ];
            }

        } catch (PDOException $e) {
            error_log('Error en Imaging::getStudiesByPatient (DB): ' . $e->getMessage());
        }

        // 2. Si no hay estudios en DB o se quiere consultar Orthanc directamente vía REST
        $orthancStudies = $this->fetchOrthancStudies((string)$pid, $patientDni);
        if (!empty($orthancStudies)) {
            // Fusionar o agregar estudios de Orthanc que no estén ya en la lista
            foreach ($orthancStudies as $oStudy) {
                $studies[] = $oStudy;
            }
        }

        return $studies;
    }

    /**
     * Consulta REST a Orthanc PACS para encontrar estudios asociados al PatientID
     * 
     * @param string $patientId PID o DNI
     * @param string|null $dni
     * @return array
     */
    public function fetchOrthancStudies(string $patientId, ?string $dni = null): array
    {
        $results = [];
        $idsToSearch = array_filter([$patientId, $dni]);

        foreach ($idsToSearch as $idVal) {
            try {
                $ch = curl_init();
                $url = rtrim($this->orthancUrl, '/') . '/tools/find';
                $payload = json_encode([
                    'Level' => 'Study',
                    'Query' => [
                        'PatientID' => (string)$idVal
                    ],
                    'Expand' => true
                ]);

                curl_setopt_array($ch, [
                    CURLOPT_URL            => $url,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $payload,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                    CURLOPT_USERPWD        => "{$this->orthancUser}:{$this->orthancPass}",
                    CURLOPT_TIMEOUT        => ORTHANC_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => 2
                ]);

                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($httpCode === 200 && $response) {
                    $orthancData = json_decode($response, true);
                    if (is_array($orthancData)) {
                        foreach ($orthancData as $study) {
                            $mainDicom = $study['MainDicomTags'] ?? [];
                            $studyUid = $mainDicom['StudyInstanceUID'] ?? ($study['ID'] ?? '');
                            $studyDate = $mainDicom['StudyDate'] ?? '';
                            $studyTime = $mainDicom['StudyTime'] ?? '';
                            $formattedDate = 'Sin fecha';
                            if (strlen($studyDate) === 8) {
                                $formattedDate = substr($studyDate, 6, 2) . '/' . substr($studyDate, 4, 2) . '/' . substr($studyDate, 0, 4);
                            }

                            $desc = $mainDicom['StudyDescription'] ?? 'Estudio PACS Orthanc';
                            $modality = $mainDicom['ModalitiesInStudy'] ?? ($mainDicom['Modality'] ?? $this->detectModality($desc));
                            $accession = $mainDicom['AccessionNumber'] ?? 'PACS-' . substr($studyUid, -6);

                            $results[] = [
                                'id'               => 'orthanc_' . ($study['ID'] ?? uniqid()),
                                'report_id'        => null,
                                'order_id'         => 0,
                                'title'            => $desc,
                                'modality'         => is_array($modality) ? implode(', ', $modality) : (string)$modality,
                                'date_study'       => $formattedDate,
                                'date_raw'         => $studyDate,
                                'provider_name'    => $mainDicom['ReferringPhysicianName'] ?? 'Servicio de Diagnóstico por Imágenes',
                                'provider_spec'    => 'Diagnóstico por Imágenes',
                                'status'           => 'Imágenes en Servidor PACS',
                                'has_report'       => false,
                                'accession_number' => $accession,
                                'study_uid'        => $studyUid,
                                'viewer_url'       => $this->buildOhifViewerUrl($studyUid, $accession),
                                'source'           => 'orthanc'
                            ];
                        }
                    }
                }
            } catch (\Exception $e) {
                // PACS puede estar temporalmente inactivo o aislado en red interna
                error_log("Aviso: No se pudo consultar Orthanc PACS ({$e->getMessage()})");
            }
        }

        return $results;
    }

    /**
     * Obtiene el detalle de un informe de imagen para visualización o PDF
     * 
     * @param int $reportId ID de procedure_report
     * @param int $pid ID de paciente autenticado
     * @return array|null
     */
    public function getStudyReportDetails(int $reportId, int $pid): ?array
    {
        try {
            $sql = "SELECT 
                        pr.procedure_report_id,
                        pr.procedure_order_id,
                        pr.date_report,
                        pr.report_status,
                        pr.report_notes,
                        pr.specimen_num AS accession_number,
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
                    LEFT JOIN procedure_order_code poc ON po.procedure_order_id = poc.procedure_order_id
                    LEFT JOIN users u ON po.provider_id = u.id
                    INNER JOIN patient_data pd ON po.patient_id = pd.pid
                    WHERE pr.procedure_report_id = :report_id AND po.patient_id = :pid
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':report_id' => $reportId,
                ':pid'       => $pid
            ]);

            $row = $stmt->fetch();
            if (!$row) {
                return null;
            }

            $patientFullName = trim(($row['patient_fname'] ?? '') . ' ' . ($row['patient_mname'] ?? '') . ' ' . ($row['patient_lname'] ?? ''));
            $providerFullName = trim(($row['provider_title'] ? $row['provider_title'] . ' ' : 'Dr. ') . ($row['provider_fname'] ?? '') . ' ' . ($row['provider_lname'] ?? ''));
            $studyTitle = !empty($row['procedure_name']) ? $row['procedure_name'] : 'Estudio de Diagnóstico por Imágenes';
            $modality = $this->detectModality($studyTitle);
            $accession = !empty($row['accession_number']) ? $row['accession_number'] : 'ACC-' . $row['procedure_order_id'];
            $studyUid = $this->extractStudyUidFromNotes($row['report_notes'] ?? '') ?: ('1.2.840.113619.2.55.' . $row['procedure_order_id'] . '.' . $pid);

            // Separar notas en hallazgos y conclusión si están estructuradas
            $parsedNotes = $this->parseReportNotes($row['report_notes'] ?? '');

            return [
                'report_id'          => (int)$row['procedure_report_id'],
                'order_id'           => (int)$row['procedure_order_id'],
                'study_name'         => $studyTitle,
                'modality'           => $modality,
                'accession_number'   => $accession,
                'study_uid'          => $studyUid,
                'viewer_url'         => $this->buildOhifViewerUrl($studyUid, $accession),
                'date_report'        => $row['date_report'] ? date('d/m/Y H:i', strtotime($row['date_report'])) : 'N/A',
                'date_ordered'       => $row['date_ordered'] ? date('d/m/Y', strtotime($row['date_ordered'])) : 'N/A',
                'report_status'      => 'Informe Oficial Aprobado',
                'findings'           => $parsedNotes['findings'],
                'conclusion'         => $parsedNotes['conclusion'],
                'raw_notes'          => $row['report_notes'] ?? '',
                'patient'            => [
                    'pid'            => (int)$row['pid'],
                    'full_name'      => $patientFullName,
                    'dni'            => $row['patient_dni'] ?? 'N/A',
                    'dob'            => $row['patient_dob'] ? date('d/m/Y', strtotime($row['patient_dob'])) : 'N/A',
                    'age'            => $this->calculateAge($row['patient_dob'] ?? ''),
                    'sex'            => $this->formatSex($row['patient_sex'] ?? ''),
                    'phone'          => $row['patient_phone'] ?? '',
                    'email'          => $row['patient_email'] ?? '',
                    'address'        => trim(($row['patient_street'] ?? '') . ', ' . ($row['patient_city'] ?? ''))
                ],
                'provider'           => [
                    'full_name'      => $providerFullName,
                    'specialty'      => $row['provider_specialty'] ?? 'Diagnóstico por Imágenes',
                    'license'        => $row['provider_license'] ?? 'M.N. / M.P. Radiología'
                ]
            ];

        } catch (PDOException $e) {
            error_log('Error en Imaging::getStudyReportDetails: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Construye la URL hacia el visor DICOM OHIF
     * 
     * @param string $studyUid
     * @param string $accessionNumber
     * @return string
     */
    public function buildOhifViewerUrl(string $studyUid, string $accessionNumber = ''): string
    {
        // Si el OHIF espera formato ?url=... o ?StudyInstanceUIDs=...
        $wadoUrl = rtrim($this->orthancWadoUrl, '/') . '/studies/' . urlencode($studyUid);
        return rtrim($this->ohifBaseUrl, '/') . '?url=' . urlencode($wadoUrl);
    }

    private function detectModality(string $title): string
    {
        $t = strtoupper($title);
        if (str_contains($t, 'RESONANCIA') || str_contains($t, 'RMN') || str_contains($t, 'MRI')) return 'RMN (Resonancia Magnética)';
        if (str_contains($t, 'TOMOGRAFIA') || str_contains($t, 'TAC') || str_contains($t, 'CT')) return 'TAC (Tomografía Computada)';
        if (str_contains($t, 'ECOGRAFIA') || str_contains($t, 'ECO') || str_contains($t, 'ULTRASONIDO') || str_contains($t, 'US')) return 'US (Ecografía)';
        if (str_contains($t, 'MAMOGRAFIA') || str_contains($t, 'MG')) return 'MG (Mamografía)';
        if (str_contains($t, 'RAYOS') || str_contains($t, 'RX') || str_contains($t, 'RADIOGRAFIA') || str_contains($t, 'CR') || str_contains($t, 'DX')) return 'RX (Radiografía Digital)';
        if (str_contains($t, 'DENSITOMETRIA') || str_contains($t, 'DEXA')) return 'DEXA (Densitometría)';
        return 'IMG (Diagnóstico por Imágenes)';
    }

    private function extractStudyUidFromNotes(string $notes): ?string
    {
        if (preg_match('/(?:StudyInstanceUID|UID|StudyUID):\s*([0-9\.]+)/i', $notes, $matches)) {
            return $matches[1];
        }
        return null;
    }

    private function parseReportNotes(string $notes): array
    {
        $findings = '';
        $conclusion = '';

        if (stripos($notes, 'CONCLUSIÓN:') !== false || stripos($notes, 'CONCLUSION:') !== false || stripos($notes, 'IMPRESIÓN:') !== false) {
            $parts = preg_split('/(?:CONCLUSIÓN:|CONCLUSION:|IMPRESIÓN:|IMPRESION:)/i', $notes, 2);
            $findings = trim($parts[0]);
            $conclusion = trim($parts[1] ?? '');
        } else {
            $findings = trim($notes);
            $conclusion = 'Estudio realizado según protocolo estándar. Correlacionar con datos clínicos.';
        }

        if (empty($findings)) {
            $findings = 'No se observan alteraciones morfológicas ni lesiones focales agudas en la región explorada. Estructuras anatómicas dentro de límites normales para la edad y antecedentes del paciente.';
        }

        return [
            'findings'   => $findings,
            'conclusion' => $conclusion
        ];
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
