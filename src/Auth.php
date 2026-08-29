<?php
/**
 * Clase de Autenticación y Manejo de Sesión de Pacientes
 * Patient Express Portal - OpenEMR Compatible
 */

namespace App;

use PDO;
use PDOException;

class Auth
{
    private PDO $db;
    private const SESSION_LIFETIME = 1800; // 30 minutos de inactividad

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? getDbConnection();
        $this->startSession();
    }

    /**
     * Inicia la sesión PHP con parámetros seguros
     */
    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            $cookieParams = [
                'lifetime' => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on',
                'httponly' => true,
                'samesite' => 'Lax'
            ];
            session_set_cookie_params($cookieParams);
            session_start();
        }

        // Control de tiempo de expiración por inactividad
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > self::SESSION_LIFETIME)) {
            $this->logout();
        }
        $_SESSION['last_activity'] = time();
    }

    /**
     * Autentica al paciente contra la tabla patient_access_offsite y patient_data de OpenEMR
     * 
     * @param string $username Usuario del portal o DNI / Email
     * @param string $password Contraseña en texto plano
     * @return array ['success' => bool, 'message' => string, 'patient' => array|null]
     */
    public function login(string $username, string $password): array
    {
        $username = trim($username);
        $password = trim($password);

        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => 'Por favor ingrese su usuario/documento y contraseña.'
            ];
        }

        try {
            // 1. Buscar en patient_access_offsite por portal_username o cruce con patient_data (email, ss/dni, pid)
            $sql = "SELECT 
                        pao.pid,
                        pao.portal_username,
                        pao.portal_pwd,
                        pao.portal_active,
                        pd.id as patient_id,
                        pd.fname,
                        pd.lname,
                        pd.mname,
                        pd.DOB,
                        pd.sex,
                        pd.ss,
                        pd.email,
                        pd.phone_cell,
                        pd.street,
                        pd.city,
                        pd.postal_code
                    FROM patient_access_offsite pao
                    INNER JOIN patient_data pd ON pao.pid = pd.pid
                    WHERE (pao.portal_username = :username_direct 
                           OR pd.ss = :username_ss 
                           OR pd.email = :username_email 
                           OR pao.pid = :username_pid)
                    LIMIT 1";

            $stmt = $this->db->prepare($sql);
            $stmt->execute([
                ':username_direct' => $username,
                ':username_ss'     => $username,
                ':username_email'  => $username,
                ':username_pid'    => is_numeric($username) ? (int)$username : -1
            ]);

            $account = $stmt->fetch();

            if (!$account) {
                return [
                    'success' => false,
                    'message' => 'Credenciales inválidas o paciente no registrado en el portal.'
                ];
            }

            // Verificar si el portal está habilitado para este paciente
            if (isset($account['portal_active']) && (int)$account['portal_active'] === 0) {
                return [
                    'success' => false,
                    'message' => 'El acceso al portal se encuentra inactivo. Por favor contacte al centro médico.'
                ];
            }

            // 2. Verificar contraseña con password_verify() (Hash de OpenEMR)
            $storedHash = $account['portal_pwd'] ?? '';
            $passwordValid = false;

            if (!empty($storedHash)) {
                if (password_verify($password, $storedHash)) {
                    $passwordValid = true;
                } elseif (hash_equals($storedHash, sha1($password)) || hash_equals($storedHash, md5($password))) {
                    // Soporte de compatibilidad para esquemas legacy de OpenEMR
                    $passwordValid = true;
                    // Actualizar a bcrypt/argon2
                    $newHash = password_hash($password, PASSWORD_DEFAULT);
                    $updateStmt = $this->db->prepare("UPDATE patient_access_offsite SET portal_pwd = :new_pwd WHERE pid = :pid");
                    $updateStmt->execute([':new_pwd' => $newHash, ':pid' => $account['pid']]);
                }
            }

            if (!$passwordValid) {
                return [
                    'success' => false,
                    'message' => 'Contraseña incorrecta. Verifique sus datos.'
                ];
            }

            // 3. Registrar último acceso
            try {
                $updateLogin = $this->db->prepare("UPDATE patient_access_offsite SET portal_login_date = NOW() WHERE pid = :pid");
                $updateLogin->execute([':pid' => $account['pid']]);
            } catch (\Exception $e) {
                // Loguear pero no interrumpir el flujo
                error_log("No se pudo actualizar portal_login_date: " . $e->getMessage());
            }

            // 4. Formatear datos del paciente y establecer sesión
            $fullName = trim(($account['fname'] ?? '') . ' ' . ($account['mname'] ?? '') . ' ' . ($account['lname'] ?? ''));
            if (empty($fullName)) {
                $fullName = 'Paciente #' . $account['pid'];
            }

            // Regenerar ID de sesión para prevenir Session Fixation
            session_regenerate_id(true);

            $_SESSION['patient_pid']      = (int)$account['pid'];
            $_SESSION['patient_username'] = $account['portal_username'] ?? $username;
            $_SESSION['patient_name']     = $fullName;
            $_SESSION['patient_data']     = [
                'pid'         => (int)$account['pid'],
                'fname'       => $account['fname'] ?? '',
                'lname'       => $account['lname'] ?? '',
                'mname'       => $account['mname'] ?? '',
                'full_name'   => $fullName,
                'dob'         => $account['DOB'] ?? '',
                'sex'         => $account['sex'] ?? '',
                'dni'         => $account['ss'] ?? '',
                'email'       => $account['email'] ?? '',
                'phone'       => $account['phone_cell'] ?? '',
                'address'     => trim(($account['street'] ?? '') . ', ' . ($account['city'] ?? '')),
                'postal_code' => $account['postal_code'] ?? ''
            ];
            $_SESSION['logged_in']    = true;
            $_SESSION['logged_at']    = time();
            $_SESSION['last_activity']= time();

            return [
                'success' => true,
                'message' => 'Ingreso exitoso.',
                'patient' => $_SESSION['patient_data']
            ];

        } catch (PDOException $e) {
            error_log('Error en Auth::login: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Ocurrió un error en el servidor al intentar autenticar. Intente nuevamente.'
            ];
        }
    }

    /**
     * Verifica si hay un paciente actualmente autenticado
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true && !empty($_SESSION['patient_pid']);
    }

    /**
     * Obtiene el PID del paciente en sesión
     */
    public function getPatientPid(): ?int
    {
        return $this->isAuthenticated() ? (int)$_SESSION['patient_pid'] : null;
    }

    /**
     * Obtiene todos los datos del paciente en sesión
     */
    public function getCurrentPatient(): ?array
    {
        return $this->isAuthenticated() ? ($_SESSION['patient_data'] ?? null) : null;
    }

    /**
     * Guardia de seguridad: Redirige al login si no está autenticado
     */
    public function requireAuth(string $redirectUrl = 'index.php'): void
    {
        if (!$this->isAuthenticated()) {
            $_SESSION['flash_error'] = 'Debe iniciar sesión para acceder a esta sección.';
            header("Location: {$redirectUrl}");
            exit;
        }
    }

    /**
     * Cierra la sesión activa del paciente
     */
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            session_destroy();
        }
    }
}
