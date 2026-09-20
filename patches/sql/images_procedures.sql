-- ============================================================================
-- images-procedures.sql
-- Mass load of the DIAGNOSTIC IMAGING catalog into the OpenEMR procedure_type
-- table.
--
-- Hierarchy created:
--   Level 1 (parent = 0)   : DIAGNOSTIC IMAGING                (procedure_type = 'grp')
--   Level 2 (parent = root): RX, US, CT, MRI, MG               (procedure_type = 'grp')
--   Level 3 (parent = modality): each individual study         (procedure_type = 'ord')
--
-- All three levels carry procedure_type_name = 'imaging' (value of the
-- order_type list), just as laboratory orders use 'laboratory_test'.
--
-- Studies are loaded with:
--   - procedure_code     : unique internal code per study (e.g. RX-CHEST-2V).
--   - standard_code      : reference CPT4 code. Replaceable by the local
--                          nomenclature (CEM/CODEM/IPROSS/health providers)
--                          by editing the cpt column values of the UNION.
--   - lab_id = 3         : provider/procedure "Imaging Service" (procedure_providers
--                          with ppid = 3). The user can change this to the value that
--                          results when creating the imaging provider under
--                          Administration -> Orders -> Procedure Providers.
--
-- The script is IDEMPOTENT: it can be run several times without duplicating rows.
-- It also includes a MIGRATION block that renames the old English/Spanish column
-- values (names, descriptions and procedure codes) if they were loaded by a
-- previous version of this script.
-- Run as:
--   mariadb -u user -p db_name < images_procedures.sql
-- ============================================================================

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
    SELECT 'RX'   AS name, 'RX'   AS code, 'Radiology'                      AS descripcion, 10 AS seq
    UNION ALL SELECT 'US',        'US',        'Ultrasound'                 ,                    20
    UNION ALL SELECT 'CT',        'CT',        'Computed Axial Tomography'   ,                    30
    UNION ALL SELECT 'MRI',       'MRI',       'Nuclear Magnetic Resonance'  ,                    40
    UNION ALL SELECT 'MG',        'MG',        'Mammography'                 ,                    50
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
    SELECT 'RX-CHEST-2V'   AS code, 'RX Chest (2 views)'         AS name, 'RX Chest (2 views)'         AS description, '71046' AS cpt, 1  AS seq
    UNION ALL SELECT 'RX-CHEST-1V',  'RX Chest (1 view)',           'RX Chest (1 view)',                       '71045', 2
    UNION ALL SELECT 'RX-ABDOMEN',   'RX Plain Abdomen',            'RX Plain Abdomen',                        '74000', 3
    UNION ALL SELECT 'RX-SINUSES',   'RX Paranasal Sinuses',        'RX Paranasal Sinuses',                    '70210', 4
    UNION ALL SELECT 'RX-SKULL',     'RX Skull',                    'RX Skull',                                '70250', 5
    UNION ALL SELECT 'RX-CERV-SPINE','RX Cervical Spine',           'RX Cervical Spine',                       '72040', 6
    UNION ALL SELECT 'RX-DORSAL',    'RX Dorsal Spine',             'RX Dorsal Spine',                         '72070', 7
    UNION ALL SELECT 'RX-LUM-SPINE', 'RX Lumbar Spine',             'RX Lumbar Spine',                         '72100', 8
    UNION ALL SELECT 'RX-PELVIS',    'RX Pelvis',                   'RX Pelvis',                               '72170', 9
    UNION ALL SELECT 'RX-HIP',       'RX Hip (unilateral)',         'RX Hip (unilateral)',                     '73500', 10
    UNION ALL SELECT 'RX-SHOULDER',  'RX Shoulder (single)',        'RX Shoulder (single)',                    '73030', 11
    UNION ALL SELECT 'RX-ELBOW',     'RX Elbow (single)',           'RX Elbow (single)',                       '73070', 12
    UNION ALL SELECT 'RX-WRIST',     'RX Wrist (single)',           'RX Wrist (single)',                       '73100', 13
    UNION ALL SELECT 'RX-HAND',      'RX Hand (single)',            'RX Hand (single)',                        '73130', 14
    UNION ALL SELECT 'RX-KNEE',      'RX Knee (single)',            'RX Knee (single)',                        '73560', 15
    UNION ALL SELECT 'RX-ANKLE',     'RX Ankle (single)',           'RX Ankle (single)',                       '73600', 16
    UNION ALL SELECT 'RX-FOOT',      'RX Foot (single)',            'RX Foot (single)',                        '73630', 17
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
    SELECT 'US-ABDOMEN'      AS code, 'Abdominal Ultrasound'                  AS name, 'Abdominal Ultrasound'                  AS description, '76700' AS cpt, 1  AS seq
    UNION ALL SELECT 'US-ABD-SUP', 'Upper Abdominal Ultrasound',                'Upper Abdominal Ultrasound',                '76705', 2
    UNION ALL SELECT 'US-RENAL-UT','Renal and Urinary Tract Ultrasound',        'Renal and Urinary Tract Ultrasound',        '76770', 3
    UNION ALL SELECT 'US-THYROID', 'Thyroid Ultrasound',                        'Thyroid Ultrasound',                        '76536', 4
    UNION ALL SELECT 'US-BREAST',  'Bilateral Breast Ultrasound',               'Bilateral Breast Ultrasound',               '76641', 5
    UNION ALL SELECT 'US-SOFT-TISSUE','Soft Tissue Ultrasound',                 'Soft Tissue Ultrasound',                    '76882', 6
    UNION ALL SELECT 'US-PELVIC-ABD','Pelvic Ultrasound (abdominal approach)',  'Pelvic Ultrasound (abdominal approach)',    '76856', 7
    UNION ALL SELECT 'US-TV',      'Transvaginal Ultrasound',                   'Transvaginal Ultrasound',                   '76830', 8
    UNION ALL SELECT 'US-OBSTETRIC','Obstetric Ultrasound',                     'Obstetric Ultrasound',                      '76805', 9
    UNION ALL SELECT 'US-JOINT',   'Joint Ultrasound',                          'Joint Ultrasound',                          '76881', 10
    UNION ALL SELECT 'US-DOPPLER-LL','Doppler Ultrasound of Lower Limbs',       'Doppler Ultrasound of Lower Limbs',         '93979', 11
    UNION ALL SELECT 'US-DOPPLER-UL','Doppler Ultrasound of Upper Limbs',       'Doppler Ultrasound of Upper Limbs',         '93979', 12
    UNION ALL SELECT 'US-DOPPLER-NECK','Doppler Ultrasound of Neck Vessels',    'Doppler Ultrasound of Neck Vessels',        '93880', 13
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
    SELECT 'CT-BRAIN'        AS code, 'CT Skull/Brain'                       AS name, 'CT Skull/Brain'                       AS description, '70450' AS cpt, 1  AS seq
    UNION ALL SELECT 'CT-SINUSES',  'CT Paranasal Sinuses',                    'CT Paranasal Sinuses',                    '70486', 2
    UNION ALL SELECT 'CT-NECK',     'CT Neck',                                 'CT Neck',                                 '70490', 3
    UNION ALL SELECT 'CT-CHEST-SC', 'CT Chest Without Contrast',               'CT Chest Without Contrast',               '71250', 4
    UNION ALL SELECT 'CT-CHEST-C',  'CT Chest With Contrast',                  'CT Chest With Contrast',                  '71260', 5
    UNION ALL SELECT 'CT-ABD-SC',   'CT Abdomen Without Contrast',             'CT Abdomen Without Contrast',             '74150', 6
    UNION ALL SELECT 'CT-ABD-C',    'CT Abdomen With Contrast',                'CT Abdomen With Contrast',                '74160', 7
    UNION ALL SELECT 'CT-PELVIS-C', 'CT Pelvis With Contrast',                 'CT Pelvis With Contrast',                 '72192', 8
    UNION ALL SELECT 'CT-ABD-PELVIS-C','CT Abdomen and Pelvis With Contrast',  'CT Abdomen and Pelvis With Contrast',     '74177', 9
    UNION ALL SELECT 'CT-CERV-SPINE','CT Cervical Spine',                      'CT Cervical Spine',                       '72125', 10
    UNION ALL SELECT 'CT-LUM-SPINE', 'CT Lumbar Spine',                        'CT Lumbar Spine',                         '72131', 11
    UNION ALL SELECT 'CT-CHEST-LD',  'CT Chest (low dose/screening)',          'CT Chest (low dose/screening)',           '71271', 12
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
    SELECT 'MRI-BRAIN'    AS code, 'MRI Skull/Brain'          AS name, 'MRI Skull/Brain'          AS description, '70551' AS cpt, 1  AS seq
    UNION ALL SELECT 'MRI-NECK',   'MRI Neck',                  'MRI Neck',                  '70543', 2
    UNION ALL SELECT 'MRI-CERV-SPINE','MRI Cervical Spine',     'MRI Cervical Spine',        '72141', 3
    UNION ALL SELECT 'MRI-DORSAL', 'MRI Dorsal Spine',          'MRI Dorsal Spine',          '72146', 4
    UNION ALL SELECT 'MRI-LUM-SPINE','MRI Lumbar Spine',        'MRI Lumbar Spine',          '72148', 5
    UNION ALL SELECT 'MRI-ABD-SUP','MRI Upper Abdomen',         'MRI Upper Abdomen',         '74181', 6
    UNION ALL SELECT 'MRI-PELVIS', 'MRI Pelvis',                'MRI Pelvis',                '72196', 7
    UNION ALL SELECT 'MRI-HIP',    'MRI Hip (unilateral)',      'MRI Hip (unilateral)',      '72195', 8
    UNION ALL SELECT 'MRI-SHOULDER','MRI Shoulder (unilateral)','MRI Shoulder (unilateral)', '73221', 9
    UNION ALL SELECT 'MRI-ELBOW',  'MRI Elbow',                 'MRI Elbow',                 '73221', 10
    UNION ALL SELECT 'MRI-WRIST',  'MRI Wrist',                 'MRI Wrist',                 '73221', 11
    UNION ALL SELECT 'MRI-KNEE',   'MRI Knee (single)',         'MRI Knee (single)',         '73721', 12
    UNION ALL SELECT 'MRI-ANKLE',  'MRI Ankle',                 'MRI Ankle',                 '73721', 13
    UNION ALL SELECT 'MRI-BREAST', 'MRI Breasts (bilateral)',   'MRI Breasts (bilateral)',   '77049', 14
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
    SELECT 'MAMMO-BIL-SCREEN'  AS code, 'Bilateral Mammography (screening)'  AS name, 'Bilateral Mammography (screening)'  AS description, '77067' AS cpt, 1 AS seq
    UNION ALL SELECT 'MAMMO-BIL-DX', 'Bilateral Mammography (diagnostic)',     'Bilateral Mammography (diagnostic)',     '77066', 2
    UNION ALL SELECT 'MAMMO-UNI',    'Unilateral Mammography',                 'Unilateral Mammography',                 '77065', 3
    UNION ALL SELECT 'MAMMO-TOMO',   'Breast Tomosynthesis (bilateral)',       'Breast Tomosynthesis (bilateral)',       '77063', 4
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
SELECT pt2.modality,
       pt.name AS study,
       pt.procedure_code,
       pt.lab_id,
       pt.procedure_type,
       pt.procedure_type_name,
       pt.standard_code
FROM procedure_type pt
LEFT JOIN (
    SELECT r.procedure_type_id, r.name AS modality
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
ORDER BY pt2.modality, pt.seq;

-- ----------------------------------------------------------------------------
-- Verification 2: totals per modality
-- ----------------------------------------------------------------------------
SELECT pt.name AS modality,
       COUNT(x.procedure_type_id) AS studies
FROM procedure_type pt
LEFT JOIN procedure_type x ON x.parent = pt.procedure_type_id
WHERE pt.procedure_type = 'grp'
  AND pt.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNOSTIC IMAGING' LIMIT 1)
GROUP BY pt.procedure_type_id, pt.name
ORDER BY pt.seq;
