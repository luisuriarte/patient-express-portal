<?php
/**
 * Configuración Global e Integración Nativa con OpenEMR
 * Patient Express Portal
 */

declare(strict_types=1);

// Evitar que el login estándar de OpenEMR intercepte las peticiones del portal express
$ignoreAuth = true;
$ignoreAuth_onsite_portal = true;

// Configurar entorno CLI para bootstrap de OpenEMR
if (php_sapi_name() === 'cli' || empty($_SERVER['HTTP_HOST'])) {
    $_SERVER['HTTP_HOST']   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $_SERVER['SERVER_NAME'] = $_SERVER['SERVER_NAME'] ?? 'localhost';
    $_SERVER['REQUEST_URI'] = $_SERVER['REQUEST_URI'] ?? '/';
    $_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION['site_id']    = $_SESSION['site_id'] ?? 'default';
    $GLOBALS['oe_site_id']  = 'default';
}

// 1. Bootstrap Nativo de OpenEMR (Subiendo niveles hasta interface/globals.php)
$globalsIncluded = false;
$searchPaths = [
    __DIR__ . '/../interface/globals.php',
    __DIR__ . '/../../interface/globals.php',
    __DIR__ . '/../../../interface/globals.php',
    dirname(__DIR__, 2) . '/interface/globals.php',
    '/var/www/html/origen.ar/hcd/interface/globals.php',
    '/var/www/html/openemr/interface/globals.php'
];

foreach ($searchPaths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $globalsIncluded = true;
        break;
    }
}

// 2. Parámetros de Integración PACS / OHIF / OpenEMR
if (!defined('ORTHANC_URL')) {
    define('ORTHANC_URL', getenv('ORTHANC_URL') ?: 'http://127.0.0.1:8042');
}
if (!defined('ORTHANC_USER')) {
    define('ORTHANC_USER', getenv('ORTHANC_USER') ?: 'orthanc');
}
if (!defined('ORTHANC_PASS')) {
    define('ORTHANC_PASS', getenv('ORTHANC_PASS') ?: 'orthanc');
}
if (!defined('ORTHANC_TIMEOUT')) {
    define('ORTHANC_TIMEOUT', 4);
}
if (!defined('ORTHANC_WADO_URL')) {
    define('ORTHANC_WADO_URL', getenv('ORTHANC_WADO_URL') ?: 'https://pacs.origen.ar/dicom-web');
}
if (!defined('OHIF_VIEWER_BASE_URL')) {
    define('OHIF_VIEWER_BASE_URL', getenv('OHIF_VIEWER_BASE_URL') ?: 'https://imagenes.origen.ar/viewer');
}
if (!defined('OPENEMR_PORTAL_URL')) {
    define('OPENEMR_PORTAL_URL', getenv('OPENEMR_PORTAL_URL') ?: 'https://hcd.origen.ar/portal');
}

// 3. Datos Institucionales
//    Se cargan dinámicamente desde la tabla `facility` de OpenEMR (facility
//    de facturación/billing principal) para NO exponer datos del centro en
//    código. El logo sí se mantiene como recurso estático local.
$facility = [];
if ($globalsIncluded && function_exists('sqlQuery')) {
    try {
        $facility = sqlQuery(
            "SELECT name, phone, fax, street, city, state, postal_code, country_code, website, email
               FROM facility
              WHERE billing_location = 1
                AND inactive = 0
              ORDER BY id LIMIT 1"
        ) ?: [];
    } catch (\Throwable $e) {
        $facility = [];
    }
}

$facName   = trim((string)($facility['name'] ?? ''));
$facPhone  = trim((string)($facility['phone'] ?? ''));
$facEmail  = trim((string)($facility['email'] ?? ''));
$facWeb    = trim((string)($facility['website'] ?? ''));
$addressParts = array_filter([
    trim((string)($facility['street'] ?? '')),
    trim((string)($facility['city'] ?? '')),
    trim((string)($facility['state'] ?? '')),
    trim((string)($facility['postal_code'] ?? '')),
]);
$facAddress = implode(', ', $addressParts);

if (!defined('CLINIC_NAME'))     define('CLINIC_NAME',     $facName ?: 'Centro de Salud');
if (!defined('CLINIC_SUBTITLE')) define('CLINIC_SUBTITLE', 'Portal del Paciente');
if (!defined('CLINIC_ADDRESS'))  define('CLINIC_ADDRESS',  $facAddress);
if (!defined('CLINIC_PHONE'))    define('CLINIC_PHONE',    $facPhone);
if (!defined('CLINIC_EMAIL'))    define('CLINIC_EMAIL',    $facEmail);
if (!defined('CLINIC_WEB'))      define('CLINIC_WEB',      $facWeb);
if (!defined('CLINIC_LOGO_PATH')) {
    $logoFile = __DIR__ . '/assets/img/logo-banner.svg';
    if (!file_exists($logoFile)) {
        $logoFile = __DIR__ . '/public/assets/img/logo-banner.svg';
    }
    define('CLINIC_LOGO_PATH', $logoFile);
}

// 4. Autoloading de Clases
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
} elseif (file_exists(dirname(__DIR__, 2) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__, 2) . '/vendor/autoload.php';
}

spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $baseDir = __DIR__ . '/src/';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }
    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
