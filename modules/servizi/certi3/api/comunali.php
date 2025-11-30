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
     * Richiedi certificato comunale
     */
    public function richiediCertificato($tipo, $dati) {
        try {
            // Prima ottieni l'elenco documenti per trovare l'ID del tipo richiesto
            $documenti = $this->getDocumentiDisponibili();

            // Trova documento per il tipo richiesto
            $documentoId = $this->trovaDocumentoPerTipo($documenti, $tipo);

            if (!$documentoId) {
                throw new Exception("Documento per tipo '{$tipo}' non trovato");
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
     * Richiedi certificato anagrafico (legacy)
     */
    public function richiediCertificatoAnagrafico($dati) {
        return $this->richiediCertificato('anagrafico', $dati);
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

    /**
     * Ottieni tipi di documento disponibili per certificati comunali
     */
    public function getTipiDocumentoDisponibili() {
        try {
            $documenti = $this->getDocumentiDisponibili();

            $tipi = [];
            foreach ($documenti as $doc) {
                // Filtro solo documenti comunali/anagrafici
                if ($this->isDocumentoComunale($doc)) {
                    $tipi[] = [
                        'id' => $doc['id'],
                        'nome' => $doc['name'] ?? 'Documento senza nome',
                        'descrizione' => $doc['description'] ?? '',
                        'categoria' => $this->categorizzaDocumento($doc)
                    ];
                }
            }

            return [
                'success' => true,
                'tipi' => $tipi
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'tipi' => []
            ];
        }
    }

    private function isDocumentoComunale($documento) {
        $nome = strtolower($documento['name'] ?? '');
        $descrizione = strtolower($documento['description'] ?? '');

        // Parole chiave per documenti comunali
        $keywords = [
            'comunale', 'comune', 'anagrafico', 'certificato',
            'residenza', 'stato civile', 'nascita', 'morte',
            'famiglia', 'convivenza'
        ];

        foreach ($keywords as $keyword) {
            if (strpos($nome, $keyword) !== false || strpos($descrizione, $keyword) !== false) {
                return true;
            }
        }

        return false;
    }

    private function categorizzaDocumento($documento) {
        $nome = strtolower($documento['name'] ?? '');

        if (strpos($nome, 'anagrafico') !== false) return 'anagrafico';
        if (strpos($nome, 'residenza') !== false) return 'residenza';
        if (strpos($nome, 'stato civile') !== false || strpos($nome, 'stato_civile') !== false) return 'stato_civile';
        if (strpos($nome, 'nascita') !== false) return 'nascita';
        if (strpos($nome, 'morte') !== false) return 'morte';
        if (strpos($nome, 'famiglia') !== false) return 'famiglia';
        if (strpos($nome, 'convivenza') !== false) return 'convivenza';

        return 'altro';
    }

    private function trovaDocumentoPerTipo($documenti, $tipo) {
        foreach ($documenti as $doc) {
            $categoria = $this->categorizzaDocumento($doc);
            if ($categoria === $tipo) {
                return $doc['id'];
            }
        }
        return null;
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
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_tipi') {
    // Debug: log della richiesta
    error_log('Richiesta get_tipi ricevuta');

    // I tipi documento sono pubblici, non richiedono autenticazione
    $api = new ComuniAPI();
    $result = $api->getTipiDocumentoDisponibili();

    // Debug: log del risultato
    error_log('Risultato get_tipi: ' . json_encode($result));

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['categoria']) && $_POST['categoria'] === 'comunali') {
    require_once '../../../includes/auth.php';

    $api = new ComuniAPI();
    $result = $api->richiediCertificato($_POST['tipo'] ?? 'anagrafico', $_POST);

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