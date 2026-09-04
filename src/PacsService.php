<?php
/**
 * PacsService.php
 *
 * Centralizes operations against the Orthanc REST API for each
 * PACS provider (see PacsProvider). Replaces the curl calls previously
 * scattered across cron_sync_pacs.php, save.php (report) and src/Imaging.php.
 *
 * All operations receive the provider explicitly and use its
 * credentials and REST endpoint.
 */

namespace App;

class PacsService
{
    /**
     * Makes an HTTP request against the provider's Orthanc and returns
     * [httpCode, body, error].
     */
    private static function request(PacsProvider $p, string $method, string $path, ?string $body, array $headers = [], int $timeout = 45): array
    {
        $url = $p->apiUrl() . $path;

        // Normalize headers into a key => value map (last one wins),
        // supporting both standalone keys and "Key: value" strings.
        $hmap = ['Content-Type' => 'application/json'];
        if ($path === '/instances') {
            $hmap['Content-Type'] = 'application/dicom';
        }
        foreach ($headers as $h) {
            if (!is_string($h)) {
                continue;
            }
            $pos = strpos($h, ':');
            if ($pos !== false) {
                $key = trim(substr($h, 0, $pos));
                $hmap[$key] = trim(substr($h, $pos + 1));
            } else {
                $hmap[$h] = '1';
            }
        }
        $allHeaders = [];
        foreach ($hmap as $k => $v) {
            $allHeaders[] = (strtolower($k) === 'content-type') ? "Content-Type: {$v}" : "{$k}: {$v}";
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD        => $p->basicAuth(),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER     => $allHeaders,
        ];
        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }
        } elseif ($method === 'GET' && $body === null) {
            $options[CURLOPT_HTTPGET] = true;
        } elseif ($method === 'DELETE') {
            $options[CURLOPT_CUSTOMREQUEST] = 'DELETE';
        }

        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        return [$httpCode, $response, $err];
    }

    /**
     * Uploads a native DICOM file (.dcm) to Orthanc.
     *
     * @return array{success:bool, instance_id:?string, study_id:?string, series_id:?string, message:string}
     */
    public static function uploadNativeDicom(PacsProvider $p, string $binary): array
    {
        [$code, $body, $err] = self::request($p, 'POST', '/instances', $binary);
        if ($code === 200 && $body) {
            $data = json_decode($body, true) ?: [];
            return [
                'success' => true,
                'instance_id' => $data['ID'] ?? null,
                'study_id' => $data['ParentStudy'] ?? null,
                'series_id' => $data['ParentSeries'] ?? null,
                'message' => xl('DICOM uploaded successfully'),
            ];
        }
        return [
            'success' => false,
            'instance_id' => null,
            'study_id' => null,
            'series_id' => null,
            'message' => "Orthanc HTTP {$code}: " . ($err ?: (string)$body),
        ];
    }

    /**
     * Uploads a complete compressed ZIP archive to the PACS as a single unit.
     *
     * Unlike a native DICOM file (which is uploaded instance by instance),
     * the container (zip) is sent as-is; the destination PACS is responsible
     * for extracting and importing the studies it contains.
     *
     * @return array{success:bool, message:string}
     */
    public static function uploadZipDicom(PacsProvider $p, string $zipBinary): array
    {
        [$code, $body, $err] = self::request($p, 'POST', '/instances', $zipBinary, ['Content-Type: application/zip'], 120);
        if ($code >= 200 && $code < 300 && $body !== '') {
            return ['success' => true, 'message' => xl('Zip archive uploaded to PACS')];
        }
        return ['success' => false, 'message' => "PACS HTTP {$code}: " . ($err ?: (string)$body)];
    }

    /**
     * Reassigns the StudyInstanceUID of an already uploaded instance (used to
     * group native DICOMs into the order's study). Orthanc creates a new
     * instance with the substituted UID and removes the original when it no
     * longer shares a study with another instance. `Force` overwrites the
     * value inherited from the parent module.
     *
     * @return array{success:bool, instance_id:?string, study_id:?string, series_id:?string, message:string}
     */
    public static function modifyInstance(PacsProvider $p, string $instanceId, string $studyUid): array
    {
        $payload = json_encode([
            'Replace' => ['StudyInstanceUID' => $studyUid],
            'Force'   => true,
        ]);
        [$code, $body, $err] = self::request($p, 'POST', '/instances/' . rawurlencode($instanceId) . '/modify', $payload, [], 45);
        if ($code === 200 && $body) {
            $data = json_decode($body, true) ?: [];
            return [
                'success' => true,
                'instance_id' => $data['ID'] ?? null,
                'study_id' => $data['ParentStudy'] ?? null,
                'series_id' => $data['ParentSeries'] ?? null,
                'message' => xl('Study reassigned to order'),
            ];
        }
        return [
            'success' => false,
            'instance_id' => null,
            'study_id' => null,
            'series_id' => null,
            'message' => "Orthanc HTTP {$code}: " . ($err ?: (string)$body),
        ];
    }

    /**
     * Converts a standard image (JPG/PNG/WEBP) to DICOM and uploads it to Orthanc.
     *
     * @param array $tags DICOM tags (PatientID, PatientName, StudyDate, Modality, ...)
     * @return array{success:bool, instance_id:?string, study_id:?string, series_id:?string, message:string}
     */
    public static function uploadImageAsDicom(PacsProvider $p, string $imageBinary, array $tags): array
    {
        // Detect actual MIME type by magic bytes
        $mime = 'image/jpeg';
        if (str_starts_with($imageBinary, "\x89PNG")) {
            $mime = 'image/png';
        } elseif (str_starts_with($imageBinary, "\xFF\xD8")) {
            $mime = 'image/jpeg';
        } elseif (str_starts_with($imageBinary, "RIFF") && str_contains(substr($imageBinary, 0, 16), "WEBP")) {
            $mime = 'image/webp';
        }

        $base64Data = 'data:' . $mime . ';base64,' . base64_encode($imageBinary);
        $payload = json_encode(['Tags' => $tags, 'Content' => $base64Data]);

        [$code, $body, $err] = self::request($p, 'POST', '/tools/create-dicom', $payload);
        if ($code === 200 && $body) {
            $data = json_decode($body, true) ?: [];
            return [
                'success' => true,
                'instance_id' => $data['ID'] ?? null,
                'study_id' => $data['ParentStudy'] ?? null,
                'series_id' => $data['ParentSeries'] ?? null,
                'message' => xl('Image converted and uploaded'),
            ];
        }
        return [
            'success' => false,
            'instance_id' => null,
            'study_id' => null,
            'series_id' => null,
            'message' => "Orthanc HTTP {$code}: " . ($err ?: (string)$body),
        ];
    }

    /**
     * Uploads a PDF as "Encapsulated PDF" (modality OT) attached to an
     * existing study (using its internal PACS id as Parent). In Orthanc, the
     * internal id is the orthanc_study_id, NOT the StudyInstanceUID, which triggers
     * the error "Trying to override a value inherited from a parent module".
     *
     * @return array{success:bool, message:string}
     */
    public static function uploadEncapsulatedPdf(PacsProvider $p, string $pdfContent, int $pid, string $parentStudy, array $tags): array
    {
        $payloadArr = [
            'Tags' => $tags,
            'Content' => 'data:application/pdf;base64,' . base64_encode($pdfContent),
        ];
        if ($parentStudy !== '') {
            $payloadArr['Parent'] = $parentStudy;
        }
        $payload = json_encode($payloadArr);

        [$code, $body, $err] = self::request($p, 'POST', '/tools/create-dicom', $payload, ['Content-Type: application/json'], 15);
        if ($code >= 200 && $code < 300) {
            return ['success' => true, 'message' => xl('PDF encapsulated uploaded')];
        }
        return ['success' => false, 'message' => "Orthanc HTTP {$code}: " . ($err ?: (string)$body)];
    }

    /**
     * Gets the actual StudyInstanceUID from an Orthanc instance or study.
     */
    public static function fetchStudyUid(PacsProvider $p, string $id): ?string
    {
        [$code, $body] = self::request($p, 'GET', '/studies/' . rawurlencode($id), null, [], 10);
        if ($code === 200 && $body) {
            $data = json_decode($body, true) ?: [];
            if (!empty($data['MainDicomTags']['StudyInstanceUID'])) {
                return (string)$data['MainDicomTags']['StudyInstanceUID'];
            }
        }

        [$code2, $body2] = self::request($p, 'GET', '/instances/' . rawurlencode($id) . '/tags?simplify', null, [], 10);
        if ($code2 === 200 && $body2) {
            $tags = json_decode($body2, true) ?: [];
            if (!empty($tags['StudyInstanceUID'])) {
                return (string)$tags['StudyInstanceUID'];
            }
        }

        return null;
    }

    /**
     * Lists a patient's studies in Orthanc (by PatientID) and returns
     * the normalized list. Root path /tools/find, Query PatientID.
     *
     * @return array<int, array{instance_id:string, study_id:string, series_id:string, study_uid:string, study_date:string, accession:string, description:string, modality:string}>
     */
    public static function findStudies(PacsProvider $p, string $patientId, ?string $dni = null): array
    {
        $results = [];
        $idsToSearch = array_unique(array_filter([
            $patientId,
            $dni,
            (string)ltrim($patientId, '0'),
            (string)str_pad($patientId, 4, '0', STR_PAD_LEFT),
            (string)str_pad($patientId, 6, '0', STR_PAD_LEFT),
            (string)str_pad($patientId, 8, '0', STR_PAD_LEFT),
        ]));

        foreach ($idsToSearch as $idVal) {
            $payload = json_encode([
                'Level' => 'Study',
                'Query' => ['PatientID' => (string)$idVal],
                'Expand' => true,
            ]);
            [$code, $body] = self::request($p, 'POST', '/tools/find', $payload, [], 4);
            if ($code !== 200 || empty($body)) {
                continue;
            }
            foreach ((json_decode($body, true) ?: []) as $study) {
                $mainDicom = $study['MainDicomTags'] ?? [];
                $studyUid = $mainDicom['StudyInstanceUID'] ?? ($study['ID'] ?? '');
                $studyDate = (string)($mainDicom['StudyDate'] ?? '');
                $modality = $mainDicom['ModalitiesInStudy'] ?? ($mainDicom['Modality'] ?? 'OT');
                $results[] = [
                    'instance_id' => (string)($study['ID'] ?? ''),
                    'study_id' => (string)($study['ID'] ?? ''),
                    'series_id' => '',
                    'study_uid' => $studyUid,
                    'study_date' => $studyDate,
                    'accession' => (string)($mainDicom['AccessionNumber'] ?? ''),
                    'description' => (string)($mainDicom['StudyDescription'] ?? ''),
                    'modality' => is_array($modality) ? implode(', ', $modality) : (string)$modality,
                ];
            }
        }

        return $results;
    }
}
