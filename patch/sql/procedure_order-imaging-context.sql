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

-- 3. Link the radiology report back to the imaging order that requested it
ALTER TABLE `form_imaging_report`
  ADD COLUMN IF NOT EXISTS `procedure_order_id`  BIGINT(20) DEFAULT NULL
    COMMENT 'Originating imaging order (procedure_order.procedure_order_id)' AFTER `pdf_category_id`;

-- 4. Index for the report->order lookup used by new.php
ALTER TABLE `form_imaging_report`
  ADD INDEX IF NOT EXISTS `idx_procedure_order_id` (`procedure_order_id`);
