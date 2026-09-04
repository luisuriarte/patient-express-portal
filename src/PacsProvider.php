<?php
/**
 * PacsProvider.php
 *
 * Represents a PACS provider configured in OpenEMR as a "procedure
 * provider" (procedure_providers table). Each provider posts against its own
 * Orthanc with its own credentials.
 *
 * Endpoint convention (product decision):
 *   remote_host  = OHIF viewer base URL (full path),
 *                  e.g. https://imagenes.origen.ar/viewer
 *   remote_api   = Orthanc REST API base URL,
 *                  e.g. https://pacs.origen.ar   (or http://<host>:8042)
 *   wado_url     = DICOMweb WADO-RS base URL,
 *                  e.g. https://pacs.origen.ar/dicom-web
 *   login/password = Orthanc HTTP Basic credentials
 *
 * Provider resolution is based on a procedure order
 * (procedure_order.lab_id -> procedure_providers.ppid). If the order has no
 * provider or is invalid, the first active provider is used as fallback.
 */

namespace App;

class PacsProvider
{
    public int $ppid = 0;
    public string $name = '';
    public string $npi = '';
    public string $remoteHost = ''; // OHIF viewer (full path)
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
     * Loads values from a procedure_providers row.
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
     * Orthanc REST API base URL.
     */
    public function apiUrl(): string
    {
        return rtrim($this->remoteApi ?: (defined('ORTHANC_URL') ? ORTHANC_URL : 'http://127.0.0.1:8042'), '/');
    }

    /**
     * WADO-RS base URL (DICOMweb). If not defined, it is derived from the
     * REST API by replacing the port/path with /dicom-web on the same host.
     */
    public function wadoBaseUrl(): string
    {
        if ($this->wadoUrl !== '') {
            return rtrim($this->wadoUrl, '/');
        }
        // Derive from remote_api (https://pacs.origen.ar -> https://pacs.origen.ar/dicom-web)
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
     * OHIF viewer base URL. Reuses remote_host (which by convention is the
     * full viewer path). Falls back to the global constant.
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
     * HTTP Basic credentials in "user:pass" format.
     */
    public function basicAuth(): string
    {
        return $this->user . ':' . $this->pass;
    }

    /**
     * Indicates whether the provider has the minimum required configuration to operate.
     */
    public function isConfigured(): bool
    {
        return $this->ppid > 0 && $this->remoteApi !== '';
    }

    // =====================================================================
    // Resolution
    // =====================================================================

    /**
     * Resolves the provider associated with a procedure order.
     *
     * procedure_order.lab_id -> procedure_providers.ppid.
     * If the order does not exist or has no valid lab_id, it falls back to
     * the default active provider.
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
     * Returns the first active provider (global fallback).
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
