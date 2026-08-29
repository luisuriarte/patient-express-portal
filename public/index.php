<?php
/**
 * Página de Inicio y Formulario de Acceso al Portal Express
 * Patient Express Portal
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

$auth = new \App\Auth();

// Si ya está autenticado, redirigir al dashboard
if ($auth->isAuthenticated()) {
    header('Location: dashboard.php');
    exit;
}

$errorMessage = null;
$usernameVal = '';

// Procesar formulario de inicio de sesión
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $usernameVal = htmlspecialchars($username);

    if (empty($username) || empty($password)) {
        $errorMessage = 'Por favor ingrese su usuario/DNI y contraseña.';
    } else {
        $result = $auth->login($username, $password);
        if ($result['success']) {
            $_SESSION['flash_success'] = '¡Bienvenido/a al Portal Express de Resultados!';
            header('Location: dashboard.php');
            exit;
        } else {
            $errorMessage = $result['message'] ?? 'Credenciales incorrectas.';
        }
    }
}

// Control de logout
if (isset($_GET['logout'])) {
    $flashSuccess = 'Ha cerrado su sesión de forma segura.';
}

$pageTitle = 'Ingreso de Pacientes | Portal Express Centro Médico Origen';
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="relative py-12 md:py-20 lg:py-24 overflow-hidden">
    
    <!-- Elementos de fondo decorativos -->
    <div class="absolute inset-0 -z-10 overflow-hidden pointer-events-none">
        <div class="absolute -top-40 -right-40 w-96 h-96 bg-sky-200/50 rounded-full blur-3xl opacity-60"></div>
        <div class="absolute top-1/2 -left-40 w-96 h-96 bg-teal-200/40 rounded-full blur-3xl opacity-50"></div>
    </div>

    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
            
            <!-- Columna Izquierda: Información y Propuesta de Valor -->
            <div class="lg:col-span-7 space-y-6 text-center lg:text-left">
                
                <div class="inline-flex items-center space-x-2 px-3 py-1 rounded-full bg-sky-100/80 border border-sky-200 text-sky-800 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-sky-600 animate-pulse"></span>
                    <span>Acceso Instantáneo 24/7 a tus Estudios</span>
                </div>

                <h1 class="text-3xl sm:text-4xl lg:text-5xl font-extrabold text-slate-900 font-heading tracking-tight leading-tight">
                    Consulta tus <span class="text-transparent bg-clip-text bg-gradient-to-r from-sky-600 to-teal-600">Resultados Médicos</span> e Imágenes en Línea
                </h1>

                <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto lg:mx-0 font-normal leading-relaxed">
                    Accede rápidamente a tus análisis clínicos de laboratorio en formato PDF oficial y visualiza tus estudios de imágenes (Rayos X, TAC, Resonancias) directamente en el visor DICOM de alta resolución.
                </p>

                <!-- Beneficios en grid -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 pt-4 text-left">
                    
                    <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex flex-col space-y-2">
                        <div class="w-9 h-9 rounded-xl bg-sky-100 text-sky-700 flex items-center justify-center">
                            <i data-lucide="test-tube" class="w-5 h-5"></i>
                        </div>
                        <span class="font-heading font-bold text-sm text-slate-900">Laboratorio</span>
                        <span class="text-xs text-slate-500">Informes con firma digital y valores de referencia.</span>
                    </div>

                    <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex flex-col space-y-2">
                        <div class="w-9 h-9 rounded-xl bg-teal-100 text-teal-700 flex items-center justify-center">
                            <i data-lucide="scan" class="w-5 h-5"></i>
                        </div>
                        <span class="font-heading font-bold text-sm text-slate-900">Imágenes DICOM</span>
                        <span class="text-xs text-slate-500">Visor interactivo OHIF de diagnóstico por imágenes.</span>
                    </div>

                    <div class="p-4 rounded-2xl bg-white border border-slate-200/80 shadow-xs flex flex-col space-y-2">
                        <div class="w-9 h-9 rounded-xl bg-indigo-100 text-indigo-700 flex items-center justify-center">
                            <i data-lucide="shield-check" class="w-5 h-5"></i>
                        </div>
                        <span class="font-heading font-bold text-sm text-slate-900">100% Seguro</span>
                        <span class="text-xs text-slate-500">Autenticación directa con tu cuenta de OpenEMR.</span>
                    </div>

                </div>

                <!-- Banner portal completo -->
                <div class="p-4 rounded-2xl bg-gradient-to-r from-slate-900 to-slate-800 text-white flex flex-col sm:flex-row items-center justify-between gap-4 shadow-md">
                    <div class="flex items-center space-x-3 text-left">
                        <div class="w-10 h-10 rounded-xl bg-sky-500/20 text-sky-400 flex items-center justify-center flex-shrink-0">
                            <i data-lucide="calendar-clock" class="w-5 h-5"></i>
                        </div>
                        <div>
                            <p class="font-heading font-bold text-sm text-white">¿Necesitas gestionar turnos o enviar mensajes?</p>
                            <p class="text-xs text-slate-300">Ingresa al Portal Integral OpenEMR para todas las funciones médicas.</p>
                        </div>
                    </div>
                    <a href="<?= htmlspecialchars(defined('OPENEMR_PORTAL_URL') ? OPENEMR_PORTAL_URL : 'https://hcd.origen.ar/portal') ?>" 
                       target="_blank" 
                       rel="noopener noreferrer"
                       class="inline-flex items-center space-x-1.5 bg-sky-600 hover:bg-sky-500 text-white text-xs font-semibold px-4 py-2.5 rounded-xl transition-colors whitespace-nowrap">
                        <span>Ir al Portal Web</span>
                        <i data-lucide="external-link" class="w-3.5 h-3.5"></i>
                    </a>
                </div>

            </div>

            <!-- Columna Derecha: Tarjeta de Login -->
            <div class="lg:col-span-5">
                <div class="bg-white rounded-3xl p-6 sm:p-8 md:p-10 shadow-xl shadow-slate-200/60 border border-slate-200 relative">
                    
                    <!-- Encabezado de la Tarjeta -->
                    <div class="text-center space-y-2 mb-6">
                        <div class="w-12 h-12 rounded-2xl bg-sky-50 border border-sky-100 text-sky-600 flex items-center justify-center mx-auto shadow-inner">
                            <i data-lucide="lock" class="w-6 h-6"></i>
                        </div>
                        <h2 class="font-heading font-extrabold text-2xl text-slate-900 tracking-tight">Acceso de Pacientes</h2>
                        <p class="text-xs text-slate-500">Ingresa con tus credenciales de portal del centro médico</p>
                    </div>

                    <!-- Mensaje de Error si existió -->
                    <?php if ($errorMessage): ?>
                        <div class="mb-5 p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs flex items-start space-x-2.5">
                            <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600 flex-shrink-0 mt-0.5"></i>
                            <span><?= htmlspecialchars($errorMessage) ?></span>
                        </div>
                    <?php endif; ?>

                    <!-- Formulario de Login -->
                    <form action="index.php" method="POST" class="space-y-4" autocomplete="on">
                        
                        <!-- Campo Usuario / DNI / Email -->
                        <div>
                            <label for="username" class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1.5">
                                Usuario / DNI / Correo Electrónico
                            </label>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <i data-lucide="user" class="w-4 h-4"></i>
                                </div>
                                <input type="text" 
                                       name="username" 
                                       id="username" 
                                       value="<?= $usernameVal ?>" 
                                       required 
                                       placeholder="Ej: 35123456 o usuario.portal" 
                                       class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-sky-500 transition-colors">
                            </div>
                        </div>

                        <!-- Campo Contraseña -->
                        <div>
                            <div class="flex items-center justify-between mb-1.5">
                                <label for="password" class="block text-xs font-bold text-slate-700 uppercase tracking-wider">
                                    Contraseña
                                </label>
                            </div>
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                                    <i data-lucide="key-round" class="w-4 h-4"></i>
                                </div>
                                <input type="password" 
                                       name="password" 
                                       id="password" 
                                       required 
                                       placeholder="••••••••••••" 
                                       class="w-full pl-10 pr-10 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-900 placeholder-slate-400 focus:bg-white focus:border-sky-500 transition-colors">
                                <button type="button" 
                                        onclick="togglePasswordVisibility()" 
                                        class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 focus:outline-hidden"
                                        title="Mostrar u ocultar contraseña">
                                    <i id="togglePassIcon" data-lucide="eye" class="w-4 h-4"></i>
                                </button>
                            </div>
                        </div>

                        <!-- Botón de Envío -->
                        <div class="pt-2">
                            <button type="submit" 
                                    class="w-full flex items-center justify-center space-x-2 py-3.5 px-4 rounded-xl bg-gradient-to-r from-sky-600 to-teal-600 hover:from-sky-700 hover:to-teal-700 text-white font-heading font-bold text-sm shadow-lg shadow-sky-600/25 hover:shadow-sky-600/35 transition-all duration-200 cursor-pointer">
                                <span>Ver Mis Estudios y Resultados</span>
                                <i data-lucide="arrow-right" class="w-4 h-4"></i>
                            </button>
                        </div>
                    </form>

                    <!-- Ayuda al paciente -->
                    <div class="mt-6 pt-5 border-t border-slate-100 text-center space-y-2">
                        <p class="text-xs text-slate-500">
                            ¿No recuerdas tu contraseña o aún no tienes acceso?
                        </p>
                        <p class="text-xs font-semibold text-slate-700">
                            Contacta a Mesa de Ayuda: <span class="text-sky-600"><?= defined('CLINIC_PHONE') ? CLINIC_PHONE : '0810-333-ORIGEN' ?></span>
                        </p>
                    </div>

                </div>
            </div>

        </div>
    </div>
</div>

<script>
    function togglePasswordVisibility() {
        const passInput = document.getElementById('password');
        const icon = document.getElementById('togglePassIcon');
        if (!passInput) return;

        if (passInput.type === 'password') {
            passInput.type = 'text';
            icon.setAttribute('data-lucide', 'eye-off');
        } else {
            passInput.type = 'password';
            icon.setAttribute('data-lucide', 'eye');
        }
        if (typeof lucide !== 'undefined') lucide.createIcons();
    }
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
