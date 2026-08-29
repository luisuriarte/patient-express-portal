<?php
/**
 * Plantilla de Cabecera Global
 * Patient Express Portal
 */
declare(strict_types=1);

if (!isset($auth)) {
    $auth = new \App\Auth();
}

$isLoggedIn = $auth->isAuthenticated();
$currentPatient = $isLoggedIn ? $auth->getCurrentPatient() : null;
$pageTitle = $pageTitle ?? 'Portal Express del Paciente | Centro Médico Origen';
?>
<!DOCTYPE html>
<html lang="es" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    
    <!-- Meta tags SEO y PWA -->
    <meta name="description" content="Acceso rápido a resultados de análisis clínicos, informes de diagnóstico por imágenes y visor DICOM.">
    <meta name="theme-color" content="#0284c7">
    <meta name="robots" content="noindex, nofollow">
    
    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="assets/img/logo.svg">
    <link rel="alternate icon" type="image/png" href="assets/img/logo.png">

    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        brand: {
                            50: '#f0f9ff',
                            100: '#e0f2fe',
                            500: '#0ea5e9',
                            600: '#0284c7',
                            700: '#0369a1',
                            800: '#075985',
                            900: '#0c4a6e',
                        },
                        clinical: {
                            teal: '#0d9488',
                            dark: '#0f172a',
                            slate: '#334155',
                        }
                    },
                    fontFamily: {
                        sans: ['Inter', 'system-ui', '-apple-system', 'sans-serif'],
                        heading: ['Plus Jakarta Sans', 'Inter', 'sans-serif'],
                    }
                }
            }
        }
    </script>

    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">

    <!-- Lucide Icons CDN -->
    <script src="https://unpkg.com/lucide@latest"></script>

    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/custom.css">
</head>
<body class="h-full flex flex-col font-sans antialiased text-slate-900 bg-slate-50 selection:bg-sky-500 selection:text-white">

    <!-- Navbar Superior -->
    <header class="sticky top-0 z-40 bg-white/90 backdrop-blur-md border-b border-slate-200/80 shadow-xs transition-all duration-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex justify-between items-center h-16 md:h-20">
                
                <!-- Logo e Identidad -->
                <div class="flex items-center space-x-3">
                    <a href="dashboard.php" class="flex items-center space-x-3 group">
                        <div class="w-10 h-10 md:w-11 md:h-11 rounded-xl bg-gradient-to-br from-sky-600 to-teal-600 flex items-center justify-center text-white shadow-md shadow-sky-500/20 group-hover:scale-105 transition-transform duration-200">
                            <i data-lucide="activity" class="w-6 h-6"></i>
                        </div>
                        <div class="flex flex-col">
                            <span class="font-heading font-extrabold text-base md:text-lg tracking-tight text-slate-900 leading-tight">
                                CENTRO MÉDICO <span class="text-sky-600">ORIGEN</span>
                            </span>
                            <span class="text-[10px] md:text-xs font-semibold text-slate-500 tracking-wider uppercase">
                                Portal Express del Paciente
                            </span>
                        </div>
                    </a>
                </div>

                <!-- Barra Derecha: Usuario & Acciones -->
                <div class="flex items-center space-x-3 sm:space-x-4">
                    
                    <?php if ($isLoggedIn && $currentPatient): ?>
                        <!-- Perfil Paciente Logueado -->
                        <div class="hidden sm:flex items-center space-x-3 px-3.5 py-1.5 rounded-full bg-slate-100/90 border border-slate-200">
                            <div class="w-7 h-7 rounded-full bg-sky-600 text-white flex items-center justify-center text-xs font-bold font-heading">
                                <?= strtoupper(substr($currentPatient['fname'] ?: 'P', 0, 1) . substr($currentPatient['lname'] ?: 'T', 0, 1)) ?>
                            </div>
                            <div class="flex flex-col text-left">
                                <span class="text-xs font-bold text-slate-800 leading-tight max-w-[150px] truncate">
                                    <?= htmlspecialchars($currentPatient['full_name']) ?>
                                </span>
                                <span class="text-[10px] text-slate-500 font-medium">
                                    PID #<?= htmlspecialchars((string)$currentPatient['pid']) ?>
                                </span>
                            </div>
                        </div>

                        <!-- Botón Enlace Portal Completo -->
                        <a href="<?= defined('OPENEMR_PORTAL_URL') ? OPENEMR_PORTAL_URL : 'https://hcd.origen.ar/portal' ?>" 
                           target="_blank" 
                           rel="noopener noreferrer"
                           title="Ingresar al Portal Completo OpenEMR"
                           class="hidden md:inline-flex items-center space-x-1.5 text-xs font-heading font-semibold text-slate-700 bg-slate-100 hover:bg-slate-200 px-3.5 py-2 rounded-xl transition-all">
                            <i data-lucide="external-link" class="w-3.5 h-3.5 text-sky-600"></i>
                            <span>Portal OpenEMR</span>
                        </a>

                        <!-- Botón Salir / Logout -->
                        <a href="logout.php" 
                           class="inline-flex items-center space-x-1.5 text-xs font-heading font-semibold text-rose-600 hover:text-rose-700 bg-rose-50 hover:bg-rose-100/80 px-3.5 py-2 rounded-xl border border-rose-200/60 transition-all shadow-xs"
                           title="Cerrar sesión segura">
                            <i data-lucide="log-out" class="w-4 h-4"></i>
                            <span class="hidden sm:inline">Cerrar Sesión</span>
                        </a>

                    <?php else: ?>
                        <!-- Si no está logueado -->
                        <div class="flex items-center space-x-2 text-xs text-slate-500">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700 border border-emerald-200 font-medium">
                                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5 animate-pulse"></span>
                                Conexión Segura SSL
                            </span>
                        </div>
                    <?php endif; ?>

                </div>

            </div>
        </div>
    </header>

    <!-- Contenido Principal -->
    <main class="flex-grow">
