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
│   ├── Imaging.php                # Imágenes: documentos + Orthanc PACS + informes
│   └── PortalSSO.php              # Single Sign-On hacia el portal completo
├── public/                        # Páginas web (punto de entrada del navegador)
│   ├── index.php                  # Pantalla de login
│   ├── dashboard.php              # Panel principal con 3 pestañas
│   ├── view_document.php          # Servidor seguro de documentos/imágenes
│   ├── print_pdf.php              # Generador de PDF (laboratorio e imágenes)
│   ├── goto_portal.php            # Redirección SSO a OpenEMR
│   └── logout.php                 # Cierre de sesión
├── templates/                     # Cabecera y pie comunes (navbar + modales)
├── cron/
│   └── cron_sync_pacs.php         # Sincronización OpenEMR → Orthanc (CLI)
├── forms/
│   └── imaging_report/            # Formulario clínico de informes de imágenes
├── sql/
│   └── documents_pacs.sql        # Tabla documents_pacs_sync
├── config/                        # Config alternativa (PDO standalone)
└── composer.json                  # Dependencias (dompdf)
```

### 2.3 Componentes externos con los que se integra

| Servicio | URL (constante) | Default |
|----------|----------------|---------|
| API REST de Orthanc | `ORTHANC_URL` | `http://127.0.0.1:8042` |
| DICOM-WADO/STOW | `ORTHANC_WADO_URL` | `https://pacs.origen.ar/dicom-web` |
| Visor OHIF v3 | `OHIF_VIEWER_BASE_URL` | `https://imagenes.origen.ar/viewer` |
| Portal completo OpenEMR | `OPENEMR_PORTAL_URL` | `https://hcd.origen.ar/portal` |

---

## 3. Flujo de trabajo (end-to-end)

### 3.1 Publicación de un informe de diagnóstico por imágenes

```
 1. El técnico radiólogo abre el encuentro del paciente en OpenEMR
    y crea un "Informe de Diagnóstico por Imágenes" (formulario clínico).
 2. Carga los datos del estudio (modalidad, región anatómica, servicio
    solicitante, médico) y redacta técnica, hallazgos, conclusión.
 3. Guarda como BORRADOR (queda editable) o FINALIZA (genera el PDF).
 4. Al finalizar, save.php:
      a. Genera el PDF institucional (Dompdf).
      b. Lo guarda en "Documentos del paciente" (tabla documents)
         dentro de la carpeta elegida (categoría automática según modalidad).
      c. Vincula el PDF al estudio DICOM del paciente en Orthanc
         (Encapsulated PDF) para que se vea junto a las series.
 5. El cron de sincronización asegura que las imágenes (DICOM / JPG / PDF)
    queden subidas a Orthanc.
 6. El paciente ingresa al Portal Express y ve su estudio + informe.
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

### 3.3 Sincronización con PACS (cron)

El cron recorre los documentos de imágenes que aún no están sincronizados y los sube a Orthanc según su tipo:

- **DICOM nativo (`.dcm`)** → `POST /instances` (sube el binario directo).
- **Imagen estándar (JPG/PNG/WEBP)** → `POST /tools/create-dicom` (convierte a DICOM con los tags del paciente/estudio).
- **PDF / Informe** → `POST /tools/create-dicom` (sube como **Encapsulated PDF**, modalidad `OT`, adjuntado al estudio de la misma carpeta/paciente mediante el campo `Parent`).

Cada documento queda registrado en la tabla `documents_pacs_sync` con su estado (`pending`, `synced`, `failed`, `ignored`).

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

3. **Configurar variables de entorno** (copiar `.env.example` a `.env` y completar):
   ```dotenv
   # Base de datos OpenEMR
   OPENEMR_DB_HOST=127.0.0.1
   OPENEMR_DB_PORT=3306
   OPENEMR_DB_NAME=openemr
   OPENEMR_DB_USER=openemr
   OPENEMR_DB_PASS=tu_password_aqui

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

4. **Crear la(s) tabla(s) de apoyo** en la base de OpenEMR:
   - `sql/documents_pacs.sql` → tabla `documents_pacs_sync`.

5. **Datos institucionales**: se cargan **automáticamente desde la tabla `facility`** de OpenEMR (se usa la facility de `billing_location = 1`). Nombre, dirección, teléfono, email y web del centro se toman de ahí, por lo que **no es necesario (ni recomendado) escribir datos de la clínica en el código**. Solo el **logo** se mantiene como recurso estático local (`public/assets/img/logo.png`).

6. **Instalar el formulario clínico** `forms/imaging_report/`: copiarlo dentro de `interface/forms/` (o la carpeta de formularios de OpenEMR) e instalar su esquema (`table.sql`), que además de crear `form_imaging_report` carga las listas normalizadas de **servicios solicitantes** y **regiones anatómicas** en `list_options`.

7. **Programar el cron** (ver sección 7).

8. **Verificar acceso**: abrir `public/index.php` (ej. `https://hcd.origen.ar/express_portal/index.php`).

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

- `form_imaging_report` conserva `study_instance_uid` y `accession_number` para mantener el vínculo con el estudio DICOM.
- El estado puede ser `borrador` o `finalizado`.
- Las listas de servicios y regiones anatómicas se cargan desde `list_options`; para agregar opciones nuevas editá esas listas en OpenEMR (Administración → Listas).

---

## 7. Operación y mantenimiento (cron / PACS)

### 7.1 Sincronización automática

El script `cron/cron_sync_pacs.php` sube los documentos de imágenes a Orthanc. Se recomienda programarlo en el crontab, por ejemplo:

```cron
*/5 * * * * php /var/www/html/origen.ar/hcd/express_portal/cron/cron_sync_pacs.php >> /var/log/orthanc_sync.log 2>&1
```

### 7.2 Modos de ejecución y flags

| Flag | Efecto |
|------|--------|
| *(ninguno)* | Procesa hasta 200 documentos pendientes. |
| `--all` | Procesa hasta 1000 documentos. |
| `--retry` / `--force` | Incluye los documentos en estado `failed` para reintentarlos. |

El script usa **file lock** (evita ejecuciones concurrentes), limita memoria (512M) y tiempo (600s), y solo actúa si la tabla `documents_pacs_sync` existe.

### 7.3 Estados en `documents_pacs_sync`

- `pending` → pendiente de procesar.
- `synced` → subido correctamente a Orthanc.
- `failed` → error (revisar `error_message`).
- `ignored` → descartado.

### 7.4 Reintentar un PDF/informe que quedó sin sincronizar

Si un informe quedó `pending` o `failed`, se resetea su estado y se corre el cron normal:

```sql
UPDATE documents_pacs_sync SET status = 'pending' WHERE document_id = <id>;
```

```bash
php cron/cron_sync_pacs.php --retry
```

---

## 8. Estructura del proyecto

Detalle de los archivos más relevantes:

| Ruta | Propósito |
|------|-----------|
| `config.php` | Bootstrap de OpenEMR, constantes de integración, carga dinámica de datos institucionales desde `facility`, autoloading. |
| `src/Auth.php` | Autenticación de pacientes (tabla `patient_access_onsite`), sesión con expiración, logout. |
| `src/Laboratory.php` | Resultados de laboratorio agrupados por encuentro. |
| `src/Imaging.php` | Imágenes: documentos de OpenEMR, informes, órdenes y estudios de Orthanc; visores OHIF/Stone. |
| `src/PortalSSO.php` | Generación de tokens `onetime_auth` para el SSO al portal completo. |
| `public/index.php` | Pantalla de login del paciente. |
| `public/dashboard.php` | Panel principal (3 pestañas + buscadores). |
| `public/view_document.php` | Servidor seguro de documentos e imágenes (control de permisos). |
| `public/print_pdf.php` | Generador de PDFs (laboratorio / imágenes) con Dompdf. |
| `public/goto_portal.php` | Redirección SSO a OpenEMR. |
| `public/logout.php` | Cierre de sesión. |
| `templates/header.php` / `templates/footer.php` | Layout común + modales PDF/imagen. |
| `cron/cron_sync_pacs.php` | Sincronización de documentos de imágenes hacia Orthanc (CLI). |
| `forms/imaging_report/` | Formulario clínico de informes de imágenes (crear, editar, ver, reporte, PDF). |
| `sql/documents_pacs.sql` | Tabla `documents_pacs_sync`. |
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
| El visor OHIF no abre un PDF encapsulado | Conocido: OHIF no renderiza PDF encapsulado; usar el visor **Stone** o descargar el **PDF del informe**. |
| Un estudio no aparece en el portal | El informe puede estar en `borrador`, o la sincronización no terminó. Reintentá con el cron (`--retry`). |
| El cron no corre | Verificá la tabla `documents_pacs_sync` y los permisos de escritura/lectura; revisá `/var/log/orthanc_sync.log`. |
| Error de PDF / Dompdf no encontrado | Ejecutá `composer install` en el proyecto (dependencia `dompdf/dompdf`). |
| Error 2020 de Orthanc al subir PDF con `Parent` | El payload con `Parent` no debe reenviar tags de paciente/estudio (producen el error "Trying to override a value inherited from a parent module"). El código ya maneja esto. |

---

## Licencia

Proyecto bajo licencia **GPL-3.0-or-later** (ver `composer.json`).
