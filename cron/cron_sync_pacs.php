<?php
/**
 * Script de Sincronización Automática: OpenEMR Documents -> Orthanc PACS
 * Proyecto: Patient Express Portal
 * 
 * Uso vía Línea de Comandos (CLI / Cron):
 *   sudo -u www-data php /var/www/html/origen.ar/hcd/express_portal/cron/cron_sync_pacs.php
 * 
 * Uso en Crontab (usuario www-data):
 *   * /5 * * * * php /var/www/html/origen.ar/hcd/express_portal/cron/cron_sync_pacs.php >> /var/log/orthanc_sync.log 2>&1
 */

declare(strict_types=1);

// Permitir ejecución prolongada para subida en lotes
set_time_limit(600);
ini_set('memory_limit', '512M');

// Configuración de variables de entorno CLI para bootstrap de OpenEMR
if (php_sapi_name() === 'cli' || empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST']   = 'localhost';
    $_SERVER['SERVER_NAME'] = 'localhost';
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION['site_id']    = 'default';
    $GLOBALS['oe_site_id']  = 'default';
}

require_once dirname(__DIR__) . '/config.php';

// ==============================================================================
// 1. Control de Concurrencia mediante File Lock
// ==============================================================================
$lockFile = sys_get_temp_dir() . '/orthanc_sync.lock';
$lockHandle = fopen($lockFile, 'c+');

if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo "[" . date('Y-m-d H:i:s') . "] [AVISO] Otra instancia del proceso de sincronización ya se encuentra en ejecución. Saliendo...\n";
    exit(0);
}

echo "==============================================================================\n";
echo "[" . date('Y-m-d H:i:s') . "] INICIANDO SINCRONIZACIÓN OPENEMR -> ORTHANC PACS\n";
echo "Servidor Orthanc destino: " . (defined('ORTHANC_URL') ? ORTHANC_URL : 'http://127.0.0.1:8042') . "\n";
echo "==============================================================================\n";

$imgService = new \App\Imaging();

// ==============================================================================
// 2. Verificar que la tabla auxiliar documents_pacs_sync exista
// ==============================================================================
try {
    $checkTable = sqlQuery("SHOW TABLES LIKE 'documents_pacs_sync'");
    if (empty($checkTable)) {
        echo "[" . date('Y-m-d H:i:s') . "] [ERROR] La tabla 'documents_pacs_sync' no existe en la base de datos.\n";
        echo "Por favor ejecute el script SQL: sql/documents_pacs.sql antes de continuar.\n";
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        exit(1);
    }
} catch (\Throwable $e) {
    echo "[" . date('Y-m-d H:i:s') . "] [ERROR] No se pudo verificar la base de datos: " . $e->getMessage() . "\n";
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(1);
}

$isAllMode = in_array('--all', $argv ?? [], true);
$isRetryMode = in_array('--retry', $argv ?? [], true) || in_array('--force', $argv ?? [], true) || $isAllMode;
$batchLimit = $isAllMode ? 1000 : 200;

$statusFilter = "AND (dps.document_id IS NULL OR dps.status = 'pending'" . ($isRetryMode ? " OR dps.status = 'failed'" : "") . ")";

$sql = "SELECT 
            d.id AS doc_id,
            d.foreign_id AS pid,
            d.url,
            d.mimetype,
            d.name AS doc_name,
            d.docdate,
            d.date AS doc_created,
            c.id AS category_id,
            c.name AS category_name,
            pd.fname,
            pd.lname,
            pd.mname,
            pd.DOB,
            pd.sex,
            pd.ss AS dni
        FROM documents d
        INNER JOIN categories_to_documents ctd ON d.id = ctd.document_id
        INNER JOIN categories c ON ctd.category_id = c.id
        INNER JOIN patient_data pd ON d.foreign_id = pd.pid
        LEFT JOIN documents_pacs_sync dps ON d.id = dps.document_id
        WHERE (d.deleted = 0 OR d.deleted IS NULL)
          {$statusFilter}
          AND (
               c.id IN (17, 18, 19, 20, 21, 22, 23, 24, 25, 26, 9753, 9762, 9772, 9800, 9801, 9802, 9803, 10001, 20002, 21002)
               OR c.name LIKE '%Imágen%' 
               OR c.name LIKE '%Imagen%'
               OR c.name LIKE '%Imaging%' 
               OR c.name LIKE '%Ecograf%' 
               OR c.name LIKE '%Radiolog%' 
               OR c.name LIKE '%Resonancia%' 
               OR c.name LIKE '%Tomograf%' 
               OR c.name LIKE '%X-Ray%' 
               OR c.name LIKE '%Rayos%' 
               OR c.name LIKE '%Doppler%' 
               OR c.name LIKE '%Cardiología%' 
               OR c.name LIKE '%Electrocardiograma%'
               OR (c.lft >= 69 AND c.rght <= 94)
               OR (c.lft >= 32 AND c.rght <= 51)
          )
        ORDER BY d.id ASC
        LIMIT {$batchLimit}";

$res = sqlStatement($sql);
if (!$res) {
    echo "[" . date('Y-m-d H:i:s') . "] No se encontraron nuevos documentos para procesar.\n";
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
    exit(0);
}

$processedCount = 0;
$successCount = 0;
$failedCount = 0;
$skippedCount = 0;

while ($row = sqlFetchArray($res)) {
    $processedCount++;
    $docId = (int)$row['doc_id'];
    $pid = (int)$row['pid'];
    $docName = !empty($row['doc_name']) ? $row['doc_name'] : basename((string)$row['url']);
    $categoryName = !empty($row['category_name']) ? $row['category_name'] : 'Diagnóstico por Imágenes';

    echo "\n------------------------------------------------------------------------------\n";
    echo "Procesando Doc ID #{$docId} | Paciente PID #{$pid} | Archivo: {$docName}\n";
    echo "Categoría: {$categoryName}\n";

    // 1. Obtener información y ruta física del archivo
    $docFile = $imgService->getDocumentFile($docId, $pid);
    if (!$docFile || (!$docFile['exists'])) {
        echo "  -> [SKIP] El archivo físico o binario no pudo ser localizado en el servidor.\n";
        
        sqlStatement("INSERT INTO documents_pacs_sync (document_id, patient_id, study_instance_uid, status, error_message, synced_at)
                      VALUES (?, ?, '', 'failed', 'Archivo físico no encontrado en disco', NOW())
                      ON DUPLICATE KEY UPDATE status = 'failed', error_message = VALUES(error_message)", [
            $docId,
            $pid
        ]);
        $skippedCount++;
        continue;
    }

    $filePath = $docFile['file_path'];
    $fileContent = $docFile['file_content'];
    $mimeType = strtolower((string)$docFile['mimetype']);
    $ext = strtolower(pathinfo($filePath ?: $docName, PATHINFO_EXTENSION));

    // Datos demográficos formateados para cabeceras DICOM
    $rawLname = trim((string)($row['lname'] ?? ''));
    $rawFname = trim((string)($row['fname'] ?? ''));
    $patientName = strtoupper(($rawLname ?: 'PACIENTE') . '^' . ($rawFname ?: (string)$pid));
    
    $dobFormatted = '';
    if (!empty($row['DOB']) && $row['DOB'] !== '0000-00-00') {
        $dobFormatted = str_replace(['-', '/'], '', substr((string)$row['DOB'], 0, 10));
    }

    $rawSex = strtoupper(substr(trim((string)($row['sex'] ?? 'O')), 0, 1));
    $patientSex = in_array($rawSex, ['M', 'F'], true) ? $rawSex : 'O';

    $studyDate = date('Ymd');
    if (!empty($row['docdate']) && $row['docdate'] !== '0000-00-00') {
        $studyDate = str_replace('-', '', (string)$row['docdate']);
    } elseif (!empty($row['doc_created'])) {
        $studyDate = date('Ymd', strtotime((string)$row['doc_created']));
    }

    $modality = detectDicomModality($categoryName . ' ' . $docName);

    $orthancInstanceId = null;
    $orthancSeriesId = null;
    $orthancStudyId = null;
    $studyInstanceUid = null;
    $errorMessage = null;

    // ==========================================================================
    // CASO A: Archivo DICOM Nativo (.dcm o mimetype application/dicom)
    // ==========================================================================
    if ($ext === 'dcm' || $mimeType === 'application/dicom') {
        echo "  -> Tipo detectado: Archivo DICOM Nativo (.dcm)\n";
        
        $binaryData = $fileContent ?: ($filePath ? file_get_contents($filePath) : null);
        if (empty($binaryData)) {
            echo "  -> [ERROR] No se pudo leer el contenido binario del archivo DICOM.\n";
            $failedCount++;
            continue;
        }

        $ch = curl_init(rtrim(ORTHANC_URL, '/') . '/instances');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $binaryData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/dicom'],
            CURLOPT_USERPWD        => ORTHANC_USER . ':' . ORTHANC_PASS,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $responseData = json_decode($response, true);
            $orthancInstanceId = $responseData['ID'] ?? null;
            $orthancStudyId = $responseData['ParentStudy'] ?? null;
            $orthancSeriesId = $responseData['ParentSeries'] ?? null;

            // Consultar tags de la instancia para obtener el StudyInstanceUID exacto
            if ($orthancInstanceId) {
                $studyInstanceUid = fetchStudyInstanceUidFromOrthanc($orthancInstanceId);
            }
        } else {
            $errorMessage = "Orthanc HTTP {$httpCode}: " . ($curlErr ?: $response);
        }
    }
    // ==========================================================================
    // CASO B: Imagen Estándar (JPG, JPEG, PNG, WEBP) -> Convertir a DICOM
    // ==========================================================================
    elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || str_starts_with($mimeType, 'image/')) {
        echo "  -> Tipo detectado: Imagen Estándar ({$ext}) -> Convirtiendo a DICOM...\n";

        $imageBinary = $fileContent ?: ($filePath ? file_get_contents($filePath) : null);
        if (empty($imageBinary)) {
            echo "  -> [ERROR] No se pudo leer el archivo de imagen.\n";
            $failedCount++;
            continue;
        }

        // Detectar MIME real por magic bytes
        $baseMime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
        if (str_starts_with($imageBinary, "\xFF\xD8")) {
            $baseMime = 'image/jpeg';
        } elseif (str_starts_with($imageBinary, "\x89PNG")) {
            $baseMime = 'image/png';
        } elseif (str_starts_with($imageBinary, "RIFF") && str_contains(substr($imageBinary, 0, 16), "WEBP")) {
            $baseMime = 'image/webp';
        }

        $base64Data = 'data:' . $baseMime . ';base64,' . base64_encode($imageBinary);

        // Tags DICOM requeridos por el estándar y OHIF
        $dicomPayload = [
            'Tags' => [
                'PatientID'               => (string)$pid,
                'PatientName'             => $patientName,
                'PatientBirthDate'        => $dobFormatted,
                'PatientSex'              => $patientSex,
                'StudyDescription'        => $categoryName,
                'SeriesDescription'       => $docName,
                'StudyDate'               => $studyDate,
                'Modality'                => $modality,
                'InstitutionName'         => defined('CLINIC_NAME') ? CLINIC_NAME : 'Centro Medico Origen',
                'AccessionNumber'         => 'DOC-' . $docId,
                'ReferringPhysicianName'  => 'Servicio de Diagnostico'
            ],
            'Content' => $base64Data
        ];

        $ch = curl_init(rtrim(ORTHANC_URL, '/') . '/tools/create-dicom');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($dicomPayload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERPWD        => ORTHANC_USER . ':' . ORTHANC_PASS,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $responseData = json_decode($response, true);
            $orthancInstanceId = $responseData['ID'] ?? null;
            $orthancStudyId = $responseData['ParentStudy'] ?? null;
            $orthancSeriesId = $responseData['ParentSeries'] ?? null;

            if ($orthancInstanceId) {
                $studyInstanceUid = fetchStudyInstanceUidFromOrthanc($orthancInstanceId);
            }
        } else {
            $errorMessage = "Orthanc HTTP {$httpCode}: " . ($curlErr ?: $response);
        }
    }
    // ==========================================================================
    // CASO C: PDF / Informe (Encapsulated PDF) -> subir como DICOM modality OT
    // ==========================================================================
    elseif ($ext === 'pdf' || $mimeType === 'application/pdf') {
        echo "  -> Tipo detectado: PDF Informe (Encapsulated PDF)\n";

        $pdfBinary = $fileContent ?: ($filePath ? file_get_contents($filePath) : null);
        if (empty($pdfBinary)) {
            echo "  -> [ERROR] No se pudo leer el contenido del PDF.\n";
            $failedCount++;
            continue;
        }

        // Adjuntar el informe PDF como Encapsulated PDF al MISMO estudio que las
        // imágenes de la MISMA carpeta/categoría del mismo paciente. Para eso se
        // usa el "Parent" (orthanc_study_id interno), NO StudyInstanceUID, que
        // dispara el error Orthanc 2020 "Trying to override a value inherited
        // from a parent module".
        $parentStudy = '';
        if (!empty($row['category_id'])) {
            $parentStudy = findParentStudyForCategory((int)$pid, (int)$row['category_id'], (int)$docId);
        }

        $pdfPayload = [
            'Tags' => [
                'PatientID'        => (string)$pid,
                'PatientName'      => $patientName,
                'PatientBirthDate' => $dobFormatted,
                'PatientSex'       => $patientSex,
                'StudyDescription' => $categoryName,
                'StudyDate'        => $studyDate,
                'Modality'         => 'OT', // Other / Encapsulated PDF
                'SOPClassUID'      => '1.2.840.10008.5.1.4.1.1.104.1', // Encapsulated PDF Storage
                'SeriesDescription'=> 'Informe de Diagnóstico por Imágenes',
                'SeriesNumber'     => '1',
                'InstitutionName'  => defined('CLINIC_NAME') ? CLINIC_NAME : 'Centro Medico Origen',
                'AccessionNumber'  => 'DOC-' . $docId,
                'DocumentTitle'    => 'Informe de Diagnóstico por Imágenes'
            ],
            'Content' => 'data:application/pdf;base64,' . base64_encode($pdfBinary)
        ];

        // Si hay un estudio de la misma categoría ya sincronizado, adjuntar el
        // PDF como nueva serie dentro de ESE estudio. Si no, Orthanc crea uno.
        if ($parentStudy !== '') {
            $pdfPayload['Parent'] = $parentStudy;
        }

        $ch = curl_init(rtrim(ORTHANC_URL, '/') . '/tools/create-dicom');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($pdfPayload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERPWD        => ORTHANC_USER . ':' . ORTHANC_PASS,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 5
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $responseData = json_decode($response, true);
            $orthancInstanceId = $responseData['ID'] ?? null;
            $orthancStudyId = $responseData['ParentStudy'] ?? null;
            $orthancSeriesId = $responseData['ParentSeries'] ?? null;

            if ($orthancInstanceId) {
                $studyInstanceUid = fetchStudyInstanceUidFromOrthanc($orthancInstanceId);
            }
        } else {
            $errorMessage = "Orthanc HTTP {$httpCode}: " . ($curlErr ?: $response);
        }
    } else {
        echo "  -> [SKIP] El formato ({$ext} / {$mimeType}) no es una imagen ni archivo DICOM.\n";
        
        sqlStatement("INSERT INTO documents_pacs_sync (document_id, patient_id, study_instance_uid, status, error_message, synced_at)
                      VALUES (?, ?, '', 'ignored', 'Formato no soportado para PACS', NOW())
                      ON DUPLICATE KEY UPDATE status = 'ignored', error_message = VALUES(error_message)", [
            $docId,
            $pid
        ]);
        $skippedCount++;
        continue;
    }

    // ==========================================================================
    // 4. Guardar Resultado en documents_pacs_sync
    // ==========================================================================
    if (!empty($studyInstanceUid) || !empty($orthancInstanceId)) {
        $finalStudyUid = $studyInstanceUid ?: ($orthancStudyId ?: ('1.2.840.113619.2.55.' . $docId . '.' . $pid));

        $sqlSave = "INSERT INTO documents_pacs_sync 
                        (document_id, patient_id, orthanc_instance_id, orthanc_series_id, orthanc_study_id, study_instance_uid, modality, status, error_message, synced_at)
                    VALUES 
                        (?, ?, ?, ?, ?, ?, ?, 'synced', NULL, NOW())
                    ON DUPLICATE KEY UPDATE 
                        orthanc_instance_id = VALUES(orthanc_instance_id),
                        orthanc_series_id   = VALUES(orthanc_series_id),
                        orthanc_study_id    = VALUES(orthanc_study_id),
                        study_instance_uid  = VALUES(study_instance_uid),
                        modality            = VALUES(modality),
                        status              = 'synced',
                        error_message       = NULL,
                        synced_at           = NOW()";

        sqlStatement($sqlSave, [
            $docId,
            $pid,
            $orthancInstanceId,
            $orthancSeriesId,
            $orthancStudyId,
            $finalStudyUid,
            $modality
        ]);

        echo "  -> [OK] Sincronizado exitosamente con Orthanc PACS.\n";
        echo "     Orthanc Instance ID: {$orthancInstanceId}\n";
        echo "     StudyInstanceUID:    {$finalStudyUid}\n";
        echo "     URL OHIF Viewer:     https://imagenes.origen.ar/viewer?StudyInstanceUIDs={$finalStudyUid}\n";
        $successCount++;
    } else {
        echo "  -> [ERROR] Falló la subida a Orthanc. Detalle: " . ($errorMessage ?: 'Respuesta inválida de Orthanc') . "\n";
        
        sqlStatement("INSERT INTO documents_pacs_sync 
                        (document_id, patient_id, study_instance_uid, status, error_message, synced_at)
                      VALUES 
                        (?, ?, '', 'failed', ?, NOW())
                      ON DUPLICATE KEY UPDATE 
                        status        = 'failed',
                        error_message = VALUES(error_message)", [
            $docId,
            $pid,
            substr((string)$errorMessage, 0, 500)
        ]);
        $failedCount++;
    }
}

echo "\n==============================================================================\n";
echo "[" . date('Y-m-d H:i:s') . "] RESUMEN DE SINCRONIZACIÓN PACS:\n";
echo "Total analizados:   {$processedCount}\n";
echo "Exitosos:           {$successCount}\n";
echo "Fallidos:           {$failedCount}\n";
echo "Omitidos:           {$skippedCount}\n";
echo "==============================================================================\n";

flock($lockHandle, LOCK_UN);
fclose($lockHandle);
exit(0);

// ==============================================================================
// FUNCIONES AUXILIARES DE SOPORTE DICOM
// ==============================================================================

/**
 * Consulta a Orthanc para extraer el StudyInstanceUID real de la instancia o estudio
 */
function fetchStudyInstanceUidFromOrthanc(string $id): ?string
{
    // 1. Intentar como Study ID
    $ch = curl_init(rtrim(ORTHANC_URL, '/') . '/studies/' . $id);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => ORTHANC_USER . ':' . ORTHANC_PASS,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 3
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

    // 2. Intentar como Instance ID
    $ch = curl_init(rtrim(ORTHANC_URL, '/') . '/instances/' . $id . '/tags?simplify');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => ORTHANC_USER . ':' . ORTHANC_PASS,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_CONNECTTIMEOUT => 3
    ]);

    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code === 200 && $res) {
        $tags = json_decode($res, true);
        if (is_array($tags) && !empty($tags['StudyInstanceUID'])) {
            return (string)$tags['StudyInstanceUID'];
        }
    }

    return null;
}

/**
 * Busca el identificador interno de Orthanc (orthanc_study_id) de un estudio ya
 * sincronizado por otro documento (imagen/DICOM) del MISMO paciente y la MISMA
 * carpeta/categoría. Se usa como "Parent" en /tools/create-dicom para que el
 * informe PDF quede DENTRO del mismo estudio que las imágenes en OHIF/STONE
 * (passing StudyInstanceUID directamente provoca el error Orthanc 2020).
 *
 * @return string Orthanc study ID, o '' si no hay ninguno sincronizado aún.
 */
function findParentStudyForCategory(int $pid, int $categoryId, ?int $excludeDocId = null): string
{
    $bind = [$pid, $categoryId];
    $exclude = '';
    if ($excludeDocId) {
        $exclude = ' AND dps.document_id != ?';
        $bind[] = $excludeDocId;
    }

    $row = sqlQuery(
        "SELECT dps.orthanc_study_id
           FROM documents_pacs_sync dps
           JOIN categories_to_documents ctd ON ctd.document_id = dps.document_id
          WHERE dps.patient_id = ?
            AND ctd.category_id = ?
            AND dps.orthanc_study_id IS NOT NULL
            AND dps.orthanc_study_id != ''
            AND dps.status = 'synced'{$exclude}
          ORDER BY dps.synced_at DESC
          LIMIT 1",
        $bind
    );

    return $row ? (string)$row['orthanc_study_id'] : '';
}

/**
 * Mapea nombres de categorías y archivos a Modalidades DICOM estándar
 */
function detectDicomModality(string $text): string
{
    $t = strtoupper($text);
    if (str_contains($t, 'RESONANCIA') || str_contains($t, 'RMN') || str_contains($t, 'MRI')) return 'MR';
    if (str_contains($t, 'TOMOGRAFIA') || str_contains($t, 'TAC') || str_contains($t, 'CT')) return 'CT';
    if (str_contains($t, 'ECOGRAFIA') || str_contains($t, 'ECO') || str_contains($t, 'US') || str_contains($t, 'DOPPLER')) return 'US';
    if (str_contains($t, 'MAMOGRAFIA') || str_contains($t, 'MG')) return 'MG';
    if (str_contains($t, 'RAYOS') || str_contains($t, 'RX') || str_contains($t, 'X-RAY') || str_contains($t, 'RADIOGRAFIA')) return 'CR';
    if (str_contains($t, 'ELECTROCARDIOGRAMA') || str_contains($t, 'ECG') || str_contains($t, 'EKG')) return 'ECG';
    if (str_contains($t, 'OCT') || str_contains($t, 'FOTO') || str_contains($t, 'PHOTO') || str_contains($t, 'RETINA')) return 'OPT';
    if (str_contains($t, 'DENTAL') || str_contains($t, 'PANORAMICA')) return 'DX';
    return 'OT'; // Other
}
