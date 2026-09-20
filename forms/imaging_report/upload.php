<?php
/**
 * upload.php
 *
 * AJAX endpoint for direct upload of imaging documents (DICOM, JPG/PNG,
 * PDF or a ZIP archive containing a study) for the imaging diagnostic
 * report form.
 *
 * Receives the file from the new.php view, saves it to 'documents' and
 * uploads it to the order provider's PACS (if a provider is configured),
 * logging the operation in form_imaging_report_images.
 *
 * @package   OpenEMR
 * @author    Centro Médico Origen
 * @license   GNU General Public License 3
 */

require_once(__DIR__ . "/../../globals.php");

use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Session\EncounterSessionUtil;
use OpenEMR\Common\Session\PatientSessionUtil;
use OpenEMR\Common\Session\SessionWrapperFactory;
use OpenEMR\Core\OEGlobalsBag;

$srcdir = class_exists(OEGlobalsBag::class)
    ? OEGlobalsBag::getInstance()->getSrcDir()
    : ($GLOBALS['srcdir'] ?? dirname(__DIR__, 3) . '/library');
$GLOBALS['srcdir'] = $srcdir;

$requestId = (string)($_POST['request_id'] ?? '');

require_once("$srcdir/api.inc.php");
require_once(__DIR__ . '/imaging_upload_functions.php');

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$pid = PatientSessionUtil::getPid();

// Count endpoint (GET, read-only) to refresh the document label.
if (($_GET['action'] ?? '') === 'count') {
    $countOrderId = (int)($_GET['procedure_order_id'] ?? 0);
    $count = 0;
    if ($pid > 0 && $countOrderId > 0) {
        $res = sqlStatement(
            "SELECT COUNT(*) AS c FROM form_imaging_report_images WHERE pid = ? AND procedure_order_id = ?",
            [$pid, $countOrderId]
        );
        $row = sqlFetchArray($res);
        $count = (int)($row['c'] ?? 0);
    }
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'count' => $count]);
    return;
}

$response = [
    'success' => false,
    'message' => xl('Invalid request'),
    'request_id' => $requestId,
    'image_id' => null,
    'document_id' => null,
    'study_uid' => null,
];

// CSRF verification + OpenEMR authentication
try {
    CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', $session);
} catch (\Throwable $e) {
    $response['message'] = xl('Invalid security token.');
    header('Content-Type: application/json');
    echo json_encode($response);
    return;
}

$procedureOrderId = (int)($_POST['procedure_order_id'] ?? 0);
$formId = (int)($_POST['form_id'] ?? 0);
$modality = trim((string)($_POST['modality'] ?? ''));
$encounterId = (int)(EncounterSessionUtil::getEncounter() ?? 0);

if ($pid <= 0) {
    $response['message'] = xl('Missing patient context.');
    header('Content-Type: application/json');
    echo json_encode($response);
    return;
}

if (empty($_FILES['imaging_doc'])) {
    $response['message'] = xl('No file received.');
    header('Content-Type: application/json');
    echo json_encode($response);
    return;
}

$file = $_FILES['imaging_doc'];
if (is_array($file['name'])) {
    // Multi-file sent as array; handle the first for a single request_id.
    $single = [
        'name' => $file['name'][0],
        'type' => $file['type'][0],
        'tmp_name' => $file['tmp_name'][0],
        'error' => $file['error'][0],
        'size' => $file['size'][0],
    ];
    $file = $single;
}

$ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
// If the "Also upload to PACS server" checkbox is disabled, files are only saved
// to OpenEMR documents (without uploading to PACS).
$uploadToPacs = (($_POST['upload_to_pacs'] ?? '1') !== '0');
$skipPacs = !$uploadToPacs;

try {
    if ($ext === 'zip') {
        // A ZIP: in OpenEMR it is extracted and each internal file is saved
        // individually to 'documents' and uploaded to the PACS following the
        // same per-file path as a standalone upload (if the checkbox is enabled).
        $result = imaging_upload_zip($file, $pid, $procedureOrderId, $formId, $modality, $encounterId, $skipPacs);
    } else {
        $result = imaging_upload_document($file, $pid, $procedureOrderId, $formId, $modality, $encounterId, $skipPacs, $skipPacs);
    }
} catch (\Throwable $e) {
    error_log('[imaging_report/upload] Error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    $result = [
        'success' => false,
        'message' => xl('An unexpected error occurred: ') . $e->getMessage(),
        'image_id' => null,
        'document_id' => null,
        'study_uid' => null,
        'count_ok' => 0,
        'count_fail' => 0,
    ];
}

$response = [
    'success' => $result['success'],
    'message' => $result['message'],
    'request_id' => $requestId,
    'image_id' => $result['image_id'] ?? null,
    'document_id' => $result['document_id'] ?? null,
    'study_uid' => $result['study_uid'] ?? null,
    'count_ok' => $result['count_ok'] ?? 1,
    'count_fail' => $result['count_fail'] ?? 0,
    'modality_dicom' => imaging_modality_to_dicom($modality),
];

header('Content-Type: application/json');
echo json_encode($response);
