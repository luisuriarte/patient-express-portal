<?php
/**
 * Database Configuration and System Parameters
 * Patient Express Portal - OpenEMR & PACS Orthanc / OHIF Connection
 */

declare(strict_types=1);

// Error configuration for production / development environment
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ==========================================
// 1. DATABASE CONSTANTS (OPENEMR)
// ==========================================
define('DB_HOST', getenv('OPENEMR_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('OPENEMR_DB_PORT') ?: '3306');
define('DB_NAME', getenv('OPENEMR_DB_NAME') ?: 'openemr');
define('DB_USER', getenv('OPENEMR_DB_USER') ?: 'openemr');
define('DB_PASS', getenv('OPENEMR_DB_PASS') ?: 'openemr');
define('DB_CHARSET', 'utf8mb4');

// ==========================================
// 2. PACS & SERVICES INTEGRATION CONSTANTS
// ==========================================
// Orthanc PACS REST API Server
define('ORTHANC_URL', getenv('ORTHANC_URL') ?: 'http://127.0.0.1:8042');
define('ORTHANC_USER', getenv('ORTHANC_USER') ?: 'orthanc');
define('ORTHANC_PASS', getenv('ORTHANC_PASS') ?: 'orthanc');
define('ORTHANC_TIMEOUT', 5); // REST query timeout in seconds

// OHIF DICOM Viewer
define('OHIF_VIEWER_BASE_URL', getenv('OHIF_VIEWER_BASE_URL') ?: 'https://imagenes.origen.ar/viewer');
define('ORTHANC_WADO_URL', getenv('ORTHANC_WADO_URL') ?: 'https://pacs.origen.ar/dicom-web');

// Full OpenEMR Portal
define('OPENEMR_PORTAL_URL', getenv('OPENEMR_PORTAL_URL') ?: 'https://hcd.origen.ar/portal');

// ==========================================
// 3. INSTITUTIONAL DATA (For headers and PDFs)
//    In this standalone deployment, facility data is not exposed in code:
//    neutral values are used. In the native deployment (config.php), this data
//    is loaded automatically from the OpenEMR `facility` table.
// ==========================================
define('CLINIC_NAME',     'Centro de Salud');
define('CLINIC_SUBTITLE', 'Portal del Paciente');
define('CLINIC_ADDRESS', '');
define('CLINIC_PHONE', '');
define('CLINIC_EMAIL', '');
define('CLINIC_WEB', '');
$logoFile = dirname(__DIR__) . '/assets/img/logo-banner.svg';
if (!file_exists($logoFile)) {
    $logoFile = dirname(__DIR__) . '/public/assets/img/logo-banner.svg';
}
define('CLINIC_LOGO_PATH', $logoFile);

// ==========================================
// 4. PDO CONNECTION HELPER FUNCTION
// ==========================================
/**
 * Retrieves the singleton connection to the OpenEMR database
 * 
 * @return PDO
 * @throws PDOException
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        // PHP 8.5+ compatibility for ATTR_INIT_COMMAND (or fallback on earlier versions)
        if (defined('\Pdo\Mysql::ATTR_INIT_COMMAND')) {
            $options[\Pdo\Mysql::ATTR_INIT_COMMAND] = "SET NAMES " . DB_CHARSET . " COLLATE " . DB_CHARSET . "_unicode_ci";
        } elseif (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . DB_CHARSET . " COLLATE " . DB_CHARSET . "_unicode_ci";
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('OpenEMR Database connection error: ' . $e->getMessage());
            throw new PDOException('Unable to establish connection to the medical records server.');
        }
    }

    return $pdo;
}

// Load Composer autoload if available
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
} else {
    // Fallback autoloader for classes in src/
    spl_autoload_register(function ($class) {
        $prefix = 'App\\';
        $baseDir = dirname(__DIR__) . '/src/';
        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            // Check direct class name in src
            $file = $baseDir . str_replace('\\', '/', $class) . '.php';
            if (file_exists($file)) {
                require_once $file;
            }
            return;
        }
        $relativeClass = substr($class, $len);
        $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    });
}
