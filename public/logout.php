<?php
/**
 * Cierre de Sesión Seguro - Patient Express Portal
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$auth = new \App\Auth();
$auth->logout();

header('Location: index.php?logout=1');
exit;
