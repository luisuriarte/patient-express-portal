<?php
/**
 * Servidor y Visor Seguro de Documentos e Imágenes Estándar de OpenEMR
 * Patient Express Portal - Permite visualización y descarga directa (JPG, PNG, PDF)
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$auth = new \App\Auth();
$auth->requireAuth('index.php');

$pid = $auth->getPatientPid();
$docId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isDownload = isset($_GET['download']) && $_GET['download'] === '1';

if ($docId <= 0 || !$pid) {
    http_response_code(400);
    die('Error: Solicitud de documento inválida.');
}

$imgService = new \App\Imaging();
$document = $imgService->getDocumentFile($docId, $pid);

if (!$document) {
    http_response_code(404);
    die('Error: Documento no encontrado o no tiene permisos para visualizar este archivo.');
}

// Si el archivo físico existe en disco
if (!empty($document['file_path']) && file_exists($document['file_path'])) {
    $filePath = $document['file_path'];
    $mimeType = $document['mimetype'] ?: mime_content_type($filePath) ?: 'application/octet-stream';
    $fileName = $document['name'] ?: basename($filePath);

    // Headers de caché y tipo de contenido
    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . filesize($filePath));
    
    $disposition = $isDownload ? 'attachment' : 'inline';
    header(sprintf('Content-Disposition: %s; filename="%s"', $disposition, addslashes($fileName)));
    header('Cache-Control: private, max-age=3600');

    // Transmitir archivo en bloques
    readfile($filePath);
    exit;
} else {
    http_response_code(404);
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Documento no disponible</title><style>body{font-family:sans-serif;padding:40px;text-align:center;color:#334155;} h3{color:#0284c7;}</style></head><body>';
    echo '<h3>Documento Registrado en Historia Clínica</h3>';
    echo '<p>El archivo <strong>' . htmlspecialchars($document['name']) . '</strong> se encuentra registrado en el sistema.</p>';
    echo '<p style="font-size:12px;color:#64748b;">Ruta interna: ' . htmlspecialchars($document['url']) . '</p>';
    echo '</body></html>';
    exit;
}
