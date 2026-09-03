<?php
/**
 * PacsService.php
 *
 * Centraliza las operaciones contra la API REST de Orthanc para cada
 * proveedor PACS (ver PacsProvider). Reemplaza las llamadas a curl que antes
 * estaban dispersas en cron_sync_pacs.php, save.php (informe) y src/Imaging.php.
 *
 * Todas las operaciones reciben el proveedor explícitamente y usan sus
 * credenciales y endpoint REST.
 */

namespace App;

class PacsService
{
    /**
     * Realiza una petición HTTP contra el Orthanc del proveedor y devuelve
     * [httpCode, body, error].
     */
    private static function request(PacsProvider $p, string $method, string $path, ?string $body, array $headers = [], int $timeout = 45): array
    {
        $url = $p->apiUrl() . $path;
        $defaultHeaders = ['Content-Type: application/json'];
        if (str_starts_with($path, '/instances')) {
            $defaultHeaders = ['Content-Type: application/dicom'];
        }
        $allHeaders = array_merge($defaultHeaders, $headers);

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
     * Sube un archivo DICOM nativo (.dcm) a Orthanc.
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
     * Convierte una imagen estándar (JPG/PNG/WEBP) a DICOM y la sube a Orthanc.
     *
     * @param array $tags Tags DICOM (PatientID, PatientName, StudyDate, Modality, ...)
     * @return array{success:bool, instance_id:?string, study_id:?string, series_id:?string, message:string}
     */
    public static function uploadImageAsDicom(PacsProvider $p, string $imageBinary, array $tags): array
    {
        // Detectar MIME real por magic bytes
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
     * Sube un PDF como "Encapsulated PDF" (modalidad OT) adjunto a un estudio
     * existente (usando su id interno PACS como Parent). En Orthanc el id
     * interno es el orthanc_study_id, NO el StudyInstanceUID, que dispara el
     * error "Trying to override a value inherited from a parent module".
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
     * Obtiene el StudyInstanceUID real de una instancia o estudio de Orthanc.
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
     * Lista los estudios de un paciente en Orthanc (por PatientID) y devuelve
     * la lista normalizada. Ruta raíz /tools/find, Query PatientID.
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
