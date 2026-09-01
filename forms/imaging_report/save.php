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
    'pdf_category_id'      => ((int)($_POST['category_id'] ?? 0)) ?: null,
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
    //    el visor OHIF dentro del PDF. Primero se intenta reutilizar el estudio que
    //    el cron ya registró para la MISMA carpeta/categoría del paciente; si no se
    //    encuentra (p.ej. carpeta distinta), se consulta Orthanc directamente por
    //    PatientID priorizando la modalidad del informe.
    $userCategoryId = (int)($fields['pdf_category_id'] ?? 0);
    $categoryId = imaging_resolve_category_id($userCategoryId, $fields['modalidad'] ?? '');
    $fields['pdf_category_id'] = $categoryId;
    $dcmModality = modalityToDicom($fields['modalidad'] ?? '');
    $study = knownStudyForCategory($pid, $categoryId, $dcmModality);
    if (empty($study['study_uid'])) {
        $study = resolveStudyInstanceUid($pid, $fields['modalidad'] ?? '');
    }
    $fields['study_instance_uid'] = $study['study_uid'] ?? '';
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
    //    La carpeta destino la eligió el técnico en el formulario (category_id);
    //    si no es válida o falta, se usa la automática según modalidad.

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

    $documentId = (int)$doc->get_id();

    // 4. Vincular el informe al estudio DICOM del flujo previo de la orden:
    //    se resuelve el StudyInstanceUID del paciente en Orthanc (según modalidad)
    //    y se guarda en el formulario. Además se sube el PDF como "Encapsulated
    //    PDF" al mismo estudio para que el médico lo vea junto a las series en
    //    OHIF / Workstation. Todo es opcional: si Orthanc no responde no se
    //    bloquea el guardado del informe.
    if ($documentId) {
        try {
            $dicom = linkPdfToDicomStudy($pid, $documentId, $formId, $fields['modalidad'] ?? '', $pdfOutput, $categoryId);
            if (!empty($dicom['study_uid'])) {
                sqlStatement(
                    "UPDATE form_imaging_report SET study_instance_uid = ?, accession_number = ? WHERE id = ?",
                    [$dicom['study_uid'], $dicom['accession'] ?: null, $formId]
                );
            }
        } catch (\Throwable $e) {
            error_log("[imaging_report/save.php] Error vinculando a DICOM/Orthanc: " . $e->getMessage());
        }
    }

    return $documentId ?: null;
}

/**
 * Resuelve el StudyInstanceUID del estudio del paciente desde Orthanc y, si hay
 * contenido PDF, lo sube como Encapsulated PDF al mismo estudio.
 *
 * La vinculación se hace con el "flujo previo de la orden": se listan los
 * estudios del paciente en Orthanc y se elige el más reciente, priorizando el
 * que coincida con la modalidad del informe.
 *
 * @return array{study_uid:string, accession:string}
 */
function linkPdfToDicomStudy(int $pid, int $documentId, int $formId, string $modality, string $pdfContent, ?int $categoryId = null): array
{
    // Primero se intenta reutilizar el StudyInstanceUID que el cron ya registró
    // en documents_pacs_sync para la MISMA carpeta/categoría del mismo paciente
    // (las imágenes JPG/DICOM que comparten carpeta y encuentro). Es la fuente
    // más fiable: garantiza que el PDF quede en el MISMO estudio que las imágenes
    // en OHIF/STONE. Solo si no hay registro conocido se recurre a Orthanc.
    $study = knownStudyForCategory($pid, $categoryId, modalityToDicom($modality));
    if (empty($study['study_uid'])) {
        $study = resolveStudyInstanceUid($pid, $modality);
    }
    $studyUid = $study['study_uid'] ?? '';
    $accession = $study['accession'] ?? '';
    $parentStudy = (string)($study['orthanc_study_id'] ?? '');

    if ($studyUid !== '') {
        // Registrar el mapeo en documents_pacs_sync para que el portal/OHIF
        // conozcan el estudio del PDF. Se marca como 'synced' (el PDF ya está
        // en OpenEMR; el vínculo es de referencia del estudio DICOM).
        try {
            sqlStatement(
                "INSERT INTO documents_pacs_sync
                    (document_id, patient_id, study_instance_uid, modality, status, synced_at)
                 VALUES (?, ?, ?, ?, 'synced', NOW())
                 ON DUPLICATE KEY UPDATE study_instance_uid = VALUES(study_instance_uid),
                    modality = VALUES(modality), status = 'synced'",
                [$documentId, $pid, $studyUid, modalityToDicom($modality)]
            );
        } catch (\Throwable $e) {
            error_log("[imaging_report/save.php] No se pudo registrar documents_pacs_sync: " . $e->getMessage());
        }

        // Subir el PDF a Orthanc como Encapsulated PDF asociado al estudio.
        if (!empty($pdfContent)) {
            uploadEncapsulatedPdfToOrthanc($pdfContent, $pid, $parentStudy, $modality, (string)$formId);
        }
    }

    return ['study_uid' => $studyUid, 'accession' => $accession];
}

/**
 * Recupera el StudyInstanceUID que el cron ya registró para esta carpeta/categoría
 * y paciente en documents_pacs_sync (las imágenes JPG/DICOM del mismo encuentro).
 * Es más fiable que adivinar por /tools/find porque garantiza que el informe PDF
 * quede dentro del MISMO estudio que las imágenes en OHIF/STONE.
 *
 * Prioriza un estudio cuya modalidad DICOM coincida con la del informe; si no,
 * devuelve el más recientemente sincronizado de esa categoría.
 *
 * @param int    $pid
 * @param ?int   $categoryId
 * @param string $dicomModality  Modalidad DICOM objetivo (ej: 'MR', 'CT', 'BMD')
 * @return array{study_uid:string, accession:string}
 */
function knownStudyForCategory(int $pid, ?int $categoryId, string $dicomModality = ''): array
{
    if (empty($categoryId) || $categoryId <= 0) {
        return ['study_uid' => '', 'accession' => ''];
    }

    $whereMod = '';
    $bind = [$pid, $categoryId];
    if ($dicomModality !== '') {
        $whereMod = ' AND dps.modality = ?';
        $bind[] = $dicomModality;
    }

    $row = sqlQuery(
        "SELECT dps.study_instance_uid, dps.orthanc_study_id
           FROM documents_pacs_sync dps
           JOIN categories_to_documents ctd ON ctd.document_id = dps.document_id
          WHERE dps.patient_id = ?
            AND ctd.category_id = ?
            AND dps.study_instance_uid IS NOT NULL
            AND dps.study_instance_uid != ''
            AND dps.status = 'synced'{$whereMod}
          ORDER BY dps.synced_at DESC
          LIMIT 1",
        $bind
    );

    if (empty($row) || empty($row['study_instance_uid'])) {
        return ['study_uid' => '', 'accession' => '', 'orthanc_study_id' => ''];
    }

    return [
        'study_uid'        => (string)$row['study_instance_uid'],
        'accession'        => '',
        'orthanc_study_id' => (string)($row['orthanc_study_id'] ?? ''),
    ];
}

/**
 * Lista los estudios de un paciente en Orthanc vía REST (/tools/find) y elige
 * el más reciente, priorizando el que coincida con la modalidad.
 */
function resolveStudyInstanceUid(int $pid, string $modality): array
{
    $orthancUrl  = getenv('ORTHANC_URL') ?: 'http://127.0.0.1:8042';
    $orthancUser = getenv('ORTHANC_USER') ?: 'orthanc';
    $orthancPass = getenv('ORTHANC_PASS') ?: 'orthanc';
    $dicomModality = modalityToDicom($modality);

    $pidStr = (string)$pid;
    $idsToSearch = array_unique([
        $pidStr,
        (string)ltrim($pidStr, '0'),
        (string)str_pad($pidStr, 4, '0', STR_PAD_LEFT),
        (string)str_pad($pidStr, 6, '0', STR_PAD_LEFT),
        (string)str_pad($pidStr, 8, '0', STR_PAD_LEFT)
    ]);

    $found = [];
    foreach ($idsToSearch as $idVal) {
        try {
            $ch = curl_init(rtrim($orthancUrl, '/') . '/tools/find');
            curl_setopt_array($ch, [
                CURLOPT_URL            => rtrim($orthancUrl, '/') . '/tools/find',
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode([
                    'Level'  => 'Study',
                    'Query'  => ['PatientID' => (string)$idVal],
                    'Expand' => true
                ]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_USERPWD        => "{$orthancUser}:{$orthancPass}",
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_CONNECTTIMEOUT => 2
            ]);
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode !== 200 || empty($response)) {
                continue;
            }
            foreach ((json_decode($response, true) ?: []) as $study) {
                $tags = $study['MainDicomTags'] ?? [];
                $mods = $tags['ModalitiesInStudy'] ?? ($tags['Modality'] ?? '');
                $found[] = [
                    'uid'       => (string)($tags['StudyInstanceUID'] ?? ''),
                    'accession' => (string)($tags['AccessionNumber'] ?? ''),
                    'modality'  => is_array($mods) ? implode(',', array_map('strval', $mods)) : (string)$mods,
                    'date'      => substr((string)($tags['StudyDate'] ?? ''), 0, 8),
                ];
            }
        } catch (\Throwable $e) {
            error_log("[imaging_report/save.php] Aviso Orthanc (find): " . $e->getMessage());
        }
    }

    if (empty($found)) {
        return ['study_uid' => '', 'accession' => ''];
    }

    // Priorizar estudio que coincida con la modalidad del informe.
    if ($dicomModality !== '') {
        foreach ($found as $s) {
            if (stripos($s['modality'], $dicomModality) !== false) {
                return ['study_uid' => $s['uid'], 'accession' => $s['accession']];
            }
        }
    }

    // Fallback: el más reciente.
    usort($found, function ($a, $b) {
        return strcmp((string)($b['date'] ?? ''), (string)($a['date'] ?? ''));
    });
    $best = $found[0];
    return ['study_uid' => $best['uid'], 'accession' => $best['accession']];
}

/**
 * Sube un PDF a Orthanc como "Encapsulated PDF" (modality OT) dentro del estudio
 * indicado, vía el endpoint REST /tools/create-dicom.
 *
 * Para adjuntarlo a un estudio EXISTENTE se usa el campo "Parent" con el
 * orthanc_study_id (id interno), NO StudyInstanceUID, que dispara el error
 * Orthanc 2020 "Trying to override a value inherited from a parent module".
 * Si $parentStudy es vacío, Orthanc crea un estudio nuevo.
 */
function uploadEncapsulatedPdfToOrthanc(string $pdfContent, int $pid, string $parentStudy, string $modality, string $refId): bool
{
    $orthancUrl  = getenv('ORTHANC_URL') ?: 'http://127.0.0.1:8042';
    $orthancUser = getenv('ORTHANC_USER') ?: 'orthanc';
    $orthancPass = getenv('ORTHANC_PASS') ?: 'orthanc';

    $tags = [
        'PatientID'        => (string)$pid,
        'Modality'         => 'OT', // Other / Encapsulated PDF
        'SOPClassUID'      => '1.2.840.10008.5.1.4.1.1.104.1', // Encapsulated PDF Storage
        'SeriesDescription'=> 'Informe de Diagnóstico por Imágenes',
        'SeriesNumber'     => '1',
        'DocumentTitle'    => 'Informe de Diagnóstico por Imágenes (# ' . $refId . ')',
        'AccessionNumber'  => 'DOC-' . $refId,
    ];

    $payloadArr = [
        'Tags'    => $tags,
        'Content' => 'data:application/pdf;base64,' . base64_encode($pdfContent)
    ];
    if ($parentStudy !== '') {
        $payloadArr['Parent'] = $parentStudy;
    }
    $payload = json_encode($payloadArr);

    $ch = curl_init(rtrim($orthancUrl, '/') . '/tools/create-dicom');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_USERPWD        => "{$orthancUser}:{$orthancPass}",
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 5
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }
    error_log("[imaging_report/save.php] Orthanc create-dicom falló (HTTP $httpCode): " . substr((string)$response, 0, 300));
    return false;
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

