<?php
/**
 * Plantilla de Pie de Página Común
 * Patient Express Portal
 */
declare(strict_types=1);
?>
    </main>

    <!-- Footer Institucional -->
    <footer class="bg-slate-900 text-slate-400 mt-16 border-t border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-10 md:py-12">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-start">
                
                <!-- Columna 1: Datos Institucionales -->
                <div class="space-y-3">
                    <div class="flex items-center space-x-2 text-white">
                        <div class="w-7 h-7 rounded-lg bg-sky-500 flex items-center justify-center text-white">
                            <i data-lucide="activity" class="w-4 h-4"></i>
                        </div>
                        <span class="font-heading font-bold text-base tracking-tight"><?= defined('CLINIC_NAME') ? CLINIC_NAME : '' ?></span>
                    </div>
                    <p class="text-xs text-slate-400 leading-relaxed">
                        <?= xlt('Committed to diagnostic excellence, medical innovation and confidentiality in the care of your health.') ?>
                    </p>
                    <div class="text-xs space-y-1 pt-1 text-slate-400">
                        <p class="flex items-center space-x-2">
                            <i data-lucide="map-pin" class="w-3.5 h-3.5 text-sky-400 flex-shrink-0"></i>
                            <span><?= defined('CLINIC_ADDRESS') ? CLINIC_ADDRESS : '' ?></span>
                        </p>
                        <p class="flex items-center space-x-2">
                            <i data-lucide="phone" class="w-3.5 h-3.5 text-teal-400 flex-shrink-0"></i>
                            <span><?= defined('CLINIC_PHONE') ? CLINIC_PHONE : '' ?></span>
                        </p>
                        <p class="flex items-center space-x-2">
                            <i data-lucide="mail" class="w-3.5 h-3.5 text-indigo-400 flex-shrink-0"></i>
                            <span><?= defined('CLINIC_EMAIL') ? CLINIC_EMAIL : '' ?></span>
                        </p>
                    </div>
                </div>

                <!-- Columna 2: Canales y Servicios -->
                <div class="space-y-3">
                    <h4 class="font-heading font-semibold text-xs text-slate-200 uppercase tracking-wider"><?= xlt('Useful Links') ?></h4>
                    <ul class="space-y-2 text-xs">
                        <li>
                            <a href="<?= defined('OPENEMR_PORTAL_URL') ? OPENEMR_PORTAL_URL : 'https://hcd.origen.ar/portal' ?>" target="_blank" rel="noopener noreferrer" class="hover:text-sky-400 transition-colors flex items-center space-x-1.5">
                                <i data-lucide="arrow-right" class="w-3 h-3 text-sky-500"></i>
                                <span><?= xlt('Full OpenEMR Portal (Appointments and Messaging)') ?></span>
                            </a>
                        </li>
                        <li>
                            <a href="<?= defined('CLINIC_WEB') ? CLINIC_WEB : '' ?>" target="_blank" rel="noopener noreferrer" class="hover:text-sky-400 transition-colors flex items-center space-x-1.5">
                                <i data-lucide="globe" class="w-3 h-3 text-teal-500"></i>
                                <span><?= xlt('Institutional Website') ?></span>
                            </a>
                        </li>
                        <li>
                            <span class="inline-flex items-center gap-1.5 text-slate-400">
                                <i data-lucide="shield-check" class="w-3.5 h-3.5 text-emerald-400"></i>
                                <span><?= xlt('End-to-End Encryption (HIPAA / Law 25.326)') ?></span>
                            </span>
                        </li>
                    </ul>
                </div>

                <!-- Columna 3: Aviso Médico Importante -->
                <div class="p-4 rounded-xl bg-slate-800/80 border border-slate-700/60 space-y-2">
                    <div class="flex items-center space-x-2 text-amber-400 text-xs font-semibold">
                        <i data-lucide="alert-triangle" class="w-4 h-4"></i>
                        <span><?= xlt('Confidential Information') ?></span>
                    </div>
                    <p class="text-[11px] text-slate-400 leading-normal">
                        <?= xlt('The results and studies available on this portal are for consultation by the patient and their treating physician. The final interpretation must be made exclusively by a healthcare professional.') ?>
                    </p>
                </div>

            </div>

            <!-- Línea inferior -->
            <div class="mt-8 pt-6 border-t border-slate-800/80 flex flex-col sm:flex-row justify-between items-center text-xs text-slate-500 gap-4">
                <p>&copy; <?= date('Y') ?> <?= defined('CLINIC_NAME') ? CLINIC_NAME : '' ?>. <?= xlt('All rights reserved.') ?></p>
                <p class="flex items-center space-x-1">
                    <span><?= xlt('Patient Express Portal v1.2') ?></span>
                    <span>&bull;</span>
                    <span class="text-emerald-400 font-medium flex items-center gap-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span> <?= xlt('Connected') ?>
                    </span>
                </p>
            </div>
        </div>
    </footer>

    <!-- Modal Global para Vista Previa de Documentos / Informes PDF -->
    <div id="pdfModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            
            <!-- Backdrop -->
            <div id="pdfModalBackdrop" class="fixed inset-0 bg-slate-900/75 backdrop-blur-xs transition-opacity modal-overlay" aria-hidden="true"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <!-- Contenido Modal -->
            <div class="relative inline-block align-bottom bg-white rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full border border-slate-200">
                
                <!-- Encabezado Modal -->
                <div class="bg-slate-900 px-4 py-3 sm:px-6 flex items-center justify-between">
                    <div class="flex items-center space-x-2 text-white">
                        <i data-lucide="file-text" class="w-5 h-5 text-sky-400"></i>
                        <h3 class="text-sm font-semibold leading-6 text-white" id="modalTitle">
                            <?= xlt('Report Preview') ?>
                        </h3>
                    </div>
                    <div class="flex items-center space-x-2">
                        <a id="modalDownloadBtn" href="#" target="_blank" class="inline-flex items-center space-x-1 text-xs bg-sky-600 hover:bg-sky-500 text-white font-medium px-2.5 py-1 rounded-lg transition-colors">
                            <i data-lucide="download" class="w-3.5 h-3.5"></i>
                            <span class="hidden sm:inline"><?= xlt('Open in Tab') ?></span>
                        </a>
                        <button type="button" id="closeModalBtn" class="text-slate-400 hover:text-white rounded-lg p-1 transition-colors">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>

                <!-- Iframe para Renderizar PDF -->
                <div class="relative bg-slate-100 h-[70vh]">
                    <div id="pdfLoading" class="absolute inset-0 flex flex-col items-center justify-center bg-slate-100 z-10 space-y-3">
                        <div class="w-8 h-8 border-4 border-sky-600 border-t-transparent rounded-full animate-spin"></div>
                        <span class="text-xs font-semibold text-slate-600"><?= xlt('Generating official document...') ?></span>
                    </div>
                    <iframe id="pdfIframe" src="" class="w-full h-full border-0" title="<?= xla('Report Preview') ?>"></iframe>
                </div>

            </div>
        </div>
    </div>

    <!-- Modal Global para Visor de Imágenes Estándar (JPG/PNG) -->
    <div id="imageModal" class="fixed inset-0 z-50 hidden overflow-y-auto" aria-labelledby="image-modal-title" role="dialog" aria-modal="true">
        <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
            
            <!-- Backdrop -->
            <div id="imageModalBackdrop" class="fixed inset-0 bg-slate-950/85 backdrop-blur-xs transition-opacity modal-overlay" aria-hidden="true"></div>

            <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

            <!-- Contenido Modal -->
            <div class="relative inline-block align-bottom bg-slate-900 rounded-2xl text-left overflow-hidden shadow-2xl transform transition-all sm:my-8 sm:align-middle sm:max-w-4xl sm:w-full border border-slate-700">
                
                <!-- Encabezado Modal -->
                <div class="bg-slate-950 px-4 py-3 sm:px-6 flex items-center justify-between border-b border-slate-800">
                    <div class="flex items-center space-x-2 text-white">
                        <i data-lucide="image" class="w-5 h-5 text-teal-400"></i>
                        <h3 class="text-sm font-semibold leading-6 text-white truncate max-w-md" id="imageModalTitle">
                            <?= xlt('Image Viewer') ?>
                        </h3>
                    </div>
                    <div class="flex items-center space-x-2">
                        <a id="imageModalDownloadBtn" href="#" download class="inline-flex items-center space-x-1.5 text-xs bg-teal-600 hover:bg-teal-500 text-white font-medium px-3 py-1.5 rounded-lg transition-colors">
                            <i data-lucide="download" class="w-3.5 h-3.5"></i>
                            <span><?= xlt('Download') ?></span>
                        </a>
                        <button type="button" id="closeImageModalBtn" class="text-slate-400 hover:text-white rounded-lg p-1.5 transition-colors">
                            <i data-lucide="x" class="w-5 h-5"></i>
                        </button>
                    </div>
                </div>

                <!-- Contenedor de la Imagen con autoajuste -->
                <div class="relative bg-slate-950/90 h-[70vh] flex items-center justify-center p-4 overflow-auto">
                    <img id="imageModalImg" src="" alt="<?= xla('Imaging Study') ?>" class="max-h-full max-w-full object-contain rounded-lg shadow-lg">
                </div>

            </div>
        </div>
    </div>

    <!-- Inicialización de Scripts & Iconos -->
    <script>
        // Inicializar Iconos Lucide
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof lucide !== 'undefined') {
                lucide.createIcons();
            }
        });

        // Funciones del Modal de PDF
        function openPdfModal(url, title = 'Informe Médico') {
            const modal = document.getElementById('pdfModal');
            const iframe = document.getElementById('pdfIframe');
            const modalTitle = document.getElementById('modalTitle');
            const downloadBtn = document.getElementById('modalDownloadBtn');
            const loading = document.getElementById('pdfLoading');

            if (!modal || !iframe) return;

            modalTitle.innerText = title;
            downloadBtn.href = url;
            loading.style.display = 'flex';
            iframe.src = url;

            iframe.onload = () => {
                loading.style.display = 'none';
            };

            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }

        function closePdfModal() {
            const modal = document.getElementById('pdfModal');
            const iframe = document.getElementById('pdfIframe');
            if (modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                if (iframe) iframe.src = '';
            }
        }

        // Funciones del Modal de Imagen Estándar (JPG/PNG)
        function openImageModal(url, title = 'Estudio de Imagen', downloadUrl = null) {
            const modal = document.getElementById('imageModal');
            const img = document.getElementById('imageModalImg');
            const modalTitle = document.getElementById('imageModalTitle');
            const downloadBtn = document.getElementById('imageModalDownloadBtn');

            if (!modal || !img) return;

            modalTitle.innerText = title;
            img.src = url;
            downloadBtn.href = downloadUrl || url;

            modal.classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
            if (typeof lucide !== 'undefined') lucide.createIcons();
        }

        function closeImageModal() {
            const modal = document.getElementById('imageModal');
            const img = document.getElementById('imageModalImg');
            if (modal) {
                modal.classList.add('hidden');
                document.body.classList.remove('overflow-hidden');
                if (img) img.src = '';
            }
        }

        // Listeners del modal PDF
        const closeBtn = document.getElementById('closeModalBtn');
        const backdrop = document.getElementById('pdfModalBackdrop');
        if (closeBtn) closeBtn.addEventListener('click', closePdfModal);
        if (backdrop) backdrop.addEventListener('click', closePdfModal);

        // Listeners del modal Imagen
        const closeImgBtn = document.getElementById('closeImageModalBtn');
        const backdropImg = document.getElementById('imageModalBackdrop');
        if (closeImgBtn) closeImgBtn.addEventListener('click', closeImageModal);
        if (backdropImg) backdropImg.addEventListener('click', closeImageModal);

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closePdfModal();
                closeImageModal();
            }
        });
    </script>
</body>
</html>
