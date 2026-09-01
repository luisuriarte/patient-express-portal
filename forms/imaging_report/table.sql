-- ============================================================
-- Formulario: Informe de Diagnóstico por Imágenes
-- OpenEMR Clinical Form: imaging_report
-- Tabla: form_imaging_report
-- ============================================================
CREATE TABLE IF NOT EXISTS `form_imaging_report` (
  `id`                  BIGINT(20)      NOT NULL AUTO_INCREMENT,
  `date`                DATETIME        DEFAULT NULL,
  `pid`                 BIGINT(20)      DEFAULT NULL,
  `user`                VARCHAR(255)    DEFAULT NULL,
  `groupname`           VARCHAR(255)    DEFAULT NULL,
  `authorized`          TINYINT(4)      DEFAULT NULL,
  `activity`            TINYINT(4)      DEFAULT NULL,
  -- Campos del informe de imágenes
  `modalidad`           VARCHAR(100)    DEFAULT NULL COMMENT 'RX, TC, RMN, US, MG, DEXA',
  `region_anatomica`    VARCHAR(255)    DEFAULT NULL COMMENT 'Área anatómica explorada',
  `servicio_solicitante` VARCHAR(255)   DEFAULT NULL,
  `medico_informante`   VARCHAR(255)    DEFAULT NULL,
  `medico_solicitante`  VARCHAR(255)    DEFAULT NULL,
  `metodologia`         LONGTEXT        DEFAULT NULL COMMENT 'Técnica / Secuencias utilizadas',
  `interpretacion`      LONGTEXT        DEFAULT NULL COMMENT 'Hallazgos descriptivos',
  `conclusion`          LONGTEXT        DEFAULT NULL COMMENT 'Impresión diagnóstica',
  `observaciones`       LONGTEXT        DEFAULT NULL COMMENT 'Sugerencias / Notas adicionales',
  `estado`              ENUM('borrador','finalizado') DEFAULT 'borrador',
  `pdf_document_id`     BIGINT(20)      DEFAULT NULL COMMENT 'ID del documento PDF en tabla documents',
  `pdf_category_id`     BIGINT(20)      DEFAULT NULL COMMENT 'Carpeta destino elegida (id en tabla categories)',
  `study_instance_uid`  VARCHAR(128)    DEFAULT NULL COMMENT 'StudyInstanceUID DICOM del estudio vinculado (tag 0020,000D)',
  `accession_number`    VARCHAR(64)     DEFAULT NULL COMMENT 'Número de acceso / identificación del estudio en Orthanc',
  `pdf_path`            VARCHAR(512)    DEFAULT NULL COMMENT 'Ruta física relativa al PDF generado',
  `fecha_informe`       DATE            DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_pid`         (`pid`),
  KEY `idx_estado`      (`estado`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Listas normalizadas (list_options) para los dropdowns del formulario
--   1. Servicios Solicitantes        -> list 'imaging_report_services'
--   2. Región / Área Anatómica       -> list 'imaging_report_anatomy'
-- INSERT IGNORE => idempotente, se instala junto con el formulario
-- ============================================================

-- 1. Registrar las dos nuevas listas en la lista maestra ('lists')
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`) VALUES
('lists', 'imaging_report_services', 'Servicios Solicitantes (Imágenes)', 510, 1, 0, '', 'Lista de servicios hospitalarios/clínicos solicitantes para informes radiológicos', ''),
('lists', 'imaging_report_anatomy',  'Región / Área Anatómica (Imágenes)', 511, 1, 0, '', 'Lista de regiones anatómicas para estudios de diagnóstico por imágenes', '');

-- 2. Opciones para el Listado de Servicios Solicitantes ('imaging_report_services')
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`) VALUES
('imaging_report_services', 'guardia',         'Guardia / Urgencias',   1, 1, 0, '', 'Servicio de Urgencias y Emergencias', ''),
('imaging_report_services', 'clinica_medica',  'Clínica Médica',        2, 0, 0, '', 'Internación y Consulta Externa de Clínica Médica', ''),
('imaging_report_services', 'traumatologia',   'Traumatología',         3, 0, 0, '', 'Ortopedia y Traumatología', ''),
('imaging_report_services', 'pediatria',       'Pediatría',             4, 0, 0, '', 'Atención Pediátrica', ''),
('imaging_report_services', 'uti',             'Unidad de Terapia Intensiva (UTI)', 5, 0, 0, '', 'Cuidados Intensivos', ''),
('imaging_report_services', 'cirugia',         'Cirugía General',       6, 0, 0, '', 'Servicio Quirúrgico', ''),
('imaging_report_services', 'consultorio_ext', 'Consultorio Externo',   7, 0, 0, '', 'Ambulatorio / Especialidades', '');

-- 3. Opciones para el Listado de Regiones Anatómicas ('imaging_report_anatomy')
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`) VALUES
-- Cabeza, Cuello y Macizo Facial
('imaging_report_anatomy', 'craneo_cerebro',     'Cráneo / Encéfalo',           10, 0, 0, '', 'Cráneo, encéfalo y parénquima cerebral', 'SNOMED-CT:12738000'),
('imaging_report_anatomy', 'macizo_facial',      'Macizo Facial / Senos Paranasales', 11, 0, 0, '', 'Senos paranasales y huesos de la cara', 'SNOMED-CT:1101003'),
('imaging_report_anatomy', 'orbita_silla_turca', 'Órbitas / Silla Turca',       12, 0, 0, '', 'Órbitas, peñascos y fosa hipofisaria', 'SNOMED-CT:361038002'),
('imaging_report_anatomy', 'cuello_partes_blandas', 'Cuello / Partes Blandas',  13, 0, 0, '', 'Partes blandas del cuello, tiroides y cavum', 'SNOMED-CT:45048000'),
-- Columna Vertebral
('imaging_report_anatomy', 'columna_cervical',   'Columna Cervical',            20, 0, 0, '', 'Columna Cervical', 'SNOMED-CT:122494005'),
('imaging_report_anatomy', 'columna_dorsal',     'Columna Dorsal / Torácica',   21, 0, 0, '', 'Columna Torácica', 'SNOMED-CT:122495006'),
('imaging_report_anatomy', 'columna_lumbar',     'Columna Lumbar',              22, 1, 0, '', 'Columna Lumbosacra', 'SNOMED-CT:122496007'),
('imaging_report_anatomy', 'sacro_coxis',        'Sacro / Cóccix',              23, 0, 0, '', 'Sacro y Cóccix', 'SNOMED-CT:87031002'),
-- Tórax, Abdomen y Pelvis
('imaging_report_anatomy', 'torax',              'Tórax',                       30, 0, 0, '', 'Tórax, pulmones y mediastino', 'SNOMED-CT:51185008'),
('imaging_report_anatomy', 'mamografia_mama',    'Mama (Izquierda / Derecha / Bilateral)', 31, 0, 0, '', 'Glándula mamaria y axila', 'SNOMED-CT:76752008'),
('imaging_report_anatomy', 'abdomen_superior',   'Abdomen Superior',            32, 0, 0, '', 'Hígado, vías biliares, páncreas y bazo', 'SNOMED-CT:818983003'),
('imaging_report_anatomy', 'abdomen_pelvis',     'Abdomen Completo y Pelvis',   33, 0, 0, '', 'Abdomen total y cavidad pélvica', 'SNOMED-CT:818981001'),
('imaging_report_anatomy', 'pelvis_ginecologica','Pelvis / Aparato Genital',    34, 0, 0, '', 'Pelvis femenina/masculina y vejiga', 'SNOMED-CT:12921003'),
-- Miembro Superior
('imaging_report_anatomy', 'hombro',             'Hombro',                      40, 0, 0, '', 'Articulación escapulohumeral y clavícula', 'SNOMED-CT:16982005'),
('imaging_report_anatomy', 'brazo_humero',       'Brazo / Húmero',              41, 0, 0, '', 'Diáfisis humeral', 'SNOMED-CT:40983000'),
('imaging_report_anatomy', 'codo',               'Codo',                        42, 0, 0, '', 'Articulación del codo', 'SNOMED-CT:127949000'),
('imaging_report_anatomy', 'antebrazo',          'Antebrazo (Cúbito y Radio)',  43, 0, 0, '', 'Radio y cúbito', 'SNOMED-CT:64262003'),
('imaging_report_anatomy', 'muneca_mano',        'Muñeca / Mano',               44, 0, 0, '', 'Carpo, metacarpianos y falanges', 'SNOMED-CT:85050009'),
-- Miembro Inferior
('imaging_report_anatomy', 'cadera_pelvis',      'Cadera / Pelvis Ósea',        50, 0, 0, '', 'Cintura pélvica y articulación coxofemoral', 'SNOMED-CT:29836001'),
('imaging_report_anatomy', 'muslo_femur',        'Muslo / Fémur',               51, 0, 0, '', 'Diáfisis femoral', 'SNOMED-CT:29838000'),
('imaging_report_anatomy', 'rodilla',            'Rodilla',                     52, 0, 0, '', 'Articulación fémorotibial y rótula', 'SNOMED-CT:72696002'),
('imaging_report_anatomy', 'pierna_tibia_perone','Pierna (Tibia y Peroné)',     53, 0, 0, '', 'Tibia y peroné', 'SNOMED-CT:30254006'),
('imaging_report_anatomy', 'tobillo_pie',        'Tobillo / Pie',               54, 0, 0, '', 'Tarsos, metatarsianos y falanges', 'SNOMED-CT:56459004');

