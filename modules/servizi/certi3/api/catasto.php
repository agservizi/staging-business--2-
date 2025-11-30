<?php
// API per certificati catastali - OpenAPI Catasto
require_once '../../../../includes/db_connect.php';
require_once '../../../../includes/helpers.php';
require_once '../../../../app/Services/ServiziWeb/OpenApiCatastoClient.php';

use App\Services\ServiziWeb\OpenApiCatastoClient;

class CatastoAPI {
    private OpenApiCatastoClient $client;

    public function __construct() {
        try {
            $this->client = new OpenApiCatastoClient();
        } catch (Exception $e) {
            throw new Exception("Errore inizializzazione client OpenAPI Catasto: " . $e->getMessage());
        }
    }

    /**
     * Richiedi certificato catastale
     */
    public function richiediCertificato($tipo, $dati) {
        try {
            // Prepara il payload per la richiesta
            $payload = $this->preparaDatiRichiesta($tipo, $dati);

            // Effettua la richiesta tramite OpenAPI Catasto
            $response = $this->client->createVisura($payload);

            return [
                'success' => true,
                'request_id' => $response['id'] ?? null,
                'state' => $response['state'] ?? 'pending',
                'tipo_richiesta' => $tipo,
                'dati_richiesta' => $payload
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Prepara i dati per la richiesta in base al tipo
     */
    private function preparaDatiRichiesta($tipo, $dati) {
        $basePayload = [
            'tipo_richiesta' => $tipo,
            'richiedente' => [
                'nome' => $dati['nome'] ?? '',
                'cognome' => $dati['cognome'] ?? '',
                'codice_fiscale' => $dati['codice_fiscale'] ?? ''
            ]
        ];

        switch ($tipo) {
            case 'visura_catastale':
                return array_merge($basePayload, [
                    'entita' => 'immobile',
                    'dati_catastali' => [
                        'comune' => $dati['comune'] ?? '',
                        'provincia' => $dati['provincia'] ?? '',
                        'foglio' => $dati['foglio'] ?? '',
                        'particella' => $dati['particella'] ?? '',
                        'subalterno' => $dati['subalterno'] ?? ''
                    ]
                ]);

            case 'visura_ipotecaria':
                return array_merge($basePayload, [
                    'entita' => 'immobile',
                    'dati_catastali' => [
                        'comune' => $dati['comune'] ?? '',
                        'provincia' => $dati['provincia'] ?? '',
                        'foglio' => $dati['foglio'] ?? '',
                        'particella' => $dati['particella'] ?? '',
                        'subalterno' => $dati['subalterno'] ?? ''
                    ],
                    'tipo_visura' => 'ipotecaria'
                ]);

            case 'visura_persona_fisica':
                return array_merge($basePayload, [
                    'entita' => 'persona',
                    'tipo_persona' => 'fisica',
                    'dati_persona' => [
                        'nome' => $dati['nome'] ?? '',
                        'cognome' => $dati['cognome'] ?? '',
                        'codice_fiscale' => $dati['codice_fiscale'] ?? '',
                        'data_nascita' => $dati['data_nascita'] ?? '',
                        'comune_nascita' => $dati['comune_nascita'] ?? ''
                    ]
                ]);

            case 'visura_persona_giuridica':
                return array_merge($basePayload, [
                    'entita' => 'persona',
                    'tipo_persona' => 'giuridica',
                    'dati_persona' => [
                        'ragione_sociale' => $dati['ragione_sociale'] ?? '',
                        'partita_iva' => $dati['partita_iva'] ?? '',
                        'codice_fiscale' => $dati['codice_fiscale'] ?? ''
                    ]
                ]);

            default:
                throw new Exception("Tipo certificato catastale '{$tipo}' non supportato");
        }
    }

    /**
     * Ottieni i tipi di certificato catastale disponibili
     */
    public function getTipiDocumentoDisponibili() {
        return [
            'success' => true,
            'tipi' => [
                [
                    'id' => 'visura_catastale',
                    'categoria' => 'visura_catastale',
                    'nome' => 'Visura Catastale Immobiliare',
                    'descrizione' => 'Visura catastale completa di un immobile'
                ],
                [
                    'id' => 'visura_ipotecaria',
                    'categoria' => 'visura_ipotecaria',
                    'nome' => 'Visura Ipotecaria',
                    'descrizione' => 'Visura ipotecaria di un immobile'
                ],
                [
                    'id' => 'visura_persona_fisica',
                    'categoria' => 'visura_persona_fisica',
                    'nome' => 'Visura Catastale Persona Fisica',
                    'descrizione' => 'Visura catastale per persona fisica'
                ],
                [
                    'id' => 'visura_persona_giuridica',
                    'categoria' => 'visura_persona_giuridica',
                    'nome' => 'Visura Catastale Persona Giuridica',
                    'descrizione' => 'Visura catastale per persona giuridica'
                ]
            ]
        ];
    }
}

// Utilizzo
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_tipi') {
    // Debug: log della richiesta
    error_log('Richiesta get_tipi catastali ricevuta');

    $api = new CatastoAPI();
    $result = $api->getTipiDocumentoDisponibili();

    // Debug: log del risultato
    error_log('Risultato get_tipi catastali: ' . json_encode($result));

    header('Content-Type: application/json');
    echo json_encode($result);
    exit;

} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['categoria']) && $_POST['categoria'] === 'catastali') {
    require_once __DIR__ . '/../../../../../../includes/auth.php';

    $api = new CatastoAPI();
    $result = $api->richiediCertificato($_POST['tipo'] ?? 'visura_catastale', $_POST);

    // Salva nel database
    global $pdo;
    $stmt = $pdo->prepare('INSERT INTO certificati_richieste (user_id, categoria, tipo, dati_richiesta, request_id, stato, errore) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $_SESSION['user_id'],
        'catastali',
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