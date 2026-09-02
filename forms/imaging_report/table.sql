-- ============================================================
-- Form: Imaging Diagnostic Report
-- OpenEMR Clinical Form: imaging_report
-- Table: form_imaging_report
-- ============================================================
CREATE TABLE IF NOT EXISTS `form_imaging_report` (
  `id`                     BIGINT(20)      NOT NULL AUTO_INCREMENT,
  `date`                   DATETIME        DEFAULT NULL,
  `pid`                    BIGINT(20)      DEFAULT NULL,
  `user`                   VARCHAR(255)    DEFAULT NULL,
  `groupname`              VARCHAR(255)    DEFAULT NULL,
  `authorized`             TINYINT(4)      DEFAULT NULL,
  `activity`               TINYINT(4)      DEFAULT NULL,
  -- Imaging report fields
  `modality`               VARCHAR(100)    DEFAULT NULL COMMENT 'RX, CT, MRI, US, MG, DEXA',
  `anatomical_region`      VARCHAR(255)    DEFAULT NULL COMMENT 'Explored anatomical area',
  `requesting_service`     VARCHAR(255)    DEFAULT NULL,
  `reporting_physician`    VARCHAR(255)    DEFAULT NULL,
  `requesting_physician`   VARCHAR(255)    DEFAULT NULL,
  `technique`              LONGTEXT        DEFAULT NULL COMMENT 'Technique / Sequences used',
  `interpretation`         LONGTEXT        DEFAULT NULL COMMENT 'Descriptive findings',
  `conclusion`             LONGTEXT        DEFAULT NULL COMMENT 'Diagnostic impression',
  `observations`           LONGTEXT        DEFAULT NULL COMMENT 'Suggestions / Additional notes',
  `status`                 ENUM('draft','finalized') DEFAULT 'draft',
  `pdf_document_id`        BIGINT(20)      DEFAULT NULL COMMENT 'ID of the PDF document in the documents table',
  `pdf_category_id`        BIGINT(20)      DEFAULT NULL COMMENT 'Chosen destination folder (id in categories table)',
  `study_instance_uid`     VARCHAR(128)    DEFAULT NULL COMMENT 'DICOM StudyInstanceUID of the linked study (tag 0020,000D)',
  `accession_number`       VARCHAR(64)     DEFAULT NULL COMMENT 'Accession number / study identifier in Orthanc',
  `pdf_path`               VARCHAR(512)    DEFAULT NULL COMMENT 'Physical path relative to the generated PDF',
  `study_date`             DATE            DEFAULT NULL COMMENT 'Date the imaging study was performed (chosen by the user)',
  `report_date`            DATE            DEFAULT NULL COMMENT 'Date the report was generated/finalized (automatic)',
  PRIMARY KEY (`id`),
  KEY `idx_pid`            (`pid`),
  KEY `idx_status`         (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- Upgrades an existing installation from the Spanish schema
-- (column and ENUM names) to the English one, without losing data.
-- Runs only if the old Spanish columns still exist.
-- ============================================================
ALTER TABLE `form_imaging_report`
  CHANGE COLUMN `modalidad`           `modality`              VARCHAR(100) DEFAULT NULL,
  CHANGE COLUMN `region_anatomica`    `anatomical_region`     VARCHAR(255) DEFAULT NULL,
  CHANGE COLUMN `servicio_solicitante` `requesting_service`   VARCHAR(255) DEFAULT NULL,
  CHANGE COLUMN `medico_informante`   `reporting_physician`   VARCHAR(255) DEFAULT NULL,
  CHANGE COLUMN `medico_solicitante`  `requesting_physician`  VARCHAR(255) DEFAULT NULL,
  CHANGE COLUMN `metodologia`         `technique`             LONGTEXT DEFAULT NULL,
  CHANGE COLUMN `interpretacion`      `interpretation`        LONGTEXT DEFAULT NULL,
  CHANGE COLUMN `conclusion`          `conclusion`            LONGTEXT DEFAULT NULL,
  CHANGE COLUMN `observaciones`       `observations`          LONGTEXT DEFAULT NULL,
  CHANGE COLUMN `estado`              `status`                ENUM('draft','finalized') DEFAULT 'draft',
  CHANGE COLUMN `fecha_informe`       `report_date`           DATE DEFAULT NULL;

UPDATE `form_imaging_report`
   SET `status` = 'finalized'
 WHERE `status` = 'finalizado';

UPDATE `form_imaging_report`
   SET `status` = 'draft'
 WHERE `status` = 'borrador';

-- ============================================================
-- Normalized lists (list_options) for the form dropdowns
--   1. Requesting Services        -> list 'imaging_report_services'
--   2. Region / Anatomical Area   -> list 'imaging_report_anatomy'
-- INSERT IGNORE => idempotent, installed together with the form
-- ============================================================

-- 1. Register the two new lists in the master list ('lists')
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`) VALUES
('lists', 'imaging_report_services', 'Requesting Services (Imaging)', 510, 1, 0, '', 'List of hospital/clinic requesting services for radiology reports', ''),
('lists', 'imaging_report_anatomy',  'Region / Anatomical Area (Imaging)', 511, 1, 0, '', 'List of anatomical regions for diagnostic imaging studies', '');

-- 2. Options for the Requesting Services list ('imaging_report_services')
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`) VALUES
('imaging_report_services', 'emergency',         'Emergency Room / Urgency',   1, 1, 0, '', 'Emergency and Urgency Service', ''),
('imaging_report_services', 'internal_medicine', 'Internal Medicine',          2, 0, 0, '', 'Inpatient and Outpatient Internal Medicine', ''),
('imaging_report_services', 'orthopedics',       'Traumatology / Orthopedics', 3, 0, 0, '', 'Orthopedics and Traumatology', ''),
('imaging_report_services', 'pediatrics',        'Pediatrics',                 4, 0, 0, '', 'Pediatric Care', ''),
('imaging_report_services', 'icu',               'Intensive Care Unit (ICU)',  5, 0, 0, '', 'Intensive Care', ''),
('imaging_report_services', 'general_surgery',   'General Surgery',            6, 0, 0, '', 'Surgical Service', ''),
('imaging_report_services', 'outpatient',        'Outpatient Clinic',          7, 0, 0, '', 'Ambulatory / Specialties', '');

-- 3. Options for the Anatomical Regions list ('imaging_report_anatomy')
INSERT IGNORE INTO `list_options` (`list_id`, `option_id`, `title`, `seq`, `is_default`, `option_value`, `mapping`, `notes`, `codes`) VALUES
-- Head, Neck and Facial Mass
('imaging_report_anatomy', 'skull_brain',          'Skull / Brain',                    10, 0, 0, '', 'Skull, brain and cerebral parenchyma', 'SNOMED-CT:12738000'),
('imaging_report_anatomy', 'facial_sinuses',       'Facial Mass / Paranasal Sinuses',  11, 0, 0, '', 'Paranasal sinuses and facial bones', 'SNOMED-CT:1101003'),
('imaging_report_anatomy', 'orbits_sella',         'Orbits / Sella Turcica',           12, 0, 0, '', 'Orbits, petrous bones and pituitary fossa', 'SNOMED-CT:361038002'),
('imaging_report_anatomy', 'neck_soft_tissue',     'Neck / Soft Tissues',              13, 0, 0, '', 'Soft tissues of the neck, thyroid and nasopharynx', 'SNOMED-CT:45048000'),
-- Spine
('imaging_report_anatomy', 'cervical_spine',       'Cervical Spine',                   20, 0, 0, '', 'Cervical Spine', 'SNOMED-CT:122494005'),
('imaging_report_anatomy', 'dorsal_spine',         'Dorsal / Thoracic Spine',          21, 0, 0, '', 'Thoracic Spine', 'SNOMED-CT:122495006'),
('imaging_report_anatomy', 'lumbar_spine',         'Lumbar Spine',                     22, 1, 0, '', 'Lumbosacral Spine', 'SNOMED-CT:122496007'),
('imaging_report_anatomy', 'sacrum_coccyx',        'Sacrum / Coccyx',                  23, 0, 0, '', 'Sacrum and Coccyx', 'SNOMED-CT:87031002'),
-- Thorax, Abdomen and Pelvis
('imaging_report_anatomy', 'thorax',               'Thorax',                           30, 0, 0, '', 'Thorax, lungs and mediastinum', 'SNOMED-CT:51185008'),
('imaging_report_anatomy', 'breast',               'Breast (Left / Right / Bilateral)', 31, 0, 0, '', 'Breast gland and axilla', 'SNOMED-CT:76752008'),
('imaging_report_anatomy', 'upper_abdomen',        'Upper Abdomen',                    32, 0, 0, '', 'Liver, biliary tract, pancreas and spleen', 'SNOMED-CT:818983003'),
('imaging_report_anatomy', 'abdomen_pelvis',       'Complete Abdomen and Pelvis',      33, 0, 0, '', 'Total abdomen and pelvic cavity', 'SNOMED-CT:818981001'),
('imaging_report_anatomy', 'pelvis_genital',       'Pelvis / Genital System',          34, 0, 0, '', 'Female/male pelvis and bladder', 'SNOMED-CT:12921003'),
-- Upper Limb
('imaging_report_anatomy', 'shoulder',             'Shoulder',                         40, 0, 0, '', 'Scapulohumeral joint and clavicle', 'SNOMED-CT:16982005'),
('imaging_report_anatomy', 'arm_humerus',          'Arm / Humerus',                    41, 0, 0, '', 'Humeral shaft', 'SNOMED-CT:40983000'),
('imaging_report_anatomy', 'elbow',                'Elbow',                            42, 0, 0, '', 'Elbow joint', 'SNOMED-CT:127949000'),
('imaging_report_anatomy', 'forearm',              'Forearm (Radius and Ulna)',        43, 0, 0, '', 'Radius and ulna', 'SNOMED-CT:64262003'),
('imaging_report_anatomy', 'wrist_hand',           'Wrist / Hand',                     44, 0, 0, '', 'Carpus, metacarpals and phalanges', 'SNOMED-CT:85050009'),
-- Lower Limb
('imaging_report_anatomy', 'hip_pelvis',           'Hip / Bony Pelvis',                50, 0, 0, '', 'Pelvic girdle and coxofemoral joint', 'SNOMED-CT:29836001'),
('imaging_report_anatomy', 'thigh_femur',          'Thigh / Femur',                    51, 0, 0, '', 'Femoral shaft', 'SNOMED-CT:29838000'),
('imaging_report_anatomy', 'knee',                 'Knee',                             52, 0, 0, '', 'Femorotibial joint and patella', 'SNOMED-CT:72696002'),
('imaging_report_anatomy', 'leg_tibia_fibula',     'Leg (Tibia and Fibula)',           53, 0, 0, '', 'Tibia and fibula', 'SNOMED-CT:30254006'),
('imaging_report_anatomy', 'ankle_foot',           'Ankle / Foot',                     54, 0, 0, '', 'Tarsals, metatarsals and phalanges', 'SNOMED-CT:56459004');
