<?php
/**
 * Configuración de Base de Datos y Parámetros del Sistema
 * Patient Express Portal - Conexión OpenEMR & PACS Orthanc / OHIF
 */

declare(strict_types=1);

// Configuración de errores para entorno de producción / desarrollo
ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

// ==========================================
// 1. CONSTANTES DE BASE DE DATOS (OPENEMR)
// ==========================================
define('DB_HOST', getenv('OPENEMR_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('OPENEMR_DB_PORT') ?: '3306');
define('DB_NAME', getenv('OPENEMR_DB_NAME') ?: 'openemr');
define('DB_USER', getenv('OPENEMR_DB_USER') ?: 'openemr');
define('DB_PASS', getenv('OPENEMR_DB_PASS') ?: 'openemr');
define('DB_CHARSET', 'utf8mb4');

// ==========================================
// 2. CONSTANTES DE INTEGRACIÓN PACS & SERVICIOS
// ==========================================
// Servidor PACS Orthanc REST API
define('ORTHANC_URL', getenv('ORTHANC_URL') ?: 'http://127.0.0.1:8042');
define('ORTHANC_USER', getenv('ORTHANC_USER') ?: 'orthanc');
define('ORTHANC_PASS', getenv('ORTHANC_PASS') ?: 'orthanc');
define('ORTHANC_TIMEOUT', 5); // Segundos timeout de consulta REST

// Visor DICOM OHIF
define('OHIF_VIEWER_BASE_URL', getenv('OHIF_VIEWER_BASE_URL') ?: 'https://imagenes.origen.ar/viewer');
define('ORTHANC_WADO_URL', getenv('ORTHANC_WADO_URL') ?: 'https://pacs.origen.ar/dicom-web');

// Portal Completo OpenEMR
define('OPENEMR_PORTAL_URL', getenv('OPENEMR_PORTAL_URL') ?: 'https://hcd.origen.ar/portal');

// ==========================================
// 3. DATOS INSTITUCIONALES (Para encabezados y PDFs)
// ==========================================
define('CLINIC_NAME', 'Centro Médico Origen');
define('CLINIC_SUBTITLE', 'Portal Express del Paciente - Diagnóstico y Resultados');
define('CLINIC_ADDRESS', 'Av. Santa Fe 1234, CABA, Argentina');
define('CLINIC_PHONE', '+54 11 4000-0000 / 0810-333-ORIGEN');
define('CLINIC_EMAIL', 'contacto@origen.ar');
define('CLINIC_WEB', 'https://origen.ar');
define('CLINIC_LOGO_PATH', dirname(__DIR__) . '/assets/img/logo.png');

// ==========================================
// 4. FUNCIÓN HELPER DE CONEXIÓN PDO
// ==========================================
/**
 * Obtiene la conexión singleton a la base de datos OpenEMR
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

        // Compatibilidad PHP 8.5+ para ATTR_INIT_COMMAND (o fallback en versiones previas)
        if (defined('\Pdo\Mysql::ATTR_INIT_COMMAND')) {
            $options[\Pdo\Mysql::ATTR_INIT_COMMAND] = "SET NAMES " . DB_CHARSET . " COLLATE " . DB_CHARSET . "_unicode_ci";
        } elseif (defined('PDO::MYSQL_ATTR_INIT_COMMAND')) {
            $options[PDO::MYSQL_ATTR_INIT_COMMAND] = "SET NAMES " . DB_CHARSET . " COLLATE " . DB_CHARSET . "_unicode_ci";
        }

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log('Error de conexión a la Base de Datos OpenEMR: ' . $e->getMessage());
            throw new PDOException('No fue posible establecer conexión con el servidor de historias clínicas.');
        }
    }

    return $pdo;
}

// Cargar autoload de composer si existe
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
} else {
    // Autoloader fallback para clases en src/
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
