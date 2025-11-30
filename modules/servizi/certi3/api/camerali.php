<?php
require_once '../../../includes/db_connect.php';
require_once '../../../includes/auth.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo non consentito']);
    exit;
}

// Verifica CSRF
if (!isset($_POST['csrf_token']) || !verify_csrf_token($_POST['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Token CSRF non valido']);
    exit;
}

// Verifica autenticazione
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Utente non autenticato']);
    exit;
}

try {
    // Validazione dati
    $required_fields = ['categoria', 'tipo', 'partita_iva', 'ragione_sociale', 'comune', 'provincia'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Campo obbligatorio mancante: $field");
        }
    }

    // Validazione partita IVA
    if (!preg_match('/^[0-9]{11}$/', $_POST['partita_iva'])) {
        throw new Exception('Partita IVA non valida');
    }

    // Validazione codice fiscale se fornito
    if (!empty($_POST['codice_fiscale']) && !preg_match('/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/', $_POST['codice_fiscale'])) {
        throw new Exception('Codice fiscale non valido');
    }

    // Prepara dati per il database
    $dati_richiesta = [
        'partita_iva' => $_POST['partita_iva'],
        'ragione_sociale' => trim($_POST['ragione_sociale']),
        'codice_fiscale' => !empty($_POST['codice_fiscale']) ? strtoupper($_POST['codice_fiscale']) : null,
        'comune' => trim($_POST['comune']),
        'provincia' => strtoupper(trim($_POST['provincia'])),
        'anno_riferimento' => !empty($_POST['anno_riferimento']) ? (int)$_POST['anno_riferimento'] : null,
        'numero_rea' => !empty($_POST['numero_rea']) ? trim($_POST['numero_rea']) : null,
        'note' => !empty($_POST['note']) ? trim($_POST['note']) : null,
        'urgente' => isset($_POST['urgente']) ? 1 : 0
    ];

    $data = [
        'user_id' => $_SESSION['user_id'],
        'categoria' => 'camerali',
        'tipo' => $_POST['tipo'],
        'dati_richiesta' => json_encode($dati_richiesta),
        'stato' => 'pending', // In attesa di approvazione admin
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s')
    ];

    // Inserisci nel database
    $columns = implode(', ', array_keys($data));
    $placeholders = ':' . implode(', :', array_keys($data));

    $stmt = $pdo->prepare("INSERT INTO certificati_richieste ($columns) VALUES ($placeholders)");

    foreach ($data as $key => $value) {
        $stmt->bindValue(":$key", $value);
    }

    $stmt->execute();
    $request_id = $pdo->lastInsertId();

    echo json_encode([
        'success' => true,
        'message' => 'Richiesta certificato camerale inviata con successo',
        'request_id' => $request_id
    ]);

} catch (Exception $e) {
    error_log("Errore richiesta certificato camerale: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
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

    /**
     * Ottieni tipi di documento disponibili per certificati camerali
     */
    public function getTipiDocumentoDisponibili() {
        try {
            $documenti = $this->getDocumentiDisponibili();

            $tipi = [];
            foreach ($documenti as $doc) {
                // Filtro solo documenti camerali
                if ($this->isDocumentoCamerale($doc)) {
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

    private function isDocumentoCamerale($documento) {
        $categoria = strtolower($documento['category'] ?? '');

        // Controlla se la categoria è "Camerali"
        return $categoria === 'camerali';
    }

    private function categorizzaDocumento($documento) {
        $nome = strtolower($documento['name'] ?? '');

        if (strpos($nome, 'visura') !== false) {
            if (strpos($nome, 'storica') !== false) return 'visura_storica';
            if (strpos($nome, 'ordinaria') !== false) return 'visura_ordinaria';
            return 'visura';
        }
        if (strpos($nome, 'bilancio') !== false) return 'bilancio';
        if (strpos($nome, 'statuto') !== false) return 'statuto';
        if (strpos($nome, 'atto') !== false) return 'atto';
        if (strpos($nome, 'certificato') !== false) {
            if (strpos($nome, 'iscrizione') !== false) return 'certificato_iscrizione';
            if (strpos($nome, 'artigiano') !== false) return 'certificato_artigiano';
            if (strpos($nome, 'storico') !== false) return 'certificato_storico';
            return 'certificato';
        }

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
        $response = $this->getRequest('/documents');
        return $response['data'] ?? [];
    }

    private function preparaDatiRichiesta($dati) {
        // Mappa i dati del form ai campi API
        $search = [];

        if (!empty($dati['piva'])) {
            $search['field0'] = $dati['piva']; // Partita IVA
        }

        if (!empty($dati['codice_fiscale'])) {
            $search['field1'] = $dati['codice_fiscale']; // Codice fiscale
        }

        if (!empty($dati['ragione_sociale'])) {
            $search['field2'] = $dati['ragione_sociale']; // Ragione sociale
        }

        if (!empty($dati['cciaa'])) {
            $search['field3'] = $dati['cciaa']; // Provincia CCIA
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
    error_log('Richiesta get_tipi camerali ricevuta');

    // I tipi documento sono pubblici, non richiedono autenticazione
    $api = new CameraliAPI();
    $result = $api->getTipiDocumentoDisponibili();

    // Debug: log del risultato
    error_log('Risultato get_tipi camerali: ' . json_encode($result));

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['categoria']) && $_POST['categoria'] === 'camerali') {
    require_once __DIR__ . '/../../../../includes/auth.php';

    $api = new CameraliAPI();
    $result = $api->richiediCertificato($_POST['tipo'] ?? 'visura', $_POST);

    // Salva nel database
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO certificati_richieste (user_id, categoria, tipo, dati_richiesta, request_id, stato, errore) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $_SESSION['user_id'],
        'camerali',
        $_POST['tipo'] ?? '',
        json_encode($_POST),
        $result['request_id'] ?? null,
        $result['success'] ? 'pending' : 'error',
        $result['error'] ?? null
    ]);

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'download' && isset($_GET['request_id'])) {
    require_once __DIR__ . '/../../../../includes/auth.php';

    $api = new CameraliAPI();
    $result = $api->scaricaDocumento($_GET['request_id'], $_GET['document_index'] ?? 0);

    if ($result['success']) {
        header('Content-Type: ' . $result['mime_type']);
        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
        echo $result['content'];
    } else {
        http_response_code(404);
        echo json_encode($result);
    }
    exit;
}
?>