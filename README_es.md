# Portal Express del Paciente — Centro Médico Origen

Portal web liviano ("express") para pacientes, integrado de forma nativa dentro de una instancia de **OpenEMR** con un **PACS Orthanc**. Permite al paciente autenticado consultar, de forma rápida y segura:

- **Resultados de laboratorio**, agrupados por encuentro clínico.
- **Diagnósticos por imágenes** (estudios DICOM, imágenes JPG/PNG e informes en PDF).
- **Acceso al portal completo de OpenEMR** mediante Single Sign-On (SSO).

Incluye además un **formulario clínico de OpenEMR** para redactar informes de diagnóstico por imágenes (con generación de PDF institucional y vinculación automática al estudio DICOM en Orthanc) y un **cron de sincronización** de los documentos de imágenes hacia el PACS.

Toda la documentación de este archivo está en español (rioplatense/argentino).

---

## Índice

1. [Descripción general del sistema](#1-description-general-del-sistema)
2. [Arquitectura y componentes](#2-arquitectura-y-componentes)
3. [Flujo de trabajo (end-to-end)](#3-flujo-de-trabajo-end-to-end)
4. [Instalación y configuración](#4-instalacion-y-configuracion)
5. [Manual de usuario — Paciente](#5-manual-de-usuario--paciente)
6. [Manual de uso — Técnico radiólogo / Médico](#6-manual-de-uso--tecnico-radiólogo--médico)
7. [Operación y mantenimiento (cron / PACS)](#7-operación-y-mantenimiento-cron--pacs)
8. [Estructura del proyecto](#8-estructura-del-proyecto)
9. [Seguridad y cumplimiento](#9-seguridad-y-cumplimiento)
10. [Solución de problemas](#10-solucion-de-problemas)

---

## 1. Descripción general del sistema

El **Portal Express** es la cara pública del paciente hacia sus resultados de salud. En lugar de depender de llamadas o retiro de informes en papel, el paciente entra con su usuario/contraseña y ve al instante:

| Módulo | Qué muestra |
|--------|-------------|
| **Laboratorio** | Informes de análisis clínicos agrupados por encuentro, con valores, unidades, rangos de referencia y alertas de valores fuera de rango. |
| **Imágenes** | Estudios DICOM (con visores OHIF y Stone WebViewer), imágenes estándar (JPG/PNG), e informes en PDF del mismo estudio. |
| **Portal OpenEMR** | Acceso con un clic al portal completo (turnos, contacto con el médico, etc.) sin volver a loguearse (SSO). |

### Rasgos principales

- **Rápido y simple**: pocas páginas, pensadas para consulta directa desde el celular.
- **Seguro**: login con contraseña (hash bcrypt), sesión con tiempo de expiración, cookies `httponly`/`samesite` y verificación CSRF.
- **Integrado**: no es una aplicación aparte; corre dentro de la instancia de OpenEMR y usa su misma base de datos y sus utilidades de seguridad.
- **Cumplimiento**: respeta los principios de confidencialidad de datos de salud (Ley 25.326 en Argentina, buenas prácticas tipo HIPAA).

---

## 2. Arquitectura y componentes

### 2.1 Tecnologías

| Componente | Tecnología |
|-----------|------------|
| Lenguaje | PHP ≥ 8.1 (usa `match`, argumentos con nombre, `str_contains`, etc.) |
| Framework | PHP vanilla + bootstrap nativo de OpenEMR (`globals.php`, `sqlQuery()`, `formSubmit()`, …) |
| Base de datos | MySQL / MariaDB (la misma de OpenEMR) |
| PDF | `dompdf/dompdf` (^2.0 / ^3.0) |
| PACS | Orthanc REST API (autenticación HTTP Basic) |
| Visor DICOM | OHIF Viewer v3 + Orthanc Stone WebViewer |
| Frontend | Tailwind CSS, Lucide Icons, Google Fonts (Inter / Plus Jakarta Sans) |

### 2.2 Piezas del sistema

```
patient-express-portal/
├── config.php                     # Bootstrap de OpenEMR + constantes + autoloading
├── src/                           # Lógica (clases PHP, namespace App\)
│   ├── Auth.php                   # Autenticación de pacientes y sesión
│   ├── Laboratory.php             # Resultados de laboratorio (por encuentro)
│   ├── Imaging.php                # Imágenes: documentos + PACS multi-proveedor + informes
│   ├── PacsProvider.php           # Modelo de configuración PACS/Orthanc por proveedor
│   ├── PacsService.php            # Cliente REST/DICOMweb de Orthanc (consciente de proveedor)
│   └── PortalSSO.php              # Single Sign-On hacia el portal completo
├── public/                        # Páginas web (punto de entrada del navegador)
│   ├── index.php                  # Pantalla de login
│   ├── dashboard.php              # Panel principal con 3 pestañas
│   ├── view_document.php          # Servidor seguro de documentos/imágenes
│   ├── print_pdf.php              # Generador de PDF (laboratorio e imágenes)
│   ├── goto_portal.php            # Redirección SSO a OpenEMR
│   └── logout.php                 # Cierre de sesión
├── templates/                     # Cabecera y pie comunes (navbar + modales)
├── forms/
│   └── imaging_report/            # Formulario clínico de informes de imágenes (subida directa a PACS)
├── sql/
│   ├── images-procedures.sql      # Esquema de orden/encuentro de imágenes
│   └── lang_custom.sql            # Traducciones al español de la interfaz
├── config/                        # Config alternativa (PDO standalone)
├── patch/                         # Patches al núcleo de OpenEMR + migraciones SQL
└── composer.json                  # Dependencias (dompdf)
```

### 2.3 Componentes externos con los que se integra

| Servicio | Campo (por proveedor) | Fallback por defecto |
|----------|----------------------|----------------------|
| API REST de Orthanc | `remote_api` | `ORTHANC_URL` (default `http://127.0.0.1:8042`) |
| DICOM-WADO/STOW | `wado_url` | `ORTHANC_WADO_URL` (default `https://pacs.origen.ar/dicom-web`) |
| Visor OHIF v3 | `remote_host` | `OHIF_VIEWER_BASE_URL` (default `https://imagenes.origen.ar/viewer`) |
| Portal completo OpenEMR | `OPENEMR_PORTAL_URL` | `https://hcd.origen.ar/portal` |

> Los endpoints de PACS (REST, WADO, OHIF) pueden configurarse **por proveedor** en la
> tabla `procedure_providers` (ver sección 4.3). Las constantes `ORTHANC_*` / `OHIF_*`
> actúan solo como fallback cuando el campo del proveedor está vacío.

---

## 3. Flujo de trabajo (end-to-end)

### 3.1 Publicación de un informe de diagnóstico por imágenes

```
 1. El médico solicitante crea una "orden de procedimiento" de imágenes
    (formulario clínico) eligiendo servicio solicitante y región anatómica;
    queda guardada en procedure_order (procedure_order_type = 'imaging').
 2. El técnico radiólogo abre el encuentro del paciente en OpenEMR
    y crea un "Informe de Diagnóstico por Imágenes" (formulario clínico).
 3. En "Datos del Estudio" el técnico selecciona la orden de estudio
    de origen; el formulario auto-completa servicio solicitante, región
    anatómica y médico solicitante, y guarda el vínculo (procedure_order_id).
 4. El técnico sube las imágenes/PDF directamente en el formulario del
    informe (arrastrar y soltar, multi-archivo: DICOM, JPG/PNG, PDF). Cada
    archivo se envía al PACS del proveedor de la orden de estudio al
    guardar y queda registrado en form_imaging_report_images con su
    StudyInstanceUID e IDs de PACS.
 5. Carga/redacta el resto (modalidad, técnica, hallazgos, conclusión) y
    guarda como BORRADOR (queda editable) o FINALIZA (genera el PDF).
 6. Al finalizar, save.php:
      a. Genera el PDF institucional (Dompdf) y lo guarda en
         "Documentos del paciente" (tabla documents).
      b. Vincula el PDF generado al estudio de las imágenes subidas
         (el PDF en sí NO se sube a PACS).
 7. El paciente ingresa al Portal Express y ve su estudio + informe.
```

### 3.2 Consulta del paciente

```
 1. El paciente ingresa usuario/contraseña en el Portal Express.
 2. En la pestaña "Diagnósticos por Imágenes" ve sus estudios agrupados,
    cada uno con la fecha del encuentro y el botón de su informe PDF.
 3. Puede abrir el visor DICOM (OHIF), el visor Stone, o descargar/ver el PDF.
 4. En la pestaña "Laboratorio" ve sus análisis agrupados por encuentro.
 5. Con un clic pasa al Portal Completo (SSO) sin volver a loguearse.
```

### 3.3 Subida directa a PACS (multi-proveedor)

**No hay cron de sincronización.** Las imágenes y PDFs se suben al PACS **en el momento de guardar** desde el formulario del informe (`forms/imaging_report/`), a través del proveedor de la orden de estudio.

- Cada archivo (DICOM, JPG/PNG/WEBP, PDF) se sube directamente al PACS/Orthanc correspondiente vía `POST /instances` (DICOM nativo) o `POST /tools/create-dicom`.
- Los archivos de una misma orden de estudio comparten un `StudyInstanceUID` determinístico (`1.2.840.113619.2.55.<orderId>.<providerId>`); los DICOM nativos conservan su estudio original.
- Cada subida queda registrada en `form_imaging_report_images` (columnas `pacs_instance_id`, `pacs_series_id`, `pacs_study_id`, `study_instance_uid`, `provider_id`, `status`).
- El **PDF del informe generado NO se sube a PACS**; vive solo en los documentos de OpenEMR del paciente y se vincula al estudio de las imágenes subidas (vía `study_instance_uid`).

---

## 4. Instalación y configuración

### 4.1 Requisitos

- Instancia de **OpenEMR** (con `interface/globals.php` accesible).
- **PHP ≥ 8.1** con extensiones `curl`, `mbstring` y las que requiera OpenEMR.
- Extensión **GD** de PHP (opcional, requerida para generar el **código QR** hacia el visor DICOM en el PDF del informe. OpenEMR la suele incluir).
- **MySQL/MariaDB**.
- **Orthanc** accesible por REST HTTP.

> El código QR del informe usa la librería **`bacon/bacon-qr-code`** que ya forma parte del `vendor/` de OpenEMR (no hace falta instalar nada extra). Si no hay GD disponible, el QR simplemente no se dibuja pero el informe se genera igual.

### 4.2 Pasos

1. **Clonar / copiar** el proyecto dentro de la ruta web de OpenEMR.
   En producción, por ejemplo: `/var/www/html/origen.ar/hcd/express_portal/`.

2. **Instalar dependencias** (Composer):
   ```bash
   composer install --no-dev
   ```

   # Orthanc PACS
   ORTHANC_URL=http://127.0.0.1:8042
   ORTHANC_USER=orthanc
   ORTHANC_PASS=orthanc
   ORTHANC_WADO_URL=https://pacs.origen.ar/dicom-web

   # Visor DICOM OHIF
   OHIF_VIEWER_BASE_URL=https://imagenes.origen.ar/viewer

   # Portal completo OpenEMR
   OPENEMR_PORTAL_URL=https://hcd.origen.ar/portal
   ```

3. **Crear la(s) tabla(s) de apoyo** en la base de OpenEMR:
   - Si usás el **PACS multi-proveedor**, aplicá `patch/sql/pacs-multiprovider.sql` para agregar `remote_api` y `wado_url` a `procedure_providers` (`remote_host`, `login` y `password` ya existen nativamente). Ver sección 4.3 para los campos del proveedor.
   - Aplicá `patch/sql/fase4-remove-pacs-sync.sql` para eliminar la tabla obsoleta `documents_pacs_sync` (el flujo viejo de cron ya no existe; el vínculo ahora vive en `form_imaging_report_images`).
   - Cargar el **catálogo de diagnóstico por imágenes** en `procedure_type` desde `patch/sql/images-procedures.sql` (inglés) o `patch/sql/images-procedures_es.sql` (español). Ambos son idempotentes, comparten el mismo `procedure_code`/`standard_code`, y solo difieren en el texto visible de los estudios.
   - Si usás el **flujo orden + informe**, aplicá `patch/sql/procedure_order-imaging-context.sql` (agrega `requesting_service`/`anatomical_region` a `procedure_order` y `procedure_order_id` a `form_imaging_report`). Debe correrse **después** de aplicar el patch de código de `interface/forms/procedure_order` para que existan las columnas que referencia el formulario.

4. **Aplicar el patch al núcleo de OpenEMR** para el contexto de la orden de imágenes (solo si usás el flujo orden + informe): aplicá el diff `patch/diffs/common.php.patch` sobre `interface/forms/procedure_order/common.php` para que el formulario de la orden muestre los desplegables **Servicio Solicitante (Requesting Service)** y **Región Anatómica (Anatomic Region)** y los guarde. Luego corré `patch/sql/procedure_order-imaging-context.sql` (paso 3).

5. **Instalar las traducciones al español** (para que la interfaz en inglés se vea en español para los usuarios hispanohablantes):
   ```bash
   mysql -u<user> -p <openemr_db> < sql/lang_custom.sql
   ```
   Es idempotente: no pisa traducciones existentes y se puede volver a correr sin problema.

6. **Datos institucionales**: se cargan **automáticamente desde la tabla `facility`** de OpenEMR (se usa la facility de `billing_location = 1`). Nombre, dirección, teléfono, email y web del centro se toman de ahí, por lo que **no es necesario (ni recomendado) escribir datos de la clínica en el código**. Solo el **logo** se mantiene como recurso estático local (`public/assets/img/logo.png`).

7. **Instalar el formulario clínico** `forms/imaging_report/`: copiarlo dentro de `interface/forms/` (o la carpeta de formularios de OpenEMR) e instalar su esquema (`table.sql`), que además de crear `form_imaging_report` carga las listas normalizadas de **servicios solicitantes** y **regiones anatómicas** en `list_options`.

8. **Configurar los proveedores de PACS** en OpenEMR: cada fila de `procedure_providers` debe apuntar a su Orthanc/OHIF (ver sección 4.3). **No hay cron que programar** — las subidas ocurren al guardar.

9. **Verificar acceso**: abrir `public/index.php` (ej. `https://hcd.origen.ar/express_portal/index.php`).

### 4.3 Campos del proveedor PACS (`procedure_providers`)

Cada PACS/Orthanc conectado se configura **como una fila de "procedure provider"** en la tabla `procedure_providers` de OpenEMR. La orden de estudio vincula el estudio del paciente a un proveedor mediante `procedure_order.lab_id → procedure_providers.ppid`.

> `remote_host`, `login` y `password` ya existen nativamente en OpenEMR.
> `remote_api` y `wado_url` se agregan con `patch/sql/pacs-multiprovider.sql`.

| Columna | Significado | Ejemplo |
|---------|-------------|---------|
| `name` | Nombre visible que se muestra en el portal | `Centro 1` |
| `remote_host` | URL base del **visor OHIF** (path completo) | `https://imagenes.origen.ar/viewer` |
| `remote_api` | URL base de la **API REST de Orthanc** | `https://pacs.origen.ar` (o `http://10.0.0.5:8042`) |
| `wado_url` | URL base de **DICOMweb WADO-RS** (usado por OHIF/STONE) | `https://pacs.origen.ar/dicom-web` |
| `login` | Usuario **HTTP Basic** de Orthanc | `orthanc` |
| `password` | Contraseña **HTTP Basic** de Orthanc | `changeme` |

Ejemplo SQL para dar de alta un proveedor:

```sql
INSERT INTO procedure_providers
    (name, remote_host, remote_api, wado_url, login, password, active)
VALUES
    ('Centro 1',
     'https://imagenes.origen.ar/viewer',   -- remote_host (URL base del visor OHIF)
     'https://pacs.origen.ar',              -- remote_api  (URL base REST de Orthanc)
     'https://pacs.origen.ar/dicom-web',    -- wado_url    (URL base WADO-RS)
     'orthanc',                             -- login (HTTP Basic de Orthanc)
     'changeme',                            -- password
     1);
```

Si un proveedor deja `remote_api` / `wado_url` vacíos, el portal cae a las constantes
`ORTHANC_URL` / `ORTHANC_WADO_URL` / `OHIF_VIEWER_BASE_URL`; `wado_url` también puede
derivarse automáticamente de `remote_api` (`https://host/dicom-web`) cuando no está definido.

---

## 5. Manual de usuario — Paciente

> Este manual está pensado para el paciente que usa el portal desde el celular o la computadora.

### 5.1 Cómo ingresar

1. Abrí el enlace que te entregó el Centro Médico (el **Portal Express del Paciente**).
2. En la pantalla de ingreso vas a ver dos campos:
   - **Usuario del Portal / DNI / Email**: podés escribir tu **DNI**, tu **usuario del portal** o tu **email** registrado.
   - **Contraseña**: la contraseña que te dieron o que configuraste.
3. Tocá el botón **"Ingresar a mis Resultados"**.

> 💡 Si no tenés usuario y contraseña, comunicate con el Centro Médico para que te los entreguen (o usá la opción "Acceder al Portal Completo OpenEMR" si ya tenés acceso por allí).

### 5.2 El panel principal (Dashboard)

Una vez adentro vas a ver tu nombre y un panel con **3 pestañas**:

| Pestaña | Contenido |
|---------|-----------|
| **Resultados de Laboratorio** | Tus análisis clínicos agrupados por encuentro. |
| **Diagnósticos por Imágenes** | Tus estudios (rayos X, tomografías, resonancias, ecografías, etc.) y sus informes. |
| **Portal Completo** | Botón para pasar al portal completo de OpenEMR (turnos, mensajes, etc.). |

### 5.3 Ver tus resultados de laboratorio

1. Entrá a la pestaña **"Resultados de Laboratorio"**.
2. Vas a ver los encuentros ordenados del más reciente al más antiguo. Cada encuentro muestra:
   - El **número y la fecha del encuentro**.
   - La **fecha de los resultados** y el **solicitante** (médico que pidió el estudio).
   - Cuántos análisis incluye.
3. Tocá el encuentro para ver **el desglose de los estudios** con cada valor, su unidad y su rango de referencia.
4. Si un valor está **fuera de rango** queda marcado con una alerta ("valores a interpretar").
5. Podés **descargar/imprimir el informe completo en PDF** con el botón "Protocolo de Laboratorio".

### 5.4 Ver tus imágenes e informes

1. Entrá a la pestaña **"Diagnósticos por Imágenes"**.
2. Vas a ver tus estudios (cada tarjeta agrupa las imágenes del mismo estudio). Cada tarjeta muestra la **fecha del estudio** y el **encuentro** asociado.
3. Por cada estudio tenés botones para:
   - **Ver Imagen DICOM (OHIF)**: abre el visor DICOM profesional para navegar las series.
   - **Ver Imagen (Stone Viewer)**: visor alternativo (muestra también los PDF encapsulados).
   - **Ver Informe (PDF)**: abre el informe del radiólogo en PDF.
   - **Descargar** el PDF del informe.

> ⚠️ Si un botón de visor no abre, probá con el otro visor o descargá el PDF del informe.

### 5.5 Pasar al Portal Completo

- Cualquiera sea la pestaña, tocá **"Portal OpenEMR"** (arriba en la barra) o el vínculo de la pestaña "Portal Completo".
- Se abrirá el portal completo de OpenEMR **sin que tengas que volver a escribir tu usuario y contraseña** (Single Sign-On).
- El acceso dura unos 15 minutos; si se vence, volvé a empezar desde el botón.

### 5.6 Cerrar sesión

- Tocá **"Cerrar Sesión"** en la barra superior.
- Esto es importante si usás una computadora o celular compartido.

### 5.7 Preguntas frecuentes (paciente)

**¿Qué hago si olvidé mi contraseña?**
Comunicate con el Centro Médico para que la restablezcan.

**¿Puedo ver los resultados desde el celular?**
Sí, el portal está pensado para verse bien en pantallas chicas.

**¿Por qué no veo un estudio nuevo?**
Puede que el informe todavía esté en borrador, o que la sincronización con el PACS no haya terminado. Solicitá al Centro que confirme la publicación.

**¿Los datos son privados?**
Sí. El acceso es con tu usuario y contraseña, y el sistema cumple con normas de confidencialidad de datos de salud (Ley 25.326 / HIPAA).

---

## 6. Manual de uso — Técnico radiólogo / Médico

Esta sección es para el personal que genera informes de diagnóstico por imágenes dentro de OpenEMR usando el formulario incluido.

### 6.1 Crear un informe

1. En el encuentro del paciente en OpenEMR, abrí **"Informe de Diagnóstico por Imágenes"**.
2. Completá la sección **"Datos del Estudio"**:
   - **Orden Solicitante (Requesting Order)**: (opcional) seleccioná la orden de estudio de imágenes de origen del paciente. Al elegirla, el formulario **auto-completa** el servicio solicitante, la región anatómica y el médico solicitante desde esa orden, y vincula el informe a la misma (`procedure_order_id`).
   - **Modalidad** *(obligatoria)*: RX, TC, RMN, US, Mamografía, DEXA u Otro.
   - **Región / Área Anatómica** *(obligatoria)*: elegila del listado normalizado (ej. "Columna Lumbar", "Tórax").
   - **Servicio / Solicitante**: drop-down de servicios (Guardia, Clínica Médica, Traumatología, etc.).
   - **Médico Solicitante**: podés elegir un médico de OpenEMR desde la lista, u optar por "Otro médico" y escribir el nombre a mano.
   - **Médico Informante** y **Fecha del Informe**.
3. Redactá las secciones de **Técnica / Metodología**, **Interpretación / Hallazgos**, **Conclusión** y **Observaciones**.
4. O usá una de las **plantillas rápidas** ("Rx Normal", "TC Normal", "RMN Normal", etc.) para cargar un informe de estudio normal y después editarlo si hace falta.
5. Elegí la **carpeta destino del PDF** en el legajo del paciente (si no elegís ninguna, se usa la automática según modalidad).
6. Guardá de una de estas dos formas:
   - **Guardar Borrador**: guarda sin generar el PDF, queda editable.
   - **Guardar y Generar PDF**: finaliza el informe, genera el PDF institucional, lo guarda en los documentos del paciente y lo vincula al estudio DICOM en Orthanc.

> **Código QR:** el PDF finalizado incluye, en la zona de validación, un **código QR**. Al escanearlo con el celular se abre el visor DICOM (OHIF) del estudio en `imagenes.origen.ar`. Solo se imprime cuando el estudio ya tiene su `StudyInstanceUID` sincronizado en el PACS.

### 6.2 Editar un informe

- Desde la vista del informe, si está en **borrador**, podés tocarlo para volver a editarlo.
- Un informe **finalizado** ya no se edita (se conserva como quedó para mantener la integridad).

### 6.3 Notas de datos

- `form_imaging_report` conserva `study_instance_uid` y `accession_number` para mantener el vínculo con el estudio DICOM, y `procedure_order_id` para vincular el informe a la orden de imágenes de origen.
- El estado puede ser `borrador` o `finalizado`.
- Las listas de servicios y regiones anatómicas se cargan desde `list_options`; para agregar opciones nuevas editá esas listas en OpenEMR (Administración → Listas). El **formulario de la orden de procedimiento** reutiliza las mismas listas (`imaging_report_services` / `imaging_report_anatomy`).

---

## 7. Operación y mantenimiento (PACS)

### 7.1 Subida directa al guardar

**No hay cron de sincronización.** Cada archivo de imágenes (DICOM, JPG/PNG/WEBP, PDF)
se sube al PACS de la orden de estudio **cuando se guarda el formulario del informe**
(`forms/imaging_report/`). Cada subida queda registrada en `form_imaging_report_images`
con su `study_instance_uid`, `pacs_instance_id`, `pacs_series_id`, `pacs_study_id`
y `provider_id`, a partir de los cuales el portal reconstruye la lista de estudios agrupada.

### 7.2 Si una subida falla

- Una subida fallida conserva el archivo en los `documents` de OpenEMR del paciente
  (primero siempre se guarda localmente) y marca la fila `status = 'failed'` en
  `form_imaging_report_images` con el error en `error_message`.
- Para reintentar, volvé a abrir el formulario del informe (Ver → Editar mientras sea
  borrador) y volvé a subir el archivo, o subilo de nuevo desde la zona de subida.
- Los proveedores se resuelven desde la orden de estudio (`procedure_order.lab_id →
  procedure_providers.ppid`); si el proveedor está mal configurado el portal cae al
  primer proveedor activo y la falla queda registrada en el log.

---

## 8. Estructura del proyecto

Detalle de los archivos más relevantes:

| Ruta | Propósito |
|------|-----------|
| `config.php` | Bootstrap de OpenEMR, constantes de integración, carga dinámica de datos institucionales desde `facility`, autoloading. |
| `src/Auth.php` | Autenticación de pacientes (tabla `patient_access_onsite`), sesión con expiración, logout. |
| `src/Laboratory.php` | Resultados de laboratorio agrupados por encuentro. |
| `src/Imaging.php` | Imágenes: documentos de OpenEMR, informes, órdenes y estudios de PACS multi-proveedor; visores OHIF/Stone. |
| `src/PacsProvider.php` | Modelo de PACS/Orthanc por proveedor (campos, constructores de URL de visor/API/WADO, resolución por orden). |
| `src/PacsService.php` | Cliente REST / DICOMweb de Orthanc para consultar y subir (consciente de proveedor). |
| `src/PortalSSO.php` | Generación de tokens `onetime_auth` para el SSO al portal completo. |
| `public/index.php` | Pantalla de login del paciente. |
| `public/dashboard.php` | Panel principal (3 pestañas + buscadores). |
| `public/view_document.php` | Servidor seguro de documentos e imágenes (control de permisos). |
| `public/print_pdf.php` | Generador de PDFs (laboratorio / imágenes) con Dompdf. |
| `public/goto_portal.php` | Redirección SSO a OpenEMR. |
| `public/logout.php` | Cierre de sesión. |
| `templates/header.php` / `templates/footer.php` | Layout común + modales PDF/imagen. |
| `forms/imaging_report/` | Formulario clínico: crear/editar/ver + **subida directa multi-archivo a PACS** (`upload.php`, `imaging_upload_functions.php`) e informe PDF. |
| `patch/sql/pacs-multiprovider.sql` | Agrega `remote_api` / `wado_url` a `procedure_providers`. |
| `patch/sql/fase4-remove-pacs-sync.sql` | Elimina la tabla obsoleta `documents_pacs_sync` (flujo de cron removido). |
| `patch/sql/images-procedures.sql` | Catálogo de imágenes (variante inglés). |
| `patch/sql/images-procedures_es.sql` | Catálogo de imágenes (variante español). |
| `patch/sql/procedure_order-imaging-context.sql` | Contexto orden + informe: `requesting_service`/`anatomical_region` en `procedure_order`, `procedure_order_id` en `form_imaging_report`. |
| `patch/diffs/common.php.patch` | Patch al núcleo: `interface/forms/procedure_order/common.php` guarda y muestra el servicio solicitante / región anatómica de imágenes. |
| `sql/lang_custom.sql` | Traducciones al español de los strings de la interfaz. |
| `.env.example` | Plantilla de variables de entorno. |

---

## 9. Seguridad y cumplimiento

- **Autenticación**: contraseñas con bcrypt (`password_verify`), con soporte a hashes legacy (sha1/md5) para migración.
- **Sesión**: caduca a los 30 minutos de inactividad, cookie `httponly` + `samesite=Lax`, y se regenera el ID de sesión al ingresar.
- **CSRF**: el formulario del informe valida el token CSRF de OpenEMR antes de guardar.
- **Acceso a documentos**: `view_document.php` verifica que el documento pertenezca al paciente autenticado.
- **SSO**: el token `onetime_auth` tiene validez de 15 minutos y es de un solo uso.
- **Confidencialidad**: el sistema cumple con los principios de protección de datos de salud de la **Ley 25.326** (Argentina) y buenas prácticas tipo HIPAA; los PDFs y el footer incluyen los avisos correspondientes.

> ⚠️ No se deben exponer publicaciones de red/logs ni credenciales (Orthanc, base de datos) fuera del entorno controlado.

---

## 10. Solución de problemas

| Problema | Causa posible / Solución |
|----------|--------------------------|
| No puedo ingresar al portal | Verificá usuario/contraseña, o que el paciente tenga `allow_patient_portal = 'YES'` en OpenEMR. |
| Un estudio no aparece en el portal | El informe puede estar en `borrador`, o las imágenes no se subieron al guardar. Revisá `form_imaging_report_images` y volvé a subir desde el formulario del informe. |
| Falla la subida de una imagen | Verificá la configuración del proveedor en `procedure_providers` (sección 4.3) y la conectividad/credenciales de Orthanc; el archivo queda igual en `documents`. |
| Error de PDF / Dompdf no encontrado | Ejecutá `composer install` en el proyecto (dependencia `dompdf/dompdf`). |
| Error 2020 de Orthanc al subir PDF con `Parent` | El payload con `Parent` no debe reenviar tags de paciente/estudio (producen el error "Trying to override a value inherited from a parent module"). El código ya maneja esto. |

---

## Licencia

Proyecto bajo licencia **GPL-3.0-or-later** (ver `composer.json`).
