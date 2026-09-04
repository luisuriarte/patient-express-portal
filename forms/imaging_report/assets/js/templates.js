/**
 * templates.js — Default normal report templates
 * for the OpenEMR Imaging Diagnostic form.
 *
 * Loaded dynamically when clicking the template buttons in new.php
 */

const IMAGING_TEMPLATES = {

    RX: {
        metodologia: "Standard projections were obtained according to the requested region using a direct digital radiography (DDR) system. Dose optimized according to the ALARA protocol.",
        interpretacion: `FINDINGS:
- Normal bone density and architecture.
- Joint spaces of normal width.
- Soft tissues without signs of alteration.
- No evidence of lytic lesions, pathological sclerosis or fractures.
- Preserved skeletal alignment.
- No abnormal calcifications in the plane of the image.`,
        conclusion: "Radiological study within normal limits for the patient's age and sex. No significant pathological findings are observed.",
        observaciones: "Clinical correlation is suggested. If symptoms persist, consider additional workup studies."
    },

    TC: {
        metodologia: "Multi-slice computed tomography (MSCT) with helical acquisition. Axial slices 1-2 mm thick with multiplanar reformats (MPR) in coronal and sagittal planes. Technique without/with intravenous iodinated contrast administration according to clinical indication.",
        interpretacion: `FINDINGS:
- Normal bone density and morphology.
- Adjacent soft tissues without significant alterations.
- No evidence of collections, hematomas or space-occupying lesions.
- Well-defined fat planes without infiltration.
- Vessels of normal caliber and density (in contrast-enhanced studies).
- Regional lymph nodes of normal size and morphology.`,
        conclusion: "Computed tomography without significant pathological findings. Evaluated structures within normal limits.",
        observaciones: "Clinical correlation recommended. No contraindications for conventional follow-up."
    },

    RMN: {
        metodologia: "Magnetic resonance imaging with a 1.5 Tesla (or 3T) unit. Sequences obtained: axial T1 SE, axial/coronal/sagittal T2 TSE, coronal STIR, DWI with ADC maps (when indicated). Without/With intravenous gadolinium administration (0.1 mmol/kg) according to protocol.",
        interpretacion: `FINDINGS:
- Homogeneous signal and intensity of the structures, within normal parameters on all evaluated sequences.
- No evidence of lesions with restricted diffusion.
- Preserved tissue interfaces.
- No mass effect or displacement of structures.
- Preserved subchondral bone signal.
- No collections or areas of pathological post-contrast enhancement (if administered).`,
        conclusion: "Magnetic resonance imaging within normal limits. No significant alterations on the evaluated sequences.",
        observaciones: "Correlation with clinical findings and prior studies is recommended. Follow-up at the treating physician's discretion."
    },

    US: {
        metodologia: "Ultrasound performed with a multifrequency linear/convex transducer (7.5-12 MHz / 3.5-5 MHz depending on the region). Real-time technique with color and spectral Doppler when indicated.",
        interpretacion: `FINDINGS:
- Explored organs/structures of normal size, echogenicity and morphology.
- No evidence of cystic, solid or mixed lesions.
- Preserved vascularization on color Doppler.
- No dilatation of ductal structures.
- No free fluid in the cavity.
- Regional lymph nodes without morphological alterations.`,
        conclusion: "Ultrasound study without pathological findings. Explored structures within normal limits for age and sex.",
        observaciones: "Clinical-analytical correlation is suggested. Follow-up at the treating physician's discretion."
    },

    MG: {
        metodologia: "Bilateral digital mammography with craniocaudal (CC) and mediolateral oblique (MLO) projections bilaterally. Tomosynthesis added according to institutional protocol.",
        interpretacion: `FINDINGS:
ACR Breast composition: Type A - predominantly fatty breasts (or as applicable).

- No density asymmetry between both breasts.
- No suspicious microcalcifications (no clustering, morphology or pathological distribution).
- No distortion of the glandular architecture.
- No nodules, masses or asymmetric densities.
- Normal-appearing skin and nipple.
- No suspicious axillary lymphadenopathy.`,
        conclusion: "BI-RADS 1: Negative. Mammographic study without pathological findings. Routine annual follow-up recommended.",
        observaciones: "Routine mammographic follow-up according to screening protocol. Correlation with clinical examination."
    },

    DEXA: {
        metodologia: "Bone densitometry by dual-energy X-ray absorptiometry (DEXA). Regions evaluated: lumbar spine (L1-L4) and proximal hip (femoral neck and total). Equipment calibrated according to ISCD standards.",
        interpretacion: `RESULTS:

LUMBAR SPINE (L1-L4):
- Bone mineral density (BMD): ___ g/cm²
- T-Score: ___  /  Z-Score: ___

FEMORAL NECK:
- Bone mineral density (BMD): ___ g/cm²
- T-Score: ___  /  Z-Score: ___

TOTAL HIP:
- Bone mineral density (BMD): ___ g/cm²
- T-Score: ___  /  Z-Score: ___

Values within the expected range for the age group. No evidence of osteopenia or osteoporosis.`,
        conclusion: "Bone mineral density within normal parameters according to WHO criteria. T-Score ≥ -1.0 SD at all evaluated regions.",
        observaciones: "Healthy habits recommended: diet rich in calcium and vitamin D, regular physical activity. Follow-up in 2 years according to clinical indication."
    }
};
