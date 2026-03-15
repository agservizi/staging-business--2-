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

    let persistNotification = () => {};

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

        if (options.persist !== false) {
            persistNotification({
                type,
                title: options.title,
                message: safeMessage,
                metadata: options.metadata,
                scope: options.scope,
                role: options.role,
            });
        }

        return toastInstance;
    };

    window.CS = window.CS || {};
    window.CS.showToast = showToast;

    const notificationsToggle = document.getElementById('notificationsToggle');
    const notificationsBadge = document.getElementById('notificationsBadge');
    const notificationsPanel = document.getElementById('notificationsPanel');
    const notificationsList = document.getElementById('notificationsList');
    const notificationsMarkAll = document.getElementById('notificationsMarkAll');
    const notificationsLoadMore = document.getElementById('notificationsLoadMore');

    const parseItemsDataset = (value) => {
        if (!value) {
            return [];
        }
        try {
            const parsed = JSON.parse(value);
            return Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            return [];
        }
    };

    const parsePositiveInt = (value) => {
        const parsed = Number.parseInt(String(value || '0'), 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    };

    const collaboratorItems = parseItemsDataset(notificationsPanel?.dataset.collabItems || '');
    const collaboratorUnreadInitial = parsePositiveInt(notificationsPanel?.dataset.collabUnread || 0);

    const notificationState = {
        items: [],
        unreadCount: 0,
        collabItems: collaboratorItems,
        collabUnreadCount: collaboratorUnreadInitial,
        nextCursor: null,
        loading: false,
        initialized: false,
        collabReadEndpoint: notificationsPanel?.dataset.collabEndpoint || '',
        collabLatestStatusAt: parsePositiveInt(notificationsPanel?.dataset.collabLatestStatus || 0),
        collabLatestTicketMessageId: parsePositiveInt(notificationsPanel?.dataset.collabLatestTicket || 0),
    };

    const notificationEndpoints = {
        list: `${window.CS?.apiBaseUrl || '/api/'}get_notifications.php`,
        save: `${window.CS?.apiBaseUrl || '/api/'}save_notification.php`,
        mark: `${window.CS?.apiBaseUrl || '/api/'}mark_notification.php`,
        markAll: `${window.CS?.apiBaseUrl || '/api/'}mark_notifications.php`,
    };

    const setBadgeCount = (count) => {
        if (!notificationsBadge) {
            return;
        }
        const value = Math.max(0, Number(count) || 0);
        if (value === 0) {
            notificationsBadge.classList.add('d-none');
            notificationsBadge.textContent = '';
            return;
        }
        notificationsBadge.textContent = String(value);
        notificationsBadge.classList.remove('d-none');
    };

    const updateBadgeCount = () => {
        setBadgeCount((notificationState.unreadCount || 0) + (notificationState.collabUnreadCount || 0));
    };

    const getNotificationUrl = (item) => {
        if (typeof item?.url === 'string' && item.url.trim() !== '') {
            return item.url.trim();
        }
        const metadata = item?.metadata;
        if (metadata && typeof metadata === 'object') {
            if (typeof metadata.url === 'string' && metadata.url.trim() !== '') {
                return metadata.url.trim();
            }
            if (typeof metadata.link === 'string' && metadata.link.trim() !== '') {
                return metadata.link.trim();
            }
        }
        return '';
    };

    const getNotificationTimestamp = (item) => {
        const raw = item?.createdAt || item?.timestamp || '';
        const timestamp = new Date(raw).getTime();
        return Number.isFinite(timestamp) ? timestamp : 0;
    };

    const composeVisibleItems = ({ append = false, incomingItems = [] } = {}) => {
        if (append) {
            return incomingItems;
        }
        const merged = [...notificationState.collabItems, ...notificationState.items];
        merged.sort((a, b) => getNotificationTimestamp(b) - getNotificationTimestamp(a));
        return merged;
    };

    const markCollaboratorNotificationsRead = async () => {
        if (notificationState.collabUnreadCount <= 0) {
            return true;
        }

        if (!notificationState.collabReadEndpoint) {
            notificationState.collabUnreadCount = 0;
            notificationState.collabItems = notificationState.collabItems.map((item) => ({ ...item, isRead: true }));
            updateBadgeCount();
            return true;
        }

        const csrf = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            const response = await fetch(notificationState.collabReadEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    action: 'mark_read',
                    last_status_at: notificationState.collabLatestStatusAt || 0,
                    last_ticket_message_id: notificationState.collabLatestTicketMessageId || 0,
                }),
            });
            if (!response.ok) {
                throw new Error('Errore');
            }
            const payload = await response.json();
            if (payload?.success) {
                notificationState.collabUnreadCount = 0;
                notificationState.collabItems = notificationState.collabItems.map((item) => ({ ...item, isRead: true }));
                document.querySelectorAll('.notification-item[data-notification-source="collaborator"]').forEach((node) => {
                    node.classList.remove('is-unread');
                });
                updateBadgeCount();
                return true;
            }
        } catch (error) {
            // ignore
        }

        return false;
    };

    const createNotificationNode = (item) => {
        const wrapper = document.createElement('div');
        wrapper.className = `notification-item${item.isRead ? '' : ' is-unread'}`;
        wrapper.dataset.notificationId = String(item.id);
        wrapper.dataset.notificationSource = item.source || 'system';

        const icon = document.createElement('div');
        icon.className = item.colorClass || 'text-info';
        icon.innerHTML = `<i class="fa-solid ${item.icon || 'fa-circle-info'}"></i>`;

        const body = document.createElement('div');
        body.className = 'flex-grow-1';

        const title = document.createElement('div');
        title.className = 'notification-item-title';
        title.textContent = item.title || 'Notifica';

        const message = document.createElement('div');
        message.className = 'notification-item-message';
        message.textContent = item.message || '';

        const meta = document.createElement('div');
        meta.className = 'notification-item-meta';
        meta.textContent = item.createdAtLabel || '';

        body.append(title, message);

        if (item.type === 'bug' && item.metadata && item.metadata.suggestions) {
            const suggestions = document.createElement('div');
            suggestions.className = 'notification-item-suggestions';
            const causes = Array.isArray(item.metadata.suggestions.causes) ? item.metadata.suggestions.causes.join(' • ') : '';
            const checks = Array.isArray(item.metadata.suggestions.checks) ? item.metadata.suggestions.checks.join(' • ') : '';
            const fix = item.metadata.suggestions.fix || '';
            suggestions.textContent = [
                causes ? `Cause: ${causes}` : '',
                checks ? `Verifiche: ${checks}` : '',
                fix ? `Fix: ${fix}` : '',
            ].filter(Boolean).join('\n');
            body.append(suggestions);
        }

        body.append(meta);
        wrapper.append(icon, body);

        wrapper.addEventListener('click', async () => {
            const targetUrl = getNotificationUrl(item);
            if (item.source === 'collaborator') {
                await markCollaboratorNotificationsRead();
                if (targetUrl !== '') {
                    window.location.assign(targetUrl);
                }
                return;
            }

            if (!item.isRead) {
                await markNotificationRead(item.id, wrapper);
            }
            if (targetUrl !== '') {
                window.location.assign(targetUrl);
            }
        });

        return wrapper;
    };

    const renderNotifications = (items, { append = false } = {}) => {
        if (!notificationsList) {
            return;
        }
        if (!append) {
            notificationsList.innerHTML = '';
        }
        if (!items || items.length === 0) {
            if (!append) {
                const empty = document.createElement('div');
                empty.className = 'text-muted small px-3 py-4 text-center';
                empty.textContent = 'Nessuna notifica al momento.';
                notificationsList.append(empty);
            }
            return;
        }
        items.forEach((item) => {
            notificationsList.append(createNotificationNode(item));
        });
    };

    const fetchNotifications = async ({ append = false, silent = false } = {}) => {
        if (notificationState.loading) {
            return;
        }
        notificationState.loading = true;
        const params = new URLSearchParams();
        params.set('limit', '10');
        if (append && notificationState.nextCursor) {
            params.set('before_id', String(notificationState.nextCursor));
        }
        try {
            const response = await fetch(`${notificationEndpoints.list}?${params.toString()}`, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok) {
                throw new Error('Fetch failed');
            }
            const payload = await response.json();
            const data = payload.data || {};
            if (!append) {
                notificationState.items = data.items || [];
            } else {
                notificationState.items = notificationState.items.concat(data.items || []);
            }
            notificationState.nextCursor = data.nextCursor || null;
            notificationState.unreadCount = Math.max(0, Number(data.unreadCount) || 0);
            updateBadgeCount();
            renderNotifications(composeVisibleItems({ append, incomingItems: data.items || [] }), { append });
            if (notificationsLoadMore) {
                notificationsLoadMore.disabled = !data.hasMore;
                notificationsLoadMore.classList.toggle('d-none', !data.hasMore);
            }
            notificationState.initialized = true;
        } catch (error) {
            if (!silent && notificationsList && !append) {
                notificationsList.innerHTML = '<div class="text-danger small px-3 py-4 text-center">Errore nel caricare le notifiche.</div>';
            }
        } finally {
            notificationState.loading = false;
        }
    };

    const markNotificationRead = async (id, element) => {
        const csrf = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            const response = await fetch(notificationEndpoints.mark, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ id }),
            });
            if (!response.ok) {
                throw new Error('Errore');
            }
            const result = await response.json();
            if (result.success) {
                const item = notificationState.items.find((n) => n.id === id);
                if (item) {
                    item.isRead = true;
                }
                if (element) {
                    element.classList.remove('is-unread');
                }
                notificationState.unreadCount = Math.max(0, notificationState.unreadCount - 1);
                updateBadgeCount();
            }
        } catch (error) {
            // ignore
        }
    };

    const markAllNotificationsRead = async () => {
        const csrf = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        let markedSystemNotifications = false;
        try {
            const response = await fetch(notificationEndpoints.markAll, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ action: 'mark_all' }),
            });
            if (!response.ok) {
                throw new Error('Errore');
            }
            const result = await response.json();
            if (result.success) {
                markedSystemNotifications = true;
                notificationState.items.forEach((item) => {
                    item.isRead = true;
                });
                document.querySelectorAll('.notification-item.is-unread:not([data-notification-source="collaborator"])').forEach((node) => {
                    node.classList.remove('is-unread');
                });
                notificationState.unreadCount = 0;
            }
        } catch (error) {
            // ignore
        }

        const markedCollaboratorNotifications = await markCollaboratorNotificationsRead();
        if (markedSystemNotifications || markedCollaboratorNotifications) {
            updateBadgeCount();
        }
    };

    persistNotification = async ({ type, title, message, metadata, scope, role } = {}) => {
        if (!message || !notificationEndpoints.save) {
            return;
        }
        const csrf = csrfToken || document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
        try {
            const response = await fetch(notificationEndpoints.save, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrf || '',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ type, title, message, metadata, scope, role }),
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            const item = payload.data;
            if (item) {
                notificationState.items.unshift(item);
                if (notificationsList) {
                    const emptyState = notificationsList.querySelector('.text-muted.small');
                    if (emptyState) {
                        emptyState.remove();
                    }
                    notificationsList.prepend(createNotificationNode(item));
                }
                notificationState.unreadCount += 1;
                updateBadgeCount();
            }
        } catch (error) {
            // ignore
        }
    };

    if (notificationsToggle) {
        notificationsToggle.addEventListener('click', () => {
            if (!notificationState.initialized) {
                fetchNotifications();
            }
        });
    }

    notificationsToggle?.addEventListener('show.bs.dropdown', () => {
        if (!notificationState.initialized) {
            fetchNotifications();
        }
    });

    notificationsMarkAll?.addEventListener('click', (event) => {
        event.preventDefault();
        markAllNotificationsRead();
    });

    notificationsLoadMore?.addEventListener('click', (event) => {
        event.preventDefault();
        fetchNotifications({ append: true });
    });

    updateBadgeCount();
    if (notificationsToggle) {
        fetchNotifications({ append: false, silent: true });
    }

    setInterval(() => {
        fetchNotifications({ append: false, silent: true });
    }, 60000);

    const searchWrapper = document.getElementById('globalSearch');
    const searchBox = document.getElementById('globalSearchBox');
    const searchInput = document.getElementById('globalSearchInput');
    const searchResults = document.getElementById('globalSearchResults');
    const searchClear = document.getElementById('globalSearchClear');
    const searchToggle = document.getElementById('globalSearchToggle');
    const searchEndpoint = searchWrapper?.dataset.searchEndpoint || '';
    const searchPageUrl = searchWrapper?.dataset.searchPage || '/modules/impostazioni/search';
    const searchState = {
        items: [],
        activeIndex: -1,
        open: false,
        loading: false,
        query: '',
        controller: null,
    };

    const SEARCH_TYPES = {
        cliente: { label: 'Clienti', icon: 'fa-user' },
        pratica: { label: 'Pratiche CAF/Patronato', icon: 'fa-folder-open' },
        opportunita: { label: 'Opportunità', icon: 'fa-briefcase' },
        contratto: { label: 'Contratti energia', icon: 'fa-file-contract' },
        fattura: { label: 'Entrate/Uscite', icon: 'fa-receipt' },
        documento: { label: 'Documenti', icon: 'fa-file-lines' },
        appuntamento: { label: 'Appuntamenti', icon: 'fa-calendar-check' },
        aci: { label: 'Pratiche ACI', icon: 'fa-car' },
        anpr: { label: 'Pratiche ANPR', icon: 'fa-id-card' },
        cie: { label: 'Prenotazioni CIE', icon: 'fa-id-card-clip' },
        digitale: { label: 'Servizi digitali', icon: 'fa-shield-halved' },
        fedelta: { label: 'Movimenti fedeltà', icon: 'fa-star' },
        curriculum: { label: 'Curriculum', icon: 'fa-file-signature' },
        spedizione: { label: 'Spedizioni', icon: 'fa-truck' },
        brt_spedizione: { label: 'Spedizioni BRT', icon: 'fa-truck-fast' },
        brt_manifest: { label: 'Manifest BRT', icon: 'fa-list-check' },
        telegramma: { label: 'Telegrammi', icon: 'fa-paper-plane' },
        visura: { label: 'Visure CR', icon: 'fa-building' },
        posta: { label: 'Posta telematica', icon: 'fa-envelope-open-text' },
        pickup: { label: 'Logistica pickup', icon: 'fa-box' },
        pickup_report: { label: 'Segnalazioni pickup', icon: 'fa-triangle-exclamation' },
        iliad: { label: 'Credenziali Iliad', icon: 'fa-sim-card' },
        campagna_email: { label: 'Campagne email', icon: 'fa-envelope-circle-check' },
        iscritto_email: { label: 'Iscritti email', icon: 'fa-user-check' },
        report: { label: 'Report', icon: 'fa-chart-line' },
        utente: { label: 'Utenti', icon: 'fa-user-gear' },
        notifica: { label: 'Notifiche', icon: 'fa-bell' },
        ticket: { label: 'Ticket', icon: 'fa-life-ring' },
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');

    const escapeRegExp = (value) => String(value ?? '').replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

    const highlightText = (text, query) => {
        const safe = escapeHtml(text);
        const needle = String(query ?? '').trim();
        if (!needle) {
            return safe;
        }
        const regex = new RegExp(`(${escapeRegExp(needle)})`, 'ig');
        return safe.replace(regex, '<mark class="live-search-highlight">$1</mark>');
    };

    const setSearchLoading = (loading) => {
        if (!searchBox) {
            return;
        }
        searchState.loading = loading;
        searchBox.classList.toggle('is-loading', loading);
    };

    const openSearchResults = () => {
        if (!searchResults || !searchBox || !searchWrapper) {
            return;
        }
        searchResults.hidden = false;
        searchBox.classList.add('is-open');
        searchWrapper.classList.add('is-open');
        searchState.open = true;
    };

    const closeSearchResults = () => {
        if (!searchResults || !searchBox || !searchWrapper) {
            return;
        }
        searchResults.hidden = true;
        searchBox.classList.remove('is-open');
        searchWrapper.classList.remove('is-open');
        searchState.open = false;
        searchState.activeIndex = -1;
        searchState.items = [];
    };

    const setActiveResult = (index) => {
        if (!searchResults) {
            return;
        }
        const items = Array.from(searchResults.querySelectorAll('.live-search-item'));
        items.forEach((item) => item.classList.remove('is-active'));
        if (index < 0 || index >= items.length) {
            searchState.activeIndex = -1;
            return;
        }
        const target = items[index];
        target.classList.add('is-active');
        searchState.activeIndex = index;
        target.scrollIntoView({ block: 'nearest' });
    };

    const renderSearchResults = (items, query) => {
        if (!searchResults) {
            return;
        }
        searchResults.innerHTML = '';
        searchState.items = [];
        searchState.activeIndex = -1;

        if (!items || items.length === 0) {
            const empty = document.createElement('div');
            empty.className = 'live-search-empty';
            empty.textContent = 'Nessun risultato trovato.';
            searchResults.append(empty);
            return;
        }

        const grouped = items.reduce((acc, item) => {
            const key = item.type || 'altro';
            if (!acc[key]) {
                acc[key] = [];
            }
            acc[key].push(item);
            return acc;
        }, {});

        Object.entries(grouped).forEach(([type, groupItems]) => {
            const meta = SEARCH_TYPES[type] || { label: type, icon: 'fa-circle-info' };
            const group = document.createElement('div');
            group.className = 'live-search-group';

            const label = document.createElement('div');
            label.className = 'live-search-group-label';
            label.innerHTML = `<i class="fa-solid ${meta.icon}"></i>${escapeHtml(meta.label)}`;
            group.append(label);

            groupItems.forEach((item) => {
                const index = searchState.items.length;
                searchState.items.push(item);

                const link = document.createElement('a');
                link.href = item.url || '#';
                link.className = 'live-search-item';
                link.dataset.index = String(index);
                link.setAttribute('role', 'option');

                const icon = item.icon || meta.icon || 'fa-circle-info';
                const badge = item.badge || meta.label || '';

                link.innerHTML = `
                    <div class="d-flex align-items-start gap-3 flex-grow-1">
                        <div class="live-search-item-icon"><i class="fa-solid ${icon}"></i></div>
                        <div class="live-search-item-content">
                            <div class="live-search-item-title">${highlightText(item.title || 'Risultato', query)}</div>
                            <div class="live-search-item-subtitle">${highlightText(item.subtitle || '', query)}</div>
                        </div>
                    </div>
                    <div class="live-search-item-badge">${escapeHtml(badge)}</div>
                `;

                link.addEventListener('mouseenter', () => setActiveResult(index));
                link.addEventListener('focus', () => setActiveResult(index));
                group.append(link);
            });

            searchResults.append(group);
        });
    };

    const runSearch = async (query) => {
        if (!searchEndpoint || !searchResults) {
            return;
        }
        const normalized = String(query ?? '').trim();
        if (normalized.length < 2) {
            closeSearchResults();
            return;
        }

        if (searchState.controller) {
            searchState.controller.abort();
        }
        const controller = new AbortController();
        searchState.controller = controller;
        setSearchLoading(true);

        try {
            const params = new URLSearchParams({ q: normalized, limit: '8' });
            const response = await fetch(`${searchEndpoint}?${params.toString()}`, {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                signal: controller.signal,
            });
            if (!response.ok) {
                throw new Error('Search failed');
            }
            const payload = await response.json();
            const items = Array.isArray(payload.items) ? payload.items : [];
            renderSearchResults(items, normalized);
            openSearchResults();
        } catch (error) {
            if (error.name === 'AbortError') {
                return;
            }
            if (searchResults) {
                searchResults.innerHTML = '<div class="live-search-empty text-danger">Errore durante la ricerca.</div>';
                openSearchResults();
            }
        } finally {
            setSearchLoading(false);
        }
    };

    const debounce = (callback, delay = 250) => {
        let timer;
        return (...args) => {
            window.clearTimeout(timer);
            timer = window.setTimeout(() => callback(...args), delay);
        };
    };

    const debouncedSearch = debounce(runSearch, 250);

    searchInput?.addEventListener('input', (event) => {
        const value = event.target.value;
        searchState.query = value;
        searchBox?.classList.toggle('has-value', value.trim() !== '');
        debouncedSearch(value);
    });

    searchInput?.addEventListener('keydown', (event) => {
        if (!searchState.open && (event.key === 'ArrowDown' || event.key === 'ArrowUp')) {
            openSearchResults();
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            const next = Math.min(searchState.items.length - 1, searchState.activeIndex + 1);
            setActiveResult(next);
            return;
        }
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            const prev = Math.max(0, searchState.activeIndex - 1);
            setActiveResult(prev);
            return;
        }
        if (event.key === 'Enter') {
            event.preventDefault();
            const current = searchState.items[searchState.activeIndex];
            if (current && current.url) {
                window.location.assign(current.url);
                return;
            }
            const query = String(searchInput.value || '').trim();
            if (query !== '') {
                window.location.assign(`${searchPageUrl}?q=${encodeURIComponent(query)}`);
            }
            return;
        }
        if (event.key === 'Escape') {
            event.preventDefault();
            closeSearchResults();
            searchInput.blur();
        }
    });

    searchInput?.addEventListener('focus', () => {
        if (searchInput.value.trim().length >= 2 && searchResults && searchResults.innerHTML.trim() !== '') {
            openSearchResults();
        }
    });

    searchClear?.addEventListener('click', () => {
        if (!searchInput) {
            return;
        }
        searchInput.value = '';
        searchBox?.classList.remove('has-value');
        closeSearchResults();
        searchInput.focus();
    });

    searchToggle?.addEventListener('click', () => {
        if (!searchWrapper) {
            return;
        }
        const willOpen = !searchWrapper.classList.contains('is-open');
        if (willOpen) {
            searchWrapper.classList.add('is-open');
            searchInput?.focus();
        } else {
            closeSearchResults();
        }
    });

    document.addEventListener('click', (event) => {
        if (!searchWrapper || !searchState.open) {
            return;
        }
        if (!searchWrapper.contains(event.target)) {
            closeSearchResults();
        }
    });

    if (initialFlashes.length > 0) {
        initialFlashes.forEach((flash, index) => {
            const { message = '', type = 'info' } = flash || {};
            const delay = 6000 + (index * 250);
            showToast(message, type, { delay });
        });
        window.CS_INITIAL_FLASHES = [];
    }

    const ensureMainContentAnchor = () => {
        const mainContent = document.querySelector('main.content-wrapper');
        if (!mainContent) {
            return;
        }
        if (!mainContent.id) {
            mainContent.id = 'main-content';
        }
        if (!mainContent.hasAttribute('tabindex')) {
            mainContent.setAttribute('tabindex', '-1');
        }
    };

    ensureMainContentAnchor();
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

    const closeMobileSidebar = (focusTarget = null) => {
        if (!sidebar) {
            return;
        }
        sidebar.classList.remove('open');
        document.body.classList.remove('offcanvas-active');
        sidebarMobileToggle?.setAttribute('aria-expanded', 'false');
        sidebarToggle?.setAttribute('aria-expanded', String(!sidebar.classList.contains('collapsed')));
        if (focusTarget && typeof focusTarget.focus === 'function') {
            focusTarget.focus();
        }
        initializeTooltips();
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

    document.addEventListener('click', (event) => {
        if (!mobileBreakpoint.matches || !sidebar || !sidebar.classList.contains('open')) {
            return;
        }
        const target = event.target;
        if (sidebar.contains(target)) {
            return;
        }
        if (sidebarMobileToggle && sidebarMobileToggle.contains(target)) {
            return;
        }
        if (sidebarToggle && sidebarToggle.contains(target)) {
            return;
        }
        closeMobileSidebar();
    });

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') {
            return;
        }
        if (!mobileBreakpoint.matches || !sidebar || !sidebar.classList.contains('open')) {
            return;
        }
        closeMobileSidebar(sidebarMobileToggle);
    });

    if (sidebar) {
        sidebar.querySelectorAll('a').forEach((link) => {
            link.addEventListener('click', () => {
                if (!mobileBreakpoint.matches) {
                    return;
                }
                closeMobileSidebar();
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

    const applyMobileTableStacking = () => {
        const shouldStack = window.matchMedia('(max-width: 768px)').matches;
        document.querySelectorAll('table').forEach((table) => {
            const headerCells = Array.from(table.querySelectorAll('thead th'));
            if (headerCells.length === 0) {
                return;
            }

            const headers = headerCells.map((cell) => (cell.textContent || '').trim());
            table.querySelectorAll('tbody tr').forEach((row) => {
                let columnIndex = 0;
                row.querySelectorAll('td').forEach((cell) => {
                    const label = headers[columnIndex] || '';
                    cell.setAttribute('data-label', label);
                    const colspan = Number.parseInt(cell.getAttribute('colspan') || '1', 10);
                    columnIndex += Number.isFinite(colspan) && colspan > 0 ? colspan : 1;
                });
            });

            table.classList.toggle('table-stacked', shouldStack);
        });
    };

    let tableStackTimer = null;
    const scheduleTableStacking = () => {
        if (tableStackTimer) {
            window.clearTimeout(tableStackTimer);
        }
        tableStackTimer = window.setTimeout(() => {
            applyMobileTableStacking();
            tableStackTimer = null;
        }, 120);
    };

    window.addEventListener('resize', scheduleTableStacking);

    const isExpressModulePage = window.location.pathname.includes('/modules/servizi/express/');
    const expressTablePageSize = 10;

    document.querySelectorAll('[data-datatable="true"]').forEach((table) => {
        const configuredPageLength = Number.parseInt(table.dataset.pageLength || '', 10);
        const effectivePageLength = Number.isInteger(configuredPageLength) && configuredPageLength > 0
            ? configuredPageLength
            : (isExpressModulePage ? expressTablePageSize : null);
        const dataTableOptions = {
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/it-IT.json'
            }
        };

        if (Number.isInteger(effectivePageLength) && effectivePageLength > 0) {
            dataTableOptions.paging = true;
            dataTableOptions.pageLength = effectivePageLength;
            dataTableOptions.lengthChange = false;
        }

        // eslint-disable-next-line no-undef
        const dataTableInstance = new DataTable(table, dataTableOptions);
        if (dataTableInstance && typeof dataTableInstance.on === 'function') {
            dataTableInstance.on('draw', applyMobileTableStacking);
        } else {
            table.addEventListener('draw.dt', applyMobileTableStacking);
        }
    });

    applyMobileTableStacking();

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
        const opportunityWidget = document.querySelector('[data-opportunities-widget]');
        const opportunityStatusList = document.getElementById('opportunityStatusList');
        const opportunityLatestList = document.getElementById('opportunityLatestList');
        const opportunityTodoList = document.getElementById('opportunityTodoList');
        const opportunityLatestBadge = document.getElementById('opportunityLatestBadge');
        const opportunityTodoBadge = document.getElementById('opportunityTodoBadge');
        const opportunityProgress = opportunityWidget?.querySelector('.opportunity-progress');
        const opportunityStatusChartCanvas = document.getElementById('opportunityStatusChart');
        const opportunityTotals = {
            total: document.querySelector('[data-opportunity-total="total"]'),
            active: document.querySelector('[data-opportunity-total="active"]'),
            won: document.querySelector('[data-opportunity-total="won"]'),
            lost: document.querySelector('[data-opportunity-total="lost"]')
        };
        const statElements = Array.from(document.querySelectorAll('[data-dashboard-stat]'));
        const numberFormatter = new Intl.NumberFormat('it-IT');
        const currencyFormatter = new Intl.NumberFormat('it-IT', { style: 'currency', currency: 'EUR' });
        const hasDynamicWidgets = statElements.length > 0 || ticketsBody || remindersList || opportunityWidget;
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

        const formatDateTime = (value) => {
            if (!value) {
                return '—';
            }
            const parsed = new Date(value);
            if (Number.isNaN(parsed.getTime())) {
                return value;
            }
            return parsed.toLocaleString('it-IT');
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
                    link.href = `modules/ticket/view?id=${ticket.id}`;
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

        const renderOpportunityList = (target, items = [], emptyText) => {
            if (!target) {
                return;
            }
            if (!Array.isArray(items) || items.length === 0) {
                target.innerHTML = `<div class="list-group-item px-0 text-muted">${emptyText}</div>`;
                return;
            }

            const statusColorMap = {
                warning: 'bg-warning text-dark',
                info: 'bg-primary text-white',
                primary: 'bg-primary',
                danger: 'bg-danger',
                success: 'bg-success'
            };

            const fragment = document.createDocumentFragment();
            items.forEach((item) => {
                const wrapper = document.createElement('div');
                wrapper.className = 'list-group-item px-0';

                const row = document.createElement('div');
                row.className = 'd-flex align-items-start justify-content-between gap-3';

                const left = document.createElement('div');
                const codeLine = document.createElement('div');
                const code = item?.code || (item?.id ? `OP-${item.id}` : 'Opportunity');
                codeLine.className = 'fw-semibold';
                codeLine.textContent = `#${code}`;

                const meta = document.createElement('small');
                meta.className = 'text-muted';
                const providerLabel = item?.providerLabel || 'Gestore non indicato';
                const customerName = item?.customerName || 'Cliente non indicato';
                meta.textContent = `${providerLabel} · ${customerName}`;

                const dateLine = document.createElement('div');
                dateLine.className = 'text-muted small';
                dateLine.textContent = formatDateTime(item?.referenceDate);

                left.appendChild(codeLine);
                left.appendChild(meta);
                left.appendChild(dateLine);

                const right = document.createElement('div');
                right.className = 'text-end opportunity-list-actions';

                const badge = document.createElement('span');
                const colorClass = statusColorMap[item?.statusColor] || 'bg-secondary';
                badge.className = `badge ${colorClass} text-uppercase`;
                badge.textContent = item?.statusLabel || item?.statusCode || '—';
                right.appendChild(badge);

                if (item?.id !== undefined && item?.id !== null) {
                    const link = document.createElement('a');
                    link.className = 'btn btn-sm btn-outline-warning';
                    link.href = `modules/opportunities/detail?id=${item.id}`;
                    link.textContent = 'Apri';
                    right.appendChild(link);
                }

                row.appendChild(left);
                row.appendChild(right);
                wrapper.appendChild(row);
                fragment.appendChild(wrapper);
            });

            target.innerHTML = '';
            target.appendChild(fragment);
        };

        const renderOpportunityWidget = (data = {}) => {
            if (!opportunityWidget) {
                return;
            }

            const statusColorMap = {
                warning: 'bg-warning text-dark',
                info: 'bg-primary text-white',
                primary: 'bg-primary',
                danger: 'bg-danger',
                success: 'bg-success'
            };
            const cssVars = getComputedStyle(document.documentElement);
            const resolveColor = (token, fallback) => {
                const value = cssVars.getPropertyValue(`--bs-${token}`)?.trim();
                return value || fallback;
            };
            const statusColorPalette = {
                warning: resolveColor('warning', '#f6c23e'),
                info: resolveColor('primary', '#0d6efd'),
                primary: resolveColor('primary', '#0d6efd'),
                danger: resolveColor('danger', '#dc3545'),
                success: resolveColor('success', '#198754'),
                secondary: resolveColor('secondary', '#6c757d')
            };

            const totals = data.totals || {};
            Object.entries(opportunityTotals).forEach(([key, element]) => {
                if (!element) {
                    return;
                }
                const value = totals[key] ?? 0;
                element.textContent = formatValue(value, 'number');
            });

            const totalCount = Number(totals.total) || 0;
            const safeTotal = totalCount > 0 ? totalCount : 1;
            const toPercent = (count) => {
                const value = ((Number(count) || 0) / safeTotal) * 100;
                const clamped = Math.max(0, Math.min(100, value));
                return Math.round(clamped * 10) / 10; // one decimal precision
            };
            const progressValues = {
                active: toPercent(totals.active),
                won: toPercent(totals.won),
                lost: toPercent(totals.lost)
            };
            if (opportunityProgress) {
                opportunityProgress.querySelectorAll('[data-progress]').forEach((segment) => {
                    const key = segment.getAttribute('data-progress');
                    if (!key || !(key in progressValues)) {
                        return;
                    }
                    const value = progressValues[key];
                    segment.style.width = `${value}%`;
                    const label = segment.querySelector('span');
                    if (label) {
                        const labelValue = Number.isInteger(value) ? value.toFixed(0) : value.toFixed(1);
                        label.textContent = `${labelValue}%`;
                    }
                    segment.title = `${key === 'won' ? 'Attivate' : key === 'lost' ? 'Annullate' : 'In lavorazione'}: ${value}%`;
                });
                const totalWidth = progressValues.active + progressValues.won + progressValues.lost;
                if (Math.abs(totalWidth - 100) > 0.1) {
                    const scale = 100 / Math.max(1, totalWidth);
                    opportunityProgress.querySelectorAll('[data-progress]').forEach((segment) => {
                        const key = segment.getAttribute('data-progress');
                        if (!key || !(key in progressValues)) {
                            return;
                        }
                        const scaled = Math.round(progressValues[key] * scale * 10) / 10;
                        segment.style.width = `${scaled}%`;
                        const label = segment.querySelector('span');
                        if (label) {
                            const labelValue = Number.isInteger(scaled) ? scaled.toFixed(0) : scaled.toFixed(1);
                            label.textContent = `${labelValue}%`;
                        }
                    });
                }
            }

            if (opportunityStatusList) {
                const statuses = Array.isArray(data.statusBreakdown) ? data.statusBreakdown : [];
                if (!statuses.length) {
                    opportunityStatusList.innerHTML = '<span class="text-muted small">Nessuna opportunity registrata.</span>';
                } else {
                    const fragment = document.createDocumentFragment();
                    statuses.forEach((status) => {
                        const pill = document.createElement('span');
                        const colorClass = statusColorMap[status?.color] || 'bg-secondary';
                        pill.className = `opportunity-status-pill ${colorClass}`;

                        const label = document.createElement('span');
                        label.className = 'fw-semibold';
                        label.textContent = status?.label || status?.code || '—';

                        const count = document.createElement('span');
                        count.className = 'count';
                        count.textContent = formatValue(status?.total ?? 0, 'number');

                        pill.appendChild(label);
                        pill.appendChild(count);
                        fragment.appendChild(pill);
                    });
                    opportunityStatusList.innerHTML = '';
                    opportunityStatusList.appendChild(fragment);
                }
            }

            renderOpportunityList(opportunityLatestList, data.latest, 'Nessuna opportunity recente.');
            renderOpportunityList(opportunityTodoList, data.todo, 'Nessuna opportunity aperta da lavorare.');
            if (opportunityLatestBadge) {
                const latestCount = Array.isArray(data.latest) ? data.latest.length : 0;
                opportunityLatestBadge.textContent = `Ultime ${latestCount}`;
            }
            if (opportunityTodoBadge) {
                const todoCount = Array.isArray(data.todo) ? data.todo.length : 0;
                opportunityTodoBadge.textContent = `Da fare ${todoCount}`;
            }

            if (opportunityStatusChartCanvas && window.Chart) {
                const chartStore = window.CSCharts || (window.CSCharts = {});
                const labels = Array.isArray(data.statusBreakdown) ? data.statusBreakdown.map((s) => s?.label || s?.code || 'Stato') : [];
                const values = Array.isArray(data.statusBreakdown) ? data.statusBreakdown.map((s) => Number(s?.total) || 0) : [];
                const colors = Array.isArray(data.statusBreakdown) ? data.statusBreakdown.map((s) => statusColorPalette[s?.color] || statusColorPalette.secondary) : [];

                if (chartStore.opportunityStatus) {
                    chartStore.opportunityStatus.data.labels = labels;
                    if (chartStore.opportunityStatus.data.datasets[0]) {
                        chartStore.opportunityStatus.data.datasets[0].data = values;
                        chartStore.opportunityStatus.data.datasets[0].backgroundColor = colors;
                    }
                    chartStore.opportunityStatus.update('none');
                } else {
                    chartStore.opportunityStatus = new window.Chart(opportunityStatusChartCanvas, {
                        type: 'doughnut',
                        data: {
                            labels,
                            datasets: [{
                                data: values,
                                backgroundColor: colors,
                                borderColor: '#ffffff',
                                borderWidth: 2
                            }]
                        },
                        options: {
                            plugins: {
                                legend: { position: 'bottom' }
                            }
                        }
                    });
                }
            }
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
            renderOpportunityWidget(payload.opportunities);
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
