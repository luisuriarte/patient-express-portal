-- ============================================================
-- Imaging report direct upload (Phase 2)
-- OpenEMR 8.2.0 patch
--
-- Adds the table that links uploaded imaging documents to a
-- radiology report and its originating procedure order, holding
-- the Orthanc/PACS linkage (StudyInstanceUID + internal ids).
--
-- Run AFTER installing/updating the form (forms/imaging_report/table.sql):
-- this table is created there too; this script is ONLY for environments
-- where the form was already installed and needs the new table added.
--
-- Idempotent: safe to run more than once.
-- ============================================================

CREATE TABLE IF NOT EXISTS `form_imaging_report_images` (
  `id`                 BIGINT(20)   NOT NULL AUTO_INCREMENT,
  `form_id`            BIGINT(20)   DEFAULT NULL COMMENT 'form_imaging_report.id (null if the report has not been saved yet)',
  `procedure_order_id` BIGINT(20)   DEFAULT NULL COMMENT 'Originating procedure_order.procedure_order_id',
  `pid`                BIGINT(20)   DEFAULT NULL COMMENT 'patient pid',
  `encounter_id`       BIGINT(20)   DEFAULT NULL COMMENT 'encounter id',
  `document_id`        BIGINT(20)   DEFAULT NULL COMMENT 'documents.id',
  `provider_id`        BIGINT(20)   DEFAULT NULL COMMENT 'procedure_providers.ppid (PACS provider)',
  `study_instance_uid` VARCHAR(128) DEFAULT NULL COMMENT 'DICOM StudyInstanceUID',
  `pacs_instance_id`  VARCHAR(128) DEFAULT NULL COMMENT 'PACS instance id (e.g. Orthanc instance UUID)',
  `pacs_series_id`    VARCHAR(128) DEFAULT NULL COMMENT 'PACS series id',
  `pacs_study_id`     VARCHAR(128) DEFAULT NULL COMMENT 'PACS internal study id',
  `modality`           VARCHAR(31)  DEFAULT NULL,
  `filename`           VARCHAR(255) DEFAULT NULL,
  `status`             ENUM('uploaded','failed') DEFAULT 'uploaded',
  `error_message`      TEXT,
  `created_at`         DATETIME     DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_form`  (`form_id`),
  KEY `idx_order` (`procedure_order_id`),
  KEY `idx_pid`   (`pid`),
  KEY `idx_doc`   (`document_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
