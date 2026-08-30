<?php
/**
 * Script de Sincronización Automática OpenEMR -> Orthanc PACS
 * Ejecución vía CLI / Cron: php cron_sync_pacs.php
 * # Ejecutar sincronización de imágenes hacia Orthanc cada 5 minutos
* php /var/www/html/origen.ar/hcd/express_portal/cron_sync_pacs.php >> /var/log/orthanc_sync.log 2>&1
*/

declare(strict_types=1);

require_once __DIR__ . '/config.php';

echo "[" . date('Y-m-d H:i:s') . "] Iniciando sincronización de imágenes hacia Orthanc PACS...\n";

// 1. Obtener IDs de categorías de imágenes
$imgService = new \App\Imaging();

// 2. Buscar documentos de imágenes que aún no se hayan sincronizado
$sql = "SELECT 
            d.id AS doc_id,
            d.foreign_id AS pid,
            d.url,
            d.mimetype,
            d.name AS doc_name,
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
          AND dps.document_id IS NULL
          AND (
               c.id IN (17, 9753, 9762, 9772, 9800, 9801, 9802, 9803, 10001, 20002, 21002)
               OR c.name LIKE '%Imágen%' 
               OR c.name LIKE '%Imaging%' 
               OR c.name LIKE '%Ecograf%'
               OR c.name LIKE '%Resonancia%'
               OR c.name LIKE '%Tomograf%'
               OR c.name LIKE '%Rayos%'
          )
        ORDER BY d.id ASC
        LIMIT 50";

$res = sqlStatement($sql);
$syncedCount = 0;

while ($row = sqlFetchArray($res)) {
    $docId = (int)$row['doc_id'];
    $pid = (int)$row['pid'];
    
    // Obtener archivo físico
    $docInfo = $imgService->getDocumentFile($docId, $pid);
    if (!$docInfo || empty($docInfo['file_path']) || !file_exists($docInfo['file_path'])) {
        echo "  [SKIP] Doc ID #{$docId}: Archivo físico no encontrado en disco.\n";
        continue;
    }

    $filePath = $docInfo['file_path'];
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $patientName = strtoupper(trim(($row['lname'] ?? '') . '^' . ($row['fname'] ?? '')));
    $studyDesc = $row['category_name'] ?: 'Estudio de Diagnóstico por Imágenes';
    $dniOrPid = !empty($row['dni']) ? (string)$row['dni'] : (string)$pid;

    $studyInstanceUid = null;
    $orthancInstanceId = null;

    // Caso A: Si es un archivo DICOM nativo (.dcm)
    if ($ext === 'dcm' || $row['mimetype'] === 'application/dicom') {
        $ch = curl_init(rtrim(ORTHANC_URL, '/') . '/instances');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => file_get_contents($filePath),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/dicom'],
            CURLOPT_USERPWD        => ORTHANC_USER . ':' . ORTHANC_PASS,
            CURLOPT_TIMEOUT        => 30
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $orthancInstanceId = $data['ID'] ?? null;
            $studyInstanceUid = $data['ParentStudy'] ?? null;
        }
    } 
    // Caso B: Si es una imagen estándar JPG/PNG -> Convertir a DICOM en Orthanc
    elseif (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
        $mime = ($ext === 'png') ? 'image/png' : 'image/jpeg';
        $base64Img = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($filePath));

        $payload = json_encode([
            'Tags' => [
                'PatientID'        => (string)$pid,
                'PatientName'      => $patientName,
                'PatientBirthDate' => str_replace('-', '', (string)$row['DOB']),
                'PatientSex'       => (strtoupper((string)$row['sex'])[0] ?? 'O'),
                'StudyDescription' => $studyDesc,
                'Modality'         => 'OT'
            ],
            'Content' => $base64Img
        ]);

        $ch = curl_init(rtrim(ORTHANC_URL, '/') . '/tools/create-dicom');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_USERPWD        => ORTHANC_USER . ':' . ORTHANC_PASS,
            CURLOPT_TIMEOUT        => 30
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $response) {
            $data = json_decode($response, true);
            $orthancInstanceId = $data['ID'] ?? null;
            $studyInstanceUid = $data['ParentStudy'] ?? null;
        }
    }

    if ($studyInstanceUid || $orthancInstanceId) {
        // Registrar en la tabla de sincronización
        sqlStatement("INSERT INTO documents_pacs_sync (document_id, study_instance_uid, orthanc_instance_id, synced_at) 
                      VALUES (?, ?, ?, NOW()) 
                      ON DUPLICATE KEY UPDATE study_instance_uid = VALUES(study_instance_uid)", [
            $docId,
            $studyInstanceUid ?: $orthancInstanceId,
            $orthancInstanceId
        ]);
        echo "  [OK] Doc ID #{$docId} ({$row['doc_name']}) sincronizado exitosamente con Orthanc.\n";
        $syncedCount++;
    } else {
        echo "  [ERROR] Doc ID #{$docId}: Falló la subida a Orthanc.\n";
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Sincronización completada. Total procesados: {$syncedCount}\n";
