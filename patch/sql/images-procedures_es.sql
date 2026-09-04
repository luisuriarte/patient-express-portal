-- ============================================================================
-- images-procedures_es.sql
-- Mass load of the DIAGNOSTIC IMAGING catalog into the procedure_type
-- table of OpenEMR. Spanish variant of imaging (same schema/codes as the
-- English variant images-procedures.sql; only the visible text of the
-- studies differs).
--
-- Hierarchy created:
--   Level 1 (parent = 0)   : DIAGNOSTIC IMAGING                (procedure_type = 'grp')
--   Level 2 (parent = root): RX, US, CT, MRI, MG               (procedure_type = 'grp')
--   Level 3 (parent = modality): each individual study          (procedure_type = 'ord')
--
-- NOTE: the root ('DIAGNOSTIC IMAGING') and modalities use the same
-- names/codes (RX, US, CT, MRI, MG) as in the English variant because
-- they are technical identifiers used by the code (find_order_popup.php,
-- src/Imaging.php, etc.). Only the studies (procedures of type 'ord')
-- carry name/description in Spanish.
--
-- All levels carry procedure_type_name = 'imaging'.
--
-- Studies are loaded with:
--   - procedure_code     : unique internal code, identical to the English
--                          variant (e.g. RX-CHEST-2V). Do NOT translate.
--   - standard_code      : reference CPT4 code. Replaceable by the local
--                          nomenclature by editing the cpt column of the UNION.
--   - lab_id = 3         : provider "Imaging Service" (procedure_providers).
--
-- The script is IDEMPOTENT and includes a MIGRATION block that renames columns
-- loaded by previous versions (old codes -> new codes and text to Spanish).
-- Run as:
--   mariadb -u user -p db_name < images-procedures_es.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- MIGRATION: rename previously-loaded values (old codes -> new codes
-- and name/description to Spanish). Safe to re-run (WHERE on old codes).
-- ----------------------------------------------------------------------------
-- Root
UPDATE procedure_type SET name = 'DIAGNOSTIC IMAGING', description = 'Diagnostic imaging: RX, US, CT, MRI, MG'
 WHERE procedure_code = 'IMG';

-- Modalities (grp): name and code are the same modality code
UPDATE procedure_type SET name = 'RX',  procedure_code = 'RX',  description = 'Radiología'                   WHERE procedure_code = 'RX'   AND name = 'RX';
UPDATE procedure_type SET name = 'US',  procedure_code = 'US',  description = 'Ecografía'                    WHERE procedure_code = 'ECO'  AND name = 'ECO';
UPDATE procedure_type SET name = 'CT',  procedure_code = 'CT',  description = 'Tomografía Axial Computada'    WHERE procedure_code = 'TAC'  AND name = 'TAC';
UPDATE procedure_type SET name = 'MRI', procedure_code = 'MRI', description = 'Resonancia Magnética Nuclear'  WHERE procedure_code = 'RMN' AND name = 'RMN';
UPDATE procedure_type SET name = 'MG',  procedure_code = 'MG',  description = 'Mamografía'                   WHERE procedure_code = 'MAMO' AND name = 'MAMO';

-- Radiology (RX) studies
UPDATE procedure_type SET procedure_code = 'RX-CHEST-2V',  name = 'RX de tórax (2 proyecciones)', description = 'RX de tórax (2 proyecciones)' WHERE procedure_code = 'RX-TORAX-2V'  AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-CHEST-1V',  name = 'RX de tórax (1 proyección)',  description = 'RX de tórax (1 proyección)'  WHERE procedure_code = 'RX-TORAX-1V'  AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-ABDOMEN',   name = 'RX de abdomen simple',        description = 'RX de abdomen simple'        WHERE procedure_code = 'RX-ABDOMEN'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-SINUSES',   name = 'RX de senos paranasales',     description = 'RX de senos paranasales'     WHERE procedure_code = 'RX-SENOS-PAR' AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-SKULL',     name = 'RX de cráneo',                description = 'RX de cráneo'                WHERE procedure_code = 'RX-CRANEO'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-CERV-SPINE',name = 'RX de columna cervical',      description = 'RX de columna cervical'      WHERE procedure_code = 'RX-CERVICAL'  AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-DORSAL',    name = 'RX de columna dorsal',        description = 'RX de columna dorsal'        WHERE procedure_code = 'RX-DORSAL'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-LUM-SPINE', name = 'RX de columna lumbar',        description = 'RX de columna lumbar'        WHERE procedure_code = 'RX-LUMBAR'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-PELVIS',    name = 'RX de pelvis',                description = 'RX de pelvis'                WHERE procedure_code = 'RX-PELVIS'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-HIP',       name = 'RX de cadera (unilateral)',   description = 'RX de cadera (unilateral)'   WHERE procedure_code = 'RX-CADERA'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-SHOULDER',  name = 'RX de hombro (mono)',         description = 'RX de hombro (mono)'         WHERE procedure_code = 'RX-HOMBRO'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-ELBOW',     name = 'RX de codo (mono)',           description = 'RX de codo (mono)'           WHERE procedure_code = 'RX-CODO'      AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-WRIST',     name = 'RX de muñeca (mono)',         description = 'RX de muñeca (mono)'         WHERE procedure_code = 'RX-MUÑECA'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-HAND',      name = 'RX de mano (mono)',           description = 'RX de mano (mono)'           WHERE procedure_code = 'RX-MANO'      AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-KNEE',      name = 'RX de rodilla (mono)',        description = 'RX de rodilla (mono)'        WHERE procedure_code = 'RX-RODILLA'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-ANKLE',     name = 'RX de tobillo (mono)',        description = 'RX de tobillo (mono)'        WHERE procedure_code = 'RX-TOBILLO'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'RX-FOOT',      name = 'RX de pie (mono)',            description = 'RX de pie (mono)'            WHERE procedure_code = 'RX-PIE'       AND procedure_type = 'ord';

-- Ultrasound (US) studies
UPDATE procedure_type SET procedure_code = 'US-ABDOMEN',      name = 'Ecografía abdominal',                  description = 'Ecografía abdominal'                  WHERE procedure_code = 'ECO-ABDOMINAL'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-ABD-SUP',      name = 'Ecografía abdominal superior',         description = 'Ecografía abdominal superior'         WHERE procedure_code = 'ECO-ABDOM-SUP'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-RENAL-UT',     name = 'Ecografía renal y vías urinarias',     description = 'Ecografía renal y vías urinarias'     WHERE procedure_code = 'ECO-RENAL'       AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-THYROID',      name = 'Ecografía de tiroides',                description = 'Ecografía de tiroides'                WHERE procedure_code = 'ECO-TIROIDEA'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-BREAST',       name = 'Ecografía mamaria bilateral',          description = 'Ecografía mamaria bilateral'          WHERE procedure_code = 'ECO-MAMARIA'     AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-SOFT-TISSUE',  name = 'Ecografía de partes blandas',          description = 'Ecografía de partes blandas'          WHERE procedure_code = 'ECO-PARTES-BLANDAS' AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-PELVIC-ABD',   name = 'Ecografía pélvica (vía abdominal)',    description = 'Ecografía pélvica (vía abdominal)'    WHERE procedure_code = 'ECO-PELVICA'     AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-TV',           name = 'Ecografía transvaginal',               description = 'Ecografía transvaginal'               WHERE procedure_code = 'ECO-TV'          AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-OBSTETRIC',    name = 'Ecografía obstétrica',                 description = 'Ecografía obstétrica'                 WHERE procedure_code = 'ECO-OBSTETRICA'  AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-JOINT',        name = 'Ecografía articular',                  description = 'Ecografía articular'                  WHERE procedure_code = 'ECO-ARTICULAR'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-DOPPLER-LL',   name = 'Eco doppler de miembros inferiores',   description = 'Eco doppler de miembros inferiores'   WHERE procedure_code = 'ECO-DOPPLER-MI'  AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-DOPPLER-UL',   name = 'Eco doppler de miembros superiores',   description = 'Eco doppler de miembros superiores'   WHERE procedure_code = 'ECO-DOPPLER-MS'  AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'US-DOPPLER-NECK', name = 'Eco doppler de vasos del cuello',      description = 'Eco doppler de vasos del cuello'      WHERE procedure_code = 'ECO-VASCULAR-CC' AND procedure_type = 'ord';

-- Computed Tomography (CT) studies
UPDATE procedure_type SET procedure_code = 'CT-BRAIN',        name = 'TAC de cráneo/cerebro',               description = 'TAC de cráneo/cerebro'               WHERE procedure_code = 'TAC-CEREBRO'       AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-SINUSES',      name = 'TAC de senos paranasales',            description = 'TAC de senos paranasales'            WHERE procedure_code = 'TAC-SENOS-PAR'     AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-NECK',         name = 'TAC de cuello',                       description = 'TAC de cuello'                       WHERE procedure_code = 'TAC-CUELLO'        AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-CHEST-SC',     name = 'TAC de tórax sin contraste',          description = 'TAC de tórax sin contraste'          WHERE procedure_code = 'TAC-TORAX-SC'      AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-CHEST-C',      name = 'TAC de tórax con contraste',          description = 'TAC de tórax con contraste'          WHERE procedure_code = 'TAC-TORAX-C'       AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-ABD-SC',       name = 'TAC de abdomen sin contraste',        description = 'TAC de abdomen sin contraste'        WHERE procedure_code = 'TAC-ABDOMEN-SC'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-ABD-C',        name = 'TAC de abdomen con contraste',        description = 'TAC de abdomen con contraste'        WHERE procedure_code = 'TAC-ABDOMEN-C'     AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-PELVIS-C',     name = 'TAC de pelvis con contraste',         description = 'TAC de pelvis con contraste'         WHERE procedure_code = 'TAC-PELVIS-C'      AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-ABD-PELVIS-C', name = 'TAC de abdomen y pelvis c/contraste',  description = 'TAC de abdomen y pelvis c/contraste'  WHERE procedure_code = 'TAC-ABDOM-PELVIS-C' AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-CERV-SPINE',   name = 'TAC de columna cervical',             description = 'TAC de columna cervical'             WHERE procedure_code = 'TAC-CERVICAL'      AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-LUM-SPINE',    name = 'TAC de columna lumbar',               description = 'TAC de columna lumbar'               WHERE procedure_code = 'TAC-LUMBAR'        AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'CT-CHEST-LD',     name = 'TAC de tórax (baja dosis/screening)', description = 'TAC de tórax (baja dosis/screening)' WHERE procedure_code = 'TAC-TORAX-BT'      AND procedure_type = 'ord';

-- Magnetic Resonance Imaging (MRI) studies
UPDATE procedure_type SET procedure_code = 'MRI-BRAIN',      name = 'RMN de cráneo/cerebro',         description = 'RMN de cráneo/cerebro'         WHERE procedure_code = 'RMN-CEREBRO'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-NECK',       name = 'RMN de cuello',                 description = 'RMN de cuello'                 WHERE procedure_code = 'RMN-CUELLO'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-CERV-SPINE', name = 'RMN de columna cervical',       description = 'RMN de columna cervical'       WHERE procedure_code = 'RMN-CERVICAL'  AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-DORSAL',     name = 'RMN de columna dorsal',         description = 'RMN de columna dorsal'         WHERE procedure_code = 'RMN-DORSAL'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-LUM-SPINE',  name = 'RMN de columna lumbar',         description = 'RMN de columna lumbar'         WHERE procedure_code = 'RMN-LUMBAR'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-ABD-SUP',    name = 'RMN de abdomen superior',       description = 'RMN de abdomen superior'       WHERE procedure_code = 'RMN-ABDOMEN'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-PELVIS',     name = 'RMN de pelvis',                 description = 'RMN de pelvis'                 WHERE procedure_code = 'RMN-PELVIS'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-HIP',        name = 'RMN de cadera (unilateral)',    description = 'RMN de cadera (unilateral)'    WHERE procedure_code = 'RMN-CADERA'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-SHOULDER',   name = 'RMN de hombro (unilateral)',    description = 'RMN de hombro (unilateral)'    WHERE procedure_code = 'RMN-HOMBRO'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-ELBOW',      name = 'RMN de codo',                   description = 'RMN de codo'                   WHERE procedure_code = 'RMN-CODO'      AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-WRIST',      name = 'RMN de muñeca',                 description = 'RMN de muñeca'                 WHERE procedure_code = 'RMN-MUÑECA'    AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-KNEE',       name = 'RMN de rodilla (mono)',         description = 'RMN de rodilla (mono)'         WHERE procedure_code = 'RMN-RODILLA'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-ANKLE',      name = 'RMN de tobillo',                description = 'RMN de tobillo'                WHERE procedure_code = 'RMN-TOBILLO'   AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MRI-BREAST',     name = 'RMN de mamas (bilateral)',      description = 'RMN de mamas (bilateral)'      WHERE procedure_code = 'RMN-MAMARIA'   AND procedure_type = 'ord';

-- Mammography (MG) studies
UPDATE procedure_type SET procedure_code = 'MAMMO-BIL-SCREEN', name = 'Mamografía bilateral (screening)',  description = 'Mamografía bilateral (screening)'  WHERE procedure_code = 'MAMO-BIL-SCREEN' AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MAMMO-BIL-DX',     name = 'Mamografía bilateral (diagnóstica)', description = 'Mamografía bilateral (diagnóstica)' WHERE procedure_code = 'MAMO-BIL-DX'     AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MAMMO-UNI',        name = 'Mamografía unilateral',              description = 'Mamografía unilateral'              WHERE procedure_code = 'MAMO-UNI'        AND procedure_type = 'ord';
UPDATE procedure_type SET procedure_code = 'MAMMO-TOMO',       name = 'Tomosíntesis mamaria (bilateral)',   description = 'Tomosíntesis mamaria (bilateral)'   WHERE procedure_code = 'MAMO-TOMO'       AND procedure_type = 'ord';

-- ----------------------------------------------------------------------------
-- Level 1: Root "DIAGNOSTIC IMAGING"
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT 0, 'DIAGNOSTIC IMAGING', 3, 'IMG', 'grp', 'Diagnostic imaging: RX, US, CT, MRI, MG', '', 10, 1, 'imaging'
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type
    WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING'
);

-- ----------------------------------------------------------------------------
-- Level 2: Modalities (parent = root)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT r.procedure_type_id, m.name, 3, m.code, 'grp', m.descripcion, '', m.seq, 1, 'imaging'
FROM (
    SELECT 'RX'   AS name, 'RX'   AS code, 'Radiología'                   AS descripcion, 10 AS seq
    UNION ALL SELECT 'US',        'US',        'Ecografía'                    ,                    20
    UNION ALL SELECT 'CT',        'CT',        'Tomografía Axial Computada'    ,                    30
    UNION ALL SELECT 'MRI',       'MRI',       'Resonancia Magnética Nuclear'  ,                    40
    UNION ALL SELECT 'MG',        'MG',        'Mamografía'                    ,                    50
) m
INNER JOIN procedure_type r
    ON r.parent = 0 AND r.procedure_type = 'grp' AND r.name = 'DIAGNOSTIC IMAGING'
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = r.procedure_type_id AND x.name = m.name
);

-- ----------------------------------------------------------------------------
-- Level 3: Radiology (RX) studies (parent = modality RX)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'RX-CHEST-2V'   AS code, 'RX de tórax (2 proyecciones)'  AS name, 'RX de tórax (2 proyecciones)'  AS description, '71046' AS cpt, 1  AS seq
    UNION ALL SELECT 'RX-CHEST-1V',  'RX de tórax (1 proyección)',     'RX de tórax (1 proyección)',      '71045', 2
    UNION ALL SELECT 'RX-ABDOMEN',   'RX de abdomen simple',           'RX de abdomen simple',            '74000', 3
    UNION ALL SELECT 'RX-SINUSES',   'RX de senos paranasales',        'RX de senos paranasales',         '70210', 4
    UNION ALL SELECT 'RX-SKULL',     'RX de cráneo',                   'RX de cráneo',                    '70250', 5
    UNION ALL SELECT 'RX-CERV-SPINE','RX de columna cervical',         'RX de columna cervical',          '72040', 6
    UNION ALL SELECT 'RX-DORSAL',    'RX de columna dorsal',           'RX de columna dorsal',            '72070', 7
    UNION ALL SELECT 'RX-LUM-SPINE', 'RX de columna lumbar',           'RX de columna lumbar',            '72100', 8
    UNION ALL SELECT 'RX-PELVIS',    'RX de pelvis',                   'RX de pelvis',                    '72170', 9
    UNION ALL SELECT 'RX-HIP',       'RX de cadera (unilateral)',      'RX de cadera (unilateral)',       '73500', 10
    UNION ALL SELECT 'RX-SHOULDER',  'RX de hombro (mono)',            'RX de hombro (mono)',             '73030', 11
    UNION ALL SELECT 'RX-ELBOW',     'RX de codo (mono)',              'RX de codo (mono)',               '73070', 12
    UNION ALL SELECT 'RX-WRIST',     'RX de muñeca (mono)',            'RX de muñeca (mono)',             '73100', 13
    UNION ALL SELECT 'RX-HAND',      'RX de mano (mono)',              'RX de mano (mono)',               '73130', 14
    UNION ALL SELECT 'RX-KNEE',      'RX de rodilla (mono)',           'RX de rodilla (mono)',            '73560', 15
    UNION ALL SELECT 'RX-ANKLE',     'RX de tobillo (mono)',           'RX de tobillo (mono)',            '73600', 16
    UNION ALL SELECT 'RX-FOOT',      'RX de pie (mono)',               'RX de pie (mono)',                '73630', 17
) s
INNER JOIN procedure_type m
    ON m.name = 'RX' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Level 3: Ultrasound (US) studies (parent = modality US)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'US-ABDOMEN'      AS code, 'Ecografía abdominal'                  AS name, 'Ecografía abdominal'                  AS description, '76700' AS cpt, 1  AS seq
    UNION ALL SELECT 'US-ABD-SUP', 'Ecografía abdominal superior',             'Ecografía abdominal superior',             '76705', 2
    UNION ALL SELECT 'US-RENAL-UT','Ecografía renal y vías urinarias',         'Ecografía renal y vías urinarias',         '76770', 3
    UNION ALL SELECT 'US-THYROID', 'Ecografía de tiroides',                    'Ecografía de tiroides',                    '76536', 4
    UNION ALL SELECT 'US-BREAST',  'Ecografía mamaria bilateral',              'Ecografía mamaria bilateral',              '76641', 5
    UNION ALL SELECT 'US-SOFT-TISSUE','Ecografía de partes blandas',           'Ecografía de partes blandas',              '76882', 6
    UNION ALL SELECT 'US-PELVIC-ABD','Ecografía pélvica (vía abdominal)',      'Ecografía pélvica (vía abdominal)',        '76856', 7
    UNION ALL SELECT 'US-TV',      'Ecografía transvaginal',                   'Ecografía transvaginal',                   '76830', 8
    UNION ALL SELECT 'US-OBSTETRIC','Ecografía obstétrica',                    'Ecografía obstétrica',                     '76805', 9
    UNION ALL SELECT 'US-JOINT',   'Ecografía articular',                      'Ecografía articular',                      '76881', 10
    UNION ALL SELECT 'US-DOPPLER-LL','Eco doppler de miembros inferiores',     'Eco doppler de miembros inferiores',       '93979', 11
    UNION ALL SELECT 'US-DOPPLER-UL','Eco doppler de miembros superiores',     'Eco doppler de miembros superiores',       '93979', 12
    UNION ALL SELECT 'US-DOPPLER-NECK','Eco doppler de vasos del cuello',      'Eco doppler de vasos del cuello',          '93880', 13
) s
INNER JOIN procedure_type m
    ON m.name = 'US' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Level 3: Computed Tomography (CT) studies (parent = modality CT)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'CT-BRAIN'        AS code, 'TAC de cráneo/cerebro'               AS name, 'TAC de cráneo/cerebro'               AS description, '70450' AS cpt, 1  AS seq
    UNION ALL SELECT 'CT-SINUSES',  'TAC de senos paranasales',               'TAC de senos paranasales',               '70486', 2
    UNION ALL SELECT 'CT-NECK',     'TAC de cuello',                          'TAC de cuello',                          '70490', 3
    UNION ALL SELECT 'CT-CHEST-SC', 'TAC de tórax sin contraste',             'TAC de tórax sin contraste',             '71250', 4
    UNION ALL SELECT 'CT-CHEST-C',  'TAC de tórax con contraste',             'TAC de tórax con contraste',             '71260', 5
    UNION ALL SELECT 'CT-ABD-SC',   'TAC de abdomen sin contraste',           'TAC de abdomen sin contraste',           '74150', 6
    UNION ALL SELECT 'CT-ABD-C',    'TAC de abdomen con contraste',           'TAC de abdomen con contraste',           '74160', 7
    UNION ALL SELECT 'CT-PELVIS-C', 'TAC de pelvis con contraste',            'TAC de pelvis con contraste',            '72192', 8
    UNION ALL SELECT 'CT-ABD-PELVIS-C','TAC de abdomen y pelvis c/contraste', 'TAC de abdomen y pelvis c/contraste',    '74177', 9
    UNION ALL SELECT 'CT-CERV-SPINE','TAC de columna cervical',               'TAC de columna cervical',                '72125', 10
    UNION ALL SELECT 'CT-LUM-SPINE', 'TAC de columna lumbar',                 'TAC de columna lumbar',                  '72131', 11
    UNION ALL SELECT 'CT-CHEST-LD',  'TAC de tórax (baja dosis/screening)',   'TAC de tórax (baja dosis/screening)',    '71271', 12
) s
INNER JOIN procedure_type m
    ON m.name = 'CT' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Level 3: Magnetic Resonance Imaging (MRI) studies (parent = modality MRI)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'MRI-BRAIN'    AS code, 'RMN de cráneo/cerebro'       AS name, 'RMN de cráneo/cerebro'       AS description, '70551' AS cpt, 1  AS seq
    UNION ALL SELECT 'MRI-NECK',   'RMN de cuello',                 'RMN de cuello',                 '70543', 2
    UNION ALL SELECT 'MRI-CERV-SPINE','RMN de columna cervical',    'RMN de columna cervical',       '72141', 3
    UNION ALL SELECT 'MRI-DORSAL', 'RMN de columna dorsal',         'RMN de columna dorsal',         '72146', 4
    UNION ALL SELECT 'MRI-LUM-SPINE','RMN de columna lumbar',       'RMN de columna lumbar',         '72148', 5
    UNION ALL SELECT 'MRI-ABD-SUP','RMN de abdomen superior',       'RMN de abdomen superior',       '74181', 6
    UNION ALL SELECT 'MRI-PELVIS', 'RMN de pelvis',                 'RMN de pelvis',                 '72196', 7
    UNION ALL SELECT 'MRI-HIP',    'RMN de cadera (unilateral)',    'RMN de cadera (unilateral)',    '72195', 8
    UNION ALL SELECT 'MRI-SHOULDER','RMN de hombro (unilateral)',   'RMN de hombro (unilateral)',    '73221', 9
    UNION ALL SELECT 'MRI-ELBOW',  'RMN de codo',                   'RMN de codo',                   '73221', 10
    UNION ALL SELECT 'MRI-WRIST',  'RMN de muñeca',                 'RMN de muñeca',                 '73221', 11
    UNION ALL SELECT 'MRI-KNEE',   'RMN de rodilla (mono)',         'RMN de rodilla (mono)',         '73721', 12
    UNION ALL SELECT 'MRI-ANKLE',  'RMN de tobillo',                'RMN de tobillo',                '73721', 13
    UNION ALL SELECT 'MRI-BREAST', 'RMN de mamas (bilateral)',      'RMN de mamas (bilateral)',      '77049', 14
) s
INNER JOIN procedure_type m
    ON m.name = 'MRI' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Level 3: Mammography (MG) studies (parent = modality MG)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'MAMMO-BIL-SCREEN'  AS code, 'Mamografía bilateral (screening)'  AS name, 'Mamografía bilateral (screening)'  AS description, '77067' AS cpt, 1 AS seq
    UNION ALL SELECT 'MAMMO-BIL-DX', 'Mamografía bilateral (diagnóstica)',    'Mamografía bilateral (diagnóstica)',    '77066', 2
    UNION ALL SELECT 'MAMMO-UNI',    'Mamografía unilateral',                 'Mamografía unilateral',                 '77065', 3
    UNION ALL SELECT 'MAMMO-TOMO',   'Tomosíntesis mamaria (bilateral)',      'Tomosíntesis mamaria (bilateral)',      '77063', 4
) s
INNER JOIN procedure_type m
    ON m.name = 'MG' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Verification 1: full tree created (root, modalities and studies)
-- ----------------------------------------------------------------------------
SELECT pt2.modalidad,
       pt.name AS estudio,
       pt.procedure_code,
       pt.lab_id,
       pt.procedure_type,
       pt.procedure_type_name,
       pt.standard_code
FROM procedure_type pt
LEFT JOIN (
    SELECT r.procedure_type_id, r.name AS modalidad
    FROM procedure_type r
    WHERE r.procedure_type = 'grp'
      AND r.parent = (SELECT procedure_type_id FROM procedure_type
                      WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
) pt2 ON pt2.procedure_type_id = pt.parent
WHERE pt.parent IN (
    SELECT r.procedure_type_id
    FROM procedure_type r
    WHERE r.procedure_type = 'grp'
      AND r.parent = (SELECT procedure_type_id FROM procedure_type
                      WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
)
   OR pt.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
ORDER BY pt2.modalidad, pt.seq;

-- ----------------------------------------------------------------------------
-- Verification 2: totals per modality
-- ----------------------------------------------------------------------------
SELECT pt.name AS modalidad,
       COUNT(x.procedure_type_id) AS estudios
FROM procedure_type pt
LEFT JOIN procedure_type x ON x.parent = pt.procedure_type_id
WHERE pt.procedure_type = 'grp'
  AND pt.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
GROUP BY pt.procedure_type_id, pt.name
ORDER BY pt.seq;
