<?php
/**
 * Pantalla de Inicio de Sesión - Patient Express Portal
 * Acceso Rápido para Pacientes
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';

$auth = new \App\Auth();

// Si ya tiene sesión activa, enviar directamente al tablero
if ($auth->isAuthenticated()) {
    header('Location: dashboard.php');
    exit;
}

$errorMessage = '';
$successMessage = '';

if (isset($_SESSION['flash_error'])) {
    $errorMessage = $_SESSION['flash_error'];
    unset($_SESSION['flash_error']);
}

// Control de logout
if (isset($_GET['logout'])) {
    $successMessage = 'Has cerrado tu sesión de forma segura.';
}

// Procesar formulario POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    $loginResult = $auth->login($username, $password);

    if ($loginResult['success']) {
        header('Location: dashboard.php');
        exit;
    } else {
        $errorMessage = $loginResult['message'];
    }
}

$pageTitle = 'Ingreso de Pacientes | ' . (defined('CLINIC_NAME') && CLINIC_NAME ? CLINIC_NAME : 'Portal Express del Paciente');
require_once dirname(__DIR__) . '/templates/header.php';
?>

<div class="min-h-[calc(100vh-160px)] flex flex-col justify-center py-10 sm:px-6 lg:px-8">
    
    <div class="sm:mx-auto sm:w-full sm:max-w-md text-center space-y-2">
        <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-gradient-to-br from-sky-600 to-teal-600 text-white shadow-lg shadow-sky-500/25 mb-2">
            <i data-lucide="shield-check" class="w-8 h-8"></i>
        </div>
        <h1 class="font-heading font-extrabold text-2xl sm:text-3xl text-slate-900 tracking-tight">
            Portal Express del Paciente
        </h1>
        <p class="text-xs sm:text-sm text-slate-500 max-w-sm mx-auto">
            Consulta al instante tus informes de laboratorio, diagnósticos por imágenes y estudios DICOM.
        </p>
    </div>

    <div class="mt-8 sm:mx-auto sm:w-full sm:max-w-md px-4 sm:px-0">
        <div class="bg-white py-8 px-6 sm:px-10 shadow-xl shadow-slate-200/60 rounded-3xl border border-slate-200/80 space-y-6">
            
            <?php if (!empty($errorMessage)): ?>
                <div class="p-4 rounded-2xl bg-rose-50 border border-rose-200/80 flex items-start space-x-3 text-rose-800 text-xs">
                    <i data-lucide="alert-circle" class="w-4 h-4 text-rose-600 flex-shrink-0 mt-0.5"></i>
                    <div class="font-medium leading-relaxed"><?= htmlspecialchars($errorMessage) ?></div>
                </div>
            <?php endif; ?>

            <?php if (!empty($successMessage)): ?>
                <div class="p-4 rounded-2xl bg-emerald-50 border border-emerald-200/80 flex items-start space-x-3 text-emerald-800 text-xs">
                    <i data-lucide="check-circle-2" class="w-4 h-4 text-emerald-600 flex-shrink-0 mt-0.5"></i>
                    <div class="font-medium leading-relaxed"><?= htmlspecialchars($successMessage) ?></div>
                </div>
            <?php endif; ?>

            <form action="index.php" method="POST" class="space-y-4" id="loginForm">
                
                <!-- Campo Usuario / DNI / Email -->
                <div class="space-y-1.5">
                    <label for="username" class="block text-xs font-heading font-bold text-slate-700">
                        Usuario del Portal / DNI / Email
                    </label>
                    <div class="relative rounded-xl shadow-xs">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="user" class="w-4 h-4"></i>
                        </div>
                        <input type="text" 
                               name="username" 
                               id="username" 
                               required 
                               autofocus
                               placeholder="Ej: tu DNI o usuario" 
                               value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                               class="block w-full pl-10 pr-3.5 py-3 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all">
                    </div>
                </div>

                <!-- Campo Contraseña -->
                <div class="space-y-1.5">
                    <div class="flex items-center justify-between">
                        <label for="password" class="block text-xs font-heading font-bold text-slate-700">
                            Contraseña
                        </label>
                    </div>
                    <div class="relative rounded-xl shadow-xs">
                        <div class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none text-slate-400">
                            <i data-lucide="lock" class="w-4 h-4"></i>
                        </div>
                        <input type="password" 
                               name="password" 
                               id="password" 
                               required 
                               placeholder="••••••••••••" 
                               class="block w-full pl-10 pr-10 py-3 text-xs sm:text-sm bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:border-sky-500 focus:ring-2 focus:ring-sky-500/20 transition-all">
                        <button type="button" 
                                id="togglePasswordBtn" 
                                class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-slate-400 hover:text-slate-600 transition-colors">
                            <i data-lucide="eye" class="w-4 h-4" id="eyeIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Botón de Ingreso -->
                <div class="pt-2">
                    <button type="submit" 
                            id="submitBtn"
                            class="w-full flex items-center justify-center space-x-2 py-3.5 px-4 rounded-xl text-xs sm:text-sm font-heading font-bold text-white bg-gradient-to-r from-sky-600 to-sky-700 hover:from-sky-500 hover:to-sky-600 active:scale-[0.99] shadow-md shadow-sky-500/20 transition-all duration-150 cursor-pointer">
                        <i data-lucide="log-in" class="w-4 h-4"></i>
                        <span>Ingresar a mis Resultados</span>
                    </button>
                </div>

            </form>

            <!-- Acceso Alternativo al Portal Completo -->
            <div class="pt-4 border-t border-slate-100 text-center space-y-2">
                <p class="text-xs text-slate-500">
                    ¿Necesitas agendar turnos o contactar a tu médico?
                </p>
                <a href="<?= defined('OPENEMR_PORTAL_URL') ? OPENEMR_PORTAL_URL : 'https://hcd.origen.ar/portal' ?>" 
                   target="_blank" 
                   rel="noopener noreferrer"
                   class="inline-flex items-center space-x-1.5 text-xs font-heading font-bold text-sky-600 hover:text-sky-700 hover:underline">
                    <span>Acceder al Portal Completo OpenEMR</span>
                    <i data-lucide="arrow-up-right" class="w-3.5 h-3.5"></i>
                </a>
            </div>

        </div>
    </div>

</div>

<!-- Script para Toggle de Visibilidad de Contraseña -->
<script>
    const toggleBtn = document.getElementById('togglePasswordBtn');
    const passwordInput = document.getElementById('password');

    if (toggleBtn && passwordInput) {
        toggleBtn.addEventListener('click', () => {
            const isPassword = passwordInput.type === 'password';
            passwordInput.type = isPassword ? 'text' : 'password';
            const icon = toggleBtn.querySelector('i');
            if (icon) {
                icon.setAttribute('data-lucide', isPassword ? 'eye-off' : 'eye');
                if (typeof lucide !== 'undefined') lucide.createIcons();
            }
        });
    }
</script>

<?php require_once dirname(__DIR__) . '/templates/footer.php'; ?>
