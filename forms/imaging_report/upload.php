<?php
/**
 * upload.php
 *
 * Endpoint AJAX de subida directa de documentos de imágenes (DICOM, JPG/PNG,
 * PDF) para el formulario de informe de diagnóstico por imágenes.
 *
 * Recibe el archivo desde la vista new.php, lo guarda en `documents` y lo
 * sube al PACS del proveedor de la orden (si hay proveedor configurado),
 * registrando la operación en form_imaging_report_images.
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

$requestId = (string)($_POST['request_id'] ?? '');

require_once(__DIR__ . "/../../library/api.inc.php");
require_once(__DIR__ . '/imaging_upload_functions.php');

$session = SessionWrapperFactory::getInstance()->getActiveSession();
$pid = PatientSessionUtil::getPid();

// Endpoint de conteo (GET, solo lectura) para refrescar la etiqueta de documentos.
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

// Verificación CSRF + autenticación OpenEMR
try {
    CsrfUtils::verifyCsrfToken($_POST['csrf_token_form'] ?? '', 'form');
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

$result = imaging_upload_document($file, $pid, $procedureOrderId, $formId, $modality, $encounterId);

$response = [
    'success' => $result['success'],
    'message' => $result['message'],
    'request_id' => $requestId,
    'image_id' => $result['image_id'],
    'document_id' => $result['document_id'],
    'study_uid' => $result['study_uid'],
    'modality_dicom' => imaging_modality_to_dicom($modality),
];

header('Content-Type: application/json');
echo json_encode($response);
