-- ==============================================================================
-- Document and Image Synchronization Table with Orthanc PACS Server
-- Project: Patient Express Portal - Native OpenEMR Integration
-- ==============================================================================

-- 1. Main Table for PACS Synchronization Tracking
CREATE TABLE IF NOT EXISTS `documents_pacs_sync` (
    `document_id` INT(11) NOT NULL COMMENT 'Document identifier in the OpenEMR documents table',
    `patient_id` BIGINT(20) NOT NULL COMMENT 'Patient identifier (PID) in OpenEMR',
    `orthanc_instance_id` VARCHAR(64) DEFAULT NULL COMMENT 'Unique ID of the instance/file generated in Orthanc PACS (hex UUID)',
    `orthanc_series_id` VARCHAR(64) DEFAULT NULL COMMENT 'Unique series ID in Orthanc PACS',
    `orthanc_study_id` VARCHAR(64) DEFAULT NULL COMMENT 'Internal study ID in Orthanc PACS',
    `study_instance_uid` VARCHAR(128) NOT NULL COMMENT 'Official DICOM StudyInstanceUID (Tag 0020,000d) used by the OHIF viewer',
    `modality` VARCHAR(16) DEFAULT 'OT' COMMENT 'Assigned DICOM modality (e.g.: OT, CR, DX, CT, MR, US, ECG)',
    `status` ENUM('pending', 'synced', 'failed', 'ignored') NOT NULL DEFAULT 'synced' COMMENT 'Synchronization process status with the PACS server',
    `error_message` TEXT DEFAULT NULL COMMENT 'Error detail returned by the Orthanc API on failure',
    `synced_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Date and time the synchronization to Orthanc completed successfully',
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Date and time of the last record update',
    PRIMARY KEY (`document_id`),
    KEY `idx_patient_id` (`patient_id`),
    KEY `idx_study_instance_uid` (`study_instance_uid`),
    KEY `idx_status` (`status`),
    KEY `idx_synced_at` (`synced_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci 
COMMENT='Record and mapping of the synchronization of OpenEMR graphic documents to Orthanc PACS';

-- ==============================================================================
-- Optional: Add indexes or optimization comments here
-- ==============================================================================
-- Test INSERT / UPDATE or safe initialization:
-- SELECT d.id, d.foreign_id, d.name, c.name AS category 
-- FROM documents d 
-- INNER JOIN categories_to_documents ctd ON d.id = ctd.document_id 
-- INNER JOIN categories c ON ctd.category_id = c.id
-- LEFT JOIN documents_pacs_sync dps ON d.id = dps.document_id
-- WHERE dps.document_id IS NULL;
