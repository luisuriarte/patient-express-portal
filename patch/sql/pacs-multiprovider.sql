-- ============================================================
-- Multi-provider PACS
-- OpenEMR 8.2.0 patch
--
-- Extends procedure_providers so each imaging service provider
-- (PACS/Orthanc) can carry its own connection endpoints. Per
-- product decision:
--   remote_host  = OHIF viewer base URL (full path, e.g. https://imagenes.origen.ar/viewer)
--   remote_api   = Orthanc REST API base URL (e.g. https://pacs.origen.ar)
--   wado_url     = DICOMweb WADO-RS base URL (e.g. https://pacs.origen.ar/dicom-web)
--   login/password = Orthanc HTTP Basic credentials (existing columns)
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
