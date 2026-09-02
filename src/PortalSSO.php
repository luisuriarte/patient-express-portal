<?php
/**
 * Servicio de Single Sign-On (SSO) hacia el Portal Completo de OpenEMR
 * Patient Express Portal - Generador de Tokens OneTimeAuth (service_auth)
 */

namespace App;

use PDO;
use Exception;

class PortalSSO
{
    /**
     * Genera un token de acceso seguro OneTimeAuth en OpenEMR y devuelve la URL de auto-login
     * 
     * @param int $pid ID del paciente
     * @param string $redirectTarget Ruta interna destino en OpenEMR (ej: 'home.php' o '')
     * @return string URL completa hacia OpenEMR con service_auth
     */
    public static function createAutoLoginUrl(int $pid, string $redirectTarget = ''): string
    {
        $basePortalUrl = defined('OPENEMR_PORTAL_URL') ? rtrim(OPENEMR_PORTAL_URL, '/') : 'https://hcd.origen.ar/portal';

        if ($pid <= 0) {
            return $basePortalUrl;
        }

        try {
            // 1. Generar token criptográfico único de 32 caracteres alfanuméricos
            $token = bin2hex(random_bytes(16));
            $pin = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
            $expires = time() + 900; // Válido por 15 minutos

            $actions = json_encode([
                'enforce_onetime_use' => false,
                'extend_portal_visit' => true,
                'enforce_auth_pin'    => false,
                'max_access_count'    => 0
            ]);

            // 2. Verificar que el paciente tenga credenciales en patient_access_onsite (requerido por OpenEMR)
            // Si solo está en patient_access_offsite, sincronizar a onsite
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

            // 3. Insertar el token en la tabla onetime_auth de OpenEMR
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

            // 4. Construir enlace con service_auth
            return $basePortalUrl . '/index.php?service_auth=' . urlencode($token);

        } catch (Exception $e) {
            error_log(xl('Error generating OneTimeAuth token for SSO OpenEMR') . ": " . $e->getMessage());
            // Fallback al portal regular si falla la generación
            return $basePortalUrl;
        }
    }
}
