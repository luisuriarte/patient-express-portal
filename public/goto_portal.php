<?php
/**
 * Redireccionador de Auto-Login (SSO) hacia el Portal Integral OpenEMR
 * Patient Express Portal
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$auth = new \App\Auth();
$auth->requireAuth('index.php');

$pid = $auth->getPatientPid();
$target = isset($_GET['target']) ? trim((string)$_GET['target']) : '';

// Generar URL con token OneTimeAuth de OpenEMR
$portalUrl = \App\PortalSSO::createAutoLoginUrl((int)$pid, $target);

// Redirigir al paciente ya autenticado
header('Location: ' . $portalUrl);
exit;
