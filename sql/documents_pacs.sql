-- ==============================================================================
-- Tabla de Sincronización de Documentos e Imágenes con Servidor PACS Orthanc
-- Proyecto: Patient Express Portal - Integración Nativa OpenEMR
-- ==============================================================================

-- 1. Tabla Principal de Registro y Seguimiento de Sincronización PACS
CREATE TABLE IF NOT EXISTS `documents_pacs_sync` (
    `document_id` INT(11) NOT NULL COMMENT 'Identificador del documento en la tabla documents de OpenEMR',
    `patient_id` BIGINT(20) NOT NULL COMMENT 'Identificador del paciente (PID) en OpenEMR',
    `orthanc_instance_id` VARCHAR(64) DEFAULT NULL COMMENT 'ID único de la instancia/archivo generado en Orthanc PACS (UUID hex)',
    `orthanc_series_id` VARCHAR(64) DEFAULT NULL COMMENT 'ID único de la serie en Orthanc PACS',
    `orthanc_study_id` VARCHAR(64) DEFAULT NULL COMMENT 'ID interno del estudio en Orthanc PACS',
    `study_instance_uid` VARCHAR(128) NOT NULL COMMENT 'StudyInstanceUID oficial del estándar DICOM (Tag 0020,000d) utilizado por el visor OHIF',
    `modality` VARCHAR(16) DEFAULT 'OT' COMMENT 'Modalidad DICOM asignada (ej: OT, CR, DX, CT, MR, US, ECG)',
    `status` ENUM('pending', 'synced', 'failed', 'ignored') NOT NULL DEFAULT 'synced' COMMENT 'Estado del proceso de sincronización con el servidor PACS',
    `error_message` TEXT DEFAULT NULL COMMENT 'Detalle del error devuelto por la API de Orthanc en caso de fallo',
    `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Fecha y hora en que se completó exitosamente la sincronización en Orthanc',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Fecha y hora de última actualización del registro',
    PRIMARY KEY (`document_id`),
    KEY `idx_patient_id` (`patient_id`),
    KEY `idx_study_instance_uid` (`study_instance_uid`),
    KEY `idx_status` (`status`),
    KEY `idx_synced_at` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Registro y mapeo de sincronización de documentos gráficos de OpenEMR hacia Orthanc PACS';

-- ==============================================================================
-- Opcional: Si se desea agregar índices o comentarios de optimización
-- ==============================================================================
-- INSERT / UPDATE de prueba o inicialización segura:
-- SELECT d.id, d.foreign_id, d.name, c.name AS category 
-- FROM documents d 
-- INNER JOIN categories_to_documents ctd ON d.id = ctd.document_id 
-- INNER JOIN categories c ON ctd.category_id = c.id
-- LEFT JOIN documents_pacs_sync dps ON d.id = dps.document_id
-- WHERE dps.document_id IS NULL;
