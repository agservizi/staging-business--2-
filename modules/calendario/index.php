<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/db_connect.php';
require_once __DIR__ . '/../../includes/helpers.php';

require_role('Admin', 'Operatore', 'Manager');
$pageTitle = 'Calendario';

require_once __DIR__ . '/../../app/Services/GoogleCalendarService.php';

use App\Services\GoogleCalendarService;

$calendarService = new GoogleCalendarService();

// Array dei mesi
$monthNames = [
    1 => 'Gennaio', 2 => 'Febbraio', 3 => 'Marzo', 4 => 'Aprile', 5 => 'Maggio', 6 => 'Giugno',
    7 => 'Luglio', 8 => 'Agosto', 9 => 'Settembre', 10 => 'Ottobre', 11 => 'Novembre', 12 => 'Dicembre'
];

// Parametri per la vista
$view = $_GET['view'] ?? 'month';
$year = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$month = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');
$day = isset($_GET['day']) ? (int)$_GET['day'] : (int)date('d');

// Calcola date in base alla vista
switch ($view) {
    case 'day':
        $currentDate = new DateTime("$year-$month-$day");
        $startOfDay = new DateTime("$year-$month-$day 00:00:00");
        $endOfDay = new DateTime("$year-$month-$day 23:59:59");
        break;
    case 'month':
        $startOfMonth = new DateTime("$year-$month-01 00:00:00");
        $endOfMonth = $startOfMonth->modify('last day of this month')->setTime(23, 59, 59);
        break;
    case 'year':
        $startOfYear = new DateTime("$year-01-01 00:00:00");
        $endOfYear = new DateTime("$year-12-31 23:59:59");
        break;
}

// Eventi in base alla vista
switch ($view) {
    case 'day':
        $eventsStart = $startOfDay;
        $eventsEnd = $endOfDay;
        break;
    case 'month':
        $eventsStart = $startOfMonth;
        $eventsEnd = $endOfMonth;
        break;
    case 'year':
        $eventsStart = $startOfYear;
        $eventsEnd = $endOfYear;
        break;
}

// Eventi da Google Calendar
$googleEvents = [];
if ($calendarService->isEnabled()) {
    try {
        $googleEvents = $calendarService->listEvents($eventsStart, $eventsEnd);
    } catch (Exception $e) {
        error_log('Errore nel recupero eventi Google Calendar: ' . $e->getMessage());
    }
}

// Eventi dagli appuntamenti locali
$appuntamentiStmt = $pdo->prepare("
    SELECT sa.id, sa.titolo, sa.data_inizio, sa.data_fine, sa.luogo, sa.responsabile,
           c.nome, c.cognome, c.ragione_sociale
    FROM servizi_appuntamenti sa
    LEFT JOIN clienti c ON sa.cliente_id = c.id
    WHERE sa.stato = 'Confermato'
    AND sa.data_inizio >= ? AND sa.data_inizio <= ?
    ORDER BY sa.data_inizio
");
$appuntamentiStmt->execute([$eventsStart->format('Y-m-d H:i:s'), $eventsEnd->format('Y-m-d H:i:s')]);
$appuntamenti = $appuntamentiStmt->fetchAll(PDO::FETCH_ASSOC);

// Organizza eventi per giorno (per vista mese)
global $eventsByDay;
$eventsByDay = [];
if ($view === 'month') {
    foreach ($googleEvents as $event) {
        $startDate = new DateTime($event['start']['dateTime'] ?? $event['start']['date']);
        $day = (int)$startDate->format('j');
        if (!isset($eventsByDay[$day])) {
            $eventsByDay[$day] = [];
        }
        $eventsByDay[$day][] = [
            'type' => 'google',
            'title' => $event['summary'] ?? 'Senza titolo',
            'time' => $startDate->format('H:i'),
            'location' => $event['location'] ?? '',
            'description' => $event['description'] ?? '',
        ];
    }

    foreach ($appuntamenti as $app) {
        $startDate = new DateTime($app['data_inizio']);
        $day = (int)$startDate->format('j');
        if (!isset($eventsByDay[$day])) {
            $eventsByDay[$day] = [];
        }
        $eventsByDay[$day][] = [
            'type' => 'appointment',
            'title' => $app['titolo'],
            'time' => $startDate->format('H:i'),
            'location' => $app['luogo'] ?? '',
            'client' => trim(($app['nome'] ?? '') . ' ' . ($app['cognome'] ?? '') . ' ' . ($app['ragione_sociale'] ?? '')),
            'id' => $app['id'],
        ];
    }
}

// Navigazione in base alla vista
switch ($view) {
    case 'day':
        $prevDate = clone $currentDate;
        $prevDate->modify('-1 day');
        $nextDate = clone $currentDate;
        $nextDate->modify('+1 day');
        $displayTitle = $currentDate->format('d') . ' ' . $monthNames[$month] . ' ' . $year;
        break;
    case 'month':
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
        $displayTitle = $monthNames[$month] . ' ' . $year;
        break;
    case 'year':
        $prevYearNav = $year - 1;
        $nextYearNav = $year + 1;
        $displayTitle = $year;
        break;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<div class="flex-grow-1 d-flex flex-column min-vh-100">
    <?php require_once __DIR__ . '/../../includes/topbar.php'; ?>
    <main class="content-wrapper">
        <div class="page-toolbar mb-4">
            <div>
                <h1 class="h3 mb-0">Calendario</h1>
                <p class="text-muted mb-0">Visualizza appuntamenti e eventi dal calendario Google.</p>
            </div>
            <div class="toolbar-actions">
                <div class="btn-group" role="group" aria-label="Vista calendario">
                    <input type="radio" class="btn-check" name="view" id="view-day" value="day" autocomplete="off" <?php echo (isset($_GET['view']) && $_GET['view'] === 'day') ? 'checked' : ''; ?>>
                    <label class="btn btn-outline-secondary btn-sm" for="view-day">
                        <i class="fa-solid fa-calendar-day me-1"></i>Giorno
                    </label>
                    <input type="radio" class="btn-check" name="view" id="view-month" value="month" autocomplete="off" <?php echo (!isset($_GET['view']) || $_GET['view'] === 'month') ? 'checked' : ''; ?>>
                    <label class="btn btn-outline-secondary btn-sm" for="view-month">
                        <i class="fa-solid fa-calendar me-1"></i>Mese
                    </label>
                    <input type="radio" class="btn-check" name="view" id="view-year" value="year" autocomplete="off" <?php echo (isset($_GET['view']) && $_GET['view'] === 'year') ? 'checked' : ''; ?>>
                    <label class="btn btn-outline-secondary btn-sm" for="view-year">
                        <i class="fa-solid fa-calendar-alt me-1"></i>Anno
                    </label>
                </div>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center gap-3">
                    <div class="btn-group" role="group">
                        <?php if ($view === 'day'): ?>
                            <button class="btn btn-outline-secondary btn-sm" onclick="navigateDate(-1)" title="Giorno precedente">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>
                        <?php elseif ($view === 'month'): ?>
                            <button class="btn btn-outline-secondary btn-sm" onclick="navigateMonth(-1)" title="Mese precedente">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>
                        <?php elseif ($view === 'year'): ?>
                            <button class="btn btn-outline-secondary btn-sm" onclick="navigateYear(-1)" title="Anno precedente">
                                <i class="fa-solid fa-chevron-left"></i>
                            </button>
                        <?php endif; ?>

                        <button class="btn btn-outline-secondary btn-sm px-3" onclick="goToToday()" title="Vai a oggi">
                            Oggi
                        </button>

                        <?php if ($view === 'day'): ?>
                            <button class="btn btn-outline-secondary btn-sm" onclick="navigateDate(1)" title="Giorno successivo">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        <?php elseif ($view === 'month'): ?>
                            <button class="btn btn-outline-secondary btn-sm" onclick="navigateMonth(1)" title="Mese successivo">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        <?php elseif ($view === 'year'): ?>
                            <button class="btn btn-outline-secondary btn-sm" onclick="navigateYear(1)" title="Anno successivo">
                                <i class="fa-solid fa-chevron-right"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                    <h2 class="h4 mb-0 fw-bold"><?php echo $displayTitle; ?></h2>
                </div>
                <div class="d-flex align-items-center gap-2">
                    <small class="text-muted">Legenda:</small>
                    <span class="badge bg-primary"><i class="fa-solid fa-calendar me-1"></i>Google Calendar</span>
                    <span class="badge bg-success"><i class="fa-solid fa-user-clock me-1"></i>Appuntamenti</span>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if ($view === 'month'): ?>
                    <?php include 'views/month_view.php'; ?>
                <?php elseif ($view === 'day'): ?>
                    <?php include 'views/day_view.php'; ?>
                <?php elseif ($view === 'year'): ?>
                    <?php include 'views/year_view.php'; ?>
                <?php endif; ?>
            </div>
        </div>

        <!-- Modale per dettagli giorno -->
        <div class="modal fade" id="dayModal" tabindex="-1" aria-labelledby="dayModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="dayModalLabel">Eventi del giorno</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="dayModalBody">
                        <!-- Contenuto dinamico -->
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                        <button type="button" class="btn btn-primary" id="viewDayBtn">Visualizza giorno completo</button>
                    </div>
                </div>
            </div>
        </div>

    </main>
</div>

<style>
.calendar-container {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}

.calendar-header {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
}

.calendar-day-header {
    padding: 12px 8px;
    text-align: center;
    font-weight: 600;
    color: #495057;
    font-size: 14px;
}

.calendar-grid {
    display: grid;
    grid-template-columns: repeat(7, 1fr);
    grid-template-rows: repeat(6, 1fr);
    min-height: 600px;
}

.calendar-day {
    border-right: 1px solid #dee2e6;
    border-bottom: 1px solid #dee2e6;
    padding: 8px;
    position: relative;
    min-height: 100px;
    cursor: pointer;
    transition: background-color 0.2s;
}

.calendar-day:hover {
    background-color: #f8f9fa;
}

.calendar-day.today {
    background-color: #e3f2fd;
}

.calendar-day.today .day-number {
    background-color: #2196f3;
    color: white;
    border-radius: 50%;
    width: 24px;
    height: 24px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 12px;
    font-weight: bold;
}

.calendar-day.other-month {
    background-color: #f8f9fa;
    color: #adb5bd;
}

.day-number {
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 4px;
}

.day-events {
    display: flex;
    flex-direction: column;
    gap: 2px;
}

.event-item {
    font-size: 11px;
    padding: 2px 4px;
    border-radius: 3px;
    color: white;
    display: flex;
    align-items: center;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.google-event {
    background-color: #1976d2;
}

.appointment-event {
    background-color: #388e3c;
}

.event-time {
    font-weight: bold;
    margin-right: 4px;
}

.more-events {
    font-size: 10px;
    color: #6c757d;
    font-style: italic;
    margin-top: 2px;
}

@media (max-width: 768px) {
    .calendar-grid {
        min-height: 400px;
    }

    .calendar-day {
        min-height: 80px;
        padding: 4px;
    }

    .day-number {
        font-size: 12px;
    }

    .event-item {
        font-size: 10px;
    }
}

/* Vista Giorno */
.day-view-container {
    max-width: 800px;
    margin: 0 auto;
}

.day-timeline {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
}

.timeline-hour {
    display: flex;
    border-bottom: 1px solid #f8f9fa;
    min-height: 60px;
}

.timeline-hour.current-hour {
    background-color: #e3f2fd;
}

.timeline-hour:last-child {
    border-bottom: none;
}

.hour-label {
    width: 80px;
    padding: 8px 12px;
    background-color: #f8f9fa;
    font-weight: 600;
    font-size: 14px;
    display: flex;
    align-items: center;
    border-right: 1px solid #dee2e6;
}

.hour-events {
    flex: 1;
    padding: 4px;
    display: flex;
    flex-direction: column;
    gap: 4px;
}

.day-event-item {
    padding: 8px 12px;
    border-radius: 6px;
    color: white;
    font-size: 14px;
}

.day-event-item.google-event {
    background-color: #1976d2;
}

.day-event-item.appointment-event {
    background-color: #388e3c;
}

.event-header {
    display: flex;
    align-items: center;
    margin-bottom: 4px;
}

.event-location,
.event-client {
    font-size: 12px;
    margin-bottom: 2px;
    opacity: 0.9;
}

.event-actions {
    margin-top: 8px;
}

/* Vista Anno */
.year-view-container {
    padding: 20px;
}

.year-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 16px;
}

.year-month-card {
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 16px;
    cursor: pointer;
    transition: all 0.2s;
    background-color: white;
}

.year-month-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    transform: translateY(-2px);
}

.year-month-card.current-month {
    border-color: #2196f3;
    background-color: #e3f2fd;
}

.month-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
}

.month-name {
    margin: 0;
    font-weight: 600;
}

.month-event-count {
    font-size: 12px;
}

.month-events {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
}

.event-indicator {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 4px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 500;
}

.google-indicator {
    background-color: #e3f2fd;
    color: #1976d2;
}

.appointment-indicator {
    background-color: #e8f5e8;
    color: #388e3c;
}

.no-events {
    text-align: center;
    padding: 20px 0;
    color: #6c757d;
}

@media (max-width: 768px) {
    .year-grid {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .year-month-card {
        padding: 12px;
    }

    .day-timeline {
        font-size: 14px;
    }

    .hour-label {
        width: 60px;
        font-size: 12px;
    }
}

/* Modale eventi giorno */
.day-events-list {
    max-height: 400px;
    overflow-y: auto;
}

.event-item-modal.google-event {
    background-color: #e3f2fd;
    border-left: 4px solid #1976d2;
}

.event-item-modal.appointment-event {
    background-color: #e8f5e8;
    border-left: 4px solid #388e3c;
}

.event-item-modal h6 {
    color: #333;
    margin-bottom: 8px;
}

.event-item-modal p {
    margin-bottom: 4px;
    font-size: 14px;
}
</style>

<script>
function navigateDate(delta) {
    const url = new URL(window.location);
    let year = parseInt(url.searchParams.get('year') || '<?php echo $year; ?>');
    let month = parseInt(url.searchParams.get('month') || '<?php echo $month; ?>');
    let day = parseInt(url.searchParams.get('day') || '<?php echo $day; ?>');

    const currentDate = new Date(year, month - 1, day);
    currentDate.setDate(currentDate.getDate() + delta);

    url.searchParams.set('year', currentDate.getFullYear());
    url.searchParams.set('month', currentDate.getMonth() + 1);
    url.searchParams.set('day', currentDate.getDate());
    window.location.href = url.toString();
}

function navigateMonth(delta) {
    const url = new URL(window.location);
    let year = parseInt(url.searchParams.get('year') || '<?php echo $year; ?>');
    let month = parseInt(url.searchParams.get('month') || '<?php echo $month; ?>');

    month += delta;
    if (month < 1) {
        month = 12;
        year--;
    } else if (month > 12) {
        month = 1;
        year++;
    }

    url.searchParams.set('year', year);
    url.searchParams.set('month', month);
    url.searchParams.delete('day'); // Rimuovi giorno quando navighi per mesi
    window.location.href = url.toString();
}

function navigateYear(delta) {
    const url = new URL(window.location);
    let year = parseInt(url.searchParams.get('year') || '<?php echo $year; ?>');

    year += delta;
    url.searchParams.set('year', year);
    url.searchParams.delete('month');
    url.searchParams.delete('day');
    window.location.href = url.toString();
}

function goToToday() {
    const today = new Date();
    const url = new URL(window.location);
    const view = url.searchParams.get('view') || 'month';

    url.searchParams.set('year', today.getFullYear());

    if (view === 'day') {
        url.searchParams.set('month', today.getMonth() + 1);
        url.searchParams.set('day', today.getDate());
    } else if (view === 'month') {
        url.searchParams.set('month', today.getMonth() + 1);
        url.searchParams.delete('day');
    } else {
        url.searchParams.delete('month');
        url.searchParams.delete('day');
    }

    window.location.href = url.toString();
}

function switchToMonth(monthNum) {
    const url = new URL(window.location);
    url.searchParams.set('view', 'month');
    url.searchParams.set('month', monthNum);
    url.searchParams.delete('day');
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    // Gestione cambio vista
    document.querySelectorAll('input[name="view"]').forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                const url = new URL(window.location);
                url.searchParams.set('view', this.value);

                // Resetta parametri specifici per vista
                if (this.value === 'year') {
                    url.searchParams.delete('month');
                    url.searchParams.delete('day');
                } else if (this.value === 'month') {
                    url.searchParams.delete('day');
                }

                window.location.href = url.toString();
            }
        });
    });

    // Gestione click sui giorni (solo per vista mese)
    document.querySelectorAll('.calendar-day').forEach(day => {
        day.addEventListener('click', function() {
            const dayNumber = this.dataset.day;
            if (dayNumber && !this.classList.contains('other-month')) {
                showDayEvents(dayNumber);
            }
        });
    });

    // Gestione pulsante "Visualizza giorno completo"
    document.getElementById('viewDayBtn').addEventListener('click', function() {
        const dayNumber = this.dataset.day;
        if (dayNumber) {
            const url = new URL(window.location);
            url.searchParams.set('view', 'day');
            url.searchParams.set('day', dayNumber);
            window.location.href = url.toString();
        }
    });
});

function showDayEvents(dayNumber) {
    const year = <?php echo $year; ?>;
    const month = <?php echo $month; ?>;

    // Costruisci la data
    const selectedDate = new Date(year, month - 1, dayNumber);
    const dateString = selectedDate.toLocaleDateString('it-IT', {
        weekday: 'long',
        year: 'numeric',
        month: 'long',
        day: 'numeric'
    });

    // Eventi del mese (da PHP)
    const monthEvents = <?php echo json_encode($eventsByDay); ?>;
    const dayEvents = monthEvents[dayNumber] || [];

    let modalContent = `<h6>${dateString}</h6>`;

    if (dayEvents.length === 0) {
        modalContent += '<p class="text-muted">Nessun evento programmato per questo giorno.</p>';
    } else {
        modalContent += '<div class="day-events-list">';
        dayEvents.forEach(event => {
            const eventClass = event.type === 'google' ? 'google-event' : 'appointment-event';
            const eventIcon = event.type === 'google' ? 'fa-calendar' : 'fa-user-clock';

            modalContent += `
                <div class="event-item-modal ${eventClass} mb-3 p-3 rounded">
                    <div class="d-flex align-items-start">
                        <i class="fa-solid ${eventIcon} me-3 mt-1"></i>
                        <div class="flex-grow-1">
                            <h6 class="mb-1">${event.title}</h6>
                            <p class="mb-1"><strong>Ora:</strong> ${event.time}</p>
                            ${event.location ? `<p class="mb-1"><i class="fa-solid fa-location-dot me-1"></i>${event.location}</p>` : ''}
                            ${event.type === 'appointment' && event.client ? `<p class="mb-1"><i class="fa-solid fa-building me-1"></i>${event.client}</p>` : ''}
                            ${event.type === 'appointment' ? `<a href="<?php echo base_url('modules/servizi/appuntamenti/view.php?id='); ?>${event.id}" class="btn btn-sm btn-outline-primary mt-2">Visualizza appuntamento</a>` : ''}
                        </div>
                    </div>
                </div>
            `;
        });
        modalContent += '</div>';
    }

    document.getElementById('dayModalLabel').textContent = `Eventi del ${dayNumber} <?php echo $monthNames[$month]; ?> ${year}`;
    document.getElementById('dayModalBody').innerHTML = modalContent;
    document.getElementById('viewDayBtn').dataset.day = dayNumber;

    const modal = new bootstrap.Modal(document.getElementById('dayModal'));
    modal.show();
}
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>