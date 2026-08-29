<?php
/**
 * Plantilla de Encabezado Común
 * Patient Express Portal
 */
declare(strict_types=1);

$pageTitle = $pageTitle ?? 'Portal Express del Paciente | Centro Médico Origen';
$auth = new \App\Auth();
$isLoggedIn = $auth->isAuthenticated();
$patient = $auth->getCurrentPatient();

// Recuperar y limpiar mensajes flash
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            200: '#bae6fd',
                            300: '#7dd3fc',
                            400: '#38bdf8',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        },
                        medical: {
                            teal: '#0d9488',
                            dark: '#0f172a',
                            slate: '#334155'
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', 'sans-serif'],
                        heading: ['"Plus Jakarta Sans"', 'Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Custom Stylesheet -->
    <link rel="stylesheet" href="assets/css/custom.css">
</head>
<body class="min-h-full flex flex-col antialiased text-slate-800 bg-slate-50 selection:bg-sky-500 selection:text-white">

    <!-- Navbar Principal -->
    <header class="sticky top-0 z-40 bg-white/95 backdrop-blur border-b border-slate-200 shadow-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16 md:h-20">
                
                <!-- Logo e Identidad -->
                <div class="flex items-center space-x-3">
                    <a href="<?= $isLoggedIn ? 'dashboard.php' : 'index.php' ?>" class="flex items-center space-x-3 group">
                        <div class="w-10 h-10 md:w-11 md:h-11 rounded-xl bg-gradient-to-tr from-sky-600 to-teal-500 flex items-center justify-center text-white shadow-md shadow-sky-500/20 group-hover:scale-105 transition-transform duration-200">
                            <i data-lucide="activity" class="w-6 h-6"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="font-heading font-extrabold text-lg md:text-xl text-slate-900 tracking-tight leading-tight">
                                CENTRO MÉDICO <span class="text-sky-600">ORIGEN</span>
                            </span>
                            <span class="text-[11px] font-semibold text-slate-500 uppercase tracking-wider flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-teal-500 animate-pulse"></span>
                                Portal Express
                            </span>
                        </div>
                    </a>
                </div>

                <!-- Opciones de Navegación según estado de sesión -->
                <?php if ($isLoggedIn && $patient): ?>
                    <div class="flex items-center space-x-3 md:space-x-6">
                        
                        <!-- Tarjeta de Paciente (Desktop) -->
                        <div class="hidden sm:flex items-center space-x-3 bg-slate-100/80 px-3.5 py-1.5 rounded-xl border border-slate-200/80">
                            <div class="w-8 h-8 rounded-lg bg-sky-100 text-sky-700 flex items-center justify-center font-bold text-sm">
                                <?= strtoupper(substr($patient['fname'] ?? 'P', 0, 1) . substr($patient['lname'] ?? '', 0, 1)) ?>
                            </div>
                            <div class="flex flex-col text-left">
                                <span class="text-xs font-semibold text-slate-800 leading-tight">
                                    <?= htmlspecialchars($patient['full_name'] ?? 'Paciente') ?>
                                </span>
                                <span class="text-[11px] text-slate-500">
                                    DNI: <?= htmlspecialchars($patient['dni'] ?: 'PID #' . $patient['pid']) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Enlace Rápido al Portal Completo OpenEMR -->
                        <a href="<?= htmlspecialchars(defined('OPENEMR_PORTAL_URL') ? OPENEMR_PORTAL_URL : 'https://hcd.origen.ar/portal') ?>" 
                           target="_blank" 
                           rel="noopener noreferrer"
                           title="Acceder al Portal Completo OpenEMR (Turnos, Mensajes y Más)" 
                           class="hidden md:inline-flex items-center space-x-1.5 text-xs font-medium text-slate-600 hover:text-sky-600 bg-white hover:bg-slate-50 px-3 py-2 rounded-lg border border-slate-200 transition-colors shadow-xs">
                            <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                            <span>Portal Completo</span>
                        </a>

                        <!-- Botón de Salir -->
                        <a href="logout.php" 
                           class="inline-flex items-center space-x-2 text-xs md:text-sm font-semibold text-rose-600 hover:text-rose-700 bg-rose-50 hover:bg-rose-100/80 px-3 md:px-4 py-2 rounded-xl transition-all duration-150 border border-rose-200/60"
                           title="Cerrar sesión segura">
                            <i data-lucide="log-out" class="w-4 h-4"></i>
                            <span class="hidden sm:inline">Cerrar Sesión</span>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="flex items-center space-x-4">
                        <div class="hidden sm:flex items-center text-xs text-slate-500 font-medium space-x-2">
                            <i data-lucide="shield-check" class="w-4 h-4 text-emerald-500"></i>
                            <span>Acceso Seguro Encriptado SSL</span>
                        </div>
                        <a href="<?= htmlspecialchars(defined('OPENEMR_PORTAL_URL') ? OPENEMR_PORTAL_URL : 'https://hcd.origen.ar/portal') ?>" 
                           target="_blank"
                           class="text-xs font-semibold text-slate-700 hover:text-sky-600 flex items-center space-x-1">
                            <span>Portal Completo</span>
                            <i data-lucide="arrow-up-right" class="w-3.5 h-3.5"></i>
                        </a>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </header>

    <!-- Contenedor de Alertas Flash -->
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 mt-4 w-full">
        <?php if ($flashSuccess): ?>
            <div class="flex items-center p-4 mb-4 text-sm text-emerald-800 rounded-xl bg-emerald-50 border border-emerald-200 shadow-sm" role="alert">
                <i data-lucide="check-circle" class="w-5 h-5 mr-3 flex-shrink-0 text-emerald-600"></i>
                <span class="font-medium"><?= htmlspecialchars($flashSuccess) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($flashError): ?>
            <div class="flex items-center p-4 mb-4 text-sm text-rose-800 rounded-xl bg-rose-50 border border-rose-200 shadow-sm" role="alert">
                <i data-lucide="alert-circle" class="w-5 h-5 mr-3 flex-shrink-0 text-rose-600"></i>
                <span class="font-medium"><?= htmlspecialchars($flashError) ?></span>
            </div>
        <?php endif; ?>
    </div>

    <!-- Contenido Principal -->
    <main class="flex-grow">
