<?php
$config = ai_assistant_frontend_config();
if (!$config['enabled']) {
    return;
}

$endpoint = $config['endpoint'] ?? base_url('api/ai/advisor.php');
$defaultPeriod = $config['defaultPeriod'] ?? 'last30';
$user = $config['user'] ?? ['name' => current_user_display_name(), 'role' => (string) ($_SESSION['role'] ?? '')];
?>
<div class="ai-assistant" data-ai-assistant data-endpoint="<?php echo sanitize_output($endpoint); ?>" data-default-period="<?php echo sanitize_output($defaultPeriod); ?>" data-user-name="<?php echo sanitize_output($user['name'] ?? ''); ?>" data-user-role="<?php echo sanitize_output($user['role'] ?? ''); ?>">
    <button class="ai-assistant-toggle" type="button" aria-expanded="false" data-ai-toggle>
        <span class="ai-assistant-toggle-icon" aria-hidden="true"><i class="fa-solid fa-sparkles"></i></span>
        <span class="ai-assistant-toggle-label">Consigli AI</span>
    </button>
    <section class="ai-assistant-panel" aria-live="polite" aria-label="Assistente AI" hidden>
        <header class="ai-assistant-panel-header">
            <div>
                <p class="ai-assistant-panel-title mb-0">Assistente operativo</p>
                <small class="text-muted">Consigli contestuali sul periodo selezionato</small>
            </div>
            <div class="ai-assistant-panel-actions">
                <button class="btn btn-link btn-sm" type="button" data-ai-refresh title="Rigenera con dati aggiornati">
                    <i class="fa-solid fa-rotate"></i>
                </button>
                <button class="btn btn-link btn-sm" type="button" data-ai-close aria-label="Chiudi assistente">
                    <i class="fa-solid fa-xmark"></i>
                </button>
            </div>
        </header>
        <div class="ai-assistant-status" data-ai-status hidden></div>
        <div class="ai-assistant-context" data-ai-context hidden></div>
        <div class="ai-assistant-log" data-ai-log aria-live="polite"></div>
        <form class="ai-assistant-form" data-ai-form>
            <div class="ai-assistant-controls">
                <label class="form-label" for="aiAssistantPeriod">Periodo di analisi</label>
                <select class="form-select" id="aiAssistantPeriod" name="period" data-ai-period>
                    <option value="last7">Ultimi 7 giorni</option>
                    <option value="last30">Ultimi 30 giorni</option>
                    <option value="thisMonth">Mese in corso</option>
                    <option value="lastMonth">Mese precedente</option>
                    <option value="thisQuarter">Trimestre in corso</option>
                    <option value="year">Anno in corso</option>
                    <option value="custom">Personalizzato…</option>
                </select>
                <div class="ai-assistant-custom-range" data-ai-custom-range hidden>
                    <div>
                        <label class="form-label" for="aiAssistantStart">Dal</label>
                        <input class="form-control" id="aiAssistantStart" type="date" name="customStart" data-ai-custom-start>
                    </div>
                    <div>
                        <label class="form-label" for="aiAssistantEnd">Al</label>
                        <input class="form-control" id="aiAssistantEnd" type="date" name="customEnd" data-ai-custom-end>
                    </div>
                </div>
            </div>
            <div class="ai-assistant-question-group">
                <label class="form-label" for="aiAssistantQuestion">Chiedi qualcosa</label>
                <textarea class="form-control" id="aiAssistantQuestion" rows="3" placeholder="Esempio: quali priorità dovrei seguire questa settimana?" data-ai-question required></textarea>
            </div>
            <div class="ai-assistant-actions">
                <button class="btn btn-outline-secondary btn-sm" type="button" data-ai-hint="Dammi una panoramica sintetica e indica 3 azioni ad alto impatto per oggi.">
                    Suggerisci una domanda
                </button>
                <div class="ms-auto d-flex gap-2">
                    <button class="btn btn-outline-warning" type="button" data-ai-thinking-toggle hidden>
                        <i class="fa-solid fa-eye"></i>
                        <span>Mostra ragionamento</span>
                    </button>
                    <button class="btn btn-warning" type="submit">
                        <i class="fa-solid fa-paper-plane me-2"></i>Chiedi aiuto
                    </button>
                </div>
            </div>
        </form>
        <footer class="ai-assistant-footer">
            <small class="text-muted" data-ai-timestamp></small>
        </footer>
        <details class="ai-assistant-thinking" data-ai-thinking hidden>
            <summary>Ragionamento interno</summary>
            <pre class="mb-0" data-ai-thinking-content></pre>
        </details>
    </section>
</div>
