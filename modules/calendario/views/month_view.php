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

                    // $eventsByDay è già calcolato globalmente
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