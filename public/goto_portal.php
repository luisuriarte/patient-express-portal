<?php
/**
 * SSO Auto-Login Redirector to the Full OpenEMR Portal
 * Patient Express Portal
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$auth = new \App\Auth();
$auth->requireAuth('index.php');

$pid = $auth->getPatientPid();
$target = isset($_GET['target']) ? trim((string)$_GET['target']) : '';

// Generate URL with OpenEMR OneTimeAuth token
$portalUrl = \App\PortalSSO::createAutoLoginUrl((int)$pid, $target);

// Redirect authenticated patient
header('Location: ' . $portalUrl);
exit;
