-- 1. Crear Categoría Principal 'DIAGNÓSTICO POR IMÁGENES'
INSERT INTO `procedure_type` (`name`, `parent`, `description`, `procedure_code`, `activity`) 
VALUES ('DIAGNÓSTICO POR IMÁGENES', 0, 'Catálogo general de estudios de diagnóstico por imágenes', 'IMG_ROOT', 1);

-- Obtener el ID insertado para la categoría raíz (asumiendo que se guardó en una variable o se consulta su ID)
SET @root_id = LAST_INSERT_ID();

-- 2. Crear Subcategorías por Modalidad
INSERT INTO `procedure_type` (`name`, `parent`, `description`, `procedure_code`, `activity`) VALUES
('Radiología Convencional (RX)', @root_id, 'Estudios radiográficos simples y contrastados', 'MOD_RX', 1),
('Ecografía / Ultrasonografía (ECO)', @root_id, 'Estudios ecográficos generales y Doppler', 'MOD_ECO', 1),
('Tomografía Computada (TAC)', @root_id, 'Tomografía axial computada con y sin contraste', 'MOD_TAC', 1),
('Resonancia Magnética (RMN)', @root_id, 'Resonancia magnética nuclear', 'MOD_RMN', 1),
('Mamografía (MAMO)', @root_id, 'Mamografía y tomosíntesis', 'MOD_MAMO', 1);

-- Obtener IDs de cada modalidad creada
SET @rx_id   = (SELECT procedure_type_id FROM procedure_type WHERE procedure_code = 'MOD_RX');
SET @eco_id  = (SELECT procedure_type_id FROM procedure_type WHERE procedure_code = 'MOD_ECO');
SET @tac_id  = (SELECT procedure_type_id FROM procedure_type WHERE procedure_code = 'MOD_TAC');
SET @rmn_id  = (SELECT procedure_type_id FROM procedure_type WHERE procedure_code = 'MOD_RMN');
SET @mamo_id = (SELECT procedure_type_id FROM procedure_type WHERE procedure_code = 'MOD_MAMO');

-- 3. Carga Masiva de Estudios Frecuentes

-- Radiología (RX)
INSERT INTO `procedure_type` (`name`, `parent`, `description`, `procedure_code`, `activity`) VALUES
('RX Tórax Frente y Perfil', @rx_id, 'Radiografía de tórax bidireccional', 'RX-TORAX-FP', 1),
('RX Columna Cervical Frente y Perfil', @rx_id, 'Radiografía de columna cervical', 'RX-COL-CERV', 1),
('RX Columna Lumbar Frente y Perfil', @rx_id, 'Radiografía de columna lumbosacra', 'RX-COL-LUMB', 1),
('RX Abdomen Simple de Pie', @rx_id, 'Radiografía de abdomen agudo', 'RX-ABD-SIMPLE', 1),
('RX Rodilla Frente y Perfil', @rx_id, 'Radiografía de articulación de rodilla', 'RX-RODILLA', 1),
('RX Cráneo Frente y Perfil', @rx_id, 'Radiografía de cráneo', 'RX-CRANEO', 1),
('RX Cadera / Pelvis Frente', @rx_id, 'Radiografía de pelvis y caderas', 'RX-PELVIS', 1);

-- Ecografía (ECO)
INSERT INTO `procedure_type` (`name`, `parent`, `description`, `procedure_code`, `activity`) VALUES
('Ecografía Abdominal Completa', @eco_id, 'Ecografía hepatobiliar, pancreática, esplénica y renal', 'ECO-ABD-COMP', 1),
('Ecografía Renal y Vesical', @eco_id, 'Ecografía de aparato urinario', 'ECO-REN-VES', 1),
('Ecografía Ginecológica / Pelviana', @eco_id, 'Ecografía ginecológica supra pÚbica', 'ECO-PELV', 1),
('Ecografía Transvaginal', @eco_id, 'Ecografía ginecológica endovaginal', 'ECO-TV', 1),
('Ecografía Tiroidea y Partes Blandas de Cuello', @eco_id, 'Ecografía de tiroides y cervical', 'ECO-TIROIDES', 1),
('Ecografía Mamaria Bilateral', @eco_id, 'Ecografía de glándulas mamarias y axilas', 'ECO-MAMA-BIL', 1),
('Ecocardiograma Doppler Color', @eco_id, 'Ecocardiografía transtorácica con Doppler', 'ECO-DOPP-CARD', 1);

-- Tomografía (TAC)
INSERT INTO `procedure_type` (`name`, `parent`, `description`, `procedure_code`, `activity`) VALUES
('TAC de CEREBRO / Encéfalo Sin Contraste', @tac_id, 'Tomografía de cráneo simple', 'TAC-CRANEO-SC', 1),
('TAC de Tórax con y sin Contraste', @tac_id, 'Tomografía de tórax contrastada', 'TAC-TORAX-CC', 1),
('TAC de Abdomen y Pelvis con Contraste', @tac_id, 'Tomografía de abdomen y pelvis completa', 'TAC-ABD-PELV-CC', 1),
('TAC de Senos Paranasales', @tac_id, 'Tomografía de macizo facial y senos paranasales', 'TAC-SPN', 1),
('TAC de Columna Lumbar', @tac_id, 'Tomografía de columna lumbosacra', 'TAC-COL-LUMB', 1);

-- Resonancia Magnética (RMN)
INSERT INTO `procedure_type` (`name`, `parent`, `description`, `procedure_code`, `activity`) VALUES
('RMN de Cerebro', @rmn_id, 'Resonancia magnética encefálica', 'RMN-CEREBRO', 1),
('RMN de Columna Lumbar', @rmn_id, 'Resonancia magnética lumbosacra', 'RMN-COL-LUMB', 1),
('RMN de Columna Cervical', @rmn_id, 'Resonancia magnética cervical', 'RMN-COL-CERV', 1),
('RMN de Rodilla', @rmn_id, 'Resonancia magnética de rodilla con secuencias ligamentarias', 'RMN-RODILLA', 1),
('RMN de Hombro', @rmn_id, 'Resonancia magnética articulación glenohumeral', 'RMN-HOMBRO', 1);

-- Mamografía (MAMO)
INSERT INTO `procedure_type` (`name`, `parent`, `description`, `procedure_code`, `activity`) VALUES
('Mamografía Bilateral Digital', @mamo_id, 'Mamografía en proyecciones CC y MLO', 'MAMO-BIL', 1),
('Mamografía Unilateral', @mamo_id, 'Mamografía de control mono-lateral', 'MAMO-UNI', 1);