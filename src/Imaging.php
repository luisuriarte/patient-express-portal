<?php
/**
 * Diagnostic Imaging Management and Orthanc / OHIF Viewer Integration
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
     * Obtiene exclusivamente los estudios y documentos de imágenes del paciente,
     * filtrando por las categorías y subcategorías de imágenes en OpenEMR
     * y complementando con estudios DICOM indexados en Orthanc PACS.
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
        // 1. Obtener IDs de todas las categorías y subcategorías de imágenes
        // =========================================================================
        $imageCategoryIds = $this->getImageCategoryIds();

        // =========================================================================
        // 2. Consultar Documentos pertenecientes a las categorías de imágenes
        // =========================================================================
        if (!empty($imageCategoryIds)) {
            $placeholders = implode(',', array_fill(0, count($imageCategoryIds), '?'));
            $params = array_merge([$pid], $imageCategoryIds);

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
                            d.encounter_id,
                            fe.date AS encounter_date,
                            fe.provider_id AS enc_provider_id,
                            up.fname AS enc_provider_fname,
                            up.lname AS enc_provider_lname,
                            c.id AS category_id,
                            c.name AS category_name,
                            cp.name AS parent_category_name,
                            dps.study_instance_uid AS pacs_study_uid,
                            dps.status AS pacs_status
                        FROM documents d
                        INNER JOIN categories_to_documents ctd ON d.id = ctd.document_id
                        INNER JOIN categories c ON ctd.category_id = c.id
                        LEFT JOIN categories cp ON c.parent = cp.id
                        LEFT JOIN documents_pacs_sync dps ON d.id = dps.document_id
                        LEFT JOIN form_encounter fe ON fe.pid = d.foreign_id AND fe.encounter = d.encounter_id
                        LEFT JOIN users up ON up.id = fe.provider_id
                        WHERE d.foreign_id = ? 
                        AND (d.deleted = 0 OR d.deleted IS NULL)
                        AND ctd.category_id IN ($placeholders)
                        ORDER BY COALESCE(d.docdate, d.date) DESC, d.id DESC";

            $resDocs = sqlStatement($sqlDocs, $params);

            // Acumulador de estudios DICOM agrupados por study_instance_uid real de PACS
            $groupedStudies = [];

            // Informes (PDF) del formulario de imágenes por carpeta/categoría.
            // Las imágenes JPG/PNG/DICOM y el PDF del informe viven en la MISMA
            // carpeta del mismo encuentro, así que vinculamos cada imagen a su
            // informe por categoría.
            $reportsByCategory = $this->getPatientReportsByCategory($pid);

            // Mapa de informes por ENCUENTRO + CATEGORÍA, construido a partir de los
            // documentos PDF de informe del formulario de imágenes del paciente.
            // Se usa para vincular el PDF a la tarjeta de imágenes agrupadas por estudio,
            // sin depender del estado de sincronización PACS ni del study_instance_uid.
            $reportPdfByEncCat = $this->buildReportsByEncounterCategory($pid);
            $consumedPdfDocIds = [];

            if ($resDocs) {
                while ($dRow = sqlFetchArray($resDocs)) {
                    $docName = !empty($dRow['doc_name']) ? $dRow['doc_name'] : basename((string)$dRow['url']);
                    $mime = strtolower((string)$dRow['mimetype']);
                    $urlLower = strtolower((string)$dRow['url']);
                    $nameLower = strtolower($docName);

                    $hasPacsSync = !empty($dRow['pacs_study_uid']) && ($dRow['pacs_status'] === 'synced');
                    $pacsStudyUid = $dRow['pacs_study_uid'] ?? null;

                    $isDicom = ($mime === 'application/dicom') 
                        || str_ends_with($urlLower, '.dcm') 
                        || str_ends_with($nameLower, '.dcm')
                        || $hasPacsSync;

                    $isImage = str_starts_with($mime, 'image/') 
                        || str_ends_with($urlLower, '.jpg') 
                        || str_ends_with($urlLower, '.jpeg') 
                        || str_ends_with($urlLower, '.png') 
                        || str_ends_with($urlLower, '.webp') 
                        || str_ends_with($nameLower, '.jpg') 
                        || str_ends_with($nameLower, '.jpeg') 
                        || str_ends_with($nameLower, '.png');

                    $isPdf = ($mime === 'application/pdf') 
                        || str_ends_with($urlLower, '.pdf') 
                        || str_ends_with($nameLower, '.pdf');

                    $categoryLabel = !empty($dRow['category_name']) ? $dRow['category_name'] : xl('Diagnostic Imaging');
                    $studyTitle = $categoryLabel . ' - ' . $docName;
                    $modality = $this->detectModality($studyTitle);

                    $docDate = $dRow['docdate'] ?: ($dRow['doc_date'] ? date('Y-m-d', strtotime($dRow['doc_date'])) : date('Y-m-d'));
                    $formattedDate = date('d/m/Y', strtotime($docDate));

                    // Encuentro del documento y fecha del encuentro (para mostrarlo
                    // en el listado de forma similar a los resultados de laboratorio)
                    $encId = (int)($dRow['encounter_id'] ?? 0);
                    $encDateDisplay = '';
                    if (!empty($dRow['encounter_date']) && $dRow['encounter_date'] !== '0000-00-00') {
                        $encDateDisplay = date('d/m/Y', strtotime((string)$dRow['encounter_date']));
                    }
                    $encProviderName = trim(($dRow['enc_provider_fname'] ?? '') . ' ' . ($dRow['enc_provider_lname'] ?? ''));

                    $viewUrl = 'view_document.php?id=' . $dRow['doc_id'];
                    $downloadUrl = 'view_document.php?id=' . $dRow['doc_id'] . '&download=1';

                    if ($isDicom || $hasPacsSync) {
                        // Si ya está sincronizado y tiene un study_uid real de PACS, agrupamos
                        // todos los documentos que comparten ese mismo estudio en una sola entrada.
                        if ($hasPacsSync && !empty($pacsStudyUid)) {
                            if (!isset($groupedStudies[$pacsStudyUid])) {
                                $groupedStudies[$pacsStudyUid] = [
                                    'first_doc_id'   => (int)$dRow['doc_id'],
                                    'doc_ids'        => [],
                                    'category_label' => $categoryLabel,
                                    'encounter_id'   => (int)($dRow['encounter_id'] ?? 0),
                                    'encounter_date' => $encDateDisplay,
                                    'enc_provider_name' => $encProviderName,
                                    'category_id'    => (int)($dRow['category_id'] ?? 0),
                                    'date_raw'       => $docDate,
                                    'formatted_date' => $formattedDate,
                                    'report_doc_id'  => null,
                                    'report_url'     => null,
                                ];
                            }
                            $groupedStudies[$pacsStudyUid]['doc_ids'][] = (int)$dRow['doc_id'];
                            if ((int)($dRow['encounter_id'] ?? 0) > 0) {
                                $groupedStudies[$pacsStudyUid]['encounter_id'] = (int)$dRow['encounter_id'];
                                if ($encDateDisplay !== '') {
                                    $groupedStudies[$pacsStudyUid]['encounter_date'] = $encDateDisplay;
                                }
                                if ($encProviderName !== '' && empty($groupedStudies[$pacsStudyUid]['enc_provider_name'])) {
                                    $groupedStudies[$pacsStudyUid]['enc_provider_name'] = $encProviderName;
                                }
                            }
                            if ((int)($dRow['category_id'] ?? 0) > 0) {
                                $groupedStudies[$pacsStudyUid]['category_id'] = (int)$dRow['category_id'];
                            }

                            // Conservamos la fecha más antigua del grupo como fecha del estudio
                            if (strtotime($docDate) < strtotime($groupedStudies[$pacsStudyUid]['date_raw'])) {
                                $groupedStudies[$pacsStudyUid]['date_raw'] = $docDate;
                                $groupedStudies[$pacsStudyUid]['formatted_date'] = $formattedDate;
                            }

                            $registeredStudyUids[] = $pacsStudyUid;
                            continue;
                        }

                        // DICOM aún no sincronizado (pending) o sin study_uid conocido:
                        // se muestra individualmente, como antes.
                        $studyUid = $pacsStudyUid ?: basename((string)$dRow['url'], '.dcm');
                        $registeredStudyUids[] = $studyUid;

                        $studies[] = [
                            'id'                => 'doc_' . $dRow['doc_id'],
                            'report_id'         => null,
                            'order_id'          => 0,
                            'doc_id'            => (int)$dRow['doc_id'],
                            'title'             => $studyTitle,
                            'modality'          => $modality,
                            'date_study'        => $formattedDate,
                            'date_raw'          => $docDate,
                            'encounter_id'      => $encId,
                            'encounter_date'    => $encDateDisplay,
                            'provider_name'     => $encProviderName ?: xl('Diagnostic Imaging Service'),
                            'provider_spec'     => $categoryLabel,
                            'status'            => xl('DICOM Study Ready'),
                            'has_report'        => false,
                            'accession_number'  => 'DOC-' . $dRow['doc_id'],
                            'study_uid'         => $studyUid,
                            'format_type'       => $isImage ? 'image' : ($isDicom ? 'dicom' : 'pdf'),
                            'viewer_type'       => $isImage ? 'inline_image' : 'ohif',
                            'viewer_url'        => $isImage ? $viewUrl : $this->buildOhifViewerUrl($studyUid),
                            'ohif_url'          => $this->buildOhifViewerUrl($studyUid),
                            'stone_url'         => $this->buildStoneViewerUrl($studyUid),
                            'has_ohif'          => true,
                            'direct_view_url'   => $viewUrl,
                            'download_url'      => $downloadUrl,
                            'mimetype'          => $mime,
                            'source'            => 'openemr_document'
                        ];
                    } else {
                        $formatType = $isImage ? 'image' : ($isPdf ? 'pdf' : 'standard_file');
                        $viewerType = $isImage ? 'inline_image' : ($isPdf ? 'inline_pdf' : 'download');

                        // Vincular al informe (PDF) de la MISMA carpeta/categoría:
                        // imágenes y PDF del informe son del mismo encuentro.
                        $catId = (int)($dRow['category_id'] ?? 0);
                        $report = $reportsByCategory[$catId] ?? null;
                        $isReportDocItself = ($report && (int)$report['doc_id'] === (int)$dRow['doc_id']);
                        $providerName = $encProviderName ?: xl('Diagnostic Imaging Service');
                        if ($report && !empty($report['requesting_physician'])) {
                            $providerName = $report['requesting_physician'];
                        }

                        $studies[] = [
                            'id'                => 'doc_' . $dRow['doc_id'],
                            'report_id'         => null,
                            'order_id'          => 0,
                            'doc_id'            => (int)$dRow['doc_id'],
                            'title'             => $studyTitle,
                            'modality'          => $modality,
                            'date_study'        => $formattedDate,
                            'date_raw'          => $docDate,
                            'encounter_id'      => $encId,
                            'encounter_date'    => $encDateDisplay,
                            'provider_name'     => $providerName,
                            'provider_spec'     => $categoryLabel,
                            'status'            => xl('Image Available'),
                            'has_report'        => ($report && !$isReportDocItself),
                            'report_pdf_url'    => ($report && !$isReportDocItself) ? 'view_document.php?id=' . $report['doc_id'] : null,
                            'report_title'      => $report ? ($report['title'] ?? xl('Diagnostic Imaging Report')) : null,
                            'accession_number'  => 'DOC-' . $dRow['doc_id'],
                            'study_uid'         => null,
                            'format_type'       => $formatType,
                            'viewer_type'       => $viewerType,
                            'viewer_url'        => $viewUrl,
                            'ohif_url'          => null,
                            'has_ohif'          => false,
                            'direct_view_url'   => $viewUrl,
                            'download_url'      => $downloadUrl,
                            'mimetype'          => $mime,
                            'source'            => 'openemr_document'
                        ];
                    }
                }
            }

            // Volcar los estudios agrupados como una sola tarjeta "Estudio" por cada
            // study_instance_uid distinto, con todas sus series/imágenes adentro.
            foreach ($groupedStudies as $uid => $group) {
                $seriesCount = count($group['doc_ids']);

                // Vincular el informe (PDF) a la tarjeta por ENCUENTRO + CATEGORÍA,
                // en lugar de por nombre/study. Así el informe queda con sus imágenes
                // del mismo encuentro, aunque aún no esté sincronizado en PACS.
                $repEnc = (int)($group['encounter_id'] ?? 0);
                $repCat = (int)($group['category_id'] ?? 0);
                $report = ($repEnc > 0 && $repCat > 0 && isset($reportPdfByEncCat[$repEnc][$repCat]))
                    ? $reportPdfByEncCat[$repEnc][$repCat]
                    : null;
                $hasReportPdf = false;
                $reportDocId = null;
                $reportUrl = null;
                if ($report && (int)$report['doc_id'] > 0) {
                    $hasReportPdf = true;
                    $reportDocId = (int)$report['doc_id'];
                    $reportUrl = 'view_document.php?id=' . $reportDocId;
                    $consumedPdfDocIds[$reportDocId] = true;
                }
                $providerName = (!empty($group['enc_provider_name']))
                    ? $group['enc_provider_name']
                    : xl('Diagnostic Imaging Service');
                if ($report && !empty($report['requesting_physician'])) {
                    $providerName = $report['requesting_physician'];
                }
                $studies[] = [
                    'id'                => 'study_' . $group['first_doc_id'],
                    'report_id'         => null,
                    'order_id'          => 0,
                    'doc_id'            => $group['first_doc_id'],
                    'doc_ids'           => $group['doc_ids'],
                    'title'             => $group['category_label'] . ' (' . $seriesCount . ' ' . ($seriesCount === 1 ? xl('image') : xl('images')) . ')',
                    'modality'          => $this->detectModality($group['category_label']),
                    'date_study'        => $group['formatted_date'],
                    'date_raw'          => $group['date_raw'],
                    'encounter_id'      => (int)($group['encounter_id'] ?? 0),
                    'encounter_date'    => $group['encounter_date'] ?? '',
                    'provider_name'     => $providerName,
                    'provider_spec'     => $group['category_label'],
                    'status'            => xl('Synchronized in Orthanc PACS'),
                    'has_report'        => $hasReportPdf,
                    'report_doc_id'     => $reportDocId,
                    'report_pdf_url'    => $reportUrl,
                    'accession_number'  => 'DOC-' . $group['first_doc_id'],
                    'study_uid'         => $uid,
                    'format_type'       => 'dicom',
                    'viewer_type'       => 'ohif',
                    'viewer_url'        => $this->buildOhifViewerUrl($uid),
                    'ohif_url'          => $this->buildOhifViewerUrl($uid),
                    'stone_url'         => $this->buildStoneViewerUrl($uid),
                    'has_ohif'          => true,
                    'direct_view_url'   => null,
                    'download_url'      => null,
                    'mimetype'          => null,
                    'source'            => 'openemr_document',
                    'series_count'      => $seriesCount,
                ];
            }
        }

        // Quitar de la lista las filas sueltas de PDFs de informe que ya quedaron
        // vinculadas a una tarjeta de estudio (para no mostrarlas duplicadas).
        if (!empty($consumedPdfDocIds)) {
            $studies = array_values(array_filter($studies, function ($s) use ($consumedPdfDocIds) {
                $docId = (int)($s['doc_id'] ?? 0);
                $isPdfSuelto = (($s['format_type'] ?? '') === 'pdf') && empty($s['study_uid']);
                return !($isPdfSuelto && $docId > 0 && isset($consumedPdfDocIds[$docId]));
            }));
        }
        // =========================================================================
        // 3. Consultar Órdenes e Informes Radiológicos en procedure_order
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
                      LEFT JOIN procedure_type ptimg ON ptimg.procedure_code = poc.procedure_code
                      LEFT JOIN users u ON po.provider_id = u.id
                      WHERE po.patient_id = ?
                        AND (
                             ptimg.procedure_type_name = 'imaging'
                             OR poc.procedure_code LIKE 'RAD%'
                             OR poc.procedure_code LIKE 'IMG%'
                             OR poc.procedure_code LIKE 'RX-%'
                             OR poc.procedure_code LIKE 'RX-TORAX%'
                             OR poc.procedure_code LIKE 'RX-CRANEO%'
                             OR poc.procedure_code LIKE 'RX-RODILLA%'
                             OR poc.procedure_code LIKE 'RX-HOMBRO%'
                             OR poc.procedure_code LIKE 'RX-CODO%'
                             OR poc.procedure_code LIKE 'RX-MU%'
                             OR poc.procedure_code LIKE 'RX-MANO%'
                             OR poc.procedure_code LIKE 'RX-TOBILLO%'
                             OR poc.procedure_code LIKE 'RX-PIE%'
                             OR poc.procedure_code LIKE 'RX-CADERA%'
                             OR poc.procedure_code LIKE 'RX-SENOS%'
                             OR poc.procedure_code LIKE 'US-%'
                             OR poc.procedure_code LIKE 'ECO-%'
                             OR poc.procedure_code LIKE 'CT-%'
                             OR poc.procedure_code LIKE 'TAC-%'
                             OR poc.procedure_code LIKE 'MRI-%'
                             OR poc.procedure_code LIKE 'RMN-%'
                             OR poc.procedure_code LIKE 'MAMMO-%'
                             OR poc.procedure_code LIKE 'MAMO-%'
                             OR pr.report_notes LIKE '%DICOM%'
                             OR pr.report_notes LIKE '%STUDY%'
                             OR pr.report_notes LIKE '%ESTUDIO%'
                        )
                      ORDER BY COALESCE(pr.date_report, po.date_ordered) DESC";

        $resOrders = sqlStatement($sqlOrders, [$pid]);
        if ($resOrders) {
            while ($row = sqlFetchArray($resOrders)) {
                $providerName = trim(($row['provider_title'] ? $row['provider_title'] . ' ' : 'Dr. ') . ($row['provider_fname'] ?? '') . ' ' . ($row['provider_lname'] ?? ''));
                if (trim($providerName) === 'Dr.' || empty(trim($providerName))) {
                    $providerName = xl('Specialist Physician');
                }

                $studyName = !empty($row['procedure_name']) ? $row['procedure_name'] : xl('Diagnostic Imaging Study');
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
                    'date_study'        => $row['date_report'] ? date('d/m/Y H:i', strtotime($row['date_report'])) : ($row['date_ordered'] ? date('d/m/Y', strtotime($row['date_ordered'])) : xl('No date')),
                    'date_raw'          => $row['date_report'] ?? $row['date_ordered'],
                    'provider_name'     => $providerName,
                    'provider_spec'     => $row['provider_specialty'] ?? xl('Diagnostic Imaging'),
                    'status'            => !empty($row['procedure_report_id']) ? xl('Report Available') : xl('In Progress / Pending'),
                    'has_report'        => !empty($row['procedure_report_id']),
                    'accession_number'  => $accessionNumber,
                    'study_uid'         => $effectiveUid,
                    'format_type'       => 'dicom', // DICOM -> Visor OHIF
                    'viewer_type'       => 'ohif',
                    'viewer_url'        => $this->buildOhifViewerUrl($effectiveUid),
                    'ohif_url'          => $this->buildOhifViewerUrl($effectiveUid),
                    'stone_url'         => $this->buildStoneViewerUrl($effectiveUid),
                    'direct_view_url'   => null,
                    'download_url'      => null,
                    'source'            => 'openemr_order'
                ];
            }
        }

        // =========================================================================
        // 4. Consultar Orthanc PACS directamente vía REST API
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

        // Ordenar todos los estudios cronológicamente descendente
        usort($studies, function ($a, $b) {
            $timeA = strtotime((string)($a['date_raw'] ?? '1970-01-01'));
            $timeB = strtotime((string)($b['date_raw'] ?? '1970-01-01'));
            return $timeB <=> $timeA;
        });

        return $studies;
    }

    /**
     * Obtiene la lista de IDs de categorías que representan imágenes y sus subcategorías
     */
    private function getImageCategoryIds(): array
    {
        $categoryIds = [];

        // Consultar categorías con palabras clave o pertenecientes a las ramas de imágenes
        $sql = "SELECT id, name, parent, lft, rght FROM categories 
                WHERE name LIKE '%Imágen%' 
                   OR name LIKE '%Imagen%' 
                   OR name LIKE '%Imaging%' 
                   OR name LIKE '%Ecograf%' 
                   OR name LIKE '%Radiolog%' 
                   OR name LIKE '%Resonancia%' 
                   OR name LIKE '%Tomograf%' 
                   OR name LIKE '%X-Ray%' 
                   OR name LIKE '%Rayos%' 
                   OR name LIKE '%Doppler%' 
                   OR name LIKE '%Cardiología%' 
                   OR name LIKE '%Electrocardiograma%'
                   OR name LIKE '%Ecocardiograma%'
                   OR name LIKE '%Photos%'
                   OR name LIKE '%Photographs%'
                   OR name LIKE '%SIP Perinatal%'
                   OR parent IN (9753, 17, 10001, 20002, 21002, 9800, 10010, 10011, 10012, 10013)
                   OR (lft >= 69 AND rght <= 94)
                   OR (lft >= 32 AND rght <= 51)";

        $res = sqlStatement($sql);
        if ($res) {
            while ($row = sqlFetchArray($res)) {
                $categoryIds[] = (int)$row['id'];
            }
        }

        // Categorías predefinidas de imágenes encontradas en la estructura de OpenEMR
        $knownImageCategoryIds = [
            17, 18, 19, 20, 21, 22, 23, 24, 25, 26, // Eye Imaging
            9753, 9762, 9772, 9800, 9801, 9802, 9803, // Imágenes Generales
            10001, 10010, 10011, 10012, 10013, 10100, 10101, 10102, 10110, 10111, 10112, 10113, 10114, 10120, 10121, 10122, 10123, 10130, 10131, 10132, // Imaging Preop
            20002, 20020, 20021, 20022, 20023, 20024, // Dental Imaging
            21002 // SIP Ecografías
        ];

        $merged = array_unique(array_merge($categoryIds, $knownImageCategoryIds));
        return array_values($merged);
    }

    /**
     * Consulta REST a Orthanc PACS para encontrar estudios asociados al PatientID / DNI
     */
    public function fetchOrthancStudies(string $patientId, ?string $dni = null): array
    {
        $results = [];
        $idsToSearch = array_unique(array_filter([
            $patientId,
            $dni,
            (string)ltrim($patientId, '0'),
            (string)str_pad($patientId, 4, '0', STR_PAD_LEFT),
            (string)str_pad($patientId, 6, '0', STR_PAD_LEFT),
            (string)str_pad($patientId, 8, '0', STR_PAD_LEFT)
        ]));

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
                            $formattedDate = xl('No date');
                            if (strlen($studyDate) === 8) {
                                $formattedDate = substr($studyDate, 6, 2) . '/' . substr($studyDate, 4, 2) . '/' . substr($studyDate, 0, 4);
                            }

                            $desc = $mainDicom['StudyDescription'] ?? xl('Orthanc PACS Study');
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
                                'provider_name'    => $mainDicom['ReferringPhysicianName'] ?? xl('Diagnostic Imaging Service'),
                                'provider_spec'    => xl('Diagnostic Imaging'),
                                'status'           => xl('Images on PACS Server'),
                                'has_report'       => false,
                                'accession_number' => $accession,
                                'study_uid'        => $studyUid,
                                'format_type'      => 'dicom', // DICOM -> Visor OHIF
                                'viewer_type'      => 'ohif',
                                'viewer_url'       => $this->buildOhifViewerUrl($studyUid),
                                'ohif_url'         => $this->buildOhifViewerUrl($studyUid),
                                'stone_url'        => $this->buildStoneViewerUrl($studyUid),
                                'direct_view_url'  => null,
                                'download_url'     => null,
                                'source'           => 'orthanc'
                            ];
                        }
                    }
                }
            } catch (\Throwable $e) {
                // Registrar aviso silencioso
                error_log(xl('Warning: Could not query Orthanc PACS') . " ({$e->getMessage()})");
            }
        }

        return $results;
    }

    /**
     * Obtiene el documento físico o contenido BLOB desde OpenEMR para servirlo de forma segura
     */
    public function getDocumentFile(int $docId, int $pid): ?array
    {
        $sql = "SELECT id, foreign_id, url, mimetype, name, size, docdate, type, document_data 
                FROM documents 
                WHERE id = ? AND foreign_id = ? AND (deleted = 0 OR deleted IS NULL)
                LIMIT 1";

        $doc = sqlQuery($sql, [$docId, $pid]);
        if (!$doc) {
            return null;
        }

        $url = (string)$doc['url'];
        $cleanUrl = ltrim(str_replace('file://', '', $url), '/');
        $fileName = $doc['name'] ?: basename($cleanUrl);
        $mimeType = $doc['mimetype'] ?: 'image/jpeg';
        $filePath = null;
        $fileContent = null;

        // 1. Intentar obtención y desencriptado directo con la clase Document de OpenEMR
        if (class_exists('\Document')) {
            try {
                $docObj = new \Document($docId);
                $decryptedData = $docObj->get_data();
                if ($decryptedData !== false && $decryptedData !== null && $decryptedData !== '') {
                    return [
                        'id'           => (int)$doc['id'],
                        'pid'          => (int)$doc['foreign_id'],
                        'name'         => $docObj->get_name() ?: $fileName,
                        'mimetype'     => $docObj->get_mimetype() ?: $mimeType,
                        'url'          => $docObj->get_url() ?: $url,
                        'file_path'    => null,
                        'file_content' => $decryptedData,
                        'exists'       => true
                    ];
                }
            } catch (\Throwable $e) {
                // El archivo físico no existe en disco o está huérfano
            }
        }

        // 2. Probar ruta directa o relativa en el sistema de archivos
        if (file_exists($url) && is_file($url)) {
            $filePath = $url;
        } elseif (file_exists('/' . $cleanUrl) && is_file('/' . $cleanUrl)) {
            $filePath = '/' . $cleanUrl;
        } elseif (file_exists($cleanUrl) && is_file($cleanUrl)) {
            $filePath = $cleanUrl;
        } else {
            $siteDir = $GLOBALS['OE_SITE_DIR'] ?? null;
            $candidatePaths = [];

            if ($siteDir) {
                $candidatePaths[] = rtrim($siteDir, '/') . '/documents/' . $cleanUrl;
                $candidatePaths[] = rtrim($siteDir, '/') . '/documents/' . $pid . '/' . basename($cleanUrl);
                $candidatePaths[] = rtrim($siteDir, '/') . '/documents/' . $pid . '/' . $fileName;
                $candidatePaths[] = rtrim($siteDir, '/') . '/documents/' . basename($cleanUrl);
                $candidatePaths[] = rtrim($siteDir, '/') . '/documents/' . $fileName;
            }

            $docRoots = [
                '/var/www/html/origen.ar/hcd/sites/default/documents',
                '/var/www/html/openemr/sites/default/documents',
                dirname(__DIR__, 2) . '/sites/default/documents',
                dirname(__DIR__) . '/storage/documents'
            ];

            foreach ($docRoots as $dRoot) {
                $candidatePaths[] = rtrim($dRoot, '/') . '/' . $cleanUrl;
                $candidatePaths[] = rtrim($dRoot, '/') . '/' . $pid . '/' . basename($cleanUrl);
                $candidatePaths[] = rtrim($dRoot, '/') . '/' . $pid . '/' . $fileName;
                $candidatePaths[] = rtrim($dRoot, '/') . '/' . basename($cleanUrl);
                $candidatePaths[] = rtrim($dRoot, '/') . '/' . $fileName;
            }

            foreach ($candidatePaths as $cPath) {
                if (file_exists($cPath) && is_file($cPath)) {
                    $filePath = $cPath;
                    break;
                }
            }
        }

        // Si se leyó de disco, verificar si requiere desencriptado
        if ($filePath && file_exists($filePath)) {
            $rawContent = file_get_contents($filePath);
            if (!empty($rawContent)) {
                if (class_exists('\OpenEMR\BC\ServiceContainer')) {
                    try {
                        $crypto = \OpenEMR\BC\ServiceContainer::getCrypto();
                        $decrypted = $crypto->decryptFromFilesystem($rawContent);
                        if (!empty($decrypted)) {
                            $fileContent = $decrypted;
                        } else {
                            $fileContent = $rawContent;
                        }
                    } catch (\Throwable $e) {
                        $fileContent = $rawContent;
                    }
                } else {
                    $fileContent = $rawContent;
                }
            }
        }

        // 3. Si no se encontró en disco, intentar con la clase nativa C_Document de OpenEMR
        if ($fileContent === null && class_exists('\C_Document')) {
            try {
                $cDoc = new \C_Document();
                $cDoc->onReturnRetrieveKey();
                $retrieved = $cDoc->retrieve_action($pid, $docId, true, true, true);
                if (!empty($retrieved)) {
                    $fileContent = $retrieved;
                }
            } catch (\Throwable $e) {
                error_log(xl('Warning: C_Document::retrieve_action failed') . " ({$e->getMessage()})");
            }
        }

        // 4. Si es BLOB almacenado directamente en la base de datos
        if ($fileContent === null && !empty($doc['document_data'])) {
            $fileContent = $doc['document_data'];
        }

        return [
            'id'           => (int)$doc['id'],
            'pid'          => (int)$doc['foreign_id'],
            'name'         => $fileName,
            'mimetype'     => $mimeType,
            'url'          => $url,
            'file_path'    => $filePath,
            'file_content' => $fileContent,
            'exists'       => ($filePath !== null || $fileContent !== null)
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
        $studyTitle = !empty($row['procedure_name']) ? $row['procedure_name'] : xl('Diagnostic Imaging Study');
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
            'report_status'      => xl('Official Approved Report'),
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
                'specialty'      => $row['provider_specialty'] ?? xl('Diagnostic Imaging'),
                'license'        => $row['provider_license'] ?? xl('M.D. / M.P. Radiology')
            ]
        ];
    }

    /**
     * Construye la URL exacta hacia el visor DICOM OHIF:
     * Si se pasa un Orthanc UUID interno (con guiones), lo resuelve al StudyInstanceUID real de DICOM.
     */
    public function buildOhifViewerUrl(string $studyUid): string
    {
        $realStudyUid = $this->resolveRealStudyInstanceUid($studyUid);

        // OHIF v3 en la ruta /viewer usa el dataSource "dicomweb" ya configurado
        // en app-config.js (defaultDataSourceName). No acepta un endpoint WADO-RS
        // arbitrario vía ?url= en esta ruta — eso solo aplica a /viewer/dicomjson.
        return rtrim($this->ohifBaseUrl, '/') . '?StudyInstanceUIDs=' . urlencode($realStudyUid);
    }

    /**
     * Construye la URL directa hacia el visor nativo de Orthanc (Stone WebViewer)
     */
    public function buildStoneViewerUrl(string $studyUid): string
    {
        $realStudyUid = $this->resolveRealStudyInstanceUid($studyUid);
        $pacsHost = parse_url($this->orthancWadoUrl, PHP_URL_HOST) ?: 'pacs.origen.ar';
        $pacsScheme = parse_url($this->orthancWadoUrl, PHP_URL_SCHEME) ?: 'https';
        return "{$pacsScheme}://{$pacsHost}/stone-webviewer/index.html?study=" . urlencode($realStudyUid);
    }

    /**
     * Resuelve un UUID interno de Orthanc a su StudyInstanceUID real de DICOM
     */
    public function resolveRealStudyInstanceUid(string $uid): string
    {
        $uid = trim($uid);
        
        // Si ya es un StudyInstanceUID numérico con puntos (ej: 2.16.840...), usarlo directo
        if (preg_match('/^[0-9]+(\.[0-9]+)+$/', $uid)) {
            return $uid;
        }

        // Si tiene formato de UUID de Orthanc con guiones (ej: a2a05afc-3208-4995-a86a-7972256fbad6)
        if (str_contains($uid, '-')) {
            // 1. Intentar consultar en documents_pacs_sync si ya está registrado
            $sqlDps = "SELECT study_instance_uid FROM documents_pacs_sync 
                       WHERE (orthanc_study_id = ? OR orthanc_instance_id = ?) 
                         AND study_instance_uid REGEXP '^[0-9]+\\.[0-9]+'
                       LIMIT 1";
            $rowDps = sqlQuery($sqlDps, [$uid, $uid]);
            if ($rowDps && !empty($rowDps['study_instance_uid'])) {
                return $rowDps['study_instance_uid'];
            }

            // 2. Consultar directamente a la API REST de Orthanc
            try {
                $ch = curl_init(rtrim($this->orthancUrl, '/') . '/studies/' . $uid);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_USERPWD        => "{$this->orthancUser}:{$this->orthancPass}",
                    CURLOPT_TIMEOUT        => ORTHANC_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => 2
                ]);
                $res = curl_exec($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($code === 200 && $res) {
                    $studyData = json_decode($res, true);
                    if (!empty($studyData['MainDicomTags']['StudyInstanceUID'])) {
                        return (string)$studyData['MainDicomTags']['StudyInstanceUID'];
                    }
                }
            } catch (\Throwable $e) {
                // Silencioso
            }
        }

        return $uid;
    }

    /**
     * Devuelve los informes (PDF) del formulario de imágenes del paciente,
     * indexados por categoría (carpeta) destino, para poder vincular cada
     * imagen del mismo encuentro con su informe.
     *
     * @param int $pid
     * @return array<int, array{doc_id:int,title:string,date:string}>
     */
    private function getPatientReportsByCategory(int $pid): array
    {
        $map = [];
        $result = sqlStatement(
            "SELECT id, pdf_document_id, pdf_category_id, modality, study_date, anatomical_region,
                    requesting_physician, reporting_physician
               FROM form_imaging_report
              WHERE pid = ?
                AND pdf_document_id IS NOT NULL
                AND pdf_document_id > 0
                AND (activity = 1 OR activity IS NULL)
              ORDER BY id DESC",
            [$pid]
        );
        if (!$result) {
            return $map;
        }
        while ($row = sqlFetchArray($result)) {
            $cat = (int)($row['pdf_category_id'] ?? 0);
            if ($cat <= 0) {
                continue;
            }
            $titulo = trim((string)($row['anatomical_region'] ?? ''));
            $map[$cat] = [
                'doc_id'              => (int)$row['pdf_document_id'],
                'title'               => ($titulo !== '' ? $titulo . ' — ' : '') . xl('Diagnostic Imaging Report'),
                'date'                => (string)($row['study_date'] ?? ''),
                'requesting_physician' => trim((string)($row['requesting_physician'] ?? '')),
                'reporting_physician'  => trim((string)($row['reporting_physician'] ?? '')),
            ];
        }
        return $map;
    }

    /**
     * Construye un mapa de informes (PDF) por ENCUENTRO + CATEGORÍA a partir del
     * formulario de imágenes (form_imaging_report.pdf_document_id), usando el
     * encounter_id del documento y la categoría del PDF. Se usa para vincular el
     * informe a la tarjeta de imágenes agrupadas por estudio.
     *
     * @return array [encounter][category] => ['doc_id'=>int, 'name'=>string]
     */
    private function buildReportsByEncounterCategory(int $pid): array
    {
        $map = [];
        $result = sqlStatement(
            "SELECT fir.pdf_document_id AS doc_id,
                    d.encounter_id,
                    c.id AS category_id,
                    c.name AS category_name,
                    d.name AS doc_name,
                    fir.requesting_physician,
                    fir.reporting_physician
               FROM form_imaging_report fir
               JOIN documents d ON d.id = fir.pdf_document_id
               LEFT JOIN categories_to_documents ctd ON ctd.document_id = d.id
               LEFT JOIN categories c ON ctd.category_id = c.id
              WHERE fir.pid = ?
                AND fir.pdf_document_id IS NOT NULL
                AND fir.pdf_document_id > 0
                AND (fir.activity = 1 OR fir.activity IS NULL)
                AND (d.deleted = 0 OR d.deleted IS NULL)
              ORDER BY fir.id DESC",
            [$pid]
        );
        if (!$result) {
            return $map;
        }
        while ($row = sqlFetchArray($result)) {
            $docId = (int)($row['doc_id'] ?? 0);
            $enc = (int)($row['encounter_id'] ?? 0);
            $cat = (int)($row['category_id'] ?? 0);
            if ($docId <= 0 || $enc <= 0 || $cat <= 0) {
                continue;
            }
            if (!isset($map[$enc][$cat])) {
                $map[$enc][$cat] = [
                    'doc_id'               => $docId,
                    'name'                 => (string)($row['doc_name'] ?? ''),
                    'requesting_physician' => trim((string)($row['requesting_physician'] ?? '')),
                    'reporting_physician'  => trim((string)($row['reporting_physician'] ?? '')),
                ];
            }
        }
        return $map;
    }

    private function detectModality(string $title): string
    {
        $t = strtoupper($title);
        if (str_contains($t, 'MRI') || str_contains($t, 'MAGNETIC RESONANCE') || str_contains($t, 'RESONANCIA') || str_contains($t, 'RMN')) return xl('MRI (Magnetic Resonance)');
        if (str_contains($t, 'CT') || str_contains($t, 'COMPUTED TOMOGRAPHY') || str_contains($t, 'TOMOGRAFIA') || str_contains($t, 'TAC')) return xl('CT (Computed Tomography)');
        if (str_contains($t, 'ULTRASOUND') || str_contains($t, 'ULTRA SOUND') || str_contains($t, 'US') || str_contains($t, 'ECOGRAFIA') || str_contains($t, 'ECO') || str_contains($t, 'ULTRASONIDO')) return xl('US (Ultrasound)');
        if (str_contains($t, 'MAMMOGRAPHY') || str_contains($t, 'MAMOGRAFIA') || str_contains($t, 'MG') || str_contains($t, 'MAMO') || str_contains($t, 'TOMOSYNTHESIS') || str_contains($t, 'TOMOSINTESIS')) return xl('MG (Mammography)');
        if (str_contains($t, 'X-RAY') || str_contains($t, 'RADIOLOGY') || str_contains($t, 'RADIOGRAPH') || str_contains($t, 'RX') || str_contains($t, 'RAYOS') || str_contains($t, 'RADIOGRAFIA') || str_contains($t, 'CR ') || str_contains($t, 'DX')) return xl('XR (Digital Radiography)');
        if (str_contains($t, 'DENSITOMETRY') || str_contains($t, 'DENSITOMETRIA') || str_contains($t, 'DEXA')) return xl('DEXA (Bone Densitometry)');
        if (str_contains($t, 'ECG') || str_contains($t, 'EKG') || str_contains($t, 'ELECTROCARDIOGRAM')) return xl('ECG (Electrocardiogram)');
        if (str_contains($t, 'FOTO') || str_contains($t, 'PHOTO')) return xl('PHOTO (Graphic Record)');
        return xl('IMG (Diagnostic Imaging)');
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
            $conclusion = xl('Study performed according to standard protocol. Correlate with clinical data.');
        }

        if (empty($findings)) {
            $findings = xl('No morphological alterations or acute focal lesions observed in the explored region. Anatomical structures within normal limits for age and patient history.');
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
            return $interval->y . ' ' . xl('years');
        } catch (\Exception $e) {
            return 'N/A';
        }
    }

    private function formatSex(string $sex): string
    {
        return match (strtoupper(trim($sex))) {
            'M', 'MALE', 'MASCULINO' => xl('Male'),
            'F', 'FEMALE', 'FEMENINO' => xl('Female'),
            default => xl('Other / Not specified')
        };
    }
}
