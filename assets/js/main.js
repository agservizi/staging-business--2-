document.addEventListener('DOMContentLoaded', () => {
    const sidebar = document.getElementById('sidebarMenu');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarMobileToggle = document.getElementById('sidebarMobileToggle');
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const mobileBreakpoint = window.matchMedia('(max-width: 991.98px)');
    const toastContainer = document.getElementById('csToastContainer');
    const initialFlashes = Array.isArray(window.CS_INITIAL_FLASHES) ? window.CS_INITIAL_FLASHES : [];
    const SIDEBAR_HOVER_CLASS = 'hover-expand';
    let sidebarHoverTimer = null;

    const toastVariants = {
        success: { className: 'text-bg-success text-white', icon: 'fa-circle-check' },
        info: { className: 'text-bg-info text-white', icon: 'fa-circle-info' },
        warning: { className: 'text-bg-warning text-white', icon: 'fa-triangle-exclamation' },
        danger: { className: 'text-bg-danger text-white', icon: 'fa-circle-exclamation' },
        error: { className: 'text-bg-danger text-white', icon: 'fa-circle-exclamation' }
    };

    const showToast = (message, type = 'info', options = {}) => {
        if (!toastContainer || typeof bootstrap === 'undefined' || !toastContainer.append) {
            return null;
        }

        const safeMessage = String(message ?? '').trim();
        if (safeMessage === '') {
            return null;
        }

        const variant = toastVariants[type] ?? { className: 'text-bg-secondary text-white', icon: 'fa-circle-info' };
        const delay = Number.isFinite(options.delay) ? options.delay : 6000;
        const url = typeof options.url === 'string' ? options.url.trim() : '';
        const onClick = typeof options.onClick === 'function' ? options.onClick : null;

        const toastEl = document.createElement('div');
        toastEl.className = `toast align-items-center shadow-sm fade ${variant.className}`;
        toastEl.setAttribute('role', 'status');
        toastEl.setAttribute('aria-live', 'polite');
        toastEl.setAttribute('aria-atomic', 'true');
        toastEl.dataset.bsAutohide = 'true';
        toastEl.dataset.bsDelay = String(Math.max(1000, delay));

        const inner = document.createElement('div');
        inner.className = 'd-flex align-items-center';

        const body = document.createElement('div');
        body.className = 'toast-body d-flex align-items-center gap-2 flex-grow-1 text-white';

        const icon = document.createElement('i');
        icon.className = `fa-solid ${variant.icon} flex-shrink-0`;
        icon.setAttribute('aria-hidden', 'true');

        const text = document.createElement('span');
        text.className = 'flex-grow-1';
        text.textContent = safeMessage;

        body.append(icon, text);

        const closeBtn = document.createElement('button');
        closeBtn.type = 'button';
        closeBtn.className = 'btn-close btn-close-white me-2 m-auto';
        closeBtn.dataset.bsDismiss = 'toast';
        closeBtn.setAttribute('aria-label', 'Chiudi notifica');

        inner.append(body, closeBtn);
        toastEl.append(inner);
        toastContainer.append(toastEl);

        const toastInstance = bootstrap.Toast.getOrCreateInstance(toastEl, {
            autohide: true,
            delay: Math.max(1000, delay)
        });

        requestAnimationFrame(() => {
            toastInstance.show();
        });

        if (url !== '' || onClick) {
            toastEl.style.cursor = 'pointer';
            toastEl.addEventListener('click', (event) => {
                if (event.target.closest('[data-bs-dismiss="toast"]')) {
                    return;
                }
                if (onClick) {
                    onClick(event);
                    return;
                }
                if (url !== '') {
                    window.location.assign(url);
                }
            });
        }

        toastEl.addEventListener('hidden.bs.toast', () => {
            toastEl.remove();
        });

        return toastInstance;
    };

    window.CS = window.CS || {};
    window.CS.showToast = showToast;

    if (initialFlashes.length > 0) {
        initialFlashes.forEach((flash, index) => {
            const { message = '', type = 'info' } = flash || {};
            const delay = 6000 + (index * 250);
            showToast(message, type, { delay });
        });
        window.CS_INITIAL_FLASHES = [];
    }
    const closeSidebarSubmenus = () => {
        if (!sidebar) {
            return;
        }
        sidebar.querySelectorAll('.collapse.show').forEach((submenu) => {
            // eslint-disable-next-line no-undef
            const collapseInstance = bootstrap.Collapse.getInstance(submenu);
            if (collapseInstance) {
                collapseInstance.hide();
            } else {
                submenu.classList.remove('show');
            }
        });
    };

    const updateSidebarToggleIcon = () => {
        const icon = sidebarToggle?.querySelector('i');
        if (!icon) {
            return;
        }
        if (sidebar?.classList.contains('collapsed')) {
            icon.classList.remove('fa-angles-left');
            icon.classList.add('fa-angles-right');
        } else {
            icon.classList.remove('fa-angles-right');
            icon.classList.add('fa-angles-left');
        }
    };

    const initializeTooltips = () => {
        // eslint-disable-next-line no-undef
        if (typeof bootstrap === 'undefined' || !bootstrap.Tooltip) {
            return;
        }
        const tooltipElements = document.querySelectorAll('[data-bs-toggle="tooltip"], [data-tooltip="true"]');
        tooltipElements.forEach((element) => {
            // eslint-disable-next-line no-undef
            const existing = bootstrap.Tooltip.getInstance(element);
            const inSidebar = sidebar?.contains(element);
            const sidebarCollapsed = sidebar?.classList.contains('collapsed');
            const sidebarOpen = sidebar?.classList.contains('open');
            const sidebarHovering = sidebar?.classList.contains(SIDEBAR_HOVER_CLASS);
            const shouldDisable = Boolean(inSidebar && (!sidebarCollapsed || sidebarOpen || sidebarHovering));

            if (shouldDisable) {
                if (existing) {
                    existing.hide();
                    existing.disable();
                }
                return;
            }

            const options = { container: 'body' };
            const trigger = element.getAttribute('data-bs-trigger');
            if (trigger) {
                options.trigger = trigger;
            }
            const placement = element.getAttribute('data-bs-placement');
            if (placement) {
                options.placement = placement;
            }
            if (!options.trigger) {
                options.trigger = 'hover focus';
            }

            const optionsSignature = JSON.stringify(options);
            const previousSignature = element.dataset.csTooltipOptions || '';
            const optionsChanged = optionsSignature !== previousSignature;

            if (existing && !optionsChanged) {
                existing.enable();
                return;
            }

            if (existing && optionsChanged) {
                existing.hide();
                existing.dispose();
            }

            // eslint-disable-next-line no-undef
            bootstrap.Tooltip.getOrCreateInstance(element, options);
            element.dataset.csTooltipOptions = optionsSignature;
        });
    };

    const applySidebarState = () => {
        if (!sidebar) {
            return;
        }
        const shouldCollapse = localStorage.getItem('csSidebar') === 'collapsed';
        sidebar.classList.remove(SIDEBAR_HOVER_CLASS);
        if (mobileBreakpoint.matches) {
            sidebar.classList.remove('collapsed');
            sidebarToggle?.setAttribute('aria-expanded', 'false');
            sidebarMobileToggle?.setAttribute('aria-expanded', sidebar.classList.contains('open') ? 'true' : 'false');
        } else {
            sidebar.classList.toggle('collapsed', shouldCollapse);
            sidebarToggle?.setAttribute('aria-expanded', String(!shouldCollapse));
            if (sidebar.classList.contains('collapsed')) {
                closeSidebarSubmenus();
            }
        }
        updateSidebarToggleIcon();
    };

    const syncSidebarMode = () => {
        if (!sidebar) {
            return;
        }
        if (!mobileBreakpoint.matches) {
            document.body.classList.remove('offcanvas-active');
            sidebar.classList.remove('open');
            sidebarMobileToggle?.setAttribute('aria-expanded', 'false');
            sidebarToggle?.setAttribute('aria-expanded', String(!sidebar.classList.contains('collapsed')));
        }
        applySidebarState();
        updateSidebarToggleIcon();
        initializeTooltips();
    };

    syncSidebarMode();
    initializeTooltips();
    const breakpointListener = mobileBreakpoint.addEventListener ? 'addEventListener' : 'addListener';
    mobileBreakpoint[breakpointListener]('change', () => {
        syncSidebarMode();
        initializeTooltips();
    });

    sidebarToggle?.addEventListener('click', () => {
        if (!sidebar) {
            return;
        }
        if (mobileBreakpoint.matches) {
            const isOpen = sidebar.classList.toggle('open');
            document.body.classList.toggle('offcanvas-active', isOpen);
            sidebarToggle?.setAttribute('aria-expanded', String(isOpen));
            sidebarMobileToggle?.setAttribute('aria-expanded', String(isOpen));
            return;
        }
        sidebar.classList.remove(SIDEBAR_HOVER_CLASS);
        const shouldCollapse = !sidebar.classList.contains('collapsed');
        sidebar.classList.toggle('collapsed', shouldCollapse);
        localStorage.setItem('csSidebar', shouldCollapse ? 'collapsed' : 'expanded');
        sidebarToggle?.setAttribute('aria-expanded', String(!shouldCollapse));
        if (sidebar.classList.contains('collapsed')) {
            closeSidebarSubmenus();
        }
        updateSidebarToggleIcon();
        initializeTooltips();
    });

    sidebarMobileToggle?.addEventListener('click', () => {
        if (!sidebar) {
            return;
        }
        sidebar.classList.remove(SIDEBAR_HOVER_CLASS);
        const isOpen = sidebar.classList.toggle('open');
        document.body.classList.toggle('offcanvas-active', isOpen);
        sidebarMobileToggle.setAttribute('aria-expanded', String(isOpen));
        updateSidebarToggleIcon();
        initializeTooltips();
    });

    if (sidebar) {
        sidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                if (!mobileBreakpoint.matches) {
                    return;
                }
                sidebar.classList.remove('open');
                document.body.classList.remove('offcanvas-active');
            });
        });
    }

    const cancelSidebarHoverTimer = () => {
        if (sidebarHoverTimer) {
            window.clearTimeout(sidebarHoverTimer);
            sidebarHoverTimer = null;
        }
    };

    const scheduleSidebarHoverCollapse = () => {
        if (!sidebar) {
            return;
        }
        cancelSidebarHoverTimer();
        sidebarHoverTimer = window.setTimeout(() => {
            sidebar.classList.remove(SIDEBAR_HOVER_CLASS);
            sidebarHoverTimer = null;
            initializeTooltips();
        }, 120);
    };

    const enableSidebarHoverPeek = () => {
        if (!sidebar) {
            return;
        }
        sidebar.addEventListener('mouseenter', () => {
            if (mobileBreakpoint.matches || !sidebar.classList.contains('collapsed')) {
                return;
            }
            cancelSidebarHoverTimer();
            sidebar.classList.add(SIDEBAR_HOVER_CLASS);
            initializeTooltips();
        });
        sidebar.addEventListener('mouseleave', () => {
            if (mobileBreakpoint.matches) {
                return;
            }
            scheduleSidebarHoverCollapse();
        });
        sidebar.addEventListener('focusin', () => {
            if (mobileBreakpoint.matches || !sidebar.classList.contains('collapsed')) {
                return;
            }
            cancelSidebarHoverTimer();
            sidebar.classList.add(SIDEBAR_HOVER_CLASS);
            initializeTooltips();
        });
        sidebar.addEventListener('focusout', (event) => {
            const nextTarget = event.relatedTarget;
            if (!nextTarget || !sidebar.contains(nextTarget)) {
                scheduleSidebarHoverCollapse();
            }
        });
    };

    enableSidebarHoverPeek();

    document.querySelectorAll('[data-datatable="true"]').forEach((table) => {
        // eslint-disable-next-line no-undef
        new DataTable(table, {
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/it-IT.json'
            }
        });
    });

    if (csrfToken) {
        document.querySelectorAll('form').forEach((form) => {
            if ((form.method || '').toLowerCase() === 'post' && !form.querySelector('input[name="_token"]')) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = '_token';
                input.value = csrfToken;
                form.appendChild(input);
            }
        });
    }

    const dashboardRoot = document.querySelector('[data-dashboard-root]');
    if (dashboardRoot) {
        const endpoint = dashboardRoot.getAttribute('data-dashboard-endpoint') || 'api/dashboard.php';
        const refreshInterval = Number.parseInt(dashboardRoot.getAttribute('data-refresh-interval'), 10) || 60000;
        const statusBanner = document.getElementById('dashboardStatus');
        const statusText = statusBanner?.querySelector('.dashboard-status-text');
        const retryButton = document.getElementById('dashboardRetry');
        const ticketsBody = document.getElementById('dashboardTicketsBody');
        const remindersList = document.getElementById('dashboardReminders');
        const statElements = Array.from(document.querySelectorAll('[data-dashboard-stat]'));
        const numberFormatter = new Intl.NumberFormat('it-IT');
        const currencyFormatter = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
        const hasDynamicWidgets = statElements.length > 0 || ticketsBody || remindersList;
        if (!hasDynamicWidgets) {
            return;
        }
        let refreshTimer = null;
        let inFlight = false;
        let lastSuccess = 0;

        const setDashboardState = (state) => {
            dashboardRoot.setAttribute('data-dashboard-state', state);
        };

        const clearStatus = () => {
            if (!statusBanner || !statusText) {
                return;
            }
            statusBanner.hidden = true;
            statusText.textContent = '';
            if (retryButton) {
                retryButton.hidden = true;
                retryButton.disabled = true;
            }
        };

        const updateStatus = (variant, message, allowRetry = false) => {
            if (!statusBanner || !statusText) {
                return;
            }
            statusBanner.classList.remove('alert-warning', 'alert-danger', 'alert-info', 'alert-success');
            statusBanner.classList.add(`alert-${variant}`);
            statusText.textContent = message;
            statusBanner.hidden = false;
            if (retryButton) {
                retryButton.hidden = !allowRetry;
                retryButton.disabled = !allowRetry;
            }
        };

        const formatValue = (value, format) => {
            if (format === 'currency') {
                const amount = Number.parseFloat(value) || 0;
                return currencyFormatter.format(amount);
            }
            if (format === 'number') {
                const numeric = Number.parseFloat(value) || 0;
                return numberFormatter.format(numeric);
            }
            return typeof value === 'string' ? value : String(value ?? '');
        };

        const applyStats = (stats = {}) => {
            statElements.forEach((element) => {
                const key = element.getAttribute('data-dashboard-stat');
                if (!key || !(key in stats)) {
                    return;
                }
                const format = element.getAttribute('data-format');
                element.textContent = formatValue(stats[key], format);
            });
        };

        const formatDate = (value) => {
            if (!value) {
                return '—';
            }
            const parsed = new Date(value);
            if (Number.isNaN(parsed.getTime())) {
                return value;
            }
            return parsed.toLocaleDateString('it-IT');
        };

        const renderTickets = (tickets = []) => {
            if (!ticketsBody) {
                return;
            }
            if (!Array.isArray(tickets) || tickets.length === 0) {
                ticketsBody.innerHTML = '';
                const emptyRow = document.createElement('tr');
                const emptyCell = document.createElement('td');
                emptyCell.colSpan = 4;
                emptyCell.className = 'text-center text-muted py-4';
                emptyCell.textContent = 'Nessun ticket disponibile.';
                emptyRow.appendChild(emptyCell);
                ticketsBody.appendChild(emptyRow);
                return;
            }
            ticketsBody.innerHTML = '';
            const fragment = document.createDocumentFragment();
            tickets.forEach((ticket) => {
                const row = document.createElement('tr');

                const ticketCell = document.createElement('td');
                const codeLabel = document.createElement('div');
                const codeValue = ticket.code || ticket.id || '—';
                codeLabel.className = 'fw-semibold';
                codeLabel.textContent = `#${codeValue}`;
                ticketCell.appendChild(codeLabel);

                const subjectLine = document.createElement('small');
                subjectLine.className = 'text-muted d-block';
                subjectLine.textContent = ticket.subject || `Ticket #${codeValue}`;
                ticketCell.appendChild(subjectLine);

                row.appendChild(ticketCell);

                const statusCell = document.createElement('td');
                const statusBadge = document.createElement('span');
                statusBadge.className = 'badge ag-badge text-uppercase';
                statusBadge.textContent = ticket.status || '—';
                statusCell.appendChild(statusBadge);
                row.appendChild(statusCell);

                const dateCell = document.createElement('td');
                dateCell.textContent = formatDate(ticket.createdAt);
                row.appendChild(dateCell);

                const actionCell = document.createElement('td');
                actionCell.className = 'text-end';
                if (ticket.id !== undefined && ticket.id !== null) {
                    const link = document.createElement('a');
                    link.className = 'btn btn-sm btn-outline-warning';
                    link.href = `modules/ticket/view.php?id=${ticket.id}`;
                    link.textContent = 'Apri';
                    actionCell.appendChild(link);
                }
                row.appendChild(actionCell);

                fragment.appendChild(row);
            });
            ticketsBody.appendChild(fragment);
        };

        const renderReminders = (reminders = []) => {
            if (!remindersList) {
                return;
            }
            if (!Array.isArray(reminders) || reminders.length === 0) {
                remindersList.innerHTML = '<li class="text-muted">Nessun promemoria attivo.</li>';
                return;
            }
            const fragment = document.createDocumentFragment();
            reminders.forEach((reminder) => {
                const item = document.createElement('li');
                item.className = 'reminder-item d-flex align-items-start';

                const badge = document.createElement('span');
                badge.className = 'badge ag-badge me-3';
                const icon = document.createElement('i');
                icon.className = `fa-solid ${reminder.icon || 'fa-bell'}`;
                badge.appendChild(icon);
                item.appendChild(badge);

                const content = document.createElement('div');
                const title = document.createElement('div');
                title.className = 'fw-semibold';
                if (reminder.url) {
                    const link = document.createElement('a');
                    link.className = 'link-warning';
                    link.href = reminder.url;
                    link.textContent = reminder.title || 'Promemoria';
                    title.appendChild(link);
                } else {
                    title.textContent = reminder.title || 'Promemoria';
                }
                content.appendChild(title);

                if (reminder.detail) {
                    const detail = document.createElement('small');
                    detail.className = 'text-muted';
                    detail.textContent = reminder.detail;
                    content.appendChild(detail);
                }

                item.appendChild(content);
                fragment.appendChild(item);
            });

            remindersList.innerHTML = '';
            remindersList.appendChild(fragment);
        };

        const getChartInstance = (canvas) => {
            const chartLib = window.Chart;
            if (!canvas || !chartLib) {
                return null;
            }
            if (typeof chartLib.getChart === 'function') {
                const found = chartLib.getChart(canvas);
                if (found) {
                    return found;
                }
            }
            if (canvas.chart || canvas._chart) {
                return canvas.chart || canvas._chart;
            }
            if (window.CSCharts) {
                if (canvas.id === 'chartRevenue' && window.CSCharts.revenue) {
                    return window.CSCharts.revenue;
                }
                if (canvas.id === 'chartServices' && window.CSCharts.services) {
                    return window.CSCharts.services;
                }
            }
            return null;
        };

        const updateCharts = (charts = {}) => {
            const revenueChart = getChartInstance(document.getElementById('chartRevenue'));
            const servicesChart = getChartInstance(document.getElementById('chartServices'));

            if (revenueChart && Array.isArray(revenueChart.data?.datasets)) {
                revenueChart.data.labels = charts.revenue?.labels ?? [];
                if (revenueChart.data.datasets[0]) {
                    revenueChart.data.datasets[0].data = charts.revenue?.values ?? [];
                }
                revenueChart.update('none');
            }

            if (servicesChart && Array.isArray(servicesChart.data?.datasets)) {
                servicesChart.data.labels = charts.services?.labels ?? [];
                if (servicesChart.data.datasets[0]) {
                    servicesChart.data.datasets[0].data = charts.services?.values ?? [];
                }
                servicesChart.update('none');
            }
        };

        const handlePayload = (payload = {}) => {
            applyStats(payload.stats);
            renderTickets(payload.tickets);
            renderReminders(payload.reminders);
            updateCharts(payload.charts);
            lastSuccess = Date.now();
            clearStatus();
            setDashboardState('ready');
        };

        const formatTime = (timestamp) => {
            if (!timestamp) {
                return '';
            }
            return new Date(timestamp).toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
        };

        const refreshDashboard = async () => {
            if (inFlight) {
                return;
            }
            inFlight = true;
            setDashboardState('loading');

            try {
                const response = await fetch(endpoint, {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('Aggiornamento non disponibile.');
                }

                const payload = await response.json();
                if (payload?.error) {
                    throw new Error(payload.error);
                }

                handlePayload(payload);
            } catch (error) {
                const staleSuffix = lastSuccess ? ` Ultimo dato valido alle ${formatTime(lastSuccess)}.` : '';
                const fallbackMessage = `Impossibile aggiornare la dashboard.${staleSuffix}`;
                const message = error?.name === 'SyntaxError' ? fallbackMessage : (error?.message ? `${error.message}${staleSuffix}` : fallbackMessage);
                updateStatus('danger', message, true);
                setDashboardState('stale');
            } finally {
                inFlight = false;
            }
        };

        const startPolling = () => {
            if (refreshTimer) {
                clearInterval(refreshTimer);
            }
            if (refreshInterval > 0) {
                refreshTimer = setInterval(() => {
                    refreshDashboard();
                }, refreshInterval);
            }
        };

        retryButton?.addEventListener('click', () => {
            clearStatus();
            refreshDashboard();
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                refreshDashboard();
                startPolling();
            } else if (refreshTimer) {
                clearInterval(refreshTimer);
                refreshTimer = null;
            }
        });

        dashboardRoot.addEventListener('refreshDashboard', () => {
            refreshDashboard();
        });

        setDashboardState('ready');
        refreshDashboard();
        startPolling();
    }

    const pickupFeedConfig = window.CS?.pickupReportFeed;
    if (pickupFeedConfig?.endpoint) {
        const endpoint = String(pickupFeedConfig.endpoint);
        const pollInterval = Math.max(5000, Number.parseInt(pickupFeedConfig.pollInterval, 10) || 30000);
        let lastId = Number.parseInt(pickupFeedConfig.initialLastId, 10);
        if (!Number.isFinite(lastId)) {
            lastId = 0;
        }
        let timerId = null;
        let feedInFlight = false;
        let failureCount = 0;
        const showToastFn = typeof window.CS.showToast === 'function' ? window.CS.showToast : null;

        const scheduleFetch = (delay = pollInterval) => {
            if (timerId) {
                clearTimeout(timerId);
            }
            const nextDelay = Math.max(2000, delay);
            timerId = window.setTimeout(() => {
                if (document.visibilityState !== 'visible') {
                    scheduleFetch(pollInterval);
                    return;
                }
                void fetchFeed();
            }, nextDelay);
        };

        const handleEvents = (events) => {
            if (!Array.isArray(events)) {
                return;
            }
            events.forEach((event) => {
                const eventId = Number.parseInt(event?.id, 10);
                if (Number.isFinite(eventId) && eventId > lastId) {
                    lastId = eventId;
                }
                const message = String(event?.message ?? '').trim();
                if (message === '' || !showToastFn) {
                    return;
                }
                const severity = String(event?.severity ?? 'info');
                const url = typeof event?.url === 'string' ? event.url : '';
                const delayOption = Number.isFinite(event?.delay) ? event.delay : undefined;
                showToastFn(message, severity, {
                    delay: Number.isFinite(delayOption) ? delayOption : 9000,
                    url
                });
            });
        };

        const fetchFeed = async () => {
            if (feedInFlight) {
                return;
            }
            feedInFlight = true;
            try {
                const url = `${endpoint}${endpoint.includes('?') ? '&' : '?'}since_id=${encodeURIComponent(lastId)}`;
                const response = await fetch(url, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                    cache: 'no-store'
                });

                if (!response.ok) {
                    throw new Error('Feed non disponibile');
                }

                const payload = await response.json();
                handleEvents(payload?.events ?? []);
                const payloadLastId = Number.parseInt(payload?.lastId, 10);
                if (Number.isFinite(payloadLastId) && payloadLastId > lastId) {
                    lastId = payloadLastId;
                }

                failureCount = 0;
                scheduleFetch(pollInterval);
            } catch (error) {
                failureCount += 1;
                const backoff = Math.min(pollInterval * (failureCount + 1), pollInterval * 6);
                scheduleFetch(backoff);
                if (failureCount === 3) {
                    console.warn('Pickup report feed temporaneamente indisponibile.', error);
                }
            } finally {
                feedInFlight = false;
            }
        };

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                scheduleFetch(Math.min(5000, pollInterval));
            }
        });

        window.addEventListener('beforeunload', () => {
            if (timerId) {
                clearTimeout(timerId);
            }
        });

        scheduleFetch(Math.min(5000, pollInterval));
    }

    const initAiAssistant = () => {
        const root = document.querySelector('[data-ai-assistant]');
        if (!root) {
            return;
        }

        const panel = root.querySelector('.ai-assistant-panel');
        const toggleBtn = root.querySelector('[data-ai-toggle]');
        const closeBtn = root.querySelector('[data-ai-close]');
        const refreshBtn = root.querySelector('[data-ai-refresh]');
        const form = root.querySelector('[data-ai-form]');
        const questionInput = root.querySelector('[data-ai-question]');
        const periodSelect = root.querySelector('[data-ai-period]');
        const customRange = root.querySelector('[data-ai-custom-range]');
        const customStart = root.querySelector('[data-ai-custom-start]');
        const customEnd = root.querySelector('[data-ai-custom-end]');
        const statusEl = root.querySelector('[data-ai-status]');
        const logContainer = root.querySelector('[data-ai-log]');
        const contextEl = root.querySelector('[data-ai-context]');
        const hintBtn = root.querySelector('[data-ai-hint]');
        const thinkingWrap = root.querySelector('[data-ai-thinking]');
        const thinkingToggle = root.querySelector('[data-ai-thinking-toggle]');
        const thinkingContent = root.querySelector('[data-ai-thinking-content]');
        const timestampEl = root.querySelector('[data-ai-timestamp]');
        const endpoint = root.dataset.endpoint || 'api/ai/advisor.php';
        const defaultPeriod = root.dataset.defaultPeriod || 'last30';
        const showToast = window?.CS?.showToast ?? (() => {});
        const pageContext = {
            title: root.dataset.pageTitle || document.title || '',
            section: root.dataset.pageSection || '',
            description: root.dataset.pageDescription || '',
            path: root.dataset.pagePath || window.location.pathname
        };

        const hintLibrary = {
            default: [
                'Dammi una panoramica sintetica e indica 3 azioni ad alto impatto per oggi.',
                'Quali rischi operativi o finanziari devo gestire con priorità questa settimana?',
                'Suggeriscimi come migliorare il cash-flow nei prossimi 7 giorni con dati attuali.'
            ],
            clienti: [
                'Quali clienti mostrano segnali di churn e come posso intervenire subito?',
                'Aiutami a pianificare le prossime campagne di upsell sui clienti più profittevoli.',
                'Che tipo di follow-up dovrei inviare ai clienti senza attività negli ultimi 30 giorni?'
            ],
            servizi: [
                'Quali appuntamenti o consegne richiedono azioni urgenti per evitare ritardi?',
                'Come posso ottimizzare le risorse operative e ridurre eventuali colli di bottiglia?',
                'Suggerisci un piano per alzare il tasso di completamento servizi entro la settimana.'
            ],
            reportistica: [
                'Aiutami a leggere i KPI principali di questo report e ricavare 3 insight azionabili.',
                'Quali metriche stanno peggiorando rispetto al periodo precedente e perché?',
                'Suggerisci un briefing per il team partendo dai dati in evidenza su questa pagina.'
            ],
            ticket: [
                'Come posso ridurre il backlog dei ticket aperti nelle prossime 48 ore?',
                'Quali ticket critici rischiano di sforare gli SLA e come posso prevenirlo?',
                'Dammi un piano per migliorare la soddisfazione clienti dai ticket attuali.'
            ],
            'email marketing': [
                'Quali segmenti meritano una campagna urgente basata sui dati di oggi?',
                'Suggerisci 3 miglioramenti per aumentare apertura e click delle ultime newsletter.',
                'Come posso recuperare gli iscritti inattivi registrati in questo periodo?'
            ],
            documenti: [
                'Segnalami eventuali documenti critici in scadenza o con anomalie.',
                'Quali procedure dovrei aggiornare per migliorare la compliance documentale?',
                'Come posso organizzare meglio i documenti condivisi per ridurre gli errori?' 
            ],
            impostazioni: [
                'Quali controlli di sicurezza o permessi dovrei verificare in questa pagina?',
                'Dammi un elenco di impostazioni critiche da rivedere per evitare misconfigurazioni.',
                'Quali automatismi potrei ottimizzare per ridurre interventi manuali?' 
            ],
            'customer portal': [
                "Come migliorare l'esperienza dei clienti sul portale partendo dai dati attuali?",
                'Quali richieste ricorrenti dovrei anticipare per alleggerire il supporto?',
                "Suggerisci iniziative per aumentare l'adozione del portale dai clienti inattivi."
            ],
            tools: [
                'Quali verifiche tecniche devo completare prima di usare questo strumento oggi?',
                'Suggerisci una checklist rapida per evitare errori con questo tool.',
                'Come posso validare i dati generati da questo strumento prima di inviarli al cliente?'
            ]
        };

        const normalizedSection = (pageContext.section || '').trim().toLowerCase();
        const hintPool = [...(hintLibrary[normalizedSection] ?? hintLibrary.default)];
        let lastHint = '';

        const pickHint = () => {
            const pool = hintPool.length > 0 ? hintPool : hintLibrary.default;
            if (pool.length === 0) {
                return hintBtn?.dataset.aiHint || 'Suggeriscimi tre priorità operative basate sui dati più recenti.';
            }
            let candidate = pool[Math.floor(Math.random() * pool.length)];
            if (pool.length > 1) {
                let attempts = 0;
                while (candidate === lastHint && attempts < 4) {
                    candidate = pool[Math.floor(Math.random() * pool.length)];
                    attempts += 1;
                }
            }
            lastHint = candidate;
            return candidate;
        };

        let isOpen = false;
        let inFlight = false;
        let history = [];
        let latestQuestion = '';
        const idleTimeoutMs = 10000;
        let idleTimerId = null;
        let isIdle = false;
        let autoRequested = false;

        const exitIdleState = () => {
            if (!toggleBtn || !isIdle) {
                return;
            }
            toggleBtn.classList.remove('is-idle');
            isIdle = false;
        };

        const enterIdleState = () => {
            if (!toggleBtn || isOpen) {
                return;
            }
            toggleBtn.classList.add('is-idle');
            isIdle = true;
        };

        const clearIdleTimer = () => {
            if (idleTimerId !== null) {
                window.clearTimeout(idleTimerId);
                idleTimerId = null;
            }
        };

        const scheduleIdleState = () => {
            if (!toggleBtn) {
                return;
            }
            clearIdleTimer();
            if (isOpen) {
                return;
            }
            idleTimerId = window.setTimeout(() => {
                enterIdleState();
            }, idleTimeoutMs);
        };

        const tryAutoRequest = () => {
            if (autoRequested || inFlight) {
                return;
            }
            const autoQuestion = pickHint();
            autoRequested = true;
            latestQuestion = autoQuestion;
            renderMessage('user', autoQuestion);
            requestAdvisor(autoQuestion);
        };

        const togglePanel = (open) => {
            if (!panel) {
                return;
            }
            isOpen = open;
            panel.hidden = !open;
            if (toggleBtn) {
                toggleBtn.setAttribute('aria-expanded', String(open));
            }
            if (open) {
                clearIdleTimer();
                exitIdleState();
                if (!logContainer?.children.length) {
                    tryAutoRequest();
                }
            } else {
                scheduleIdleState();
            }
            if (open && questionInput instanceof HTMLTextAreaElement) {
                setTimeout(() => questionInput.focus(), 120);
            }
        };

        const setStatus = (message, variant = 'info') => {
            if (!statusEl) {
                return;
            }
            if (!message) {
                statusEl.hidden = true;
                statusEl.textContent = '';
                statusEl.classList.remove('text-danger', 'text-success', 'text-muted');
                statusEl.classList.add('text-muted');
                return;
            }
            statusEl.hidden = false;
            statusEl.textContent = message;
            statusEl.classList.remove('text-danger', 'text-success', 'text-muted');
            if (variant === 'error') {
                statusEl.classList.add('text-danger');
            } else if (variant === 'success') {
                statusEl.classList.add('text-success');
            } else {
                statusEl.classList.add('text-muted');
            }
        };

        const renderMessage = (role, content) => {
            if (!logContainer) {
                return;
            }
            const bubble = document.createElement('div');
            bubble.className = `ai-assistant-message ${role}`;
            const chunks = String(content ?? '').split(/\n{2,}/).map((chunk) => chunk.trim()).filter((chunk) => chunk !== '');
            if (chunks.length === 0) {
                const p = document.createElement('p');
                p.className = 'mb-0';
                p.textContent = String(content ?? '').trim();
                bubble.appendChild(p);
            } else {
                chunks.forEach((chunk, index) => {
                    const p = document.createElement('p');
                    p.className = index === chunks.length - 1 ? 'mb-0' : 'mb-2';
                    p.textContent = chunk;
                    bubble.appendChild(p);
                });
            }
            logContainer.appendChild(bubble);
            logContainer.scrollTop = logContainer.scrollHeight;
        };

        const updateContext = (lines) => {
            if (!contextEl) {
                return;
            }
            contextEl.innerHTML = '';
            if (!Array.isArray(lines) || lines.length === 0) {
                contextEl.hidden = true;
                return;
            }
            const list = document.createElement('ul');
            list.className = 'mb-0 ps-3';
            lines.forEach((line) => {
                const item = document.createElement('li');
                item.textContent = line;
                list.appendChild(item);
            });
            contextEl.appendChild(list);
            contextEl.hidden = false;
        };

        const updateThinking = (content) => {
            if (!thinkingWrap || !thinkingContent || !thinkingToggle) {
                return;
            }
            const hasContent = typeof content === 'string' && content.trim() !== '';
            thinkingWrap.hidden = !hasContent;
            thinkingToggle.hidden = !hasContent;
            if (!hasContent) {
                thinkingContent.textContent = '';
                return;
            }
            thinkingContent.textContent = content.trim();
            const labelEl = thinkingToggle.querySelector('span');
            if (labelEl) {
                labelEl.textContent = thinkingWrap.open ? 'Nascondi ragionamento' : 'Mostra ragionamento';
            }
        };

        thinkingToggle?.addEventListener('click', () => {
            if (!thinkingWrap) {
                return;
            }
            thinkingWrap.open = !thinkingWrap.open;
            const label = thinkingWrap.open ? 'Nascondi ragionamento' : 'Mostra ragionamento';
            const labelEl = thinkingToggle.querySelector('span');
            if (labelEl) {
                labelEl.textContent = label;
            }
        });

        const formatTimestamp = (value) => {
            if (!value || !timestampEl) {
                return;
            }
            const date = new Date(value);
            if (Number.isNaN(date.getTime())) {
                return;
            }
            const formatted = date.toLocaleString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
            timestampEl.textContent = `Aggiornato alle ${formatted}`;
        };

        const buildPayload = (question) => {
            const payload = {
                question,
                period: periodSelect?.value || defaultPeriod,
                history,
                focus: root.dataset.userRole === 'Manager' ? 'Bilanciare finanza e operation' : '',
                page: pageContext,
            };
            if (payload.period === 'custom' && customStart && customEnd) {
                payload.customStart = customStart.value;
                payload.customEnd = customEnd.value;
            }
            return payload;
        };

        const requestAdvisor = async (question) => {
            if (inFlight) {
                return;
            }
            inFlight = true;
            setStatus('Sto analizzando il periodo selezionato…', 'info');
            try {
                const headers = {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                };
                if (csrfToken) {
                    headers['X-CSRF-Token'] = csrfToken;
                }

                const response = await fetch(endpoint, {
                    method: 'POST',
                    headers,
                    credentials: 'same-origin',
                    body: JSON.stringify(buildPayload(question))
                });

                const data = await response.json();
                if (!response.ok || !data?.ok) {
                    throw new Error(data?.error || 'Impossibile ottenere consigli.');
                }

                history = Array.isArray(data.history) ? data.history : history;
                updateContext(data.contextLines || []);
                renderMessage('assistant', data.answer || 'Nessuna risposta disponibile.');
                updateThinking(data.thinking || '');
                setStatus('Consigli aggiornati.', 'success');
                formatTimestamp(data.generatedAt || new Date().toISOString());
            } catch (error) {
                const message = error instanceof Error ? error.message : 'Errore sconosciuto.';
                setStatus(message, 'error');
                showToast(message, 'danger');
                renderMessage('assistant', 'Non riesco a completare la richiesta in questo momento. Riprova più tardi.');
            } finally {
                inFlight = false;
            }
        };

        toggleBtn?.addEventListener('click', () => {
            exitIdleState();
            clearIdleTimer();
            togglePanel(!isOpen);
        });

        toggleBtn?.addEventListener('mouseenter', () => {
            exitIdleState();
            scheduleIdleState();
        });

        toggleBtn?.addEventListener('focus', () => {
            exitIdleState();
            scheduleIdleState();
        });

        closeBtn?.addEventListener('click', () => togglePanel(false));

        refreshBtn?.addEventListener('click', () => {
            if (latestQuestion) {
                requestAdvisor(latestQuestion);
            }
        });

        if (periodSelect) {
            periodSelect.value = defaultPeriod;
            if (customRange) {
                customRange.hidden = periodSelect.value !== 'custom';
            }
            periodSelect.addEventListener('change', () => {
                if (customRange) {
                    customRange.hidden = periodSelect.value !== 'custom';
                }
            });
        }

        hintBtn?.addEventListener('click', () => {
            if (!(questionInput instanceof HTMLTextAreaElement)) {
                return;
            }
            const hint = pickHint();
            questionInput.value = hint;
            questionInput.focus();
        });

        form?.addEventListener('submit', (event) => {
            event.preventDefault();
            if (!(questionInput instanceof HTMLTextAreaElement)) {
                return;
            }
            const question = questionInput.value.trim();
            if (question === '') {
                questionInput.focus();
                return;
            }
            latestQuestion = question;
            renderMessage('user', question);
            questionInput.value = '';
            requestAdvisor(question);
        });

        scheduleIdleState();
    };

    initAiAssistant();

    if (Array.isArray(window.CS_INITIAL_FLASHES)) {
        window.CS_INITIAL_FLASHES.forEach((flash) => {
            if (flash?.message) {
                const type = flash.type ?? 'info';
                FlashModal.show(flash.message, type);
            }
        });
    }

});

const Toast = {
    show(message, type = 'info') {
        const container = document.querySelector('.toast-container') || createToastContainer();
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-bg-${type} border-0`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast" aria-label="Close"></button>
            </div>
        `;
        container.appendChild(toast);
        // eslint-disable-next-line no-undef
        const bootstrapToast = new bootstrap.Toast(toast, { delay: 4000 });
        bootstrapToast.show();
    }
};

function createToastContainer() {
    const container = document.createElement('div');
    container.className = 'toast-container position-fixed top-0 end-0 p-3';
    document.body.appendChild(container);
    return container;
}

window.CSToast = Toast;

const FlashModal = (() => {
    const queue = [];
    let isShowing = false;

    const typeConfig = {
        success: { title: 'Operazione completata', headerClass: 'text-bg-success' },
        danger: { title: 'Operazione non riuscita', headerClass: 'text-bg-danger' },
        warning: { title: 'Attenzione', headerClass: 'text-bg-warning text-dark' },
        info: { title: 'Informazione', headerClass: 'text-bg-info text-dark' }
    };

    const createModal = () => {
        const modal = document.createElement('div');
        modal.id = 'csFlashModal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5">Avviso</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-warning text-dark" data-bs-dismiss="modal">Chiudi</button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(modal);
        return modal;
    };

    const getModalElement = () => document.getElementById('csFlashModal') || createModal();

    const applyTypeStyles = (modalElement, type) => {
        const { title, headerClass } = typeConfig[type] ?? typeConfig.info;
        const header = modalElement.querySelector('.modal-header');
        const titleEl = modalElement.querySelector('.modal-title');
        const bodyEl = modalElement.querySelector('.modal-body');
        if (!header || !titleEl || !bodyEl) {
            return;
        }

        header.className = 'modal-header';
        if (headerClass) {
            header.classList.add(...headerClass.split(' '));
        }
        titleEl.textContent = title;
    };

    const showNext = () => {
        if (queue.length === 0) {
            isShowing = false;
            return;
        }

        isShowing = true;
        const { message, type } = queue.shift();
        const modalElement = getModalElement();
        applyTypeStyles(modalElement, type);
        const bodyEl = modalElement.querySelector('.modal-body');
        if (bodyEl) {
            bodyEl.textContent = message;
        }

        // eslint-disable-next-line no-undef
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modalElement);
        modalElement.addEventListener('hidden.bs.modal', () => {
            showNext();
        }, { once: true });
        modalInstance.show();
    };

    return {
        show(message, type = 'info') {
            queue.push({ message, type });
            if (!isShowing) {
                showNext();
            }
        }
    };
})();

window.CSFlashModal = FlashModal;

const ConfirmModal = (() => {
    const defaults = {
        title: 'Conferma operazione',
        confirmLabel: 'Conferma',
        cancelLabel: 'Annulla',
        confirmClass: 'btn btn-warning text-dark',
        cancelClass: 'btn btn-outline-secondary',
        allowHtml: false
    };

    const ensureModal = () => {
        let modal = document.getElementById('csConfirmModal');
        if (modal) {
            return modal;
        }

        modal = document.createElement('div');
        modal.id = 'csConfirmModal';
        modal.className = 'modal fade';
        modal.tabIndex = -1;
        modal.setAttribute('aria-hidden', 'true');
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header">
                        <h1 class="modal-title fs-5">Conferma</h1>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                    </div>
                    <div class="modal-body"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-confirm-cancel> Annulla </button>
                        <button type="button" class="btn btn-warning text-dark" data-confirm-accept> Conferma </button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(modal);
        return modal;
    };

    const applyOptions = (modal, options) => {
        const config = { ...defaults, ...options };
        const titleEl = modal.querySelector('.modal-title');
        const bodyEl = modal.querySelector('.modal-body');
        const confirmBtn = modal.querySelector('[data-confirm-accept]');
        const cancelBtn = modal.querySelector('[data-confirm-cancel]');

        if (titleEl) {
            titleEl.textContent = config.title;
        }

        if (bodyEl) {
            if (config.allowHtml) {
                bodyEl.innerHTML = config.message;
            } else {
                bodyEl.textContent = config.message;
            }
        }

        if (confirmBtn) {
            confirmBtn.textContent = config.confirmLabel;
            confirmBtn.className = config.confirmClass;
        }

        if (cancelBtn) {
            cancelBtn.textContent = config.cancelLabel;
            cancelBtn.className = config.cancelClass;
        }
    };

    const confirm = (message, options = {}) => new Promise((resolve) => {
        const modal = ensureModal();
        const confirmBtn = modal.querySelector('[data-confirm-accept]');
        const cancelBtn = modal.querySelector('[data-confirm-cancel]');

        const config = { ...options, message };
        applyOptions(modal, config);

        // eslint-disable-next-line no-undef
        const modalInstance = bootstrap.Modal.getOrCreateInstance(modal);

        let settled = false;

        const cleanup = (result) => {
            if (settled) {
                return;
            }
            settled = true;
            resolve(result);
            if (confirmBtn) {
                confirmBtn.removeEventListener('click', onConfirm);
            }
            if (cancelBtn) {
                cancelBtn.removeEventListener('click', onCancel);
            }
        };

        const onConfirm = () => {
            cleanup(true);
            modalInstance.hide();
        };

        const onCancel = () => {
            cleanup(false);
            modalInstance.hide();
        };

        const onHidden = () => {
            cleanup(false);
            modal.removeEventListener('hidden.bs.modal', onHidden);
        };

        if (confirmBtn) {
            confirmBtn.addEventListener('click', onConfirm, { once: true });
        }
        if (cancelBtn) {
            cancelBtn.addEventListener('click', onCancel, { once: true });
        }

        modal.addEventListener('hidden.bs.modal', onHidden, { once: true });

        modalInstance.show();
    });

    return { confirm };
})();

window.CSConfirm = ConfirmModal;

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) {
        return;
    }

    if (form.dataset.csConfirmBypass === '1') {
        delete form.dataset.csConfirmBypass;
        return;
    }

    const message = form.dataset.confirm;
    if (!message) {
        return;
    }

    event.preventDefault();

    const options = {};
    if (form.dataset.confirmTitle) {
        options.title = form.dataset.confirmTitle;
    }
    if (form.dataset.confirmConfirmLabel) {
        options.confirmLabel = form.dataset.confirmConfirmLabel;
    }
    if (form.dataset.confirmCancelLabel) {
        options.cancelLabel = form.dataset.confirmCancelLabel;
    }
    if (form.dataset.confirmClass) {
        options.confirmClass = form.dataset.confirmClass;
    }
    if (form.dataset.confirmCancelClass) {
        options.cancelClass = form.dataset.confirmCancelClass;
    }
    if (form.dataset.confirmAllowHtml === 'true') {
        options.allowHtml = true;
    }

    window.CSConfirm.confirm(message, options).then((accepted) => {
        if (!accepted) {
            return;
        }
        form.dataset.csConfirmBypass = '1';
        form.submit();
    });
});
