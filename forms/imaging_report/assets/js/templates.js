/**
 * templates.js — Default normal report templates
 * for the OpenEMR Imaging Diagnostic form.
 *
 * Loaded dynamically when clicking the template buttons in new.php
 */

const IMAGING_TEMPLATES = {

    RX: {
        metodologia: "Se realizaron proyecciones estándar según la región solicitada con equipo de radiografía digital directa (DDR). Dosis optimizada según protocolo ALARA.",
        interpretacion: `HALLAZGOS:
- Trama ósea de densidad y arquitectura conservadas.
- Espacios articulares de amplitud normal.
- Partes blandas sin signos de alteración.
- Sin evidencia de lesiones líticas, esclerosis patológica ni fracturas.
- Alineación esquelética conservada.
- Sin calcificaciones anómalas en el plano de la imagen.`,
        conclusion: "Estudio radiológico dentro de límites normales para la edad y sexo del paciente. No se observan hallazgos patológicos significativos.",
        observaciones: "Se sugiere correlación clínica. Ante persistencia de síntomas, considerar estudios complementarios."
    },

    TC: {
        metodologia: "Tomografía computada multicorte (TCMC) con adquisición helicoidal. Cortes axiales de 1-2mm de grosor con reformateos multiplanares (MPR) en planos coronal y sagital. Técnica sin/con administración de contraste yodado endovenoso según indicación clínica.",
        interpretacion: `HALLAZGOS:
- Estructuras óseas de densidad y morfología normales.
- Partes blandas adyacentes sin alteraciones significativas.
- Sin evidencia de colecciones, hematomas ni lesiones ocupantes de espacio.
- Espacios grasos bien definidos, sin infiltración.
- Vasculatura de calibre y densidad normales (en estudios contrastados).
- Ganglios linfáticos regionales de tamaño y morfología dentro de parámetros normales.`,
        conclusion: "Tomografía computada sin hallazgos patológicos significativos. Estructuras estudiadas dentro de límites normales.",
        observaciones: "Correlación clínica recomendada. Sin contraindicaciones para seguimiento convencional."
    },

    RMN: {
        metodologia: "Resonancia magnética con unidad de 1.5 Tesla (o 3T). Secuencias obtenidas: T1 SE axial, T2 TSE axial/coronal/sagital, STIR coronal, DWI con mapas ADC (cuando indicado). Sin/Con administración de gadolinio endovenoso (0.1 mmol/kg) según protocolo.",
        interpretacion: `HALLAZGOS:
- Estructuras de señal e intensidad homogéneas, dentro de parámetros normales en todas las secuencias evaluadas.
- Sin evidencia de lesiones con restricción a la difusión.
- Interfaces tisulares conservadas.
- Sin efecto de masa ni desplazamiento de estructuras.
- Señal del hueso subcondral conservada.
- Sin colecciones ni áreas de realce patológico post-contraste (si se administró).`,
        conclusion: "Resonancia magnética dentro de límites normales. Sin alteraciones significativas en las secuencias evaluadas.",
        observaciones: "Se recomienda correlación con clínica y estudios previos. Control evolutivo a criterio del médico tratante."
    },

    US: {
        metodologia: "Ecografía realizada con transductor multifrecuencia lineal/convex (7.5–12MHz / 3.5–5MHz según región). Técnica en tiempo real con Doppler color y espectral cuando indicado.",
        interpretacion: `HALLAZGOS:
- Órganos/estructuras exploradas de tamaño, ecogenicidad y morfología normales.
- Sin evidencia de imágenes quísticas, sólidas ni mixtas.
- Vascularización conservada al Doppler color.
- Sin dilatación de estructuras ductuales.
- Sin líquido libre en cavidad.
- Ganglios regionales sin alteraciones morfológicas.`,
        conclusion: "Estudio ecográfico sin hallazgos patológicos. Estructuras exploradas dentro de parámetros normales para la edad y sexo.",
        observaciones: "Se sugiere correlación clínico-analítica. Seguimiento a criterio del médico tratante."
    },

    MG: {
        metodologia: "Mamografía digital bilateral con proyecciones craneocaudal (CC) y oblicua mediolateral (OML) bilateral. Tomosíntesis añadida según protocolo institucional.",
        interpretacion: `HALLAZGOS:
ACR Composición mamaria: Tipo A — Mamas predominantemente grasas (o según corresponda).

- Sin asimetría de densidad entre ambas mamas.
- Sin microcalcificaciones sospechosas (sin agrupamiento, morfología ni distribución patológica).
- Sin distorsión de la arquitectura glandular.
- Sin nódulos, masas ni densidades asimétricas.
- Piel y pezón de aspecto normal.
- Sin adenopatías axilares sospechosas.`,
        conclusion: "BI-RADS 1: Negativo. Estudio mamográfico sin hallazgos patológicos. Se recomienda control anual de rutina.",
        observaciones: "Seguimiento mamográfico de rutina según protocolo de cribado. Correlación con exploración clínica."
    },

    DEXA: {
        metodologia: "Densitometría ósea por absorciometría de rayos X de doble energía (DEXA). Regiones evaluadas: columna lumbar (L1-L4) y cadera proximal (cuello femoral y total). Equipo calibrado según estándares ISCD.",
        interpretacion: `RESULTADOS:

COLUMNA LUMBAR (L1–L4):
- Densidad mineral ósea (DMO): ___ g/cm²
- T-Score: ___  /  Z-Score: ___

CUELLO FEMORAL:
- Densidad mineral ósea (DMO): ___ g/cm²
- T-Score: ___  /  Z-Score: ___

TOTAL CADERA:
- Densidad mineral ósea (DMO): ___ g/cm²
- T-Score: ___  /  Z-Score: ___

Valores dentro de rango esperado para el grupo etario. Sin evidencia de osteopenia ni osteoporosis.`,
        conclusion: "Densidad mineral ósea dentro de parámetros normales según criterios OMS. T-Score ≥ -1.0 DS en todas las regiones evaluadas.",
        observaciones: "Se recomienda mantener hábitos saludables: dieta rica en calcio y vitamina D, actividad física regular. Control en 2 años según indicación clínica."
    }
};
