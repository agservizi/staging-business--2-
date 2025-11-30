(function () {
    'use strict';

    const form = document.getElementById('cafTrackingForm');
    if (!form) {
        return;
    }

    const input = document.getElementById('cafTrackingCode');
    const feedback = document.getElementById('cafTrackingFeedback');
    const loader = document.getElementById('cafTrackingLoader');
    const resultSection = document.getElementById('cafTrackingResult');
    const titleEl = document.getElementById('cafTrackingTitle');
    const statusEl = document.getElementById('cafTrackingStatus');
    const categoryEl = document.getElementById('cafTrackingCategory');
    const createdEl = document.getElementById('cafTrackingCreated');
    const updatedEl = document.getElementById('cafTrackingUpdated');
    const codeEl = document.getElementById('cafTrackingCodeLabel');
    const timelineContainer = document.getElementById('cafTrackingTimeline');
    const emptyTimeline = document.getElementById('cafTrackingTimelineEmpty');

    const config = window.CAFTrackingConfig || {};
    const endpoint = typeof config.endpoint === 'string' && config.endpoint !== ''
        ? config.endpoint
        : 'api/caf-patronato/public-tracking.php';

    const authorLabels = {
        admin: 'Team amministrazione',
        manager: 'Responsabile pratica',
        operatore: 'Operatore di sportello',
        patronato: 'Patronato Coresuite'
    };

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const code = (input?.value || '').trim();
        if (!code) {
            showFeedback('Inserisci un codice di tracking valido.', 'danger');
            return;
        }

        searchTracking(code);
    });

    async function searchTracking(code) {
        clearFeedback();
        toggleLoader(true);
        toggleResult(false);

        try {
            const response = await fetch(`${endpoint}?code=${encodeURIComponent(code)}`, {
                headers: {
                    'Accept': 'application/json'
                },
                credentials: 'same-origin'
            });

            const payload = await parseJson(response);
            if (!response.ok || !payload || typeof payload !== 'object') {
                const reason = payload && payload.error ? payload.error : 'Impossibile recuperare i dati.';
                throw new Error(reason);
            }

            if (!payload.data || typeof payload.data !== 'object') {
                throw new Error('Risposta inattesa dal servizio.');
            }

            renderResult(code, payload.data);
            toggleResult(true);
        } catch (error) {
            const message = error instanceof Error ? error.message : 'Errore imprevisto durante la ricerca.';
            showFeedback(message, 'danger');
        } finally {
            toggleLoader(false);
        }
    }

    function renderResult(code, data) {
        const practice = data.practice || {};
        const steps = Array.isArray(data.steps) ? data.steps : [];

        if (titleEl) {
            titleEl.textContent = practice.titolo || 'Pratica CAF/Patronato';
        }
        if (statusEl) {
            statusEl.textContent = practice.stato || 'In lavorazione';
        }
        if (categoryEl) {
            categoryEl.textContent = practice.categoria || 'CAF';
        }
        if (createdEl) {
            createdEl.textContent = formatDateTime(practice.data_creazione);
        }
        if (updatedEl) {
            updatedEl.textContent = formatDateTime(practice.data_aggiornamento);
        }
        if (codeEl) {
            codeEl.textContent = practice.tracking_code || code;
        }

        renderTimeline(steps);
    }

    function renderTimeline(steps) {
        if (!timelineContainer || !emptyTimeline) {
            return;
        }

        timelineContainer.innerHTML = '';

        if (!steps.length) {
            emptyTimeline.hidden = false;
            return;
        }

        emptyTimeline.hidden = true;

        steps.forEach(function (step) {
            const wrapper = document.createElement('article');
            wrapper.className = 'tracking-step';

            const timeEl = document.createElement('time');
            timeEl.setAttribute('datetime', step.data || '');
            timeEl.textContent = formatDateTime(step.data);
            wrapper.appendChild(timeEl);

            const heading = document.createElement('h3');
            heading.textContent = step.descrizione || '';
            wrapper.appendChild(heading);

            const author = document.createElement('small');
            author.innerHTML = `<i class="fa-solid fa-user"></i>${escapeHtml(authorLabels[step.autore] || 'Operatore certificato')}`;
            wrapper.appendChild(author);

            timelineContainer.appendChild(wrapper);
        });
    }

    function toggleLoader(active) {
        if (!loader) {
            return;
        }
        loader.classList.toggle('active', Boolean(active));
    }

    function toggleResult(visible) {
        if (!resultSection) {
            return;
        }
        resultSection.hidden = !visible;
    }

    async function parseJson(response) {
        const text = await response.text();
        if (!text) {
            return null;
        }
        try {
            return JSON.parse(text);
        } catch (error) {
            console.error('Invalid JSON response', error);
            return null;
        }
    }

    function showFeedback(message, type) {
        if (!feedback) {
            return;
        }
        feedback.innerHTML = `<div class="alert alert-${type}" role="alert">${escapeHtml(message)}</div>`;
    }

    function clearFeedback() {
        if (feedback) {
            feedback.innerHTML = '';
        }
    }

    function formatDateTime(value) {
        if (!value) {
            return '---';
        }
        const date = new Date(value.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return new Intl.DateTimeFormat('it-IT', {
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        }).format(date);
    }

    function escapeHtml(value) {
        const span = document.createElement('span');
        span.textContent = value == null ? '' : String(value);
        return span.innerHTML;
    }
})();
