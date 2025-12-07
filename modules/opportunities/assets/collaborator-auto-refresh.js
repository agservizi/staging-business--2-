// Reload collaborator pages every hour unless disabled and show a visual cue.
(function () {
    const ONE_HOUR_MS = 60 * 60 * 1000;
    const INDICATOR_DELAY_MS = 2500;

    if (window.COLLABORATOR_AUTO_REFRESH_DISABLED) {
        return;
    }

    if (window.CollaboratorAutoRefreshInitialized) {
        return;
    }
    window.CollaboratorAutoRefreshInitialized = true;

    const ensureIndicator = () => {
        if (window.CollaboratorAutoRefreshIndicator) {
            return window.CollaboratorAutoRefreshIndicator;
        }

        const indicator = document.createElement('div');
        indicator.className = 'collab-refresh-indicator';
        indicator.setAttribute('role', 'status');
        indicator.setAttribute('aria-live', 'polite');
        indicator.setAttribute('aria-hidden', 'true');
        indicator.innerHTML = [
            '<div class="collab-refresh-indicator__content">',
            '<div class="collab-refresh-indicator__label">',
            '<span class="collab-refresh-indicator__title">Aggiornamento automatico</span>',
            '<span class="collab-refresh-indicator__subtitle">Stiamo ricaricando i dati per te…</span>',
            '</div>',
            '<div class="collab-refresh-indicator__bar" aria-hidden="true"></div>',
            '</div>'
        ].join('');

        const topbar = document.querySelector('.topbar');
        if (topbar && typeof topbar.insertAdjacentElement === 'function') {
            topbar.insertAdjacentElement('afterend', indicator);
        } else {
            document.body.prepend(indicator);
        }

        window.CollaboratorAutoRefreshIndicator = indicator;
        return indicator;
    };

    const triggerIndicator = () => {
        const indicator = ensureIndicator();
        indicator.classList.add('is-active');
        indicator.setAttribute('aria-hidden', 'false');
    };

    const beginReload = () => {
        triggerIndicator();
        window.setTimeout(() => {
            window.location.reload();
        }, INDICATOR_DELAY_MS);
    };

    const scheduleRefresh = () => {
        window.setTimeout(() => {
            if (document.visibilityState === 'hidden') {
                scheduleRefresh();
                return;
            }

            if (typeof window.beforeCollaboratorAutoRefresh === 'function') {
                try {
                    const result = window.beforeCollaboratorAutoRefresh();
                    if (result === false) {
                        scheduleRefresh();
                        return;
                    }
                } catch (error) {
                    // If hook fails we still proceed with refresh.
                }
            }

            beginReload();
        }, ONE_HOUR_MS);
    };

    scheduleRefresh();
})();
