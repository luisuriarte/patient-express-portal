-- ============================================================
-- Order + Report imaging context
-- OpenEMR 8.2.0 patch
--
-- Extends the imaging order (procedure_order) with the requesting
-- service / anatomical region selected when creating the imaging
-- request, and links the generated radiology report
-- (form_imaging_report) back to the originating order.
--
-- Run AFTER applying the common.php.patch (interface/forms/
-- procedure_order) so the columns referenced by the form exist.
-- Idempotent: safe to run more than once.
-- ============================================================

-- 1. Requesting service on the order (phone-in / list_options option_id)
ALTER TABLE `procedure_order`
  ADD COLUMN IF NOT EXISTS `requesting_service`  varchar(255) DEFAULT NULL
    COMMENT 'Imaging requesting service (list imaging_report_services option_id)' AFTER `location_id`;

-- 2. Anatomical region on the order (list_options option_id)
ALTER TABLE `procedure_order`
  ADD COLUMN IF NOT EXISTS `anatomical_region`  varchar(255) DEFAULT NULL
    COMMENT 'Anatomical region to explore (list imaging_report_anatomy option_id)' AFTER `requesting_service`;

-- ============================================================
-- Multi-provider PACS
-- OpenEMR 8.2.0 patch
--
-- Extends procedure_providers so each imaging service provider
-- (PACS/Orthanc) can carry its own connection endpoints. Per
-- product decision:
--   remote_host  = OHIF viewer base URL (full path, e.g. https://imagenes.origen.ar/viewer)
--   remote_api   = PACS REST API base URL (e.g. https://pacs.origen.ar)
--   wado_url     = DICOMweb WADO-RS base URL (e.g. https://pacs.origen.ar/dicom-web)
--   login/password = PACS HTTP Basic credentials (existing columns)
--
-- Idempotent: safe to run more than once (uses IF NOT EXISTS).
-- ============================================================

ALTER TABLE `procedure_providers`
  ADD COLUMN IF NOT EXISTS `remote_api` varchar(255) DEFAULT NULL
    COMMENT 'PACS REST API base URL (e.g. https://pacs.origen.ar)'
    AFTER `remote_host`;

ALTER TABLE `procedure_providers`
  ADD COLUMN IF NOT EXISTS `wado_url` varchar(255) DEFAULT NULL
    COMMENT 'DICOMweb WADO-RS base URL (e.g. https://pacs.origen.ar/dicom-web)'
    AFTER `remote_api`;
