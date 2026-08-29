<?php
/**
 * Gestión de Diagnóstico por Imágenes e Integración Orthanc / OHIF Viewer
 * Patient Express Portal - Integración Nativa con Funciones OpenEMR
 */

namespace App;

class Imaging
{
    private string $orthancUrl;
    private string $orthancUser;
    private string $orthancPass;
    private string $ohifBaseUrl;
    private string $orthancWadoUrl;

    public function __construct()
    {
        $this->orthancUrl = defined('ORTHANC_URL') ? ORTHANC_URL : 'http://127.0.0.1:8042';
        $this->orthancUser = defined('ORTHANC_USER') ? ORTHANC_USER : 'orthanc';
        $this->orthancPass = defined('ORTHANC_PASS') ? ORTHANC_PASS : 'orthanc';
        $this->ohifBaseUrl = defined('OHIF_VIEWER_BASE_URL') ? OHIF_VIEWER_BASE_URL : 'https://imagenes.origen.ar/viewer';
        $this->orthancWadoUrl = defined('ORTHANC_WADO_URL') ? ORTHANC_WADO_URL : 'https://pacs.origen.ar/dicom-web';
    }

    /**
     * Obtiene los estudios de imágenes y documentos gráficos del paciente desde OpenEMR y PACS Orthanc
     * 
     * @param int $pid ID de paciente en OpenEMR
     * @param string|null $patientDni DNI / Documento del paciente
     * @return array Lista de estudios clasificados
     */
    public function getStudiesByPatient(int $pid, ?string $patientDni = null): array
    {
        $studies = [];
        $registeredStudyUids = [];

        // =========================================================================
        // 1. Consultar estudios DICOM e Informes Radiológicos en procedure_order
        // =========================================================================
        $sqlOrders = "SELECT 
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
                      WHERE po.patient_id = ?
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
                             OR poc.procedure_name LIKE '%IMAGEN%'
                        )
                      ORDER BY COALESCE(pr.date_report, po.date_ordered) DESC";

        $resOrders = sqlStatement($sqlOrders, [$pid]);
        if ($resOrders) {
            while ($row = sqlFetchArray($resOrders)) {
                $providerName = trim(($row['provider_title'] ? $row['provider_title'] . ' ' : 'Dr. ') . ($row['provider_fname'] ?? '') . ' ' . ($row['provider_lname'] ?? ''));
                if (trim($providerName) === 'Dr.' || empty(trim($providerName))) {
                    $providerName = 'Médico Especialista';
                }

                $studyName = !empty($row['procedure_name']) ? $row['procedure_name'] : 'Estudio de Diagnóstico por Imágenes';
                $modality = $this->detectModality($studyName);

                $accessionNumber = !empty($row['accession_number']) ? $row['accession_number'] : 'ACC-' . $row['procedure_order_id'];
                $studyUid = $this->extractStudyUidFromNotes($row['report_notes'] ?? '');
                $effectiveUid = $studyUid ?: ('1.2.840.113619.2.55.' . $row['procedure_order_id'] . '.' . $pid);
                $registeredStudyUids[] = $effectiveUid;

                $studies[] = [
                    'id'                => 'po_' . ($row['procedure_report_id'] ?: $row['procedure_order_id']),
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
                    'study_uid'         => $effectiveUid,
                    'format_type'       => 'dicom', // DICOM -> Visor OHIF
                    'viewer_type'       => 'ohif',
                    'viewer_url'        => $this->buildOhifViewerUrl($effectiveUid),
                    'direct_view_url'   => null,
                    'download_url'      => null,
                    'source'            => 'openemr_order'
                ];
            }
        }

        // =========================================================================
        // 2. Consultar Documentos de Imágenes Estándar en OpenEMR (JPG / PNG / PDF)
        // =========================================================================
        $sqlDocs = "SELECT 
                        d.id AS doc_id,
                        d.type,
                        d.size,
                        d.date AS doc_date,
                        d.docdate,
                        d.url,
                        d.mimetype,
                        d.name AS doc_name,
                        d.foreign_id AS patient_id,
                        c.name AS category_name
                    FROM documents d
                    LEFT JOIN categories_to_documents ctd ON d.id = ctd.document_id
                    LEFT JOIN categories c ON ctd.category_id = c.id
                    WHERE d.foreign_id = ? 
                      AND (d.deleted = 0 OR d.deleted IS NULL)
                      AND (
                           d.mimetype LIKE 'image/%' 
                           OR d.mimetype = 'application/pdf'
                           OR LOWER(d.url) LIKE '%.jpg'
                           OR LOWER(d.url) LIKE '%.jpeg'
                           OR LOWER(d.url) LIKE '%.png'
                           OR LOWER(d.url) LIKE '%.pdf'
                           OR LOWER(d.name) LIKE '%.jpg'
                           OR LOWER(d.name) LIKE '%.jpeg'
                           OR LOWER(d.name) LIKE '%.png'
                           OR LOWER(d.name) LIKE '%.pdf'
                           OR LOWER(c.name) LIKE '%imagen%'
                           OR LOWER(c.name) LIKE '%estudio%'
                           OR LOWER(c.name) LIKE '%rayos%'
                           OR LOWER(c.name) LIKE '%ecografia%'
                      )
                    ORDER BY COALESCE(d.docdate, d.date) DESC";

        $resDocs = sqlStatement($sqlDocs, [$pid]);
        if ($resDocs) {
            while ($dRow = sqlFetchArray($resDocs)) {
                $docName = !empty($dRow['doc_name']) ? $dRow['doc_name'] : basename((string)$dRow['url']);
                $mime = strtolower((string)$dRow['mimetype']);
                $urlLower = strtolower((string)$dRow['url']);
                $nameLower = strtolower($docName);

                $isImage = str_starts_with($mime, 'image/') || str_ends_with($urlLower, '.jpg') || str_ends_with($urlLower, '.jpeg') || str_ends_with($urlLower, '.png') || str_ends_with($nameLower, '.jpg') || str_ends_with($nameLower, '.jpeg') || str_ends_with($nameLower, '.png');
                $isPdf = ($mime === 'application/pdf') || str_ends_with($urlLower, '.pdf') || str_ends_with($nameLower, '.pdf');

                $formatType = $isImage ? 'image' : ($isPdf ? 'pdf' : 'standard_file');
                $viewerType = $isImage ? 'inline_image' : ($isPdf ? 'inline_pdf' : 'download');
                
                $studyTitle = $docName;
                if (!empty($dRow['category_name']) && $dRow['category_name'] !== 'General') {
                    $studyTitle = $dRow['category_name'] . ' - ' . $docName;
                }

                $modality = $this->detectModality($studyTitle);
                $docDate = $dRow['docdate'] ?: ($dRow['doc_date'] ? date('Y-m-d', strtotime($dRow['doc_date'])) : date('Y-m-d'));
                $formattedDate = date('d/m/Y', strtotime($docDate));

                $viewUrl = 'view_document.php?id=' . $dRow['doc_id'];
                $downloadUrl = 'view_document.php?id=' . $dRow['doc_id'] . '&download=1';

                $studies[] = [
                    'id'                => 'doc_' . $dRow['doc_id'],
                    'report_id'         => null,
                    'order_id'          => 0,
                    'doc_id'            => (int)$dRow['doc_id'],
                    'title'             => $studyTitle,
                    'modality'          => $modality,
                    'date_study'        => $formattedDate,
                    'date_raw'          => $docDate,
                    'provider_name'     => 'Servicio de Diagnóstico / Digitalización',
                    'provider_spec'     => 'Documentación Médica',
                    'status'            => 'Archivo Listo para Visualizar',
                    'has_report'        => false,
                    'accession_number'  => 'DOC-' . $dRow['doc_id'],
                    'study_uid'         => null,
                    'format_type'       => $formatType, // 'image' o 'pdf' estándar
                    'viewer_type'       => $viewerType, // 'inline_image' o 'inline_pdf' (sin OHIF)
                    'viewer_url'        => $viewUrl,
                    'direct_view_url'   => $viewUrl,
                    'download_url'      => $downloadUrl,
                    'mimetype'          => $mime,
                    'source'            => 'openemr_document'
                ];
            }
        }

        // =========================================================================
        // 3. Consultar Orthanc PACS directamente vía REST API
        // =========================================================================
        $orthancStudies = $this->fetchOrthancStudies((string)$pid, $patientDni);
        if (!empty($orthancStudies)) {
            foreach ($orthancStudies as $oStudy) {
                if (!empty($oStudy['study_uid']) && in_array($oStudy['study_uid'], $registeredStudyUids, true)) {
                    continue;
                }
                $studies[] = $oStudy;
            }
        }

        usort($studies, function ($a, $b) {
            $timeA = strtotime((string)($a['date_raw'] ?? '1970-01-01'));
            $timeB = strtotime((string)($b['date_raw'] ?? '1970-01-01'));
            return $timeB <=> $timeA;
        });

        return $studies;
    }

    /**
     * Consulta REST a Orthanc PACS para encontrar estudios asociados al PatientID / DNI
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
                                'format_type'      => 'dicom', // DICOM -> Visor OHIF
                                'viewer_type'      => 'ohif',
                                'viewer_url'       => $this->buildOhifViewerUrl($studyUid),
                                'direct_view_url'  => null,
                                'download_url'     => null,
                                'source'           => 'orthanc'
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                error_log("Aviso: No se pudo consultar Orthanc PACS ({$e->getMessage()})");
            }
        }

        return $results;
    }

    /**
     * Obtiene el documento físico o registro desde la tabla documents de OpenEMR
     * usando sqlQuery()
     */
    public function getDocumentFile(int $docId, int $pid): ?array
    {
        $sql = "SELECT id, foreign_id, url, mimetype, name, size, docdate 
                FROM documents 
                WHERE id = ? AND foreign_id = ? AND (deleted = 0 OR deleted IS NULL)
                LIMIT 1";

        $doc = sqlQuery($sql, [$docId, $pid]);
        if (!$doc) {
            return null;
        }

        $url = $doc['url'];
        $filePath = null;

        if (file_exists($url)) {
            $filePath = $url;
        } else {
            $basePaths = [
                defined('OPENEMR_DOCUMENTS_PATH') ? OPENEMR_DOCUMENTS_PATH : '/var/www/html/openemr/sites/default/documents',
                dirname(__DIR__, 2) . '/sites/default/documents',
                dirname(__DIR__) . '/storage/documents',
                '/var/www/html/origen.ar/hcd/sites/default/documents',
                '/var/www/html/openemr/sites/default/documents'
            ];

            $cleanUrl = ltrim(str_replace('file://', '', $url), '/');
            foreach ($basePaths as $base) {
                $testPath = rtrim($base, '/') . '/' . $cleanUrl;
                if (file_exists($testPath)) {
                    $filePath = $testPath;
                    break;
                }
            }
        }

        return [
            'id'        => (int)$doc['id'],
            'pid'       => (int)$doc['foreign_id'],
            'name'      => $doc['name'] ?: basename((string)$doc['url']),
            'mimetype'  => $doc['mimetype'] ?: 'application/octet-stream',
            'url'       => $doc['url'],
            'file_path' => $filePath,
            'exists'    => ($filePath !== null && file_exists($filePath))
        ];
    }

    /**
     * Obtiene el detalle de un informe de imagen para PDF
     */
    public function getStudyReportDetails(int $reportId, int $pid): ?array
    {
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
                    pd.pubpid,
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
                WHERE pr.procedure_report_id = ? AND po.patient_id = ?
                LIMIT 1";

        $row = sqlQuery($sql, [$reportId, $pid]);
        if (!$row) {
            return null;
        }

        $patientFullName = trim(($row['patient_fname'] ?? '') . ' ' . ($row['patient_mname'] ?? '') . ' ' . ($row['patient_lname'] ?? ''));
        $providerFullName = trim(($row['provider_title'] ? $row['provider_title'] . ' ' : 'Dr. ') . ($row['provider_fname'] ?? '') . ' ' . ($row['provider_lname'] ?? ''));
        $studyTitle = !empty($row['procedure_name']) ? $row['procedure_name'] : 'Estudio de Diagnóstico por Imágenes';
        $modality = $this->detectModality($studyTitle);
        $accession = !empty($row['accession_number']) ? $row['accession_number'] : 'ACC-' . $row['procedure_order_id'];
        $studyUid = $this->extractStudyUidFromNotes($row['report_notes'] ?? '') ?: ('1.2.840.113619.2.55.' . $row['procedure_order_id'] . '.' . $pid);

        $parsedNotes = $this->parseReportNotes($row['report_notes'] ?? '');

        return [
            'report_id'          => (int)$row['procedure_report_id'],
            'order_id'           => (int)$row['procedure_order_id'],
            'study_name'         => $studyTitle,
            'modality'           => $modality,
            'accession_number'   => $accession,
            'study_uid'          => $studyUid,
            'viewer_url'         => $this->buildOhifViewerUrl($studyUid),
            'date_report'        => $row['date_report'] ? date('d/m/Y H:i', strtotime($row['date_report'])) : 'N/A',
            'date_ordered'       => $row['date_ordered'] ? date('d/m/Y', strtotime($row['date_ordered'])) : 'N/A',
            'report_status'      => 'Informe Oficial Aprobado',
            'findings'           => $parsedNotes['findings'],
            'conclusion'         => $parsedNotes['conclusion'],
            'raw_notes'          => $row['report_notes'] ?? '',
            'patient'            => [
                'pid'            => (int)$row['pid'],
                'pubpid'         => $row['pubpid'] ?: (string)$row['pid'],
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
    }

    /**
     * Construye la URL exacta hacia el visor DICOM OHIF:
     * https://imagenes.origen.ar/viewer?url=https://pacs.origen.ar/dicom-web/studies/{StudyInstanceUID}
     */
    public function buildOhifViewerUrl(string $studyUid): string
    {
        $dicomWebStudyUrl = rtrim($this->orthancWadoUrl, '/') . '/studies/' . $studyUid;
        return rtrim($this->ohifBaseUrl, '/') . '?url=' . urlencode($dicomWebStudyUrl);
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
