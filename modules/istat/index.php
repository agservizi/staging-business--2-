<?php
require_once '../../includes/header.php';
require_once '../../includes/auth.php';
require_once '../../includes/db_connect.php';
require_once '../../includes/helpers.php';

$current_page = 'ISTAT Webservices';
$pageTitle = 'ISTAT Webservices';

// Funzione per chiamare API ISTAT
function callIstatApi($endpoint, $params = []) {
    $baseUrl = 'https://esploradati.istat.it/SDMXWS/rest/';
    $url = $baseUrl . $endpoint;

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200) {
        return ['error' => 'Errore nella chiamata API: ' . $httpCode];
    }

    return json_decode($response, true);
}

// Gestione richieste
$datasets = [];
$error = '';
$data = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['get_datasets'])) {
        // Ottieni lista dataset
        $result = callIstatApi('dataflow/IT1');
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $datasets = $result['data']['dataflows'] ?? [];
        }
    } elseif (isset($_POST['get_data']) && !empty($_POST['dataset_id'])) {
        // Ottieni dati per un dataset specifico
        $datasetId = $_POST['dataset_id'];
        $result = callIstatApi('data/IT1/' . $datasetId . '/all');
        if (isset($result['error'])) {
            $error = $result['error'];
        } else {
            $data = $result;
        }
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h4 class="card-title mb-0">ISTAT Webservices</h4>
                </div>
                <div class="card-body">
                    <?php if ($error): ?>
                        <div class="alert alert-danger">
                            <?php echo htmlspecialchars($error); ?>
                        </div>
                    <?php endif; ?>

                    <div class="row">
                        <div class="col-md-6">
                            <form method="post">
                                <button type="submit" name="get_datasets" class="btn btn-primary">
                                    <i class="fa-solid fa-list"></i> Carica Dataset Disponibili
                                </button>
                            </form>
                        </div>
                    </div>

                    <?php if (!empty($datasets)): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h5>Dataset Disponibili</h5>
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Nome</th>
                                                <th>Descrizione</th>
                                                <th>Azioni</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($datasets as $dataset): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($dataset['id'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($dataset['name'] ?? ''); ?></td>
                                                    <td><?php echo htmlspecialchars($dataset['description'] ?? ''); ?></td>
                                                    <td>
                                                        <form method="post" style="display: inline;">
                                                            <input type="hidden" name="dataset_id" value="<?php echo htmlspecialchars($dataset['id'] ?? ''); ?>">
                                                            <button type="submit" name="get_data" class="btn btn-sm btn-info">
                                                                <i class="fa-solid fa-eye"></i> Visualizza Dati
                                                            </button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($data): ?>
                        <div class="row mt-4">
                            <div class="col-12">
                                <h5>Dati del Dataset</h5>
                                <pre><?php echo json_encode($data, JSON_PRETTY_PRINT); ?></pre>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../../includes/footer.php'; ?>