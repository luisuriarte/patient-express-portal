# Patient Express Portal — Origen Medical Center

A lightweight ("express") web portal for patients, natively integrated into an **OpenEMR** instance with an **Orthanc PACS**. It lets the authenticated patient quickly and securely consult:

- **Laboratory results**, grouped by clinical encounter.
- **Diagnostic imaging** (DICOM studies, JPG/PNG images and PDF reports).
- **Access to the full OpenEMR portal** via Single Sign-On (SSO).

It also includes an **OpenEMR clinical form** for writing diagnostic imaging reports (with institutional PDF generation and automatic linking to the DICOM study in Orthanc) and a **synchronization cron** for pushing imaging documents to the PACS.

> Main documentation is in English; a Spanish version is available in [`README_es.md`](README_es.md).

---

## Table of Contents

1. [System overview](#1-system-overview)
2. [Architecture and components](#2-architecture-and-components)
3. [Workflow (end-to-end)](#3-workflow-end-to-end)
4. [Installation and configuration](#4-installation-and-configuration)
5. [User manual — Patient](#5-user-manual--patient)
6. [Usage guide — Radiology technician / Physician](#6-usage-guide--radiology-technician--physician)
7. [Operation and maintenance (cron / PACS)](#7-operation-and-maintenance-cron--pacs)
8. [Project structure](#8-project-structure)
9. [Security and compliance](#9-security-and-compliance)
10. [Troubleshooting](#10-troubleshooting)

---

## 1. System overview

The **Express Portal** is the patient's public face for their health results. Instead of relying on phone calls or picking up paper reports, the patient logs in with their username/password and immediately sees:

| Module | What it shows |
|--------|---------------|
| **Laboratory** | Clinical test reports grouped by encounter, with values, units, reference ranges and out-of-range alerts. |
| **Imaging** | DICOM studies (OHIF and Stone WebViewer viewers), standard images (JPG/PNG), and PDF reports for the same study. |
| **OpenEMR Portal** | One-click access to the full portal (appointments, contact with the doctor, etc.) without logging in again (SSO). |

### Main features

- **Fast and simple**: few pages, designed for direct consultation from a mobile phone.
- **Secure**: password login (bcrypt hash), expiring session, `httponly`/`samesite` cookies and CSRF verification.
- **Integrated**: not a separate application; it runs inside the OpenEMR instance and uses its same database and security utilities.
- **Compliant**: follows health data confidentiality principles (Law 25.326 in Argentina, HIPAA-like best practices).

---

## 2. Architecture and components

### 2.1 Technologies

| Component | Technology |
|-----------|------------|
| Language | PHP ≥ 8.1 (uses `match`, named arguments, `str_contains`, etc.) |
| Framework | Vanilla PHP + native OpenEMR bootstrap (`globals.php`, `sqlQuery()`, `formSubmit()`, …) |
| Database | MySQL / MariaDB (the same one OpenEMR uses) |
| PDF | `dompdf/dompdf` (^2.0 / ^3.0) |
| PACS | Orthanc REST API (HTTP Basic authentication) |
| DICOM viewer | OHIF Viewer v3 + Orthanc Stone WebViewer |
| Frontend | Tailwind CSS, Lucide Icons, Google Fonts (Inter / Plus Jakarta Sans) |

### 2.2 System parts

```
patient-express-portal/
├── config.php                     # OpenEMR bootstrap + constants + autoloading
├── src/                           # Logic (PHP classes, namespace App\)
│   ├── Auth.php                   # Patient authentication and session
│   ├── Laboratory.php             # Laboratory results (by encounter)
│   ├── Imaging.php                # Imaging: documents + multi-provider PACS + reports
│   ├── PacsProvider.php           # Per-provider PACS/Orthanc configuration model
│   ├── PacsService.php            # Orthanc REST/DICOMweb client (provider-aware)
│   └── PortalSSO.php              # Single Sign-On to the full portal
├── public/                        # Web pages (browser entry point)
│   ├── index.php                  # Login screen
│   ├── dashboard.php              # Main panel with 3 tabs
│   ├── view_document.php          # Secure document/image server
│   ├── print_pdf.php              # PDF generator (laboratory and imaging)
│   ├── goto_portal.php            # SSO redirect to OpenEMR
│   └── logout.php                 # Log out
├── templates/                     # Common header and footer (navbar + modals)
├── forms/
│   └── imaging_report/            # Clinical form for imaging reports (direct PACS upload)
├── sql/
│   ├── images-procedures.sql      # Imaging order/encounter schema
│   └── lang_custom.sql            # Spanish translations for the UI
├── config/                        # Alternate config (standalone PDO)
├── patch/                         # OpenEMR core patches + SQL migrations
└── composer.json                  # Dependencies (dompdf)
```

### 2.3 External components it integrates with

| Service | Field (per provider) | Default fallback |
|----------|----------------------|------------------|
| Orthanc REST API | `remote_api` | `ORTHANC_URL` (default `http://127.0.0.1:8042`) |
| DICOM-WADO/STOW | `wado_url` | `ORTHANC_WADO_URL` (default `https://pacs.origen.ar/dicom-web`) |
| OHIF v3 viewer | `remote_host` | `OHIF_VIEWER_BASE_URL` (default `https://imagenes.origen.ar/viewer`) |
| Full OpenEMR portal | `OPENEMR_PORTAL_URL` | `https://hcd.origen.ar/portal` |

> The PACS endpoints (REST, WADO, OHIF) can be set **per provider** in the
> `procedure_providers` table (see section 4 below). The `ORTHANC_*` / `OHIF_*`
> constants act only as fallback when a provider field is empty.

---

## 3. Workflow (end-to-end)

### 3.1 Publishing a diagnostic imaging report

```
 1. The ordering physician creates an imaging "procedure order" (clinical
    form) choosing the requesting service and anatomical region; it is
    stored on procedure_order (procedure_order_type = 'imaging').
 2. The radiology technician opens the patient's encounter in OpenEMR
    and creates an "Imaging Diagnostic Report" (clinical form).
 3. In "Study Data" the technician selects the originating study order;
    the report form auto-fills the requesting service, anatomical region
    and requesting physician from it, and stores the link (procedure_order_id).
 4. The technician uploads the images/PDFs directly in the report form
    (drag & drop, multiple files: DICOM, JPG/PNG, PDF). Each file is sent
    to the PACS of the provider of the study order at submit time and is
    recorded in form_imaging_report_images with its StudyInstanceUID and
    PACS IDs.
 5. Loads/writes the rest (modality, technique, findings, conclusion) and
    saves as DRAFT (remains editable) or FINALIZES (generates the PDF).
 6. When finalizing, save.php:
      a. Generates the institutional PDF (Dompdf) and stores it in
         "Patient Documents" (documents table).
      b. Links the generated PDF to the study of the uploaded images
         (the PDF itself is NOT pushed to PACS).
 7. The patient logs into the Express Portal and sees their study + report.
```

### 3.2 Patient consultation

```
 1. The patient enters username/password in the Express Portal.
 2. In the "Diagnostic Imaging" tab they see their studies grouped,
    each with the encounter date and their PDF report button.
 3. They can open the DICOM viewer (OHIF), the Stone viewer, or
    download/view the PDF.
 4. In the "Laboratory" tab they see their tests grouped by encounter.
 5. With one click they go to the Full Portal (SSO) without logging in again.
```

### 3.3 Direct PACS upload (multi-provider)

There is **no synchronization cron**. Images and PDFs are pushed to the PACS at **submit time** from the report form (`forms/imaging_report/`) through the provider of the study order.

- Each file (DICOM, JPG/PNG/WEBP, PDF) is uploaded directly to the corresponding PACS/Orthanc via `POST /instances` (native DICOM) or `POST /tools/create-dicom`.
- Files of the same study order share a deterministic `StudyInstanceUID` (`1.2.840.113619.2.55.<orderId>.<providerId>`); native DICOM files keep their original study.
- Each upload is recorded in `form_imaging_report_images` (columns `pacs_instance_id`, `pacs_series_id`, `pacs_study_id`, `study_instance_uid`, `provider_id`, `status`).
- The **generated report PDF is NOT uploaded to PACS**; it lives only in the patient's OpenEMR documents and is linked to the study of the uploaded images (via `study_instance_uid`).

---

## 4. Installation and configuration

### 4.1 Requirements

- An **OpenEMR** instance (with `interface/globals.php` accessible).
- **PHP ≥ 8.1** with the `curl`, `mbstring` extensions and whatever else OpenEMR requires.
- **GD** PHP extension (optional, required to generate the **QR code** that points to the DICOM viewer in the report PDF. OpenEMR usually bundles it).
- **MySQL/MariaDB**.
- **Orthanc** reachable over HTTP REST.

> The report QR code uses the **`bacon/bacon-qr-code`** library, already part of OpenEMR's `vendor/` (nothing extra to install). If GD is unavailable, the QR is simply not rendered but the report is generated anyway.

### 4.2 Steps

1. **Clone / copy** the project into OpenEMR's web path.
   In production, for example: `/var/www/html/origen.ar/hcd/express_portal/`.

2. **Install dependencies** (Composer):
   ```bash
   composer install --no-dev
   ```

3. **Create the support table(s)** in the OpenEMR database:
   - If you use the **multi-provider PACS**, apply `patch/sql/pacs-multiprovider.sql` to add `remote_api` and `wado_url` to `procedure_providers` (`remote_host`, `login` and `password` already exist natively). See section 4.3 for the provider fields.
   - Apply `patch/sql/fase4-remove-pacs-sync.sql` to drop the obsolete `documents_pacs_sync` table (the old cron flow is gone; linkage now lives in `form_imaging_report_images`).
   - Load the **diagnostic imaging catalog** into `procedure_type` from `patch/sql/images-procedures.sql` (English) or `patch/sql/images-procedures_es.sql` (Spanish). Both are idempotent, share the same `procedure_code`/`standard_code`, and differ only in the studies' visible text.
   - If you use the **order + report flow**, apply `patch/sql/procedure_order-imaging-context.sql` (adds `requesting_service`/`anatomical_region` to `procedure_order` and `procedure_order_id` to `form_imaging_report`). This must run **after** applying the `interface/forms/procedure_order` code patch so the columns referenced by the form exist.

4. **Patch OpenEMR core** for the imaging order context (only if you use the order + report flow): apply the `patch/diffs/common.php.patch` diff onto `interface/forms/procedure_order/common.php` so the procedure order form shows the **Requesting Service** and **Anatomic Region** dropdowns and saves them. Then run `patch/sql/procedure_order-imaging-context.sql` (step 3).

5. **Install the Spanish translations** (so the English UI shows in Spanish for Spanish-language users):
   ```bash
   mysql -u<user> -p <openemr_db> < sql/lang_custom.sql
   ```
   This is idempotent: it does not overwrite existing translations and can be re-run safely.

6. **Institutional data**: loaded **automatically from the OpenEMR `facility` table** (the facility with `billing_location = 1` is used). Name, address, phone, email and website are taken from there, so there is **no need (nor is it recommended) to write clinic data in code**. Only the **logo** is kept as a local static resource (`public/assets/img/logo.png`).

7. **Install the clinical form** `forms/imaging_report/`: copy it into `interface/forms/` (or OpenEMR's forms folder) and install its schema (`table.sql`), which besides creating `form_imaging_report` loads the normalized lists of **requesting services** and **anatomical regions** into `list_options`.

8. **Configure the PACS providers** in OpenEMR: each `procedure_providers` row must point to its Orthanc/OHIF (see section 4.3). There is **no cron to schedule** — uploads happen at submit time.

9. **Verify access**: open `public/index.php` (e.g. `https://hcd.origen.ar/express_portal/index.php`).

### 4.3 PACS provider fields (`procedure_providers`)

Each connected PACS/Orthanc is configured **as one "procedure provider" row** in the
OpenEMR table `procedure_providers`. The study order links the patient's study to a
provider via `procedure_order.lab_id → procedure_providers.ppid`.

> `remote_host`, `login` and `password` already exist natively in OpenEMR.
> `remote_api` and `wado_url` are added by `patch/sql/pacs-multiprovider.sql`.

| Column | Meaning | Example |
|--------|---------|---------|
| `name` | Display name shown in the portal | `Centro 1` |
| `remote_host` | Base URL of the **OHIF viewer** (full path) | `https://imagenes.origen.ar/viewer` |
| `remote_api` | Base URL of the **Orthanc REST API** | `https://pacs.origen.ar` (or `http://10.0.0.5:8042`) |
| `wado_url` | Base URL of **DICOMweb WADO-RS** (used by OHIF/STONE) | `https://pacs.origen.ar/dicom-web` |
| `login` | Orthanc **HTTP Basic** username | `orthanc` |
| `password` | Orthanc **HTTP Basic** password | `changeme` |

Example SQL to set up a provider:

```sql
INSERT INTO procedure_providers
    (name, remote_host, remote_api, wado_url, login, password, active)
VALUES
    ('Centro 1',
     'https://imagenes.origen.ar/viewer',   -- remote_host (OHIF base URL)
     'https://pacs.origen.ar',              -- remote_api  (Orthanc REST base)
     'https://pacs.origen.ar/dicom-web',    -- wado_url    (WADO-RS base)
     'orthanc',                             -- login (Orthanc HTTP Basic)
     'changeme',                            -- password
     1);
```

If a provider leaves `remote_api` / `wado_url` empty, the portal falls back to the
`ORTHANC_URL` / `ORTHANC_WADO_URL` / `OHIF_VIEWER_BASE_URL` constants; `wado_url` can
also be derived automatically from `remote_api` (`https://host/dicom-web`) when unset.

---

## 5. User manual — Patient

> This manual is aimed at the patient who uses the portal from a phone or computer.

### 5.1 How to log in

1. Open the link the Medical Center gave you (the **Patient Express Portal**).
2. On the login screen you will see two fields:
   - **Portal Username / ID / Email**: you can enter your **ID (DNI)**, your **portal username** or your registered **email**.
   - **Password**: the password you were given or configured.
3. Tap the **"Access my Results"** button.

> 💡 If you do not have a username and password, contact the Medical Center to get them (or use the "Access the Full OpenEMR Portal" option if you already have access there).

### 5.2 The main panel (Dashboard)

Once inside you will see your name and a panel with **3 tabs**:

| Tab | Content |
|---------|-----------|
| **Laboratory Results** | Your clinical tests grouped by encounter. |
| **Diagnostic Imaging** | Your studies (X-rays, CT, MRI, ultrasound, etc.) and their reports. |
| **Full Portal** | Button to go to the full OpenEMR portal (appointments, messages, etc.). |

### 5.3 View your laboratory results

1. Go to the **"Laboratory Results"** tab.
2. You will see the encounters ordered from newest to oldest. Each encounter shows:
   - The **encounter number and date**.
   - The **results date** and the **requester** (physician who ordered the study).
   - How many tests it includes.
3. Tap the encounter to see **the breakdown of the studies** with each value, its unit and its reference range.
4. If a value is **out of range** it is flagged with an alert ("values to interpret").
5. You can **download/print the full PDF report** with the "Laboratory Protocol" button.

### 5.4 View your images and reports

1. Go to the **"Diagnostic Imaging"** tab.
2. You will see your studies (each card groups the images of the same study). Each card shows the **study date** and the associated **encounter**.
3. For each study you have buttons to:
   - **View DICOM Image (OHIF)**: opens the professional DICOM viewer to browse the series.
   - **View Image (Stone Viewer)**: alternate viewer.
   - **View Report (PDF)**: opens the radiologist's PDF report.
   - **Download** the report PDF.

> ⚠️ If one viewer button does not open, try the other viewer or download the report PDF.

### 5.5 Go to the Full Portal

- From any tab, tap **"OpenEMR Portal"** (in the top bar) or the link in the "Full Portal" tab.
- The full OpenEMR portal will open **without needing to type your username and password again** (Single Sign-On).
- Access lasts about 15 minutes; if it expires, start again from the button.

### 5.6 Log out

- Tap **"Log Out"** in the top bar.
- This matters if you use a shared computer or phone.

### 5.7 FAQ (patient)

**What do I do if I forget my password?**
Contact the Medical Center to have it reset.

**Can I view results from my phone?**
Yes, the portal is designed to look good on small screens.

**Why don't I see a new study?**
The report may still be a draft, or the PACS sync may not have finished. Ask the Center to confirm publication.

**Is my data private?**
Yes. Access is with your username and password, and the system complies with health data confidentiality standards (Law 25.326 / HIPAA).

---

## 6. Usage guide — Radiology technician / Physician

This section is for the staff who generate diagnostic imaging reports inside OpenEMR using the included form.

### 6.1 Create a report

1. In the patient's encounter in OpenEMR, open **"Imaging Diagnostic Report"**.
2. Fill in the **"Study Data"** section:
   - **Requesting Order**: (optional) select the originating imaging study order for the patient. Picking one **auto-fills** the requesting service, anatomical region and requesting physician from that order, and links the report to it (`procedure_order_id`).
   - **Modality** *(required)*: XR, CT, MRI, US, Mammography, DEXA or Other.
   - **Region / Anatomical Area** *(required)*: choose from the normalized list (e.g. "Lumbar Spine", "Thorax").
   - **Service / Requester**: dropdown of services (Emergency, Internal Medicine, Traumatology, etc.).
   - **Requesting Physician**: you can pick an OpenEMR physician from the list, or choose "Other physician" and type the name by hand.
   - **Reporting Physician** and **Report Date**.
3. Write the **Technique / Methodology**, **Interpretation / Findings**, **Conclusion** and **Observations** sections.
4. Or use one of the **quick templates** ("Rx Normal", "TC Normal", "RMN Normal", etc.) to load a normal-study report and then edit it if needed.
5. Choose the **PDF destination folder** in the patient chart (if you pick none, the automatic one is used based on modality).
6. Save in one of two ways:
   - **Save Draft**: saves without generating the PDF, remains editable.
   - **Save and Generate PDF**: finalizes the report, generates the institutional PDF, saves it in the patient's documents and records the study data for Orthanc sync.

> **QR code:** the finalized PDF includes, in the validation area, a **QR code**. Scanning it with a phone opens the DICOM viewer (OHIF) for the study at `imagenes.origen.ar`. It is only printed once the study already has its `StudyInstanceUID` synchronized in the PACS.

### 6.2 Edit a report

- From the report view, if it is a **draft**, you can tap it to edit it again.
- A **finalized** report is no longer edited (it is kept as-is to preserve integrity).

### 6.3 Data notes

- `form_imaging_report` keeps `study_instance_uid` and `accession_number` to maintain the link with the DICOM study, and `procedure_order_id` to link the report back to the originating imaging order.
- The status can be `draft` or `finalized`.
- The service and anatomical region lists are loaded from `list_options`; to add new options edit those lists in OpenEMR (Administration → Lists). The **procedure order form** reuses the same lists (`imaging_report_services` / `imaging_report_anatomy`).

---

## 7. Operation and maintenance (PACS)

### 7.1 Direct upload at submit time

There is **no synchronization cron**. Every imaging file (DICOM, JPG/PNG/WEBP, PDF)
is uploaded to the PACS of the study order **when the report form is submitted**
(`forms/imaging_report/`). Each upload is recorded in `form_imaging_report_images`
with its `study_instance_uid`, `pacs_instance_id`, `pacs_series_id`, `pacs_study_id`
and `provider_id`, from which the portal reconstructs the study grouped list.

### 7.2 If an upload fails

- A failed upload keeps the file in the patient's OpenEMR `documents` (it is always
  stored locally first) and marks the row `status = 'failed'` in
  `form_imaging_report_images` with the error in `error_message`.
- To retry, reopen the report form (View → Edit while a draft) and re-upload the file,
  or upload it again from the form's upload zone.
- Providers are resolved from the study order (`procedure_order.lab_id →
  procedure_providers.ppid`); if the provider is misconfigured the portal falls back
  to the first active provider and the failure is logged.

---

## 8. Project structure

Details of the most relevant files:

| Path | Purpose |
|------|-----------|
| `config.php` | OpenEMR bootstrap, integration constants, dynamic loading of institutional data from `facility`, autoloading. |
| `src/Auth.php` | Patient authentication (`patient_access_onsite` table), expiring session, logout. |
| `src/Laboratory.php` | Laboratory results grouped by encounter. |
| `src/Imaging.php` | Imaging: OpenEMR documents, reports, orders and multi-provider PACS studies; OHIF/Stone viewers. |
| `src/PacsProvider.php` | Per-provider PACS/Orthanc model (fields, viewer/API/WADO URL builders, resolution by order). |
| `src/PacsService.php` | Orthanc REST / DICOMweb client used for querying and uploading (provider-aware). |
| `src/PortalSSO.php` | Generation of `onetime_auth` tokens for SSO to the full portal. |
| `public/index.php` | Patient login screen. |
| `public/dashboard.php` | Main panel (3 tabs + search boxes). |
| `public/view_document.php` | Secure server for documents and images (permission control). |
| `public/print_pdf.php` | PDF generator (laboratory / imaging) with Dompdf. |
| `public/goto_portal.php` | SSO redirect to OpenEMR. |
| `public/logout.php` | Log out. |
| `templates/header.php` / `templates/footer.php` | Common layout + PDF/image modals. |
| `forms/imaging_report/` | Clinical form: create/edit/view + **direct multi-file PACS upload** (`upload.php`, `imaging_upload_functions.php`) and PDF report. |
| `patch/sql/pacs-multiprovider.sql` | Adds `remote_api` / `wado_url` to `procedure_providers`. |
| `patch/sql/fase4-remove-pacs-sync.sql` | Drops the obsolete `documents_pacs_sync` table (cron flow removed). |
| `patch/sql/images-procedures.sql` | Imaging catalog (English variant). |
| `patch/sql/images-procedures_es.sql` | Imaging catalog (Spanish variant). |
| `patch/sql/procedure_order-imaging-context.sql` | Order + report context: `requesting_service`/`anatomical_region` on `procedure_order`, `procedure_order_id` on `form_imaging_report`. |
| `patch/diffs/common.php.patch` | Core patch: `interface/forms/procedure_order/common.php` saves and shows the imaging requesting service / anatomic region. |
| `sql/lang_custom.sql` | Spanish translations for the UI strings. |
| `.env.example` | Environment variable template. |

---

## 9. Security and compliance

- **Authentication**: bcrypt passwords (`password_verify`), with support for legacy hashes (sha1/md5) for migration.
- **Session**: expires after 30 minutes of inactivity, `httponly` + `samesite=Lax` cookie, and the session ID is regenerated on login.
- **CSRF**: the report form validates OpenEMR's CSRF token before saving.
- **Document access**: `view_document.php` verifies the document belongs to the authenticated patient.
- **SSO**: the `onetime_auth` token is valid for 15 minutes and is single-use.
- **Confidentiality**: the system complies with the health data protection principles of **Law 25.326** (Argentina) and HIPAA-like best practices; the PDFs and footer include the corresponding notices.

> ⚠️ Do not expose network/log publications or credentials (Orthanc, database) outside the controlled environment.

---

## 10. Troubleshooting

| Problem | Possible cause / Solution |
|----------|--------------------------|
| I cannot log into the portal | Check username/password, or that the patient has `allow_patient_portal = 'YES'` in OpenEMR. |
| A study does not appear in the portal | The report may be in `draft`, or the images were not uploaded at submit time. Check `form_imaging_report_images` and re-upload from the report form. |
| An image upload fails | Check the provider configuration in `procedure_providers` (section 4.3) and Orthanc connectivity/credentials; the file is still kept in `documents`. |
| PDF / Dompdf not found error | Run `composer install` in the project (`dompdf/dompdf` dependency). |

---

## License

Project under the **GPL-3.0-or-later** license (see `composer.json`).
