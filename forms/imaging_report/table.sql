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
