<?php
/**
 * PacsProvider.php
 *
 * Representa un proveedor de PACS configurado en OpenEMR como un "procedure
 * provider" (tabla procedure_providers). Cada proveedor posta contra su propio
 * Orthanc con credenciales propias.
 *
 * Convención de endpoints (decisión de producto):
 *   remote_host  = URL base del visor OHIF (path completo),
 *                  ej. https://imagenes.origen.ar/viewer
 *   remote_api   = URL base de la API REST de Orthanc,
 *                  ej. https://pacs.origen.ar   (o http://<host>:8042)
 *   wado_url     = URL base DICOMweb WADO-RS,
 *                  ej. https://pacs.origen.ar/dicom-web
 *   login/password = credenciales HTTP Basic de Orthanc
 *
 * La resolución del proveedor se hace a partir de una orden de procedimiento
 * (procedure_order.lab_id -> procedure_providers.ppid). Si la orden no tiene
 * proveedor o no es válida, se usa el primer proveedor activo como fallback.
 */

namespace App;

class PacsProvider
{
    public int $ppid = 0;
    public string $name = '';
    public string $npi = '';
    public string $remoteHost = ''; // visor OHIF (path completo)
    public string $remoteApi = '';  // Orthanc REST base
    public string $wadoUrl = '';    // WADO-RS base
    public string $user = '';
    public string $pass = '';
    public string $protocol = 'DL';

    public function __construct(array $row = [])
    {
        if (!empty($row)) {
            $this->applyRow($row);
        }
    }

    /**
     * Carga los valores desde una fila de procedure_providers.
     */
    public function applyRow(array $row): void
    {
        $this->ppid = (int)($row['ppid'] ?? 0);
        $this->name = trim((string)($row['name'] ?? ''));
        $this->npi = trim((string)($row['npi'] ?? ''));
        $this->remoteHost = trim((string)($row['remote_host'] ?? ''));
        $this->remoteApi = trim((string)($row['remote_api'] ?? ''));
        $this->wadoUrl = trim((string)($row['wado_url'] ?? ''));
        $this->user = trim((string)($row['login'] ?? ''));
        $this->pass = (string)($row['password'] ?? '');
        $this->protocol = trim((string)($row['protocol'] ?? 'DL'));
    }

    /**
     * URL base de la API REST de Orthanc.
     */
    public function apiUrl(): string
    {
        return rtrim($this->remoteApi ?: (defined('ORTHANC_URL') ? ORTHANC_URL : 'http://127.0.0.1:8042'), '/');
    }

    /**
     * URL base WADO-RS (DICOMweb). Si no está definida, se deriva de la API
     * REST reemplazando el puerto/path por /dicom-web sobre el mismo host.
     */
    public function wadoBaseUrl(): string
    {
        if ($this->wadoUrl !== '') {
            return rtrim($this->wadoUrl, '/');
        }
        // Derivar de remote_api (https://pacs.origen.ar -> https://pacs.origen.ar/dicom-web)
        $api = $this->apiUrl();
        $parsed = parse_url($api);
        if ($parsed && !empty($parsed['host'])) {
            $scheme = $parsed['scheme'] ?? 'https';
            $host = $parsed['host'];
            return "{$scheme}://{$host}/dicom-web";
        }
        return rtrim($api, '/') . '/dicom-web';
    }

    /**
     * URL base del visor OHIF. Reutiliza remote_host (que por convención es el
     * path completo del viewer). Fallback a la constante global.
     */
    public function ohifBaseUrl(): string
    {
        if ($this->remoteHost !== '') {
            return rtrim($this->remoteHost, '/');
        }
        return rtrim(defined('OHIF_VIEWER_BASE_URL') ? OHIF_VIEWER_BASE_URL : 'https://imagenes.origen.ar/viewer', '/');
    }

    /**
     * Builds the OHIF viewer URL for a given StudyInstanceUID.
     */
    public function buildOhifViewerUrl(string $studyUid): string
    {
        return $this->ohifBaseUrl() . '?StudyInstanceUIDs=' . urlencode($studyUid);
    }

    /**
     * Builds the Stone WebViewer URL for a StudyInstanceUID.
     */
    public function buildStoneViewerUrl(string $studyUid): string
    {
        $parsed = parse_url($this->wadoBaseUrl());
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        if ($host === '') {
            $host = parse_url($this->apiUrl(), PHP_URL_HOST) ?: '';
        }
        return "{$scheme}://{$host}/stone-webviewer/index.html?study=" . urlencode($studyUid);
    }

    /**
     * Credenciales HTTP Basic en formato "user:pass".
     */
    public function basicAuth(): string
    {
        return $this->user . ':' . $this->pass;
    }

    /**
     * Indica si el proveedor tiene lo mínimo para operar.
     */
    public function isConfigured(): bool
    {
        return $this->ppid > 0 && $this->remoteApi !== '';
    }

    // =====================================================================
    // Resolución
    // =====================================================================

    /**
     * Resuelve el proveedor asociado a una orden de procedimiento.
     *
     * procedure_order.lab_id -> procedure_providers.ppid.
     * Si la orden no existe o no tiene lab_id válido, cae al proveedor
     * activo por defecto.
     */
    public static function resolveForOrder(?int $procedureOrderId): self
    {
        $labId = 0;
        if ($procedureOrderId && $procedureOrderId > 0) {
            $order = sqlQuery(
                "SELECT lab_id FROM procedure_order WHERE procedure_order_id = ? LIMIT 1",
                [$procedureOrderId]
            );
            $labId = (int)($order['lab_id'] ?? 0);
        }
        if ($labId > 0) {
            $row = sqlQuery(
                "SELECT * FROM procedure_providers WHERE ppid = ? AND active = 1 LIMIT 1",
                [$labId]
            );
            if (!empty($row)) {
                return new self($row);
            }
        }
        return self::resolveDefault();
    }

    /**
     * Devuelve el primer proveedor activo (fallback global).
     */
    public static function resolveDefault(): self
    {
        $row = sqlQuery(
            "SELECT * FROM procedure_providers WHERE active = 1 ORDER BY ppid ASC LIMIT 1"
        );
        if (!empty($row)) {
            return new self($row);
        }
        return new self();
    }
}
