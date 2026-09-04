<?php
/**
 * Secure OpenEMR Standard Document and Image Server and Viewer
 * Patient Express Portal - Supports direct viewing and download (JPG, PNG, PDF)
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
    die(xlt('Error: Invalid document request.'));
}

$imgService = new \App\Imaging();
$document = $imgService->getDocumentFile($docId, $pid);

if (!$document) {
    http_response_code(404);
    die(xlt('Error: Document not found or you do not have permission to view this file.'));
}

$mimeType = $document['mimetype'] ?: 'image/jpeg';
$fileName = $document['name'] ?: ('document_' . $docId);
$disposition = $isDownload ? 'attachment' : 'inline';

// 1. If physical file exists on the server
if (!empty($document['file_path']) && file_exists($document['file_path']) && is_file($document['file_path'])) {
    $filePath = $document['file_path'];
    $fileSize = filesize($filePath);

    header('Content-Type: ' . $mimeType);
    if ($fileSize > 0) {
        header('Content-Length: ' . $fileSize);
    }
    header(sprintf('Content-Disposition: %s; filename="%s"', $disposition, addslashes($fileName)));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    readfile($filePath);
    exit;
}

// 2. If content was retrieved by C_Document or BLOB
if (!empty($document['file_content'])) {
    $content = $document['file_content'];

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($content));
    header(sprintf('Content-Disposition: %s; filename="%s"', $disposition, addslashes($fileName)));
    header('Cache-Control: private, max-age=3600');
    header('X-Content-Type-Options: nosniff');

    echo $content;
    exit;
}

// 3. If physical file was not located
http_response_code(404);
echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . xla('Document unavailable') . '</title><style>body{font-family:sans-serif;padding:40px;text-align:center;color:#334155;} h3{color:#0284c7;} .box{background:#f8fafc;border:1px solid #e2e8f0;padding:20px;border-radius:12px;display:inline-block;max-width:500px;}</style></head><body>';
echo '<div class="box">';
echo '<h3>' . xla('Document recorded in the medical record') . '</h3>';
echo '<p>' . xla('The file') . ' <strong>' . htmlspecialchars($fileName) . '</strong> ' . xla('is recorded in the system but its physical path could not be read from local storage.') . '</p>';
echo '<p style="font-size:11px;color:#64748b;">' . xla('Registered path') . ': ' . htmlspecialchars($document['url']) . '</p>';
echo '</div>';
echo '</body></html>';
exit;
