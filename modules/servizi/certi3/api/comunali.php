<?php
// API per certificati comunali - DocuEngine
require_once '../../../includes/db_connect.php';
require_once '../../../includes/helpers.php';

class ComuniAPI {
    private $baseUrl;
    private $apiKey;
    private $token;

    public function __construct() {
        $this->baseUrl = env('DOCUENGINE_BASE_URL', 'https://docuengine.openapi.com');
        $this->apiKey = env('DOCUENGINE_API_KEY', '');
        $this->token = env('DOCUENGINE_TOKEN', '');
    }

    /**
     * Richiedi certificato anagrafico
     */
    public function richiediCertificatoAnagrafico($dati) {
        try {
            // Prima ottieni l'elenco documenti per trovare l'ID del certificato anagrafico
            $documenti = $this->getDocumentiDisponibili();

            // Cerca documento per certificato anagrafico
            $documentoId = $this->trovaDocumentoAnagrafico($documenti);

            if (!$documentoId) {
                throw new Exception('Documento certificato anagrafico non trovato');
            }

            // Prepara i dati per la richiesta
            $requestData = [
                'documentId' => $documentoId,
                'search' => $this->preparaDatiRichiesta($dati),
                'callback' => env('APP_URL') . '/api/callback/docuengine' // Per notifiche asincrone
            ];

            // Effettua la richiesta
            $response = $this->postRequest('/requests', $requestData);

            return [
                'success' => true,
                'request_id' => $response['id'] ?? null,
                'state' => $response['state'] ?? 'pending',
                'results' => $response['results'] ?? []
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verifica stato richiesta
     */
    public function verificaStatoRichiesta($requestId) {
        try {
            $response = $this->getRequest("/requests/{$requestId}");
            return [
                'success' => true,
                'state' => $response['state'] ?? 'unknown',
                'documents' => $response['documents'] ?? []
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Scarica documento
     */
    public function scaricaDocumento($requestId, $documentIndex = 0) {
        try {
            $response = $this->getRequest("/requests/{$requestId}/documents");

            if (!isset($response[$documentIndex])) {
                throw new Exception('Documento non trovato');
            }

            $document = $response[$documentIndex];
            $fileContent = file_get_contents($document['downloadUrl']);

            return [
                'success' => true,
                'filename' => $document['fileName'],
                'content' => $fileContent,
                'mime_type' => $document['mimeType']
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    private function getDocumentiDisponibili() {
        return $this->getRequest('/documents');
    }

    private function trovaDocumentoAnagrafico($documenti) {
        foreach ($documenti as $doc) {
            if (stripos($doc['name'] ?? '', 'anagrafico') !== false ||
                stripos($doc['name'] ?? '', 'certificato') !== false) {
                return $doc['id'];
            }
        }
        return null;
    }

    private function preparaDatiRichiesta($dati) {
        // Mappa i dati del form ai campi API
        $search = [];

        if (!empty($dati['codice_fiscale'])) {
            $search['field0'] = $dati['codice_fiscale']; // Tax code
        }

        if (!empty($dati['nome'])) {
            $search['field1'] = $dati['nome'];
        }

        if (!empty($dati['cognome'])) {
            $search['field2'] = $dati['cognome'];
        }

        if (!empty($dati['comune'])) {
            $search['field3'] = $dati['comune'];
        }

        if (!empty($dati['provincia'])) {
            $search['field4'] = $dati['provincia'];
        }

        return $search;
    }

    private function getRequest($endpoint) {
        return $this->makeRequest('GET', $endpoint);
    }

    private function postRequest($endpoint, $data) {
        return $this->makeRequest('POST', $endpoint, $data);
    }

    private function makeRequest($method, $endpoint, $data = null) {
        $url = $this->baseUrl . $endpoint;

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Content-Type: application/json',
            'Accept: application/json'
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        } elseif ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        } else {
            throw new Exception("API Error: HTTP {$httpCode} - {$response}");
        }
    }
}

// Utilizzo
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['categoria']) && $_POST['categoria'] === 'comunali') {
    require_once '../../../includes/auth.php';

    $api = new ComuniAPI();
    $result = $api->richiediCertificatoAnagrafico($_POST);

    // Salva nel database
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO certificati_richieste (user_id, categoria, tipo, dati_richiesta, request_id, stato, errore) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $_SESSION['user_id'],
        'comunali',
        $_POST['tipo'] ?? '',
        json_encode($_POST),
        $result['request_id'] ?? null,
        $result['success'] ? 'pending' : 'error',
        $result['error'] ?? null
    ]);

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}
?>