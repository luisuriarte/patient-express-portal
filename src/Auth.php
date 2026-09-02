<?php
/**
 * Patient Authentication and Session Management
 * Patient Express Portal — Native Integration with OpenEMR
 *
 * @package   PatientExpressPortal
 * @license   GNU General Public License 3
 */

namespace App;

class Auth
{
    private const SESSION_LIFETIME = 1800; // 30 minutes of inactivity

    public function __construct()
    {
        $this->startSession();
    }

    /**
     * Starts the PHP session with secure parameters
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

        // Inactivity expiration check
        if (isset($_SESSION['express_last_activity']) && (time() - $_SESSION['express_last_activity'] > self::SESSION_LIFETIME)) {
            $this->logout();
        }
        $_SESSION['express_last_activity'] = time();
    }

    /**
     * Authenticates the patient against OpenEMR using sqlQuery() and password_verify()
     *
     * @param string $username Portal username, SSN/ID, Email or PID
     * @param string $password Plain-text password
     * @return array ['success' => bool, 'message' => string, 'patient' => array|null]
     */
    public function login(string $username, string $password): array
    {
        $username = trim($username);
        $password = trim($password);

        if (empty($username) || empty($password)) {
            return [
                'success' => false,
                'message' => xl('Please enter your username/ID and password.')
            ];
        }

        // Query the native OpenEMR patient portal table: patient_access_onsite
        $sql = "SELECT 
                    pao.pid,
                    pao.portal_username,
                    pao.portal_login_username,
                    pao.portal_pwd,
                    pao.portal_pwd_status,
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

        $user = sqlQuery($sql, [
            $username,
            $username,
            $username,
            $username,
            is_numeric($username) ? (int)$username : -1
        ]);

        if (!$user || empty($user['pid'])) {
            return [
                'success' => false,
                'message' => xl('Invalid credentials or patient not registered in the portal.')
            ];
        }

        // Check portal access status
        if (isset($user['allow_patient_portal']) && strtoupper((string)$user['allow_patient_portal']) === 'NO') {
            return [
                'success' => false,
                'message' => xl('Portal access is disabled for this patient. Please contact the medical center.')
            ];
        }

        // Verify password with password_verify()
        $storedHash = $user['portal_pwd'] ?? '';
        $passwordValid = false;

        if (!empty($storedHash)) {
            if (password_verify($password, $storedHash)) {
                $passwordValid = true;
            } elseif (hash_equals($storedHash, sha1($password)) || hash_equals($storedHash, md5($password))) {
                // Legacy hash support (sha1/md5) for migration
                $passwordValid = true;
            }
        }

        if (!$passwordValid) {
            return [
                'success' => false,
                'message' => xl('Incorrect password. Please verify your credentials.')
            ];
        }

        // Format patient data and establish the Express session
        $fullName = trim(($user['fname'] ?? '') . ' ' . ($user['mname'] ?? '') . ' ' . ($user['lname'] ?? ''));
        if (empty($fullName)) {
            $fullName = xl('Patient') . ' #' . $user['pid'];
        }

        session_regenerate_id(true);

        $preferredUsername = !empty($user['portal_login_username']) ? $user['portal_login_username'] : ($user['portal_username'] ?? $username);

        $_SESSION['express_patient_pid']    = (int)$user['pid'];
        $_SESSION['express_patient_pubpid'] = $user['pubpid'] ?: (string)$user['pid'];
        $_SESSION['express_patient_user']   = $preferredUsername;
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
            'message' => xl('Login successful.'),
            'patient' => $_SESSION['express_patient_data']
        ];
    }

    /**
     * Checks whether the patient has an active Express session
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
     * Security guard — redirects unauthenticated requests to login
     */
    public function requireAuth(string $redirectUrl = 'index.php'): void
    {
        if (!$this->isAuthenticated()) {
            $_SESSION['flash_error'] = xl('You must log in to access the portal.');
            header("Location: {$redirectUrl}");
            exit;
        }
    }

    /**
     * Destroys the Express session
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
