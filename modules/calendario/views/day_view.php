                <div class="day-view-container">
                    <div class="day-timeline">
                        <?php
                        // Organizza eventi per ora
                        $eventsByHour = [];
                        for ($hour = 0; $hour < 24; $hour++) {
                            $eventsByHour[$hour] = [];
                        }

                        // Aggiungi eventi Google Calendar
                        foreach ($googleEvents as $event) {
                            $startDateTime = new DateTime($event['start']['dateTime'] ?? $event['start']['date']);
                            if ($startDateTime->format('Y-m-d') === $currentDate->format('Y-m-d')) {
                                $hour = (int)$startDateTime->format('H');
                                $eventsByHour[$hour][] = [
                                    'type' => 'google',
                                    'title' => $event['summary'] ?? 'Senza titolo',
                                    'time' => $startDateTime->format('H:i'),
                                    'endTime' => isset($event['end']['dateTime']) ? (new DateTime($event['end']['dateTime']))->format('H:i') : null,
                                    'location' => $event['location'] ?? '',
                                    'description' => $event['description'] ?? '',
                                ];
                            }
                        }

                        // Aggiungi appuntamenti
                        foreach ($appuntamenti as $app) {
                            $startDateTime = new DateTime($app['data_inizio']);
                            if ($startDateTime->format('Y-m-d') === $currentDate->format('Y-m-d')) {
                                $hour = (int)$startDateTime->format('H');
                                $endDateTime = $app['data_fine'] ? new DateTime($app['data_fine']) : null;
                                $eventsByHour[$hour][] = [
                                    'type' => 'appointment',
                                    'title' => $app['titolo'],
                                    'time' => $startDateTime->format('H:i'),
                                    'endTime' => $endDateTime ? $endDateTime->format('H:i') : null,
                                    'location' => $app['luogo'] ?? '',
                                    'client' => trim(($app['nome'] ?? '') . ' ' . ($app['cognome'] ?? '') . ' ' . ($app['ragione_sociale'] ?? '')),
                                    'id' => $app['id'],
                                ];
                            }
                        }

                        // Mostra timeline oraria
                        for ($hour = 0; $hour < 24; $hour++) {
                            $hourLabel = str_pad($hour, 2, '0', STR_PAD_LEFT) . ':00';
                            $isCurrentHour = $hour === (int)date('H') && $currentDate->format('Y-m-d') === date('Y-m-d');
                            $hourEvents = $eventsByHour[$hour];
                            ?>
                            <div class="timeline-hour <?php echo $isCurrentHour ? 'current-hour' : ''; ?>">
                                <div class="hour-label"><?php echo $hourLabel; ?></div>
                                <div class="hour-events">
                                    <?php if (!empty($hourEvents)): ?>
                                        <?php foreach ($hourEvents as $event): ?>
                                            <div class="day-event-item <?php echo $event['type'] === 'google' ? 'google-event' : 'appointment-event'; ?>">
                                                <div class="event-header">
                                                    <i class="fa-solid <?php echo $event['type'] === 'google' ? 'fa-calendar' : 'fa-user-clock'; ?> me-2"></i>
                                                    <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                                                    <small class="text-muted ms-2">
                                                        <?php echo $event['time']; ?>
                                                        <?php if ($event['endTime']): ?>
                                                            - <?php echo $event['endTime']; ?>
                                                        <?php endif; ?>
                                                    </small>
                                                </div>
                                                <?php if (!empty($event['location'])): ?>
                                                    <div class="event-location">
                                                        <i class="fa-solid fa-location-dot me-1"></i>
                                                        <?php echo htmlspecialchars($event['location']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($event['type'] === 'appointment' && !empty($event['client'])): ?>
                                                    <div class="event-client">
                                                        <i class="fa-solid fa-building me-1"></i>
                                                        <?php echo htmlspecialchars($event['client']); ?>
                                                    </div>
                                                <?php endif; ?>
                                                <?php if ($event['type'] === 'appointment'): ?>
                                                    <div class="event-actions mt-2">
                                                        <a href="<?php echo base_url('modules/servizi/appuntamenti/view.php?id=' . $event['id']); ?>" class="btn btn-sm btn-outline-primary">
                                                            <i class="fa-solid fa-eye me-1"></i>Visualizza
                                                        </a>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php
                        }
                        ?>
                    </div>
                </div>