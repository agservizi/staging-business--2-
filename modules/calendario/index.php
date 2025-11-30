<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Calendario';

require_once __DIR__ . '/../../app/Services/GoogleCalendarService.php';

use App\Services\GoogleCalendarService;

$calendarService = new GoogleCalendarService();

// Parametri per il mese corrente
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

// Calcola inizio e fine mese
$startOfMonth = new DateTimeImmutable("{$year}-{$month}-01 00:00:00");
$endOfMonth = $startOfMonth->modify('last day of this month')->setTime(23, 59, 59);

// Eventi da Google Calendar
$googleEvents = [];
if ($calendarService->isEnabled()) {
    try {
        $googleEvents = $calendarService->listEvents($startOfMonth, $endOfMonth);
    } catch (Exception $e) {
        error_log('Errore nel recupero eventi Google Calendar: ' . $e->getMessage());
    }
}

// Eventi dagli appuntamenti locali (confermati)
$appuntamentiStmt = $pdo->prepare("
    SELECT sa.id, sa.titolo, sa.data_inizio, sa.data_fine, sa.luogo, sa.responsabile,
           c.nome, c.cognome, c.ragione_sociale
    FROM servizi_appuntamenti sa
    LEFT JOIN clienti c ON sa.cliente_id = c.id
    WHERE sa.stato = 'Confermato'
    AND sa.data_inizio >= ? AND sa.data_inizio <= ?
    ORDER BY sa.data_inizio
");
$appuntamentiStmt->execute([$startOfMonth->format('Y-m-d H:i:s'), $endOfMonth->format('Y-m-d H:i:s')]);
$appuntamenti = $appuntamentiStmt->fetchAll(PDO::FETCH_ASSOC);

// Navigazione mesi
$prevMonth = $month - 1;
$prevYear = $year;
if ($prevMonth < 1) {
    $prevMonth = 12;
    $prevYear--;
}
$nextMonth = $month + 1;
$nextYear = $year;
if ($nextMonth > 12) {
    $nextMonth = 1;
    $nextYear++;
}

$monthNames = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile', 5 => 'Maggio', 6 => 'Giugno',
    7 => 'Luglio', 8 => 'Agosto', 9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0">
                        <i class="fa-solid fa-calendar-days me-2"></i>
                        Calendario - <?php echo $monthNames[$month] . ' ' . $year; ?>
                    </h5>
                    <div class="btn-group">
                        <a href="?year=<?php echo $prevYear; ?>&month=<?php echo $prevMonth; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-chevron-left"></i>
                        </a>
                        <a href="?year=<?php echo $nextYear; ?>&month=<?php echo $nextMonth; ?>" class="btn btn-outline-secondary btn-sm">
                            <i class="fa-solid fa-chevron-right"></i>
                        </a>
                    </div>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Eventi Google Calendar</h6>
                            <?php if (empty($googleEvents)): ?>
                                <p class="text-muted">Nessun evento trovato in Google Calendar per questo mese.</p>
                            <?php else: ?>
                                <div class="list-group">
                                    <?php foreach ($googleEvents as $event): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($event['summary'] ?? 'Senza titolo'); ?></h6>
                                                <small><?php echo date('d/m/Y H:i', strtotime($event['start']['dateTime'] ?? $event['start']['date'])); ?></small>
                                            </div>
                                            <?php if (!empty($event['location'])): ?>
                                                <p class="mb-1"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($event['location']); ?></p>
                                            <?php endif; ?>
                                            <?php if (!empty($event['description'])): ?>
                                                <small><?php echo htmlspecialchars(substr($event['description'], 0, 100)); ?>...</small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="col-md-6">
                            <h6>Appuntamenti Confermati</h6>
                            <?php if (empty($appuntamenti)): ?>
                                <p class="text-muted">Nessun appuntamento confermato per questo mese.</p>
                            <?php else: ?>
                                <div class="list-group">
                                    <?php foreach ($appuntamenti as $app): ?>
                                        <div class="list-group-item">
                                            <div class="d-flex w-100 justify-content-between">
                                                <h6 class="mb-1"><?php echo htmlspecialchars($app['titolo']); ?></h6>
                                                <small><?php echo date('d/m/Y H:i', strtotime($app['data_inizio'])); ?></small>
                                            </div>
                                            <p class="mb-1">
                                                <i class="fa-solid fa-user"></i> <?php echo htmlspecialchars($app['responsabile']); ?>
                                                <?php if (!empty($app['nome']) || !empty($app['cognome']) || !empty($app['ragione_sociale'])): ?>
                                                    <br><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars(trim(($app['nome'] ?? '') . ' ' . ($app['cognome'] ?? '') . ' ' . ($app['ragione_sociale'] ?? ''))); ?>
                                                <?php endif; ?>
                                            </p>
                                            <?php if (!empty($app['luogo'])): ?>
                                                <p class="mb-1"><i class="fa-solid fa-location-dot"></i> <?php echo htmlspecialchars($app['luogo']); ?></p>
                                            <?php endif; ?>
                                            <a href="<?php echo base_url('modules/servizi/appuntamenti/view.php?id=' . $app['id']); ?>" class="btn btn-sm btn-outline-primary">Visualizza</a>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>