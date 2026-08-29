<?php
/**
 * Clase de Autenticación y Manejo de Sesión de Pacientes
 * Patient Express Portal - Integración Nativa con Funciones OpenEMR
 */

namespace App;

class Auth
{
    private const SESSION_LIFETIME = 1800; // 30 minutos de inactividad

    public function __construct()
    {
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

        // Control de expiración por inactividad
        if (isset($_SESSION['express_last_activity']) && (time() - $_SESSION['express_last_activity'] > self::SESSION_LIFETIME)) {
            $this->logout();
        }
        $_SESSION['express_last_activity'] = time();
    }

    /**
     * Autentica al paciente contra OpenEMR usando sqlQuery() y password_verify()
     * 
     * @param string $username Usuario del portal o DNI / Email / PID
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

        $user = null;

        // 1. Intentar consulta sobre patient_access_offsite
        try {
            $sqlOffsite = "SELECT 
                                pao.pid,
                                pao.portal_username,
                                pao.portal_pwd,
                                pd.id as patient_id,
                                pd.pubpid,
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
                           WHERE (pao.portal_username = ? 
                                  OR pd.ss = ? 
                                  OR pd.email = ? 
                                  OR pao.pid = ?)
                             AND (pao.portal_status = 1 OR pao.portal_status IS NULL)
                           LIMIT 1";

            $user = sqlQuery($sqlOffsite, [
                $username,
                $username,
                $username,
                is_numeric($username) ? (int)$username : -1
            ]);
        } catch (\Throwable $e) {
            $user = false;
        }

        // 2. Si no existe o no se encuentra en patient_access_offsite, consultar patient_access_onsite (OpenEMR 7+)
        if (!$user) {
            try {
                $sqlOnsite = "SELECT 
                                pao.pid,
                                pao.portal_username,
                                pao.portal_login_username,
                                pao.portal_pwd,
                                pd.id as patient_id,
                                pd.pubpid,
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
                                pd.postal_code,
                                pd.allow_patient_portal
                              FROM patient_access_onsite pao
                              INNER JOIN patient_data pd ON pao.pid = pd.pid
                              WHERE (pao.portal_login_username = ? 
                                     OR pao.portal_username = ? 
                                     OR pd.ss = ? 
                                     OR pd.email = ? 
                                     OR pao.pid = ?)
                              LIMIT 1";

                $user = sqlQuery($sqlOnsite, [
                    $username,
                    $username,
                    $username,
                    $username,
                    is_numeric($username) ? (int)$username : -1
                ]);
            } catch (\Throwable $e) {
                $user = false;
            }
        }

        if (!$user || empty($user['pid'])) {
            return [
                'success' => false,
                'message' => 'Credenciales inválidas o paciente no registrado en el portal.'
            ];
        }

        // Verificar estado de habilitación si está presente
        if (isset($user['allow_patient_portal']) && strtoupper((string)$user['allow_patient_portal']) === 'NO') {
            return [
                'success' => false,
                'message' => 'El acceso al portal se encuentra inactivo para este paciente. Por favor contacte al centro médico.'
            ];
        }

        // 3. Verificar Contraseña con password_verify()
        $storedHash = $user['portal_pwd'] ?? '';
        $passwordValid = false;

        if (!empty($storedHash)) {
            if (password_verify($password, $storedHash)) {
                $passwordValid = true;
            } elseif (hash_equals($storedHash, sha1($password)) || hash_equals($storedHash, md5($password))) {
                $passwordValid = true;
            }
        }

        if (!$passwordValid) {
            return [
                'success' => false,
                'message' => 'Contraseña incorrecta. Verifique los datos ingresados.'
            ];
        }

        // 4. Formatear datos y establecer la sesión exclusiva Express
        $fullName = trim(($user['fname'] ?? '') . ' ' . ($user['mname'] ?? '') . ' ' . ($user['lname'] ?? ''));
        if (empty($fullName)) {
            $fullName = 'Paciente #' . $user['pid'];
        }

        session_regenerate_id(true);

        $_SESSION['express_patient_pid']    = (int)$user['pid'];
        $_SESSION['express_patient_pubpid'] = $user['pubpid'] ?: (string)$user['pid'];
        $_SESSION['express_patient_user']   = $user['portal_username'] ?? $username;
        $_SESSION['express_logged_in']      = true;
        $_SESSION['express_logged_at']      = time();
        $_SESSION['express_last_activity']  = time();
        
        $_SESSION['express_patient_data']   = [
            'pid'         => (int)$user['pid'],
            'pubpid'      => $user['pubpid'] ?: (string)$user['pid'],
            'fname'       => $user['fname'] ?? '',
            'lname'       => $user['lname'] ?? '',
            'mname'       => $user['mname'] ?? '',
            'full_name'   => $fullName,
            'dob'         => $user['DOB'] ?? '',
            'sex'         => $user['sex'] ?? '',
            'dni'         => $user['ss'] ?? '',
            'email'       => $user['email'] ?? '',
            'phone'       => $user['phone_cell'] ?? '',
            'address'     => trim(($user['street'] ?? '') . ', ' . ($user['city'] ?? '')),
            'postal_code' => $user['postal_code'] ?? ''
        ];

        return [
            'success' => true,
            'message' => 'Ingreso exitoso.',
            'patient' => $_SESSION['express_patient_data']
        ];
    }

    /**
     * Comprueba si el paciente tiene sesión Express activa
     */
    public function isAuthenticated(): bool
    {
        return isset($_SESSION['express_logged_in']) 
            && $_SESSION['express_logged_in'] === true 
            && !empty($_SESSION['express_patient_pid']);
    }

    public function getPatientPid(): ?int
    {
        return $this->isAuthenticated() ? (int)$_SESSION['express_patient_pid'] : null;
    }

    public function getPatientPubpid(): ?string
    {
        return $this->isAuthenticated() ? (string)$_SESSION['express_patient_pubpid'] : null;
    }

    public function getCurrentPatient(): ?array
    {
        return $this->isAuthenticated() ? ($_SESSION['express_patient_data'] ?? null) : null;
    }

    /**
     * Guardia de seguridad
     */
    public function requireAuth(string $redirectUrl = 'index.php'): void
    {
        if (!$this->isAuthenticated()) {
            $_SESSION['flash_error'] = 'Debe iniciar sesión para acceder al portal.';
            header("Location: {$redirectUrl}");
            exit;
        }
    }

    /**
     * Cierra la sesión
     */
    public function logout(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            unset(
                $_SESSION['express_patient_pid'],
                $_SESSION['express_patient_pubpid'],
                $_SESSION['express_patient_user'],
                $_SESSION['express_logged_in'],
                $_SESSION['express_logged_at'],
                $_SESSION['express_last_activity'],
                $_SESSION['express_patient_data']
            );
        }
    }
}
