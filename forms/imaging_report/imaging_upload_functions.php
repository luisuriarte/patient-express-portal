<?php
/**
 * imaging_upload_functions.php
 *
 * Funciones para la subida DIRECTA de documentos de imágenes (DICOM, JPG/PNG,
 * PDF) desde el formulario de informe de diagnóstico por imágenes.
 *
 * A diferencia del cron anterior (que sincronizaba Documents -> PACS en lote),
 * cada archivo se sube en el momento: se guarda en `documents` y, si hay un
 * PACS proveedor configurado, se sube de inmediato al PACS de la orden
 * (procedure_order.lab_id -> procedure_providers).
 *
 * Convención "mismo estudio por orden": para imágenes, PDF y DICOM nativos se
 * fuerza un StudyInstanceUID determinístico derivado de la orden, de modo que
 * todas las series de una misma orden coexisten en un único estudio DICOM en
 * el PACS. Los DICOM nativos se reasignan a ese estudio tras subirlos (con
 * Force en Orthanc); si la reasignación falla, conservan su estudio original.
 *
 * Requiere: globals.php incluido (sqlQuery/sqlStatement), y la tabla
 * `form_imaging_report_images` existente (ver forms/imaging_report/table.sql).
 */

use App\PacsProvider;
use App\PacsService;

require_once __DIR__ . '/category_functions.php';

// Asegurar el autoload de las clases proyecto (App\...) en cualquier contexto
// (formulario clínico de OpenEMR o ejecución standalone). Se usa un fallback
// global por si el composer autoload del proyecto no está ya registrado.
if (!class_exists('App\PacsProvider')) {
    spl_autoload_register(function ($class) {
        $prefix = 'App\\';
        if (!str_starts_with($class, $prefix)) {
            return;
        }
        $rel = substr($class, strlen($prefix)) . '.php';
        $dir = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $candidate = rtrim($dir, '/\\') . '/src/' . $rel;
            if (is_file($candidate)) {
                require_once $candidate;
                return;
            }
            $dir = dirname($dir);
        }
    });
}

define('IMAGING_UPLOAD_MAX_BYTES', 50 * 1024 * 1024); // 50 MB por archivo

const IMAGING_UPLOAD_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'dcm', 'dicom', 'pdf'];
const IMAGING_UPLOAD_ALLOWED_MIME = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
    'application/dicom',
    'image/dicom',
    'application/octet-stream',
];

const IMAGING_UPLOAD_ROOT_NAME = 'Diagnostic Imaging';

/**
 * Obtiene el encabezado de tags DICOM comunes para un paciente/modalidad.
 */
function imaging_build_dicom_tags(int $pid, string $modality, string $studyUid): array
{
    $patient = sqlQuery(
        "SELECT fname, lname, mname, DOB, sex, ss FROM patient_data WHERE pid = ? LIMIT 1",
        [$pid]
    ) ?: [];

    $lname = trim((string)($patient['lname'] ?? ''));
    $fname = trim((string)($patient['fname'] ?? ''));
    $patientName = strtoupper(($lname ?: 'PATIENT') . '^' . ($fname ?: (string)$pid));

    $dob = '';
    if (!empty($patient['DOB']) && $patient['DOB'] !== '0000-00-00') {
        $dob = str_replace(['-', '/'], '', substr((string)$patient['DOB'], 0, 10));
    }

    $rawSex = strtoupper(substr(trim((string)($patient['sex'] ?? 'O')), 0, 1));
    $sex = in_array($rawSex, ['M', 'F'], true) ? $rawSex : 'O';

    return [
        'PatientID'         => (string)$pid,
        'PatientName'       => $patientName,
        'PatientBirthDate'  => $dob,
        'PatientSex'        => $sex,
        'StudyDate'         => date('Ymd'),
        'Modality'          => imaging_modality_to_dicom($modality),
        'StudyInstanceUID'  => $studyUid,
        'InstitutionName'   => defined('CLINIC_NAME') ? CLINIC_NAME : xl('Health Center'),
        'AccessionNumber'   => (string)$pid . '-' . date('Ymd'),
    ];
}

/**
 * Genera un StudyInstanceUID determinístico para una orden, de modo que todos
 * los archivos de la misma orden compartan el mismo estudio en el PACS.
 */
function imaging_study_uid_for_order(int $procedureOrderId, int $providerId): string
{
    return '1.2.840.113619.2.55.' . $procedureOrderId . '.' . ($providerId ?: 0);
}

/**
 * Traduce la modalidad del formulario a modalidad DICOM.
 */
function imaging_modality_to_dicom(string $modalidad): string
{
    return match ($modalidad) {
        'RX'   => 'DX',
        'TC'   => 'CT',
        'RMN'  => 'MR',
        'US'   => 'US',
        'MG'   => 'MG',
        'DEXA' => 'BMD',
        default => 'OT',
    };
}

/**
 * Devuelve la categoría destino para las imágenes (subcategoría de modalidad
 * bajo "Diagnostic Imaging", o la raíz de imágenes como fallback).
 */
function imaging_upload_category_id(string $modality): int
{
    $root = sqlQuery(
        "SELECT id FROM categories WHERE name = ? ORDER BY id LIMIT 1",
        [IMAGING_UPLOAD_ROOT_NAME]
    );
    $rootId = (int)($root['id'] ?? 0);
    if ($rootId <= 0) {
        return 0;
    }

    $subName = match ($modality) {
        'RMN' => 'Resonancia Magnética',
        'TC'   => 'Tomografía',
        default => '',
    };
    if ($subName !== '') {
        $sub = sqlQuery(
            "SELECT id FROM categories WHERE parent = ? AND name = ? ORDER BY id LIMIT 1",
            [$rootId, $subName]
        );
        if (!empty($sub['id'])) {
            return (int)$sub['id'];
        }
    }

    return $rootId;
}

/**
 * Valida y devuelve la información de un archivo subido, o un mensaje de error.
 *
 * @return array{ok:bool, ext:string, mime:string, size:int, error?:string}
 */
function imaging_validate_upload(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'ext' => '', 'mime' => '', 'size' => 0, 'error' => _imaging_upload_error_message((int)$file['error'])];
    }
    if (($file['size'] ?? 0) > IMAGING_UPLOAD_MAX_BYTES) {
        return ['ok' => false, 'ext' => '', 'mime' => '', 'size' => (int)$file['size'], 'error' => xl('File exceeds maximum allowed size (50 MB).')];
    }
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, IMAGING_UPLOAD_ALLOWED_EXT, true)) {
        return ['ok' => false, 'ext' => $ext, 'mime' => '', 'size' => (int)$file['size'], 'error' => xl('File type not allowed. Use: JPG, PNG, WEBP, PDF, DICOM.')];
    }
    $mime = mime_content_type((string)$file['tmp_name']) ?: '';
    if (!in_array($mime, IMAGING_UPLOAD_ALLOWED_MIME, true) && !in_array($ext, ['dcm', 'dicom'], true)) {
        return ['ok' => false, 'ext' => $ext, 'mime' => $mime, 'size' => (int)$file['size'], 'error' => xl('File content type not allowed.')];
    }
    return ['ok' => true, 'ext' => $ext, 'mime' => $mime, 'size' => (int)$file['size']];
}

/**
 * Sube un archivo de imagen: lo guarda en `documents` y lo envía al PACS del
 * proveedor de la orden. Registra la operación en form_imaging_report_images.
 *
 * @param array $file          Elemento de $_FILES
 * @param int   $pid           Paciente
 * @param int   $procedureOrderId Orden de procedimiento de origen
 * @param int   $formId        id de form_imaging_report (0 si aún no se guardó)
 * @param string $modality     Modalidad del informe (para tags DICOM / categoría)
 * @param int   $encounterId   Encuentro
 * @return array{success:bool, message:string, image_id:?int, document_id:?int, study_uid:?string}
 */
function imaging_upload_document(array $file, int $pid, int $procedureOrderId, int $formId, string $modality, int $encounterId): array
{
    $valid = imaging_validate_upload($file);
    if (!$valid['ok']) {
        return ['success' => false, 'message' => $valid['error'], 'image_id' => null, 'document_id' => null, 'study_uid' => null];
    }

    require_once $GLOBALS['srcdir'] . '/classes/Document.class.php';

    $ext = $valid['ext'];
    $mime = $valid['mime'] ?: ('application/octet-stream');
    $fileContent = file_get_contents((string)$file['tmp_name']);

    // Guardar en documents
    $categoryId = imaging_upload_category_id($modality);
    if ($categoryId <= 0) {
        $categoryId = imaging_default_category_id($modality); // fallback form helper
    }
    if ($categoryId <= 0) {
        $categoryId = 3; // última red de seguridad: categoría raíz de imágenes
    }

    $doc = new \Document();
    $errorMsg = $doc->createDocument(
        $pid,
        $categoryId,
        $file['name'],
        $mime,
        $fileContent,
        '',
        1,
        (int)($session->get('authUserID', 1)),
        $file['tmp_name'],
        null,
        $procedureOrderId,
        'procedure_order'
    );
    $documentId = (int)$doc->get_id();

    if (!empty($errorMsg) || $documentId <= 0) {
        return ['success' => false, 'message' => xl('Error saving document: ') . (string)$errorMsg, 'image_id' => null, 'document_id' => null, 'study_uid' => null];
    }

    if ($encounterId > 0) {
        sqlStatement("UPDATE documents SET encounter_id = ? WHERE id = ?", [$encounterId, $documentId]);
    }

    // Resolver proveedor PACS desde la orden
    $provider = PacsProvider::resolveForOrder($procedureOrderId);
    $studyUid = '';
    $pacsInstance = '';
    $pacsSeries = '';
    $pacsStudy = '';
    $status = 'uploaded';
    $errorMessage = null;

    if ($provider->isConfigured()) {
        if (in_array($ext, ['dcm', 'dicom'], true)) {
            $res = PacsService::uploadNativeDicom($provider, $fileContent);
            if ($res['success']) {
                // Forzar el estudio de la orden para agrupar todos los archivos
                // de la misma orden en un único estudio DICOM (igual que imágenes).
                $orderStudyUid = imaging_study_uid_for_order($procedureOrderId, $provider->ppid);
                $mod = PacsService::modifyInstance($provider, $res['instance_id'], $orderStudyUid);
                if ($mod['success']) {
                    $pacsInstance = (string)$mod['instance_id'];
                    $pacsSeries = (string)$mod['series_id'];
                    $pacsStudy = (string)$mod['study_id'];
                    $studyUid = $orderStudyUid;
                } else {
                    // Fallback: conservar el estudio original del DICOM si la
                    // reasignación falla (el documento igual queda guardado).
                    $pacsInstance = (string)$res['instance_id'];
                    $pacsSeries = (string)$res['series_id'];
                    $pacsStudy = (string)$res['study_id'];
                    $studyUid = $pacsStudy ? (PacsService::fetchStudyUid($provider, $pacsStudy) ?? '') : '';
                    if ($studyUid === '' && $pacsInstance) {
                        $studyUid = PacsService::fetchStudyUid($provider, $pacsInstance) ?? '';
                    }
                    $status = 'failed';
                    $errorMessage = $mod['message'];
                }
            } else {
                $status = 'failed';
                $errorMessage = $res['message'];
            }
        } else {
            $studyUid = imaging_study_uid_for_order($procedureOrderId, $provider->ppid);
            $dicomTags = imaging_build_dicom_tags($pid, $modality, $studyUid);
            $dicomTags['SeriesDescription'] = (string)$file['name'];
            if ($ext === 'pdf') {
                $parentStudy = ($pacsStudy !== '') ? $pacsStudy : '';
                $res = PacsService::uploadEncapsulatedPdf($provider, $fileContent, $pid, $parentStudy, $dicomTags);
                if (!$res['success']) {
                    $status = 'failed';
                    $errorMessage = $res['message'];
                }
            } else {
                $res = PacsService::uploadImageAsDicom($provider, $fileContent, $dicomTags);
                if ($res['success']) {
                    $pacsInstance = (string)$res['instance_id'];
                    $pacsSeries = (string)$res['series_id'];
                    $pacsStudy = (string)$res['study_id'];
                } else {
                    $status = 'failed';
                    $errorMessage = $res['message'];
                }
            }
        }
    }

    // Registrar en form_imaging_report_images (aunque el PACS falle, documentamos)
    $imageId = (int)sqlInsert(
        "INSERT INTO form_imaging_report_images
            (form_id, procedure_order_id, pid, encounter_id, document_id, provider_id,
             study_instance_uid, pacs_instance_id, pacs_series_id, pacs_study_id,
             modality, filename, status, error_message)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
        [
            $formId > 0 ? $formId : null,
            $procedureOrderId ?: null,
            $pid,
            $encounterId ?: null,
            $documentId,
            $provider->ppid ?: null,
            $studyUid ?: null,
            $pacsInstance ?: null,
            $pacsSeries ?: null,
            $pacsStudy ?: null,
            imaging_modality_to_dicom($modality),
            (string)$file['name'],
            $status,
            $errorMessage ? substr($errorMessage, 0, 500) : null,
        ]
    );

    if ($status === 'failed') {
        return ['success' => false, 'message' => xl('Document saved, but PACS upload failed: ') . $errorMessage, 'image_id' => $imageId, 'document_id' => $documentId, 'study_uid' => $studyUid];
    }

    return ['success' => true, 'message' => xl('Document and PACS upload completed.'), 'image_id' => $imageId, 'document_id' => $documentId, 'study_uid' => $studyUid];
}

/**
 * Devuelve los archivos de imágenes vinculados a un informe (o a una orden).
 *
 * @return array<int, array{id:int, filename:string, modality:string, status:string, study_uid:string, document_id:int}>
 */
function imaging_get_report_images(int $formId, int $procedureOrderId = 0): array
{
    $sql = "SELECT id, document_id, study_instance_uid, modality, filename, status, error_message
            FROM form_imaging_report_images
            WHERE 1=1";
    $bind = [];
    if ($formId > 0) {
        $sql .= " AND form_id = ?";
        $bind[] = $formId;
    } elseif ($procedureOrderId > 0) {
        $sql .= " AND procedure_order_id = ? AND (form_id IS NULL OR form_id = 0)";
        $bind[] = $procedureOrderId;
    }
    $sql .= " ORDER BY id ASC";

    $res = sqlStatement($sql, $bind);
    $rows = [];
    while ($row = sqlFetchArray($res)) {
        $rows[] = $row;
    }
    return $rows;
}

/**
 * Asocia imágenes previamente subidas (sin informa) al informe recién guardado.
 */
function imaging_attach_images_to_report(int $formId, int $procedureOrderId, int $pid): void
{
    if ($formId <= 0) {
        return;
    }
    sqlStatement(
        "UPDATE form_imaging_report_images SET form_id = ? WHERE procedure_order_id = ? AND pid = ? AND (form_id IS NULL OR form_id = 0)",
        [$formId, $procedureOrderId, $pid]
    );
}

/**
 * Devuelve una etiqueta HTML de estado para una fila de imagen subida.
 */
function imaging_status_badge(string $status): string
{
    if ($status === 'failed') {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">' . xlt('Failed') . '</span>';
    }
    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700">' . xlt('Uploaded') . '</span>';
}

/**
 * Renderiza la fila HTML (Tailwind) de un documento de imágenes ya subido,
 * para la lista "Uploaded documents" del formulario.
 *
 * @param array $img  Fila de imaging_get_report_images()
 */
function imaging_render_uploaded_row(array $img): string
{
    $safeName = htmlspecialchars((string)($img['filename'] ?? ''), ENT_QUOTES);
    $badge = imaging_status_badge((string)($img['status'] ?? 'uploaded'));
    $studyUid = (string)($img['study_instance_uid'] ?? '');
    $modalidad = htmlspecialchars((string)($img['modality'] ?? ''), ENT_QUOTES);
    $docUrl = '';

    if (!empty($img['document_id'])) {
        $docUrl = $GLOBALS['webroot'] . '/controller.php?document&retrieve&patient_id=' .
            urlencode((string)($img['pid'] ?? 0)) . '&document_id=' . urlencode((string)$img['document_id']) . '&as_file=false';
    }

    $uidHtml = $studyUid !== ''
        ? '<span class="text-xs text-slate-400">' . $studyUid . '</span>'
        : '<span class="text-xs text-slate-400">' . xlt('No study UID') . '</span>';

    $viewLink = $docUrl !== ''
        ? '<a href="' . htmlspecialchars($docUrl, ENT_QUOTES) . '" target="_blank" class="text-xs text-sky-600 hover:underline">' . xlt('View') . '</a>'
        : '';

    return '<div class="px-4 py-3 flex items-center justify-between gap-3" data-image-id="' . (int)($img['id'] ?? 0) . '">
                <div class="min-w-0">
                    <div class="text-sm font-medium text-slate-700 truncate">' . $safeName . '</div>
                    <div class="flex items-center gap-2 mt-0.5">
                        <span class="text-xs text-slate-400">' . $modalidad . '</span>
                        ' . $uidHtml . '
                    </div>
                </div>
                <div class="flex items-center gap-2 shrink-0">
                    ' . $viewLink . '
                    ' . $badge . '
                </div>
            </div>';
}

function _imaging_upload_error_message(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => xl('File exceeds maximum allowed size.'),
        UPLOAD_ERR_PARTIAL => xl('File was only partially uploaded. Please try again.'),
        UPLOAD_ERR_NO_FILE => xl('No file was selected.'),
        UPLOAD_ERR_NO_TMP_DIR => xl('Temporary directory not available on server.'),
        UPLOAD_ERR_CANT_WRITE => xl('Failed to write file to disk.'),
        default => xl('Unknown upload error'),
    };
}
