<?php
/**
 * Informe de Diagnóstico por Imágenes - save.php
 *
 * Procesador de datos, guardado SQL y generación de PDF Dompdf.
 * Indexa el PDF en la tabla documents de OpenEMR.
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

$srcdir    = OEGlobalsBag::getInstance()->getSrcDir();
$session   = SessionWrapperFactory::getInstance()->getActiveSession();
$pid       = PatientSessionUtil::getPid();
$encounter = EncounterSessionUtil::getEncounter();
$userauthorized = PatientSessionUtil::getUserAuthorized();

require_once("$srcdir/api.inc.php");
require_once("$srcdir/forms.inc.php");
require_once(__DIR__ . '/category_functions.php');
require_once(__DIR__ . '/imaging_upload_functions.php');

// Verificación CSRF
CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);

$mode   = $_GET['mode'] ?? 'new';
$formId = (int)($_GET['id'] ?? 0);
$action = $_POST['action'] ?? 'draft'; // 'draft' or 'finalize'

// ============================================================
// Data sanitization and preparation
// ============================================================
// The report date is auto-generated ONLY when finalizing (generating the PDF).
// On drafts we keep the previously stored value (null or the already generated one).
$reportDate = null;
if ($mode === 'update' && $formId > 0) {
    $existing  = sqlQuery("SELECT report_date FROM form_imaging_report WHERE id = ?", [$formId]);
    $reportDate = $existing['report_date'] ?? null;
}
if ($action === 'finalize') {
    $reportDate = date('Y-m-d');
}

$fields = [
    'modality'             => trim((string)($_POST['modality'] ?? '')),
    'anatomical_region'    => trim((string)($_POST['anatomical_region'] ?? '')),
    'requesting_service'   => trim((string)($_POST['requesting_service'] ?? '')),
    'reporting_physician'  => trim((string)($_POST['reporting_physician'] ?? '')),
    'requesting_physician' => trim((string)($_POST['requesting_physician'] ?? '')),
    'technique'            => trim((string)($_POST['technique'] ?? '')),
    'interpretation'       => trim((string)($_POST['interpretation'] ?? '')),
    'conclusion'           => trim((string)($_POST['conclusion'] ?? '')),
    'observations'         => trim((string)($_POST['observations'] ?? '')),
    'status'               => ($action === 'finalize') ? 'finalized' : 'draft',
    'study_date'           => $_POST['study_date'] ?: date('Y-m-d'),
    'report_date'          => $reportDate,
    'pdf_category_id'      => ((int)($_POST['category_id'] ?? 0)) ?: null,
    'procedure_order_id'   => ((int)($_POST['procedure_order_id'] ?? 0)) ?: null,
];

// ============================================================
// Save to the database
// ============================================================
if ($mode === 'new') {
    $newId = formSubmit('form_imaging_report', $fields, $encounter, $userauthorized);
    addForm($encounter, xl('Imaging Report'), $newId, 'imaging_report', $pid, $userauthorized);
    $formId = (int)$newId;
} elseif ($mode === 'update' && $formId > 0) {
    formUpdate('form_imaging_report', $fields, $formId, $userauthorized);
}

// Vincular imágenes previamente subidas (sin informe) a este registro.
$reportOrderId = (int)($fields['procedure_order_id'] ?? 0);
if ($formId > 0 && $reportOrderId > 0) {
    imaging_attach_images_to_report($formId, $reportOrderId, $pid);
}

// ============================================================
// If the user wants to finalize, generate the PDF
// ============================================================
if ($action === 'finalize' && $formId > 0) {
    try {
        $pdfDocumentId = generateAndStorePdf($pid, $formId, $fields, $session);
        if ($pdfDocumentId) {
            sqlStatement(
                "UPDATE form_imaging_report SET pdf_document_id = ? WHERE id = ?",
                [$pdfDocumentId, $formId]
            );
        }
    } catch (Throwable $e) {
        error_log("[imaging_report/save.php] Error generando PDF: " . $e->getMessage());
    }
}

formHeader(xl("Redirecting..."));
formJump();
formFooter();

// ============================================================
// FUNCIÓN: Generar PDF y guardarlo en documentos del paciente
// ============================================================
function generateAndStorePdf(int $pid, int $formId, array $fields, $session): ?int
{
    global $srcdir;
    $siteDir = OEGlobalsBag::getInstance()->getString('OE_SITE_DIR');

    // Datos del paciente
    $patientRow = sqlQuery(
        "SELECT fname, lname, mname, DOB, sex, ss, phone_cell FROM patient_data WHERE pid = ? LIMIT 1",
        [$pid]
    );

    // Datos del médico informante (para firma)
    $authUser = $session->get('authUser');
    $userRow  = sqlQuery("SELECT fname, lname, specialty, npi FROM users WHERE username = ? LIMIT 1", [$authUser]);

    // Datos del encuentro
    $encounter = EncounterSessionUtil::getEncounter();
    $encounterRow = sqlQuery(
        "SELECT date, facility FROM form_encounter WHERE pid = ? ORDER BY id DESC LIMIT 1",
        [$pid]
    );

    // Datos institucionales (facility principal de OpenEMR) para el membrete del PDF
    $facilityRow = sqlQuery(
        "SELECT name, street, city, state, postal_code, phone, email, fax
           FROM facility
          WHERE billing_location = 1
          ORDER BY id LIMIT 1"
    ) ?: [];

    // Logo institucional: prioriza la configuración de OpenEMR si existe,
    // con fallback a la ruta local del formulario (logo-banner.svg).
    $logoPath = null;
    if (!empty($GLOBALS['images_static_absolute'])) {
        $candidate = rtrim((string)$GLOBALS['images_static_absolute'], '/') . '/logo-banner.svg';
        if (file_exists($candidate)) {
            $logoPath = $candidate;
        }
    }
    if (!$logoPath) {
        $searches = [
            dirname(__DIR__, 4) . '/public/assets/img/logo-banner.svg',
            dirname(__DIR__, 4) . '/assets/img/logo-banner.svg',
            $siteDir . '/assets/img/logo-banner.svg',
        ];
        foreach ($searches as $candidate) {
            if (file_exists($candidate)) {
                $logoPath = $candidate;
                break;
            }
        }
    }

    // 0. Carpeta destino + UID del estudio DICOM: se resuelven ANTES de renderizar
    //    la plantilla para poder mostrar el StudyInstanceUID y generar el QR hacia
    //    el visor OHIF dentro del PDF. El estudio se obtiene de las imágenes subidas
    //    por el flujo directo (form_imaging_report_images) de la orden del informe;
    //    el PDF en sí NO se sube a PACS (permanece en documents de OpenEMR).
    $userCategoryId = (int)($fields['pdf_category_id'] ?? 0);
    $categoryId = imaging_resolve_category_id($userCategoryId, $fields['modality'] ?? '');
    $fields['pdf_category_id'] = $categoryId;
    $fields['study_instance_uid'] = resolveReportStudyUid($formId, (int)($fields['procedure_order_id'] ?? 0), $pid);
    $studyOhifUrl = '';
    if (!empty($fields['study_instance_uid'])) {
        $ohifBase = defined('OHIF_VIEWER_BASE_URL') ? rtrim(OHIF_VIEWER_BASE_URL, '/') : '';
        if ($ohifBase !== '') {
            $studyOhifUrl = $ohifBase . '?StudyInstanceUIDs=' . urlencode($fields['study_instance_uid']);
        }
    }
    $fields['study_ohif_url'] = $studyOhifUrl;

    // 1. Renderizar la plantilla HTML del PDF
    ob_start();
    require __DIR__ . '/templates/pdf_template.php';
    $htmlContent = ob_get_clean();

    // 2. Instanciar Dompdf
    $dompdfAutoload = null;
    $searchPaths = [
        $GLOBALS['vendor_dir'] ?? null,
        dirname(__DIR__, 4) . '/vendor',
        dirname(__DIR__, 3) . '/vendor',
        '/var/www/html/origen.ar/hcd/vendor',
    ];

    foreach ($searchPaths as $path) {
        if ($path && file_exists($path . '/autoload.php')) {
            $dompdfAutoload = $path . '/autoload.php';
            break;
        }
    }

    if (!$dompdfAutoload) {
        throw new \RuntimeException(xl('Dompdf not found. Please verify composer install.'));
    }
    require_once $dompdfAutoload;

    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new \RuntimeException(xl('The Dompdf\\Dompdf class is not available.'));
    }

    $options = new \Dompdf\Options();
    // Solo activar recursos remotos si se requiere (p.ej. fuentes externas); los
    // assets del informe (logo) van embebidos en Base64 para evitar SSRF.
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'DejaVu Sans');
    $options->set('isPhpEnabled', false);
    $options->set('isFontSubsettingEnabled', true);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($htmlContent, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $pdfOutput = $dompdf->output();

    // 3. Guardar el documento usando la API nativa de OpenEMR.
    //    \Document::createDocument() se encarga de almacenar el PDF (filesystem,
    //    CouchDB o almacenamiento remoto según la configuración), encriptarlo si
    //    corresponde, generar hash/uuid, e indexarlo en `documents` y
    //    `categories_to_documents`. Devuelve '' en caso de éxito.
    //    La carpeta destino la eligió el técnico en el formulario (category_id);
    //    si no es válida o falta, se usa la automática según modalidad.

    $pdfFileName = 'Imaging_Report_' . $formId . '_' . date('Ymd_His') . '.pdf';

    $doc = new \Document();
    $ret = $doc->createDocument(
        $pid,                          // patient_id
        $categoryId,                   // category_id
        $pdfFileName,                  // filename
        'application/pdf',             // mimetype
        $pdfOutput,                    // &$data (contenido binario del PDF)
        eid: $encounter                // vinculación al encuentro (extra)
    );

    // createDocument() devuelve un string vacío en éxito, o un mensaje de error.
    if (!empty($ret)) {
        throw new \RuntimeException(xl('Error saving document: ') . $ret);
    }

    $documentId = (int)$doc->get_id();

    // 4. Registrar el StudyInstanceUID del estudio de imágenes subidas en el
    //    formulario, para que el portal enlace el informe a ese estudio. El PDF
    //    del informe NO se sube a PACS (permanece solo en documents de OpenEMR).
    if ($documentId) {
        try {
            $studyUid = resolveReportStudyUid($formId, (int)($fields['procedure_order_id'] ?? 0), $pid);
            if ($studyUid !== '') {
                sqlStatement(
                    "UPDATE form_imaging_report SET study_instance_uid = ? WHERE id = ?",
                    [$studyUid, $formId]
                );
            }
        } catch (\Throwable $e) {
            error_log("[imaging_report/save.php] Error registrando estudio DICOM: " . $e->getMessage());
        }
    }

    return $documentId ?: null;
}

/**
 * Resuelve el StudyInstanceUID del estudio de imágenes subidas por el flujo
 * directo (Fase 2) para la orden del informe. Se prioriza la imagen vinculada
 * a este mismo informe (form_id) y, si no hay, la primera imagen subida de la
 * orden del paciente. El PDF del informe NO se sube a PACS; este UID solo se
 * usa para enlazar el informe al estudio en el portal y para el QR del PDF.
 *
 * @param int $formId           ID de form_imaging_report
 * @param int $procedureOrderId ID de la orden (procedure_order_id)
 * @param int $pid              ID de paciente
 * @return string StudyInstanceUID ('' si no hay estudio asociado)
 */
function resolveReportStudyUid(int $formId, int $procedureOrderId, int $pid): string
{
    if ($formId > 0) {
        $row = sqlQuery(
            "SELECT study_instance_uid FROM form_imaging_report_images
              WHERE form_id = ? AND status = 'uploaded'
                AND study_instance_uid IS NOT NULL AND study_instance_uid != ''
              ORDER BY id ASC LIMIT 1",
            [$formId]
        );
        if (!empty($row['study_instance_uid'])) {
            return (string)$row['study_instance_uid'];
        }
    }
    if ($procedureOrderId > 0) {
        $row = sqlQuery(
            "SELECT study_instance_uid FROM form_imaging_report_images
              WHERE procedure_order_id = ? AND pid = ? AND status = 'uploaded'
                AND study_instance_uid IS NOT NULL AND study_instance_uid != ''
              ORDER BY id ASC LIMIT 1",
            [$procedureOrderId, $pid]
        );
        if (!empty($row['study_instance_uid'])) {
            return (string)$row['study_instance_uid'];
        }
    }
    return '';
}

/**
 * Traduce la modalidad del formulario a modalidad DICOM (para match y tags).
 */
function modalityToDicom(string $modalidad): string
{
    return match ($modalidad) {
        'RX'   => 'DX',
        'TC'   => 'CT',
        'RMN'  => 'MR',
        'US'   => 'US',
        'MG'   => 'MG',
        'DEXA' => 'BMD',
        'OT'   => 'OT',
        default => 'OT',
    };
}

