<?php
/**
 * Single Sign-On (SSO) Service to the Full OpenEMR Portal
 * Patient Express Portal - OneTimeAuth Token Generator (service_auth)
 */

namespace App;

use PDO;
use Exception;

class PortalSSO
{
    /**
     * Generates a secure OneTimeAuth access token in OpenEMR and returns the auto-login URL
     * 
     * @param int $pid Patient ID
     * @param string $redirectTarget Internal destination path in OpenEMR (e.g. 'home.php' or '')
     * @return string Full URL to OpenEMR with service_auth
     */
    public static function createAutoLoginUrl(int $pid, string $redirectTarget = ''): string
    {
        $basePortalUrl = defined('OPENEMR_PORTAL_URL') ? rtrim(OPENEMR_PORTAL_URL, '/') : 'https://hcd.origen.ar/portal';

        if ($pid <= 0) {
            return $basePortalUrl;
        }

        try {
            // 1. Generate a unique 32-character alphanumeric cryptographic token
            $token = bin2hex(random_bytes(16));
            $pin = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expires = time() + 900; // Valid for 15 minutes

            $actions = json_encode([
                'enforce_onetime_use' => false,
                'extend_portal_visit' => true,
                'enforce_auth_pin'    => false,
                'max_access_count'    => 0
            ]);

            // 2. Verify that the patient has credentials in patient_access_onsite (required by OpenEMR)
            // If only in patient_access_offsite, sync to onsite
            $checkOnsite = sqlQuery("SELECT pid FROM patient_access_onsite WHERE pid = ? LIMIT 1", [$pid]);
            if (!$checkOnsite) {
                $offsite = sqlQuery("SELECT * FROM patient_access_offsite WHERE pid = ? LIMIT 1", [$pid]);
                if ($offsite) {
                    $insertOnsiteSql = "INSERT INTO patient_access_onsite 
                        (pid, portal_username, portal_pwd, portal_pwd_status, portal_active, portal_login_date) 
                        VALUES (?, ?, ?, ?, ?, NOW()) 
                        ON DUPLICATE KEY UPDATE portal_username = VALUES(portal_username), portal_pwd = VALUES(portal_pwd), portal_active = 1";
                    sqlStatement($insertOnsiteSql, [
                        $pid,
                        $offsite['portal_username'] ?? ('paciente_' . $pid),
                        $offsite['portal_pwd'] ?? '',
                        $offsite['portal_pwd_status'] ?? 1,
                        1
                    ]);
                }
            }

            // 3. Insert the token into OpenEMR's onetime_auth table
            $redirectUrl = !empty($redirectTarget) ? $redirectTarget : 'home.php';
            $sql = "INSERT INTO onetime_auth 
                    (id, pid, create_user_id, context, onetime_pin, onetime_token, redirect_url, expires, date_created, scope, profile, onetime_actions) 
                    VALUES (NULL, ?, NULL, 'portal', ?, ?, ?, ?, CURRENT_TIMESTAMP(), 'redirect', 'default', ?)";

            sqlInsert($sql, [
                $pid,
                $pin,
                $token,
                $redirectUrl,
                $expires,
                $actions
            ]);

            // 4. Build link with service_auth
            return $basePortalUrl . '/index.php?service_auth=' . urlencode($token);

        } catch (Exception $e) {
            error_log(xl('Error generating OneTimeAuth token for SSO OpenEMR') . ": " . $e->getMessage());
            // Fallback to the regular portal if token generation fails
            return $basePortalUrl;
        }
    }
}
