<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/helpers.php';

$pageTitle = 'Tracking Pratiche CAF / Patronato';
$apiEndpoint = base_url('api/caf-patronato/public-tracking.php');
$assetVersion = defined('APP_VERSION') ? (string) constant('APP_VERSION') : '1.0.0';
?>
<!DOCTYPE html>
<html lang="it" data-bs-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> | Coresuite Business</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= asset('assets/vendor/bootstrap/css/bootstrap.min.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="<?= asset('assets/css/custom.css'); ?>">
    <link rel="stylesheet" href="<?= asset('assets/css/caf-tracking.css'); ?>">
    <meta name="description" content="Consulta lo stato della tua pratica CAF/Patronato inserendo il codice di tracking fornito dagli operatori Coresuite.">
</head>
<body class="tracking-body">
    <main class="tracking-shell">
        <header class="tracking-hero">
            <span class="tracking-label">
                <i class="fa-solid fa-compass"></i>
                Tracking pratiche CAF/Patronato
            </span>
            <h1>Segui l'avanzamento della tua pratica</h1>
            <p>Inserisci il codice che hai ricevuto via email o dal tuo operatore per consultare lo stato aggiornato e le comunicazioni pubbliche relative alla tua pratica.</p>
        </header>

        <section class="tracking-search-card">
            <form id="cafTrackingForm" class="tracking-form" role="search" aria-label="Ricerca tracking pratica">
                <div class="row g-3 align-items-end">
                    <div class="col-12 col-lg-8">
                        <label for="cafTrackingCode" class="form-label">Codice di tracking</label>
                        <input type="text" id="cafTrackingCode" name="tracking_code" class="form-control form-control-lg" placeholder="Es. CAF-PRAT-01234" autocomplete="off" required>
                    </div>
                    <div class="col-12 col-lg-4 d-grid">
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="fa-solid fa-magnifying-glass me-2"></i>
                            Cerca pratica
                        </button>
                    </div>
                </div>
            </form>
            <div id="cafTrackingFeedback" class="tracking-feedback" role="status" aria-live="polite"></div>
            <div id="cafTrackingLoader" class="tracking-loader" role="status" aria-live="polite">
                <div class="spinner-border" role="presentation"></div>
                <span>Verifica del codice in corso…</span>
            </div>
        </section>

        <section id="cafTrackingResult" class="tracking-result" hidden>
            <article class="tracking-card" aria-live="polite">
                <div class="d-flex align-items-center justify-content-between flex-wrap gap-3 mb-4">
                    <div>
                        <h2 class="h4 mb-1" id="cafTrackingTitle">Pratica CAF/Patronato</h2>
                        <div class="tracking-status-badge" aria-live="polite">
                            <i class="fa-solid fa-circle-notch"></i>
                            <span id="cafTrackingStatus">In lavorazione</span>
                        </div>
                    </div>
                    <div class="text-lg-end">
                        <span class="text-uppercase text-muted small d-block">Codice pratica</span>
                        <span class="fs-5 fw-semibold" id="cafTrackingCodeLabel">CAF-PRAT-00000</span>
                    </div>
                </div>

                <dl class="tracking-meta">
                    <div>
                        <dt>Tipologia</dt>
                        <dd id="cafTrackingCategory">CAF</dd>
                    </div>
                    <div>
                        <dt>Creata il</dt>
                        <dd id="cafTrackingCreated">---</dd>
                    </div>
                    <div>
                        <dt>Ultimo aggiornamento</dt>
                        <dd id="cafTrackingUpdated">---</dd>
                    </div>
                </dl>

                <div class="tracking-timeline" aria-live="polite" aria-label="Timeline degli eventi">
                    <div id="cafTrackingTimeline"></div>
                    <div id="cafTrackingTimelineEmpty" class="tracking-empty" hidden>
                        <i class="fa-regular fa-hourglass-half d-block mb-2"></i>
                        Nessun aggiornamento pubblico disponibile al momento. Verifica più tardi o contatta il tuo operatore di riferimento.
                    </div>
                </div>
            </article>
        </section>
    </main>

    <footer class="text-center py-4 text-muted small">
        &copy; <?= date('Y'); ?> Coresuite Business · Tutti i diritti riservati
    </footer>

    <script>
        window.CAFTrackingConfig = {
            endpoint: '<?= htmlspecialchars($apiEndpoint, ENT_QUOTES, 'UTF-8'); ?>'
        };
    </script>
    <script src="<?= asset('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
    <script src="<?= asset('assets/js/caf-tracking.js'); ?>"></script>
</body>
</html>
