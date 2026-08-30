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

// Verificación CSRF
CsrfUtils::checkCsrfInput(INPUT_POST, dieOnFail: true);

$mode   = $_GET['mode'] ?? 'new';
$formId = (int)($_GET['id'] ?? 0);
$accion = $_POST['accion'] ?? 'borrador'; // 'borrador' o 'finalizar'

// ============================================================
// Sanitización y preparación de datos
// ============================================================
$fields = [
    'modalidad'            => trim((string)($_POST['modalidad'] ?? '')),
    'region_anatomica'     => trim((string)($_POST['region_anatomica'] ?? '')),
    'servicio_solicitante' => trim((string)($_POST['servicio_solicitante'] ?? '')),
    'medico_informante'    => trim((string)($_POST['medico_informante'] ?? '')),
    'medico_solicitante'   => trim((string)($_POST['medico_solicitante'] ?? '')),
    'metodologia'          => trim((string)($_POST['metodologia'] ?? '')),
    'interpretacion'       => trim((string)($_POST['interpretacion'] ?? '')),
    'conclusion'           => trim((string)($_POST['conclusion'] ?? '')),
    'observaciones'        => trim((string)($_POST['observaciones'] ?? '')),
    'estado'               => ($accion === 'finalizar') ? 'finalizado' : 'borrador',
    'fecha_informe'        => $_POST['fecha_informe'] ?: date('Y-m-d'),
];

// ============================================================
// Guardar en la base de datos
// ============================================================
if ($mode === 'new') {
    $newId = formSubmit('form_imaging_report', $fields, $encounter, $userauthorized);
    addForm($encounter, 'Informe de Diagnóstico por Imágenes', $newId, 'imaging_report', $pid, $userauthorized);
    $formId = (int)$newId;
} elseif ($mode === 'update' && $formId > 0) {
    formUpdate('form_imaging_report', $fields, $formId, $userauthorized);
}

// ============================================================
// Si el usuario pide finalizar, generar PDF
// ============================================================
if ($accion === 'finalizar' && $formId > 0) {
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

formHeader("Redireccionando...");
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
    // con fallback a la ruta local del formulario.
    $logoPath = null;
    if (!empty($GLOBALS['images_static_absolute'])) {
        $candidate = rtrim((string)$GLOBALS['images_static_absolute'], '/') . '/logo.png';
        if (file_exists($candidate)) {
            $logoPath = $candidate;
        }
    }
    if (!$logoPath) {
        $searches = [
            dirname(__DIR__, 4) . '/public/assets/img/logo.png',
            dirname(__DIR__, 4) . '/assets/img/logo.png',
            $siteDir . '/assets/img/logo.png',
        ];
        foreach ($searches as $candidate) {
            if (file_exists($candidate)) {
                $logoPath = $candidate;
                break;
            }
        }
    }

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
        throw new \RuntimeException('Dompdf no encontrado. Verifique composer install.');
    }
    require_once $dompdfAutoload;

    if (!class_exists(\Dompdf\Dompdf::class)) {
        throw new \RuntimeException('La clase Dompdf\\Dompdf no está disponible.');
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
    $categoryId = resolveImagingCategoryId();

    $pdfFileName = 'Informe_Imagenes_' . $formId . '_' . date('Ymd_His') . '.pdf';

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
        throw new \RuntimeException('Error guardando el documento: ' . $ret);
    }

    return (int)$doc->get_id() ?: null;
}

/**
 * Encuentra la categoría de "Imaging" / "Diagnóstico por Imágenes",
 * creándola como nodo hijo de la raíz si no existe (manteniendo el
 * árbol MPTT de OpenEMR). Devuelve el id de categoría.
 */
function resolveImagingCategoryId(): int
{
    $cat = sqlQuery(
        "SELECT id FROM categories WHERE name = ? OR name LIKE '%Imag%' OR name LIKE '%Radiolog%' ORDER BY id LIMIT 1",
        ['Diagnóstico por Imágenes']
    );
    if (!empty($cat['id'])) {
        return (int)$cat['id'];
    }

    // Crear la categoría bajo la raíz (id = 1 "Categories") usando la clase
    // CategoryTree, que mantiene correctamente los valores lft/rght (MPTT).
    $categoryTree = new \CategoryTree(1);
    $newId = $categoryTree->add_node(1, 'Diagnóstico por Imágenes', 'imaging', 'patients|docs', '');

    return (int)$newId;
}
