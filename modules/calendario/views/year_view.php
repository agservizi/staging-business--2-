                <div class="year-view-container">
                    <div class="year-grid">
                        <?php
                        // Organizza eventi per mese
                        $eventsByMonth = [];
                        for ($m = 1; $m <= 12; $m++) {
                            $eventsByMonth[$m] = ['google' => 0, 'appointments' => 0];
                        }

                        // Conta eventi Google Calendar per mese
                        foreach ($googleEvents as $event) {
                            $startDate = new DateTime($event['start']['dateTime'] ?? $event['start']['date']);
                            $month = (int)$startDate->format('n');
                            $eventsByMonth[$month]['google']++;
                        }

                        // Conta appuntamenti per mese
                        foreach ($appuntamenti as $app) {
                            $startDate = new DateTime($app['data_inizio']);
                            $month = (int)$startDate->format('n');
                            $eventsByMonth[$month]['appointments']++;
                        }

                        // Mostra griglia mesi
                        foreach ($monthNames as $monthNum => $monthName) {
                            $monthEvents = $eventsByMonth[$monthNum];
                            $totalEvents = $monthEvents['google'] + $monthEvents['appointments'];
                            $isCurrentMonth = $monthNum === (int)date('n') && $year === (int)date('Y');
                            ?>
                            <div class="year-month-card <?php echo $isCurrentMonth ? 'current-month' : ''; ?>"
                                 onclick="switchToMonth(<?php echo $monthNum; ?>)">
                                <div class="month-header">
                                    <h6 class="month-name"><?php echo $monthName; ?></h6>
                                    <?php if ($totalEvents > 0): ?>
                                        <span class="month-event-count badge bg-primary"><?php echo $totalEvents; ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="month-body">
                                    <?php if ($totalEvents > 0): ?>
                                        <div class="month-events">
                                            <?php if ($monthEvents['google'] > 0): ?>
                                                <div class="event-indicator google-indicator">
                                                    <i class="fa-solid fa-calendar"></i>
                                                    <span><?php echo $monthEvents['google']; ?></span>
                                                </div>
                                            <?php endif; ?>
                                            <?php if ($monthEvents['appointments'] > 0): ?>
                                                <div class="event-indicator appointment-indicator">
                                                    <i class="fa-solid fa-user-clock"></i>
                                                    <span><?php echo $monthEvents['appointments']; ?></span>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="no-events">
                                            <small class="text-muted">Nessun evento</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>