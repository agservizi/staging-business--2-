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
                    <input type="radio" class="btn-check" name="view" id="view-month" autocomplete="off" checked>
                    <label class="btn btn-outline-secondary btn-sm" for="view-month">Mese</label>
                    <input type="radio" class="btn-check" name="view" id="view-week" autocomplete="off">
                    <label class="btn btn-outline-secondary btn-sm" for="view-week">Settimana</label>
                    <input type="radio" class="btn-check" name="view" id="view-day" autocomplete="off">
                    <label class="btn btn-outline-secondary btn-sm" for="view-day">Giorno</label>
                </div>
            </div>
        </div>

        <div class="card ag-card">
            <div class="card-header bg-transparent border-0 d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <button class="btn btn-link p-0 me-3" onclick="changeMonth(-1)">
                        <i class="fa-solid fa-chevron-left fa-lg"></i>
                    </button>
                    <h2 class="h4 mb-0"><?php echo $monthNames[$month] . ' ' . $year; ?></h2>
                    <button class="btn btn-link p-0 ms-3" onclick="changeMonth(1)">
                        <i class="fa-solid fa-chevron-right fa-lg"></i>
                    </button>
                </div>
                <div class="d-flex align-items-center">
                    <span class="badge bg-primary me-2">Google Calendar</span>
                    <span class="badge bg-success">Appuntamenti</span>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="calendar-container">
                    <?php
                    // Giorni della settimana
                    $daysOfWeek = ['Lun', 'Mar', 'Mer', 'Gio', 'Ven', 'Sab', 'Dom'];

                    // Primo giorno del mese
                    $firstDayOfMonth = new DateTime("$year-$month-01");
                    $firstDayOfWeek = (int)$firstDayOfMonth->format('N'); // 1 = Lun, 7 = Dom

                    // Ultimo giorno del mese
                    $lastDayOfMonth = new DateTime("$year-$month-" . $firstDayOfMonth->format('t'));
                    $totalDays = (int)$lastDayOfMonth->format('t');

                    // Giorni dal mese precedente per riempire la prima settimana
                    $prevMonth = clone $firstDayOfMonth;
                    $prevMonth->modify('-1 day');
                    $daysFromPrevMonth = $firstDayOfWeek - 1;

                    // Organizza eventi per giorno
                    $eventsByDay = [];
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
                    ?>

                    <!-- Header giorni della settimana -->
                    <div class="calendar-header">
                        <?php foreach ($daysOfWeek as $day): ?>
                            <div class="calendar-day-header"><?php echo $day; ?></div>
                        <?php endforeach; ?>
                    </div>

                    <!-- Griglia calendario -->
                    <div class="calendar-grid">
                        <?php
                        $currentDay = 1;
                        $nextMonthDay = 1;

                        // Riempie le celle vuote all'inizio
                        for ($i = 0; $i < $daysFromPrevMonth; $i++) {
                            echo '<div class="calendar-day other-month"></div>';
                        }

                        // Giorni del mese corrente
                        while ($currentDay <= $totalDays) {
                            $isToday = $currentDay == date('j') && $month == date('m') && $year == date('Y');
                            $dayEvents = $eventsByDay[$currentDay] ?? [];
                            $eventCount = count($dayEvents);

                            echo '<div class="calendar-day' . ($isToday ? ' today' : '') . '" data-day="' . $currentDay . '">';
                            echo '<div class="day-number">' . $currentDay . '</div>';
                            if ($eventCount > 0) {
                                echo '<div class="day-events">';
                                $shownEvents = array_slice($dayEvents, 0, 3); // Mostra max 3 eventi
                                foreach ($shownEvents as $event) {
                                    $eventClass = $event['type'] === 'google' ? 'google-event' : 'appointment-event';
                                    $eventIcon = $event['type'] === 'google' ? 'fa-calendar' : 'fa-user-clock';
                                    echo '<div class="event-item ' . $eventClass . '" title="' . htmlspecialchars($event['title']) . '">';
                                    echo '<i class="fa-solid ' . $eventIcon . ' me-1"></i>';
                                    echo '<span class="event-time">' . $event['time'] . '</span>';
                                    echo '<span class="event-title">' . htmlspecialchars(substr($event['title'], 0, 20)) . '</span>';
                                    echo '</div>';
                                }
                                if ($eventCount > 3) {
                                    echo '<div class="more-events">+' . ($eventCount - 3) . ' altri</div>';
                                }
                                echo '</div>';
                            }
                            echo '</div>';

                            $currentDay++;
                        }

                        // Riempie le celle vuote alla fine
                        $totalCells = $daysFromPrevMonth + $totalDays;
                        $remainingCells = 42 - $totalCells; // 6 settimane * 7 giorni
                        for ($i = 0; $i < $remainingCells; $i++) {
                            echo '<div class="calendar-day other-month"></div>';
                        }
                        ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modale per dettagli evento -->
        <div class="modal fade" id="eventModal" tabindex="-1" aria-labelledby="eventModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="eventModalLabel">Dettagli Evento</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="eventModalBody">
                        <!-- Contenuto dinamico -->
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
</style>

<script>
function changeMonth(delta) {
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
    window.location.href = url.toString();
}

document.addEventListener('DOMContentLoaded', function() {
    // Gestione click sui giorni per mostrare eventi
    document.querySelectorAll('.calendar-day').forEach(day => {
        day.addEventListener('click', function() {
            const dayNumber = this.dataset.day;
            if (dayNumber) {
                // Qui potresti aprire un modale con tutti gli eventi del giorno
                // Per ora, solo un placeholder
                console.log('Eventi del giorno:', dayNumber);
            }
        });
    });

    // Gestione cambio vista (placeholder)
    document.querySelectorAll('input[name="view"]').forEach(radio => {
        radio.addEventListener('change', function() {
            if (this.checked) {
                // Implementare cambio vista
                console.log('Cambio vista:', this.id);
            }
        });
    });
});
</script>

<?php
require_once __DIR__ . '/../../includes/footer.php';
?>