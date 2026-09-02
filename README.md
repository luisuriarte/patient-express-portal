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
│   ├── Imaging.php                # Imaging: documents + Orthanc PACS + reports
│   └── PortalSSO.php              # Single Sign-On to the full portal
├── public/                        # Web pages (browser entry point)
│   ├── index.php                  # Login screen
│   ├── dashboard.php              # Main panel with 3 tabs
│   ├── view_document.php          # Secure document/image server
│   ├── print_pdf.php              # PDF generator (laboratory and imaging)
│   ├── goto_portal.php            # SSO redirect to OpenEMR
│   └── logout.php                 # Log out
├── templates/                     # Common header and footer (navbar + modals)
├── cron/
│   └── cron_sync_pacs.php         # OpenEMR → Orthanc sync (CLI)
├── forms/
│   └── imaging_report/            # Clinical form for imaging reports
├── sql/
│   ├── documents_pacs.sql         # documents_pacs_sync table
│   ├── images-procedures.sql      # Imaging order/encounter schema
│   └── lang_custom.sql            # Spanish translations for the UI
├── config/                        # Alternate config (standalone PDO)
└── composer.json                  # Dependencies (dompdf)
```

### 2.3 External components it integrates with

| Service | URL (constant) | Default |
|----------|----------------|---------|
| Orthanc REST API | `ORTHANC_URL` | `http://127.0.0.1:8042` |
| DICOM-WADO/STOW | `ORTHANC_WADO_URL` | `https://pacs.origen.ar/dicom-web` |
| OHIF v3 viewer | `OHIF_VIEWER_BASE_URL` | `https://imagenes.origen.ar/viewer` |
| Full OpenEMR portal | `OPENEMR_PORTAL_URL` | `https://hcd.origen.ar/portal` |

---

## 3. Workflow (end-to-end)

### 3.1 Publishing a diagnostic imaging report

```
 1. The radiology technician opens the patient's encounter in OpenEMR
    and creates an "Imaging Diagnostic Report" (clinical form).
 2. Loads the study data (modality, anatomical region, requesting
    service, physician) and writes technique, findings, conclusion.
 3. Saves as DRAFT (remains editable) or FINALIZES (generates the PDF).
 4. When finalizing, save.php:
      a. Generates the institutional PDF (Dompdf).
      b. Saves it in "Patient Documents" (documents table) inside the
         chosen folder (automatic category based on modality).
      c. Records the study data that will be synchronized to Orthanc.
 5. The synchronization cron ensures images (DICOM / JPG) are pushed
    to Orthanc.
 6. The patient logs into the Express Portal and sees their study + report.
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

### 3.3 PACS sync (cron)

The cron walks the imaging documents that are not yet synchronized and pushes them to Orthanc according to their type:

- **Native DICOM (`.dcm`)** → `POST /instances` (uploads the raw binary directly).
- **Standard image (JPG/PNG/WEBP)** → `POST /tools/create-dicom` (converts to DICOM with the patient/study tags).
- **PDF / Report** → **not synchronized** (the encapsulated-PDF sync was removed because mobile viewers without a PDF renderer fail and the image may not be displayed; the PDF report remains in the patient's OpenEMR documents).

Each document is recorded in the `documents_pacs_sync` table with its status (`pending`, `synced`, `failed`, `ignored`).

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
   - `sql/documents_pacs.sql` → `documents_pacs_sync` table.
   - `sql/images-procedures.sql` → imaging order/encounter support schema.

4. **Install the Spanish translations** (so the English UI shows in Spanish for Spanish-language users):
   ```bash
   mysql -u<user> -p <openemr_db> < sql/lang_custom.sql
   ```
   This is idempotent: it does not overwrite existing translations and can be re-run safely.

5. **Institutional data**: loaded **automatically from the OpenEMR `facility` table** (the facility with `billing_location = 1` is used). Name, address, phone, email and website are taken from there, so there is **no need (nor is it recommended) to write clinic data in code**. Only the **logo** is kept as a local static resource (`public/assets/img/logo.png`).

6. **Install the clinical form** `forms/imaging_report/`: copy it into `interface/forms/` (or OpenEMR's forms folder) and install its schema (`table.sql`), which besides creating `form_imaging_report` loads the normalized lists of **requesting services** and **anatomical regions** into `list_options`.

7. **Schedule the cron** (see section 7).

8. **Verify access**: open `public/index.php` (e.g. `https://hcd.origen.ar/express_portal/index.php`).

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

- `form_imaging_report` keeps `study_instance_uid` and `accession_number` to maintain the link with the DICOM study.
- The status can be `draft` or `finalized`.
- The service and anatomical region lists are loaded from `list_options`; to add new options edit those lists in OpenEMR (Administration → Lists).

---

## 7. Operation and maintenance (cron / PACS)

### 7.1 Automatic sync

The `cron/cron_sync_pacs.php` script pushes imaging documents to Orthanc. It is recommended to schedule it in the crontab, for example:

```cron
*/5 * * * * php /var/www/html/origen.ar/hcd/express_portal/cron/cron_sync_pacs.php >> /var/log/orthanc_sync.log 2>&1
```

### 7.2 Execution modes and flags

| Flag | Effect |
|------|--------|
| *(none)* | Processes up to 200 pending documents. |
| `--all` | Processes up to 1000 documents. |
| `--retry` / `--force` | Includes documents in `failed` status to retry them. |

The script uses a **file lock** (avoids concurrent runs), limits memory (512M) and time (600s), and only acts if the `documents_pacs_sync` table exists.

### 7.3 Statuses in `documents_pacs_sync`

- `pending` → pending processing.
- `synced` → uploaded correctly to Orthanc.
- `failed` → error (check `error_message`).
- `ignored` → discarded (e.g. PDF reports, which are intentionally not synced).

### 7.4 Retrying a document that was left unsynced

If a document is left `pending` or `failed`, reset its status and run the normal cron:

```sql
UPDATE documents_pacs_sync SET status = 'pending' WHERE document_id = <id>;
```

```bash
php cron/cron_sync_pacs.php --retry
```

---

## 8. Project structure

Details of the most relevant files:

| Path | Purpose |
|------|-----------|
| `config.php` | OpenEMR bootstrap, integration constants, dynamic loading of institutional data from `facility`, autoloading. |
| `src/Auth.php` | Patient authentication (`patient_access_onsite` table), expiring session, logout. |
| `src/Laboratory.php` | Laboratory results grouped by encounter. |
| `src/Imaging.php` | Imaging: OpenEMR documents, reports, orders and Orthanc studies; OHIF/Stone viewers. |
| `src/PortalSSO.php` | Generation of `onetime_auth` tokens for SSO to the full portal. |
| `public/index.php` | Patient login screen. |
| `public/dashboard.php` | Main panel (3 tabs + search boxes). |
| `public/view_document.php` | Secure server for documents and images (permission control). |
| `public/print_pdf.php` | PDF generator (laboratory / imaging) with Dompdf. |
| `public/goto_portal.php` | SSO redirect to OpenEMR. |
| `public/logout.php` | Log out. |
| `templates/header.php` / `templates/footer.php` | Common layout + PDF/image modals. |
| `cron/cron_sync_pacs.php` | Sync of imaging documents to Orthanc (CLI). |
| `forms/imaging_report/` | Clinical form for imaging reports (create, edit, view, report, PDF). |
| `sql/documents_pacs.sql` | `documents_pacs_sync` table. |
| `sql/images-procedures.sql` | Imaging order schema. |
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
| A study does not appear in the portal | The report may be in `draft`, or the sync has not finished. Retry with the cron (`--retry`). |
| The cron does not run | Check the `documents_pacs_sync` table and read/write permissions; review `/var/log/orthanc_sync.log`. |
| PDF / Dompdf not found error | Run `composer install` in the project (`dompdf/dompdf` dependency). |

---

## License

Project under the **GPL-3.0-or-later** license (see `composer.json`).
