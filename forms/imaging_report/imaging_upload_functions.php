<?php
/**
 * imaging_upload_functions.php
 *
 * Functions for DIRECT upload of imaging documents (DICOM, JPG/PNG,
 * PDF or a ZIP containing a study) from the imaging diagnostic
 * report form.
 *
 * Unlike the previous cron job (which synchronized Documents -> PACS
 * in batch), each file is uploaded in real time: saved to 'documents'
 * and, if a PACS provider is configured, immediately uploaded to the
 * order's PACS (procedure_order.lab_id -> procedure_providers).
 *
 * "Same study per order" convention: for images, PDFs and native DICOM,
 * a deterministic StudyInstanceUID derived from the order is enforced, so
 * all series from the same order coexist in a single DICOM study on the
 * PACS. Native DICOM files are reassigned to that study after upload
 * (using Force in Orthanc); if reassignment fails, they keep their
 * original study.
 *
 * Requires: globals.php included (sqlQuery/sqlStatement), and the
 * `form_imaging_report_images` table existing (see forms/imaging_report/table.sql).
 */

use App\PacsProvider;
use App\PacsService;

require_once __DIR__ . '/category_functions.php';

// Ensure autoload for project classes (App\...) in any context
// (OpenEMR clinical form or standalone execution). A global fallback is used
// in case the project's composer autoload is not already registered.
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

define('IMAGING_UPLOAD_MAX_BYTES', 50 * 1024 * 1024); // 50 MB per file

const IMAGING_UPLOAD_ALLOWED_EXT = ['jpg', 'jpeg', 'png', 'webp', 'dcm', 'dicom', 'pdf', 'zip'];
const IMAGING_UPLOAD_ALLOWED_MIME = [
    'image/jpeg',
    'image/png',
    'image/webp',
    'application/pdf',
    'application/dicom',
    'image/dicom',
    'application/octet-stream',
    'application/zip',
    'application/x-zip-compressed',
];

const IMAGING_UPLOAD_ROOT_NAME = 'Diagnostic Imaging';

/**
 * Builds common DICOM tag headers for a patient/modality.
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
 * Generates a deterministic StudyInstanceUID for an order, so that all
 * files from the same order share the same study on the PACS.
 */
function imaging_study_uid_for_order(int $procedureOrderId, int $providerId): string
{
    return '1.2.840.113619.2.55.' . $procedureOrderId . '.' . ($providerId ?: 0);
}

/**
 * Translates the form modality to DICOM modality.
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
 * Returns the destination category for images (modality subcategory
 * under "Diagnostic Imaging", or the root images category as fallback).
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
 * Validates and returns uploaded file information, or an error message.
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
 * Uploads an image file: saves it to `documents` and sends it to the
 * order provider's PACS. Logs the operation in form_imaging_report_images.
 *
 * @param array $file          $_FILES element
 * @param int   $pid           Patient
 * @param int   $procedureOrderId Source procedure order
 * @param int   $formId        ID of form_imaging_report (0 if not yet saved)
 * @param string $modality     Report modality (for DICOM tags / category)
 * @param int   $encounterId   Encounter
 * @param bool  $skipPacsUpload If true, only saves to `documents` (and logs
 *                              in form_imaging_report_images) without uploading
 *                              to PACS. Used for internal files of a ZIP, which
 *                              are uploaded compressed to PACS as a single unit.
 * @param bool  $pacsDisabled   If true, the file must NOT go to PACS because the
 *                              "Also upload to PACS server" checkbox is
 *                              disabled; logged with status='skipped'.
 * @return array{success:bool, message:string, image_id:?int, document_id:?int, study_uid:?string}
 */
function imaging_upload_document(array $file, int $pid, int $procedureOrderId, int $formId, string $modality, int $encounterId, bool $skipPacsUpload = false, bool $pacsDisabled = false): array
{
    $valid = imaging_validate_upload($file);
    if (!$valid['ok']) {
        return ['success' => false, 'message' => $valid['error'], 'image_id' => null, 'document_id' => null, 'study_uid' => null];
    }

    require_once $GLOBALS['srcdir'] . '/classes/Document.class.php';

    $ext = $valid['ext'];
    $mime = $valid['mime'] ?: ('application/octet-stream');
    $fileContent = file_get_contents((string)$file['tmp_name']);

    // Save to documents
    $categoryId = imaging_upload_category_id($modality);
    if ($categoryId <= 0) {
        $categoryId = imaging_default_category_id($modality); // form helper fallback
    }
    if ($categoryId <= 0) {
        $categoryId = 3; // last resort: root images category
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

    // Resolve PACS provider from the order
    $provider = PacsProvider::resolveForOrder($procedureOrderId);
    $studyUid = '';
    $pacsInstance = '';
    $pacsSeries = '';
    $pacsStudy = '';
    $status = 'uploaded';
    $errorMessage = null;

    // If the "Also upload to PACS server" checkbox is disabled, the file
    // remains in documents only and is logged as 'skipped' (not sent to PACS).
    if ($pacsDisabled) {
        $status = 'skipped';
    }

    if (($skipPacsUpload || $pacsDisabled) && $provider->ppid) {
        // Files (internal to a ZIP or with the PACS checkbox disabled)
        // are logged with the order's study in their individual record.
        $studyUid = imaging_study_uid_for_order($procedureOrderId, $provider->ppid);
    }

    if (!$skipPacsUpload && !$pacsDisabled && $provider->isConfigured()) {
        if (in_array($ext, ['dcm', 'dicom'], true)) {
            $res = PacsService::uploadNativeDicom($provider, $fileContent);
            if ($res['success']) {
                // Force the order's study to group all files
                // from the same order into a single DICOM study (same as images).
                $orderStudyUid = imaging_study_uid_for_order($procedureOrderId, $provider->ppid);
                $mod = PacsService::modifyInstance($provider, $res['instance_id'], $orderStudyUid);
                if ($mod['success']) {
                    $pacsInstance = (string)$mod['instance_id'];
                    $pacsSeries = (string)$mod['series_id'];
                    $pacsStudy = (string)$mod['study_id'];
                    $studyUid = $orderStudyUid;
                } else {
                    // Fallback: keep the original DICOM study if the
                    // reassignment fails (the document is still saved).
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

    // Log in form_imaging_report_images (even if PACS fails, we document it)
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

    if ($status === 'skipped') {
        return ['success' => true, 'message' => xl('Document saved. PACS upload was not enabled.'), 'image_id' => $imageId, 'document_id' => $documentId, 'study_uid' => $studyUid];
    }

    return ['success' => true, 'message' => xl('Document and PACS upload completed.'), 'image_id' => $imageId, 'document_id' => $documentId, 'study_uid' => $studyUid];
}

/**
 * Uploads a ZIP file containing a study (folders with .dcm and optionally
 * images/PDF). Behavior:
 *   - In OpenEMR (`documents` folders): each internal file is saved
 *     individually (separately), with its own record in
 *     form_imaging_report_images.
 *   - In the PACS: the ZIP is uploaded compressed as a single unit (the PACS
 *     accepts it and handles extraction and import).
 *
 * @param array  $file          $_FILES element for the ZIP
 * @param int    $pid           Patient
 * @param int    $procedureOrderId Source procedure order
 * @param int    $formId        ID of form_imaging_report
 * @param string $modality      Report modality
 * @param int    $encounterId   Encounter
 * @param bool   $skipPacs      If true, the ZIP is NOT uploaded to PACS (the
 *                              "Also upload to PACS server" checkbox is
 *                              disabled); files are still extracted and saved
 *                              individually to `documents`.
 * @return array{success:bool, message:string, image_id:?int, document_id:?int, study_uid:?string, count_ok:int, count_fail:int}
 */
function imaging_upload_zip(array $file, int $pid, int $procedureOrderId, int $formId, string $modality, int $encounterId, bool $skipPacs = false): array
{
    $zipPath = (string)$file['tmp_name'];
    if (!class_exists('ZipArchive')) {
        return ['success' => false, 'message' => xl('ZIP support is not available on this server.'), 'image_id' => null, 'document_id' => null, 'study_uid' => null, 'count_ok' => 0, 'count_fail' => 0];
    }

    $zip = new \ZipArchive();
    if ($zip->open($zipPath) !== true) {
        return ['success' => false, 'message' => xl('Could not open the ZIP archive.'), 'image_id' => null, 'document_id' => null, 'study_uid' => null, 'count_ok' => 0, 'count_fail' => 0];
    }

    $workdir = sys_get_temp_dir() . '/imaging_zip_' . bin2hex(random_bytes(6));
    if (!@mkdir($workdir, 0777, true) && !is_dir($workdir)) {
        $zip->close();
        return ['success' => false, 'message' => xl('Could not create temporary directory for the ZIP.')];
    }

    $okCount = 0;
    $failCount = 0;
    $lastResult = ['success' => false, 'message' => '', 'image_id' => null, 'document_id' => null, 'study_uid' => null];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $entry = $zip->getNameIndex($i);
        if ($entry === false || substr($entry, -1) === DIRECTORY_SEPARATOR || substr($entry, -1) === '/') {
            continue; // directory entry
        }

        $ext = strtolower(pathinfo($entry, PATHINFO_EXTENSION));
        if (!in_array($ext, ['dcm', 'dicom', 'jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
            continue; // ignore non-image files (not uploaded)
        }

        // Extract to an individual temporary file and build the $_FILES element
        $safeName = basename($entry);
        $tmpFile = $workdir . '/' . $i . '_' . $safeName;
        $data = $zip->getFromIndex($i);
        if ($data === false) {
            $failCount++;
            continue;
        }
        file_put_contents($tmpFile, $data);

        $subFile = [
            'name' => $safeName,
            'type' => mime_content_type($tmpFile) ?: 'application/octet-stream',
            'tmp_name' => $tmpFile,
            'error' => UPLOAD_ERR_OK,
            'size' => strlen($data),
        ];

        // Individual save to documents + logging (without uploading to PACS: the
        // entire ZIP is uploaded to PACS compressed, below).
        $res = imaging_upload_document($subFile, $pid, $procedureOrderId, $formId, $modality, $encounterId, true, $skipPacs);
        $lastResult = $res;
        if ($res['success']) {
            $okCount++;
        } else {
            $failCount++;
        }
        @unlink($tmpFile);
    }
    $zip->close();

    // Upload the complete compressed ZIP to PACS as a single unit (only if the
    // "Also upload to PACS server" checkbox is enabled).
    $provider = PacsProvider::resolveForOrder($procedureOrderId);
    $zipFail = null;
    if (!$skipPacs && $okCount > 0 && $provider && $provider->isConfigured()) {
        $zipBinary = file_get_contents($zipPath);
        $zipRes = PacsService::uploadZipDicom($provider, $zipBinary);
        if (!$zipRes['success']) {
            $zipFail = $zipRes['message'];
        }
    }

    @array_map('unlink', glob($workdir . '/*') ?: []);
    @rmdir($workdir);

    $message = xl('ZIP processed: ') . $okCount . xl(' document(s) saved individually.') .
        ($skipPacs ? ' ' . xl('PACS upload was not enabled.') : '') .
        ($failCount > 0 ? ' ' . $failCount . xl(' failed.') : '') .
        ($zipFail ? ' ' . xl('PACS zip upload failed: ') . $zipFail : '');

    return [
        'success' => $okCount > 0 && !$zipFail,
        'message' => $message,
        'image_id' => $lastResult['image_id'],
        'document_id' => $lastResult['document_id'],
        'study_uid' => $lastResult['study_uid'],
        'count_ok' => $okCount,
        'count_fail' => $failCount + ($zipFail ? 1 : 0),
    ];
}

/**
 * Returns the imaging files linked to a report (or to an order).
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
 * Links previously uploaded images (without a report) to the newly saved report.
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
 * Returns an HTML status badge for an uploaded image row.
 */
function imaging_status_badge(string $status): string
{
    if ($status === 'failed') {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700">' . xlt('Failed') . '</span>';
    }
    if ($status === 'skipped') {
        return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-slate-200 text-slate-600">' . xlt('No PACS') . '</span>';
    }
    return '<span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-green-100 text-green-700">' . xlt('Uploaded') . '</span>';
}

/**
 * Renders the HTML row (Tailwind) for an already uploaded imaging document,
 * for the "Uploaded documents" list in the form.
 *
 * @param array $img  Row from imaging_get_report_images()
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
