<?php
$config = ai_assistant_frontend_config();
if (!$config['enabled']) {
    return;
}

$endpoint = $config['endpoint'] ?? base_url('api/ai/advisor.php');
$defaultPeriod = $config['defaultPeriod'] ?? 'last30';
$user = $config['user'] ?? ['name' => current_user_display_name(), 'role' => (string) ($_SESSION['role'] ?? '')];
$page = $config['page'] ?? ai_assistant_page_context();
?>
<div class="ai-assistant" data-ai-assistant data-endpoint="<?php echo sanitize_output($endpoint); ?>" data-default-period="<?php echo sanitize_output($defaultPeriod); ?>" data-user-name="<?php echo sanitize_output($user['name'] ?? ''); ?>" data-user-role="<?php echo sanitize_output($user['role'] ?? ''); ?>" data-page-title="<?php echo sanitize_output($page['title'] ?? ''); ?>" data-page-section="<?php echo sanitize_output($page['section'] ?? ''); ?>" data-page-description="<?php echo sanitize_output($page['description'] ?? ''); ?>" data-page-path="<?php echo sanitize_output($page['path'] ?? ''); ?>">
    <button class="ai-assistant-toggle" type="button" aria-expanded="false" data-ai-toggle title="Apri Assistente AI">
        <span class="ai-assistant-toggle-icon" aria-hidden="true">
            <i class="fa-solid fa-robot"></i>
        </span>
        <span class="ai-assistant-toggle-label">AI Assistant</span>
        <span class="ai-assistant-toggle-badge" data-ai-badge hidden>!</span>
    </button>

    <div class="ai-assistant-overlay" data-ai-overlay hidden></div>

    <aside class="ai-assistant-sidebar" data-ai-sidebar hidden>
        <header class="ai-assistant-sidebar-header">
            <h2 class="ai-assistant-sidebar-title">
                <i class="fa-solid fa-brain"></i>
                Assistente AI Operativo
            </h2>
            <p class="ai-assistant-sidebar-subtitle">Consigli intelligenti basati sui tuoi dati</p>
            <button class="ai-assistant-sidebar-close" type="button" data-ai-close aria-label="Chiudi">
                <i class="fa-solid fa-times"></i>
            </button>
        </header>

        <nav class="ai-assistant-nav">
            <button class="ai-assistant-nav-item active" data-ai-nav="chat">
                <i class="fa-solid fa-comments"></i>
                Chat
            </button>
            <button class="ai-assistant-nav-item" data-ai-nav="history">
                <i class="fa-solid fa-history"></i>
                Storico
            </button>
            <button class="ai-assistant-nav-item" data-ai-nav="suggestions">
                <i class="fa-solid fa-lightbulb"></i>
                Suggerimenti
            </button>
            <button class="ai-assistant-nav-item" data-ai-nav="settings">
                <i class="fa-solid fa-cog"></i>
                Impostazioni
            </button>
        </nav>

        <div class="ai-assistant-content">
            <!-- Chat Tab -->
            <div class="ai-assistant-tab" data-ai-tab="chat">
                <div class="ai-assistant-chat-messages" data-ai-messages>
                    <div class="ai-assistant-welcome">
                        <div class="ai-assistant-welcome-icon">
                            <i class="fa-solid fa-robot"></i>
                        </div>
                        <h3>Ciao <?php echo sanitize_output($user['name'] ?? 'Utente'); ?>!</h3>
                        <p>Sono il tuo assistente AI. Posso aiutarti con analisi, consigli e risposte alle tue domande sui dati aziendali.</p>
                    </div>
                </div>

                <form class="ai-assistant-chat-form" data-ai-form>
                    <div class="ai-assistant-chat-input-group">
                        <div class="ai-assistant-period-selector">
                            <label for="aiAssistantPeriod">Periodo:</label>
                            <select id="aiAssistantPeriod" name="period" data-ai-period>
                                <option value="last7">Ultimi 7 giorni</option>
                                <option value="last30" selected>Ultimi 30 giorni</option>
                                <option value="thisMonth">Mese corrente</option>
                                <option value="lastMonth">Mese scorso</option>
                                <option value="thisQuarter">Trimestre corrente</option>
                                <option value="year">Anno corrente</option>
                                <option value="custom">Personalizzato...</option>
                            </select>
                        </div>
                        <div class="ai-assistant-custom-range" data-ai-custom-range hidden>
                            <input type="date" name="customStart" data-ai-custom-start placeholder="Data inizio">
                            <input type="date" name="customEnd" data-ai-custom-end placeholder="Data fine">
                        </div>
                        <textarea
                            name="question"
                            data-ai-question
                            placeholder="Chiedimi qualcosa sui tuoi clienti, servizi, report..."
                            rows="2"
                            required
                        ></textarea>
                        <button type="submit" class="ai-assistant-send-btn" data-ai-submit>
                            <i class="fa-solid fa-paper-plane"></i>
                        </button>
                    </div>
                </form>

                <div class="ai-assistant-status" data-ai-status hidden>
                    <div class="ai-assistant-status-spinner">
                        <i class="fa-solid fa-spinner fa-spin"></i>
                    </div>
                    <span data-ai-status-text>Elaborazione...</span>
                </div>
            </div>

            <!-- History Tab -->
            <div class="ai-assistant-tab hidden" data-ai-tab="history">
                <div class="ai-assistant-history-list" data-ai-history-list>
                    <p class="text-muted">Nessuna conversazione recente.</p>
                </div>
            </div>

            <!-- Suggestions Tab -->
            <div class="ai-assistant-tab hidden" data-ai-tab="suggestions">
                <div class="ai-assistant-suggestions">
                    <h4>Domande suggerite</h4>
                    <div class="ai-assistant-suggestion-list">
                        <button class="ai-assistant-suggestion-btn" data-ai-suggestion="Quanti clienti ho nel sistema?">
                            <i class="fa-solid fa-users"></i>
                            Quanti clienti ho?
                        </button>
                        <button class="ai-assistant-suggestion-btn" data-ai-suggestion="Quali servizi sono attivi questa settimana?">
                            <i class="fa-solid fa-truck"></i>
                            Servizi attivi
                        </button>
                        <button class="ai-assistant-suggestion-btn" data-ai-suggestion="Genera un report finanziario del mese">
                            <i class="fa-solid fa-chart-line"></i>
                            Report finanziario
                        </button>
                        <button class="ai-assistant-suggestion-btn" data-ai-suggestion="Quali ticket sono aperti?">
                            <i class="fa-solid fa-ticket-alt"></i>
                            Ticket aperti
                        </button>
                        <button class="ai-assistant-suggestion-btn" data-ai-suggestion="Come posso migliorare l'efficienza?">
                            <i class="fa-solid fa-lightbulb"></i>
                            Migliorare efficienza
                        </button>
                    </div>
                </div>
            </div>

            <!-- Settings Tab -->
            <div class="ai-assistant-tab hidden" data-ai-tab="settings">
                <div class="ai-assistant-settings">
                    <h4>Impostazioni</h4>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="aiThinkingToggle" data-ai-thinking-toggle checked>
                        <label class="form-check-label" for="aiThinkingToggle">
                            Mostra ragionamento interno
                        </label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="aiAutoRefreshToggle" data-ai-auto-refresh-toggle>
                        <label class="form-check-label" for="aiAutoRefreshToggle">
                            Aggiorna automaticamente i dati
                        </label>
                    </div>
                    <button class="btn btn-sm btn-outline-danger" data-ai-clear-history>
                        <i class="fa-solid fa-trash"></i>
                        Cancella storico
                    </button>
                </div>
            </div>
        </div>

        <footer class="ai-assistant-footer">
            <small class="text-muted">
                <i class="fa-solid fa-shield-alt"></i>
                Le tue conversazioni sono private e sicure.
            </small>
        </footer>
    </aside>
</div>
