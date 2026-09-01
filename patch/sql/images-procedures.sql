-- ============================================================================
-- images-procedures.sql
-- Carga masiva del catálogo de DIAGNÓSTICO POR IMÁGENES en la tabla
-- procedure_type de OpenEMR.
--
-- Jerarquía creada:
--   Nivel 1 (padre = 0)   : DIAGNÓSTICO POR IMÁGENES        (procedure_type = 'grp')
--   Nivel 2 (padre = raíz): RX, ECO, TAC, RMN, MAMO          (procedure_type = 'grp')
--   Nivel 3 (padre = modalidad): cada estudio particular     (procedure_type = 'ord')
--
-- Los tres niveles llevan procedure_type_name = 'imaging' (valor de la lista
-- order_type), igual que los órdenes de laboratorio usan 'laboratory_test'.
--
-- Los estudios se cargan con:
--   - procedure_code     : código interno único por estudio (ej. RX-TORAX-2V).
--   - standard_code      : código CPT4 de referencia. Reemplazable por el
--                          nomenclador local (CEM/CODEM/IPROSS/obras sociales)
--                          editando los valores de la columna cpt del UNION.
--   - lab_id = 3         : proveedor/procedimiento "Imagen Service" (procedure_providers
--                          con ppid = 3). El usuario puede cambiar el valor al que
--                          resulte al crear el proveedor de imágenes en
--                          Administración -> Órdenes -> Proveedores de procedimientos.
--
-- El script es IDEMPOTENTE: puede ejecutarse varias veces sin duplicar filas.
-- Ejecutar como:
--   mariadb -u usuario -p nombre_bd < images-procedures.sql
-- ============================================================================

-- ----------------------------------------------------------------------------
-- Nivel 1: Raíz "DIAGNÓSTICO POR IMÁGENES"
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT 0, 'DIAGNÓSTICO POR IMÁGENES', 3, 'IMG', 'grp', 'Diagnóstico por imágenes: RX, ECO, TAC, RMN, MAMO', '', 10, 1, 'imaging'
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type
    WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES'
);

-- ----------------------------------------------------------------------------
-- Nivel 2: Modalidades (padre = raíz)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT r.procedure_type_id, m.name, 3, m.code, 'grp', m.descripcion, '', m.seq, 1, 'imaging'
FROM (
    SELECT 'RX'   AS name, 'RX'   AS code, 'Radiología'                          AS descripcion, 10 AS seq
    UNION ALL SELECT 'ECO',        'ECO',        'Ecografía'                     ,                    20
    UNION ALL SELECT 'TAC',        'TAC',        'Tomografía Axial Computada'     ,                    30
    UNION ALL SELECT 'RMN',        'RMN',        'Resonancia Magnética Nuclear'   ,                    40
    UNION ALL SELECT 'MAMO',       'MAMO',       'Mamografía'                     ,                    50
) m
INNER JOIN procedure_type r
    ON r.parent = 0 AND r.procedure_type = 'grp' AND r.name = 'DIAGNÓSTICO POR IMÁGENES'
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = r.procedure_type_id AND x.name = m.name
);

-- ----------------------------------------------------------------------------
-- Nivel 3: Estudios de RX (padre = modalidad RX)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'RX-TORAX-2V'   AS code, 'RX de tórax (2 proyecciones)'  AS name, 'RX de tórax (2 proyecciones)'  AS description, '71046' AS cpt, 1  AS seq
    UNION ALL SELECT 'RX-TORAX-1V',  'RX de tórax (1 proyección)',     'RX de tórax (1 proyección)',       '71045', 2
    UNION ALL SELECT 'RX-ABDOMEN',   'RX de abdomen simple',           'RX de abdomen simple',             '74000', 3
    UNION ALL SELECT 'RX-SENOS-PAR', 'RX de senos paranasales',        'RX de senos paranasales',          '70210', 4
    UNION ALL SELECT 'RX-CRANEO',    'RX de cráneo',                   'RX de cráneo',                     '70250', 5
    UNION ALL SELECT 'RX-CERVICAL',  'RX de columna cervical',         'RX de columna cervical',           '72040', 6
    UNION ALL SELECT 'RX-DORSAL',    'RX de columna dorsal',           'RX de columna dorsal',             '72070', 7
    UNION ALL SELECT 'RX-LUMBAR',    'RX de columna lumbar',           'RX de columna lumbar',             '72100', 8
    UNION ALL SELECT 'RX-PELVIS',    'RX de pelvis',                   'RX de pelvis',                     '72170', 9
    UNION ALL SELECT 'RX-CADERA',    'RX de cadera (unilateral)',      'RX de cadera (unilateral)',        '73500', 10
    UNION ALL SELECT 'RX-HOMBRO',    'RX de hombro (mono)',            'RX de hombro (mono)',              '73030', 11
    UNION ALL SELECT 'RX-CODO',      'RX de codo (mono)',              'RX de codo (mono)',                '73070', 12
    UNION ALL SELECT 'RX-MUÑECA',    'RX de muñeca (mono)',            'RX de muñeca (mono)',              '73100', 13
    UNION ALL SELECT 'RX-MANO',      'RX de mano (mono)',              'RX de mano (mono)',                '73130', 14
    UNION ALL SELECT 'RX-RODILLA',   'RX de rodilla (mono)',           'RX de rodilla (mono)',             '73560', 15
    UNION ALL SELECT 'RX-TOBILLO',   'RX de tobillo (mono)',           'RX de tobillo (mono)',             '73600', 16
    UNION ALL SELECT 'RX-PIE',       'RX de pie (mono)',               'RX de pie (mono)',                 '73630', 17
) s
INNER JOIN procedure_type m
    ON m.name = 'RX' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Nivel 3: Estudios de ECO (padre = modalidad ECO)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'ECO-ABDOMINAL'   AS code, 'Ecografía abdominal'              AS name, 'Ecografía abdominal'              AS description, '76700' AS cpt, 1  AS seq
    UNION ALL SELECT 'ECO-ABDOM-SUP', 'Ecografía abdominal superior',       'Ecografía abdominal superior',       '76705', 2
    UNION ALL SELECT 'ECO-RENAL',     'Ecografía renal y vías urinarias',   'Ecografía renal y vías urinarias',   '76770', 3
    UNION ALL SELECT 'ECO-TIROIDEA',  'Ecografía de tiroides',              'Ecografía de tiroides',              '76536', 4
    UNION ALL SELECT 'ECO-MAMARIA',   'Ecografía mamaria bilateral',        'Ecografía mamaria bilateral',        '76641', 5
    UNION ALL SELECT 'ECO-PARTES-BLANDAS', 'Ecografía de partes blandas',   'Ecografía de partes blandas',        '76882', 6
    UNION ALL SELECT 'ECO-PELVICA',   'Ecografía pélvica (via abdominal)',  'Ecografía pélvica (via abdominal)',  '76856', 7
    UNION ALL SELECT 'ECO-TV',        'Ecografía transvaginal',             'Ecografía transvaginal',             '76830', 8
    UNION ALL SELECT 'ECO-OBSTETRICA','Ecografía obstétrica',               'Ecografía obstétrica',               '76805', 9
    UNION ALL SELECT 'ECO-ARTICULAR', 'Ecografía articular',                'Ecografía articular',                '76881', 10
    UNION ALL SELECT 'ECO-DOPPLER-MI','Eco doppler de miembros inferiores', 'Eco doppler de miembros inferiores', '93979', 11
    UNION ALL SELECT 'ECO-DOPPLER-MS','Eco doppler de miembros superiores', 'Eco doppler de miembros superiores', '93979', 12
    UNION ALL SELECT 'ECO-VASCULAR-CC','Eco doppler de vasos del cuello',   'Eco doppler de vasos del cuello',    '93880', 13
) s
INNER JOIN procedure_type m
    ON m.name = 'ECO' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Nivel 3: Estudios de TAC (padre = modalidad TAC)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'TAC-CEREBRO'        AS code, 'TAC de cráneo/cerebro'         AS name, 'TAC de cráneo/cerebro'         AS description, '70450' AS cpt, 1  AS seq
    UNION ALL SELECT 'TAC-SENOS-PAR',  'TAC de senos paranasales',          'TAC de senos paranasales',          '70486', 2
    UNION ALL SELECT 'TAC-CUELLO',     'TAC de cuello',                     'TAC de cuello',                     '70490', 3
    UNION ALL SELECT 'TAC-TORAX-SC',   'TAC de tórax sin contraste',        'TAC de tórax sin contraste',        '71250', 4
    UNION ALL SELECT 'TAC-TORAX-C',    'TAC de tórax con contraste',        'TAC de tórax con contraste',        '71260', 5
    UNION ALL SELECT 'TAC-ABDOMEN-SC', 'TAC de abdomen sin contraste',      'TAC de abdomen sin contraste',      '74150', 6
    UNION ALL SELECT 'TAC-ABDOMEN-C',  'TAC de abdomen con contraste',      'TAC de abdomen con contraste',      '74160', 7
    UNION ALL SELECT 'TAC-PELVIS-C',   'TAC de pelvis con contraste',       'TAC de pelvis con contraste',       '72192', 8
    UNION ALL SELECT 'TAC-ABDOM-PELVIS-C','TAC de abdomen y pelvis c/contraste','TAC de abdomen y pelvis con contraste', '74177', 9
    UNION ALL SELECT 'TAC-CERVICAL',   'TAC de columna cervical',           'TAC de columna cervical',           '72125', 10
    UNION ALL SELECT 'TAC-LUMBAR',     'TAC de columna lumbar',             'TAC de columna lumbar',             '72131', 11
    UNION ALL SELECT 'TAC-TORAX-BT',   'TAC de tórax (baja dosis/screening)','TAC de tórax (baja dosis/screening)','71271', 12
) s
INNER JOIN procedure_type m
    ON m.name = 'TAC' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Nivel 3: Estudios de RMN (padre = modalidad RMN)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'RMN-CEREBRO'    AS code, 'RMN de cráneo/cerebro'      AS name, 'RMN de cráneo/cerebro'      AS description, '70551' AS cpt, 1  AS seq
    UNION ALL SELECT 'RMN-CUELLO',   'RMN de cuello',                'RMN de cuello',                '70543', 2
    UNION ALL SELECT 'RMN-CERVICAL', 'RMN de columna cervical',      'RMN de columna cervical',      '72141', 3
    UNION ALL SELECT 'RMN-DORSAL',   'RMN de columna dorsal',        'RMN de columna dorsal',        '72146', 4
    UNION ALL SELECT 'RMN-LUMBAR',   'RMN de columna lumbar',        'RMN de columna lumbar',        '72148', 5
    UNION ALL SELECT 'RMN-ABDOMEN',  'RMN de abdomen superior',      'RMN de abdomen superior',      '74181', 6
    UNION ALL SELECT 'RMN-PELVIS',   'RMN de pelvis',                'RMN de pelvis',                '72196', 7
    UNION ALL SELECT 'RMN-CADERA',   'RMN de cadera (unilateral)',   'RMN de cadera (unilateral)',   '72195', 8
    UNION ALL SELECT 'RMN-HOMBRO',   'RMN de hombro (unilateral)',   'RMN de hombro (unilateral)',   '73221', 9
    UNION ALL SELECT 'RMN-CODO',     'RMN de codo',                  'RMN de codo',                  '73221', 10
    UNION ALL SELECT 'RMN-MUÑECA',   'RMN de muñeca',                'RMN de muñeca',                '73221', 11
    UNION ALL SELECT 'RMN-RODILLA',  'RMN de rodilla (mono)',        'RMN de rodilla (mono)',        '73721', 12
    UNION ALL SELECT 'RMN-TOBILLO',  'RMN de tobillo',               'RMN de tobillo',               '73721', 13
    UNION ALL SELECT 'RMN-MAMARIA',  'RMN de mamas (bilateral)',     'RMN de mamas (bilateral)',     '77049', 14
) s
INNER JOIN procedure_type m
    ON m.name = 'RMN' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Nivel 3: Estudios de MAMO (padre = modalidad MAMO)
-- ----------------------------------------------------------------------------
INSERT INTO procedure_type (parent, name, lab_id, procedure_code, procedure_type, description, standard_code, seq, activity, procedure_type_name)
SELECT m.procedure_type_id, s.name, 3, s.code, 'ord', s.description, CONCAT('CPT4:', s.cpt), s.seq, 1, 'imaging'
FROM (
    SELECT 'MAMO-BIL-SCREEN'  AS code, 'Mamografía bilateral (screening)'  AS name, 'Mamografía bilateral (screening)'  AS description, '77067' AS cpt, 1 AS seq
    UNION ALL SELECT 'MAMO-BIL-DX', 'Mamografía bilateral (diagnóstica)',     'Mamografía bilateral (diagnóstica)',     '77066', 2
    UNION ALL SELECT 'MAMO-UNI',    'Mamografía unilateral',                  'Mamografía unilateral',                  '77065', 3
    UNION ALL SELECT 'MAMO-TOMO',   'Tomosíntesis mamaria (bilateral)',       'Tomosíntesis mamaria (bilateral)',       '77063', 4
) s
INNER JOIN procedure_type m
    ON m.name = 'MAMO' AND m.procedure_type = 'grp'
   AND m.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES' LIMIT 1)
WHERE NOT EXISTS (
    SELECT 1 FROM procedure_type x
    WHERE x.parent = m.procedure_type_id AND x.procedure_code = s.code
);

-- ----------------------------------------------------------------------------
-- Verificación 1: árbol completo creado (raíz, modalidades y estudios)
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
                      WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES' LIMIT 1)
) pt2 ON pt2.procedure_type_id = pt.parent
WHERE pt.parent IN (
    SELECT r.procedure_type_id
    FROM procedure_type r
    WHERE r.procedure_type = 'grp'
      AND r.parent = (SELECT procedure_type_id FROM procedure_type
                      WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES' LIMIT 1)
)
   OR pt.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES' LIMIT 1)
ORDER BY pt2.modalidad, pt.seq;

-- ----------------------------------------------------------------------------
-- Verificación 2: totales por modalidad
-- ----------------------------------------------------------------------------
SELECT pt.name AS modalidad,
       COUNT(x.procedure_type_id) AS estudios
FROM procedure_type pt
LEFT JOIN procedure_type x ON x.parent = pt.procedure_type_id
WHERE pt.procedure_type = 'grp'
  AND pt.parent = (SELECT procedure_type_id FROM procedure_type
                   WHERE parent = 0 AND procedure_type = 'grp' AND name = 'DIAGNÓSTICO POR IMÁGENES' LIMIT 1)
GROUP BY pt.procedure_type_id, pt.name
ORDER BY pt.seq;