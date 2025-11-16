/**
 * CAF & Patronato Module JavaScript
 * Handles frontend interactions for practices, operators, and configuration
 */

const CAF_HELPERS = window.CAFPatronatoHelpers || {};
const escapeHtml = CAF_HELPERS.escapeHtml || ((value) => String(value ?? ''));
const formatDateTime = CAF_HELPERS.formatDateTime || ((value) => String(value ?? ''));
const formatBytes = CAF_HELPERS.formatBytes || ((value) => `${value || 0} B`);
const coerceBoolean = CAF_HELPERS.coerceBoolean || ((value) => value === true || value === 'true' || value === 1 || value === '1');
const isValidEmail = CAF_HELPERS.isValidEmail || ((value) => /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value || '').trim()));
const resolveDocumentUrl = CAF_HELPERS.resolveDocumentUrl || ((path) => {
    if (typeof path !== 'string') {
        return '#';
    }
    let trimmed = path.trim();
    if (!trimmed) {
        return '#';
    }
    trimmed = trimmed.replace(/\\/g, '/');
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(trimmed)) {
        return trimmed;
    }
    const assetsBase = typeof window !== 'undefined' && window.CS && typeof window.CS.assetsBaseUrl === 'string'
        ? window.CS.assetsBaseUrl.replace(/\\/g, '/').replace(/\/+$/, '/')
        : null;
    if (assetsBase && trimmed.startsWith('assets/')) {
        return assetsBase + trimmed.slice(7);
    }
    if (trimmed.startsWith('/')) {
        return trimmed;
    }
    if (assetsBase) {
        return assetsBase + trimmed;
    }
    return `/${trimmed}`;
});
const CAF_PATRONATO_STORAGE_BASE = 'assets/uploads/caf-patronato';

function normalizeDocumentPath(rawPath, practiceId) {
    if (typeof rawPath !== 'string') {
        return '';
    }
    let path = rawPath.trim().replace(/\\/g, '/');
    if (!path) {
        return '';
    }
    if (/^[a-z][a-z0-9+.-]*:\/\//i.test(path)) {
        return path;
    }
    path = path.replace(/^\.\/+/u, '');
    while (path.startsWith('../')) {
        path = path.slice(3);
    }
    if (path.startsWith('/')) {
        return path;
    }
    if (path.startsWith('assets/')) {
        return path;
    }
    if (path.startsWith('uploads/')) {
        return `${CAF_PATRONATO_STORAGE_BASE}/${path.slice('uploads/'.length)}`;
    }
    const targetId = practiceId ? String(practiceId).trim() : '';
    if (targetId) {
        return `${CAF_PATRONATO_STORAGE_BASE}/${targetId}/${path}`;
    }
    return `${CAF_PATRONATO_STORAGE_BASE}/${path}`;
}

function resolvePracticeDocumentHref(documentMeta, practiceId) {
    if (!documentMeta || typeof documentMeta !== 'object') {
        return '#';
    }
    const rawPath = typeof documentMeta.download_url === 'string' && documentMeta.download_url.trim() !== ''
        ? documentMeta.download_url
        : documentMeta.file_path;
    const normalized = normalizeDocumentPath(rawPath || '', practiceId);
    return resolveDocumentUrl(normalized || rawPath || '');
}
let API_BASE = '/api/caf-patronato/index.php';

let cafContext = {
    canConfigureModule: false,
    canManagePractices: false,
    canCreatePractices: false,
    isPatronato: false,
    operatorId: null,
    useLegacyCreate: false,
    createUrl: 'create.php',
    trackingBaseUrl: '',
};
const dataCache = {
    statuses: null,
    types: null,
    operators: null,
};
const statusDictionary = {};
let assignedPracticesSnapshot = new Set();
let assignedSnapshotInitialized = false;

function normalizeStatusCode(code) {
    if (typeof code !== 'string') {
        return '';
    }
    return code.trim().toUpperCase();
}

function sanitizeStatusColor(value) {
    if (typeof value !== 'string') {
        return '';
    }
    const trimmed = value.trim();
    if (!trimmed) {
        return '';
    }
    const sanitized = trimmed.replace(/[^a-z0-9#(),.%\s-]/gi, ' ').replace(/\s+/g, ' ').slice(0, 64).trim();
    return sanitized;
}

function getStatusMeta(code) {
    const normalized = normalizeStatusCode(code);
    if (normalized && Object.prototype.hasOwnProperty.call(statusDictionary, normalized)) {
        return statusDictionary[normalized];
    }
    const fallbackLabel = typeof code === 'string' && code.trim() !== '' ? code.trim() : 'Sconosciuto';
    return {
        label: fallbackLabel,
        color: '',
    };
}

const LIGHT_BADGE_KEYWORDS = ['warning', 'info', 'light', 'soft', 'yellow', 'accent', 'white', 'muted', 'gray', 'grey', 'sand', 'beige', 'cream', 'silver', 'pastel', 'peach', 'sky', 'mint', 'lime'];

const NAMED_BADGE_COLORS = {
    slate: '#475569',
    gray: '#6b7280',
    zinc: '#71717a',
    neutral: '#737373',
    stone: '#78716c',
    red: '#dc2626',
    orange: '#f97316',
    amber: '#f59e0b',
    yellow: '#eab308',
    lime: '#84cc16',
    green: '#16a34a',
    emerald: '#059669',
    teal: '#0d9488',
    cyan: '#06b6d4',
    sky: '#0ea5e9',
    blue: '#2563eb',
    indigo: '#4f46e5',
    violet: '#7c3aed',
    purple: '#8b5cf6',
    fuchsia: '#d946ef',
    pink: '#ec4899',
    rose: '#f43f5e',
    bronze: '#b45309',
    gold: '#d97706',
    silver: '#94a3b8',
    platinum: '#d1d5db',
};

function isLightBadgeToken(token) {
    if (typeof token !== 'string') {
        return false;
    }
    const normalized = token.trim().toLowerCase();
    if (!normalized) {
        return false;
    }
    if (normalized.startsWith('soft-')) {
        return true;
    }
    return LIGHT_BADGE_KEYWORDS.some((keyword) => normalized.includes(keyword));
}

function hexToRgb(hex) {
    let clean = hex.replace('#', '').trim();
    if (clean.length === 3) {
        clean = clean.split('').map((char) => char + char).join('');
    }
    if (clean.length !== 6 && clean.length !== 8) {
        return null;
    }
    const r = parseInt(clean.slice(0, 2), 16);
    const g = parseInt(clean.slice(2, 4), 16);
    const b = parseInt(clean.slice(4, 6), 16);
    if ([r, g, b].some((component) => Number.isNaN(component))) {
        return null;
    }
    return { r, g, b };
}

function parseRgbString(value) {
    const match = value.match(/^rgba?\(([^)]+)\)$/i);
    if (!match) {
        return null;
    }
    const parts = match[1].split(',').map((part) => part.trim());
    if (parts.length < 3) {
        return null;
    }
    const [rStr, gStr, bStr] = parts;
    const parseComponent = (str) => {
        if (str.endsWith('%')) {
            const percent = parseFloat(str.slice(0, -1));
            if (Number.isNaN(percent)) {
                return null;
            }
            return Math.round(Math.min(Math.max(percent, 0), 100) * 2.55);
        }
        const valueNum = parseFloat(str);
        if (Number.isNaN(valueNum)) {
            return null;
        }
        return Math.min(Math.max(Math.round(valueNum), 0), 255);
    };
    const r = parseComponent(rStr);
    const g = parseComponent(gStr);
    const b = parseComponent(bStr);
    if ([r, g, b].some((component) => component === null)) {
        return null;
    }
    return { r, g, b };
}

function rgbToHex({ r, g, b }) {
    const toHex = (component) => component.toString(16).padStart(2, '0');
    return `#${toHex(r)}${toHex(g)}${toHex(b)}`;
}

function isRgbLight({ r, g, b }) {
    const luminance = 0.299 * r + 0.587 * g + 0.114 * b;
    return luminance > 155;
}

function computeBadgeStyle(appearance) {
    if (!appearance) {
        return '';
    }
    if (appearance.style) {
        return appearance.style;
    }
    const color = appearance.accentColor;
    if (!color) {
        return '';
    }
    let rgb = null;
    if (typeof color === 'string') {
        if (color.trim().startsWith('#')) {
            rgb = hexToRgb(color.trim());
        } else if (color.toLowerCase().startsWith('rgb')) {
            rgb = parseRgbString(color.trim());
        }
    }
    const textColor = rgb ? (isRgbLight(rgb) ? '#212529' : '#ffffff') : '#ffffff';
    return `background-color: ${color}; color: ${textColor};`;
}

function resolveStatusBadgeAppearance() {
    return {
        className: 'bg-secondary',
        textClass: 'text-white',
        style: '',
        accentColor: null,
        rawColor: null,
    };
}
const modalRefs = {
    root: null,
    instance: null,
    title: null,
    body: null,
    footer: null,
    confirmRoot: null,
    confirmInstance: null,
    confirmBody: null,
    confirmAction: null,
};

let currentPracticeDetails = null;
let operatorsDataset = [];
let activeQuickFilterId = null;
let currentPracticeFilters = {};
let currentPracticePage = 1;
let practicesAutoRefreshTimer = null;
const PRACTICES_AUTO_REFRESH_MS = 60000;

document.addEventListener('DOMContentLoaded', () => {
    const contextElement = document.getElementById('caf-patronato-context');
    if (contextElement) {
        let resolvedOperatorId = contextElement.dataset.operatorId ? parseInt(contextElement.dataset.operatorId, 10) : null;
        if (Number.isNaN(resolvedOperatorId)) {
            resolvedOperatorId = null;
        }
        cafContext = {
            ...cafContext,
            canConfigureModule: contextElement.dataset.canConfigure === '1',
            canManagePractices: contextElement.dataset.canManagePractices === '1',
            canCreatePractices: contextElement.dataset.canCreatePractices === '1',
            isPatronato: contextElement.dataset.isPatronato === '1',
            operatorId: resolvedOperatorId,
            useLegacyCreate: contextElement.dataset.useLegacyCreate === '1',
            createUrl: contextElement.dataset.createUrl || cafContext.createUrl,
            trackingBaseUrl: contextElement.dataset.trackingBaseUrl || cafContext.trackingBaseUrl,
        };
        if (contextElement.dataset.apiBase) {
            API_BASE = contextElement.dataset.apiBase;
        }
    }

    initModals();
    const modules = detectModulesFromDOM();
    if (modules.has('dashboard')) {
        initDashboard();
    }
    if (modules.has('practices')) {
        initPracticesModule();
    }
    if (modules.has('practice-view')) {
        initPracticeDetailPage();
    }
    if (modules.has('practice-status')) {
        initPracticeStatusPage();
    }
    if (modules.has('practice-edit')) {
        initPracticeEditPage();
    }
    if (modules.has('admin')) {
        initAdminModule();
    }
    if (modules.has('operators')) {
        initOperatorsModule();
    }

    setupQuickFilters();
    setupGlobalShortcuts();
    refreshNotificationsPreview();
});

function detectModulesFromDOM() {
    const modules = new Set();
    if (document.getElementById('caf-patronato-dashboard')) modules.add('dashboard');
    if (document.getElementById('caf-patronato-practices')) modules.add('practices');
    if (document.getElementById('caf-patronato-practice-view')) modules.add('practice-view');
    if (document.getElementById('caf-patronato-practice-status')) modules.add('practice-status');
    if (document.getElementById('caf-patronato-practice-edit')) modules.add('practice-edit');
    if (document.getElementById('caf-patronato-admin')) modules.add('admin');
    if (document.getElementById('caf-patronato-operators')) modules.add('operators');
    return modules;
}

function setupGlobalShortcuts() {
    const inlineCreateBtn = document.getElementById('new-practice-inline');
    if (inlineCreateBtn) {
        if (!cafContext.canCreatePractices) {
            inlineCreateBtn.classList.add('d-none');
        } else if (cafContext.useLegacyCreate) {
            if (cafContext.createUrl) {
                inlineCreateBtn.setAttribute('href', cafContext.createUrl);
            }
        } else {
            inlineCreateBtn.addEventListener('click', (event) => {
                event.preventDefault();
                showCreatePracticeModal();
            });
        }
    }

    document.querySelectorAll('[data-open-notifications]').forEach((element) => {
            element.addEventListener('click', (event) => {
                event.preventDefault();
                openNotificationsModal();
            });
        });

        document.querySelectorAll('[data-open-practice-recap]').forEach((element) => {
            element.addEventListener('click', (event) => {
                event.preventDefault();
                openQuickRecapModal();
            });
        });
}

function setupQuickFilters() {
    const quickFilterButtons = document.querySelectorAll('[data-quick-filter]');
    if (!quickFilterButtons.length) {
        return;
    }

    let initialActiveFilterId = null;
    quickFilterButtons.forEach((button) => {
        if (button.dataset.quickFilterBound === '1') {
            return;
        }
        button.dataset.quickFilterBound = '1';
        button.addEventListener('click', (event) => {
            event.preventDefault();
            const filterId = button.dataset.quickFilter || null;
            if (!filterId) {
                return;
            }

            let filtersPayload = {};
            const rawFilters = button.dataset.filters;
            if (rawFilters) {
                try {
                    filtersPayload = JSON.parse(rawFilters);
                } catch (error) {
                    console.warn('Impossibile analizzare i filtri rapidi', error);
                }
            }

            applyQuickFilter(filtersPayload, filterId);
        });

        if (button.classList.contains('active') && initialActiveFilterId === null) {
            initialActiveFilterId = button.dataset.quickFilter || null;
        }
    });

    if (initialActiveFilterId) {
        setQuickFilterActive(initialActiveFilterId);
    }
}

function applyQuickFilter(filtersPayload, filterId) {
    const form = document.getElementById('practices-filters-form');
    if (!form) {
        return;
    }

    const preservedFields = ['per_page'];
    const preservedValues = {};
    preservedFields.forEach((fieldName) => {
        const field = form.elements.namedItem(fieldName);
        if (field && 'value' in field) {
            preservedValues[fieldName] = field.value;
        }
    });

    form.reset();
    setFormValues(form, filtersPayload);

    preservedFields.forEach((fieldName) => {
        if (!(fieldName in filtersPayload) && preservedValues[fieldName] !== undefined) {
            const field = form.elements.namedItem(fieldName);
            if (field && 'value' in field) {
                field.value = preservedValues[fieldName];
            }
        }
    });

    activeQuickFilterId = filterId;
    setQuickFilterActive(filterId);
    loadPracticesList();
}

function setQuickFilterActive(filterId) {
    const quickFilterButtons = document.querySelectorAll('[data-quick-filter]');
    quickFilterButtons.forEach((button) => {
        const isActive = button.dataset.quickFilter === filterId;
        button.classList.toggle('active', isActive);
        if (isActive) {
            button.setAttribute('aria-current', 'true');
        } else {
            button.removeAttribute('aria-current');
        }
    });
    activeQuickFilterId = filterId;

    const activeFilterLabel = document.getElementById('active-quick-filter');
    if (activeFilterLabel) {
        const activeBtn = Array.from(quickFilterButtons).find((button) => button.dataset.quickFilter === filterId);
        const shouldShow = Boolean(filterId && filterId !== 'all' && activeBtn);
        if (shouldShow) {
            activeFilterLabel.textContent = activeBtn.dataset.quickFilterLabel || activeBtn.textContent.trim();
            activeFilterLabel.style.display = 'inline-flex';
        } else {
            activeFilterLabel.style.display = 'none';
        }
    }
}

function updateQuickFilterCounts(summaries = {}) {
    const { per_stato: perStato = {} } = summaries;
    const normalizedCounts = {};
    Object.entries(perStato).forEach(([key, value]) => {
        const normalizedKey = normalizeStatusCode(key);
        if (!normalizedKey) {
            return;
        }
        normalizedCounts[normalizedKey] = value;
    });
    const quickFilterButtons = document.querySelectorAll('[data-quick-filter]');
    quickFilterButtons.forEach((button) => {
        const statusKey = button.dataset.quickFilterStatus;
        if (!statusKey) {
            return;
        }
        const normalizedKey = normalizeStatusCode(statusKey);
        const countValue = normalizedKey ? (normalizedCounts[normalizedKey] ?? 0) : 0;
        const badge = button.querySelector('[data-quick-filter-count]');
        if (badge) {
            badge.textContent = String(countValue);
            badge.classList.toggle('d-none', false);
        }
    });
}

function updateHeroMetrics(summaries = {}) {
    updateSummaryElement('summary-total', summaries.totale ?? 0);
    updateSummaryElement('summary-total-badge', summaries.totale ?? 0);
    const perStato = summaries.per_stato || {};
    Object.entries(perStato).forEach(([statusKey, value]) => {
        const meta = getStatusMeta(statusKey);
        const appearance = resolveStatusBadgeAppearance(meta.color);
        const valueElement = ensureHeroStatusCard(statusKey, meta, appearance);
        if (valueElement) {
            const body = valueElement.closest('.hero-kpi-body');
            const labelElement = body?.querySelector('.hero-kpi-label');
            if (labelElement) {
                labelElement.textContent = meta.label;
            }
            const iconElement = valueElement.closest('.hero-kpi')?.querySelector('.hero-kpi-icon');
            if (iconElement) {
                applyHeroIconAppearance(iconElement, appearance);
            }
            updateSummaryElement(valueElement.id, value);
        }
    });
}

function findHeroStatusValueElement(statusKey) {
    if (statusKey === null || statusKey === undefined) {
        return null;
    }
    const normalized = normalizeStatusCode(statusKey);
    const raw = typeof statusKey === 'string' ? statusKey.trim() : String(statusKey);
    const candidates = [];
    if (normalized) {
        candidates.push(`summary-status-${normalized}`);
        candidates.push(`summary-status-${normalized.toLowerCase()}`);
        candidates.push(`summary-status-${normalized.toLowerCase().replace(/[^a-z0-9]+/g, '_')}`);
    }
    if (raw !== '') {
        candidates.push(`summary-status-${raw}`);
        candidates.push(`summary-status-${raw.toLowerCase().replace(/[^a-z0-9]+/g, '_')}`);
    }
    for (const id of candidates) {
        const element = document.getElementById(id);
        if (element) {
            return element;
        }
    }
    return null;
}

function applyHeroIconAppearance(iconElement, appearance) {
    if (!iconElement || !appearance) {
        return;
    }
    if (appearance.className) {
        const token = appearance.className.startsWith('bg-') ? appearance.className.slice(3) : appearance.className;
        const baseToken = token.startsWith('soft-') ? token.slice(5) : token;
        iconElement.className = `hero-kpi-icon hero-kpi-icon-services text-${baseToken}`;
        iconElement.style.color = '';
    } else if (appearance.accentColor) {
        iconElement.className = 'hero-kpi-icon hero-kpi-icon-services';
        iconElement.style.color = appearance.accentColor;
    } else {
        iconElement.className = 'hero-kpi-icon hero-kpi-icon-services text-secondary';
        iconElement.style.color = '';
    }
}

function ensureHeroStatusCard(statusKey, meta, appearance) {
    const existing = findHeroStatusValueElement(statusKey);
    if (existing) {
        return existing;
    }

    const rawKey = typeof statusKey === 'string' ? statusKey.trim() : String(statusKey ?? '');
    const normalizedKey = normalizeStatusCode(statusKey) || rawKey || 'UNKNOWN';
    const idKey = (normalizedKey.toLowerCase() || 'unknown').replace(/[^a-z0-9]+/g, '_') || 'unknown';
    const container = document.getElementById('hero-status-grid');
    if (!container) {
        return null;
    }

    const wrapper = document.createElement('div');
    wrapper.className = 'hero-kpi';
    wrapper.dataset.heroStatus = normalizedKey;
    wrapper.innerHTML = `
        <div class="hero-kpi-icon hero-kpi-icon-services"><i class="fa-solid fa-circle"></i></div>
        <div class="hero-kpi-body">
            <span class="hero-kpi-label">${escapeHtml(meta.label)}</span>
            <span class="hero-kpi-value" id="summary-status-${idKey}">0</span>
        </div>
    `;
    container.appendChild(wrapper);

    const valueElement = wrapper.querySelector(`#summary-status-${idKey}`);
    const iconElement = wrapper.querySelector('.hero-kpi-icon');
    if (iconElement) {
        applyHeroIconAppearance(iconElement, appearance);
    }
    return valueElement;
}

function updateSummaryElement(elementId, value) {
    const element = document.getElementById(elementId);
    if (!element) {
        return;
    }
    element.textContent = typeof value === 'number' ? value.toString() : String(value ?? '0');
}

function openQuickRecapModal() {
    const totalValue = document.getElementById('summary-total')?.textContent?.trim() || '0';
    const statusItems = Object.entries(statusDictionary).map(([code, meta]) => {
        const value = document.getElementById(`summary-status-${code}`)?.textContent?.trim() || '0';
        return {
            code,
            label: meta.label || code,
            value,
        };
    });

    let statusList = '';
    if (statusItems.length > 0) {
        statusList = statusItems.map((item) => `
            <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>${escapeHtml(item.label)}</span>
                <span class="badge bg-secondary">${escapeHtml(item.value)}</span>
            </li>`).join('');
    } else {
        statusList = '<li class="list-group-item text-muted">Nessun dato di stato disponibile.</li>';
    }

    const activeFiltersHtml = document.getElementById('active-filters-list')?.innerHTML || '';
    const quickFilterLabel = document.getElementById('active-quick-filter')?.textContent?.trim() || '';

    const body = `
        <div class="mb-3">
            <div class="d-flex justify-content-between align-items-center">
                <span class="text-muted">Totale pratiche</span>
                <span class="display-6 fw-semibold">${escapeHtml(totalValue)}</span>
            </div>
        </div>
        <div class="mb-3">
            <h6 class="text-uppercase text-muted fw-semibold small">Pratiche per stato</h6>
            <ul class="list-group list-group-flush">
                ${statusList}
            </ul>
        </div>
        <div>
            <h6 class="text-uppercase text-muted fw-semibold small">Filtri correnti</h6>
            <div class="d-flex flex-wrap gap-2">
                ${quickFilterLabel ? `<span class="badge bg-warning text-dark"><i class="fa-solid fa-bolt me-1"></i>${escapeHtml(quickFilterLabel)}</span>` : ''}
                ${activeFiltersHtml || '<span class="text-muted small">Nessun filtro attivo</span>'}
            </div>
        </div>
    `;

    openModal({
        title: 'Riepilogo pratiche',
        body,
        footer: '',
        size: 'md',
    });
}

async function openNotificationsModal() {
    try {
        const response = await apiRequest('GET', { action: 'list_notifications', show_read: 1 });
        const notifications = response.data.notifications || [];
        const body = buildNotificationsMarkup(notifications, {
            showActions: false,
            compact: false,
            emptyMessage: 'Nessuna notifica disponibile.',
        });
        openModal({
            title: 'Notifiche CAF & Patronato',
            body,
            footer: '',
            size: 'lg',
        });
    } catch (error) {
        openModal({
            title: 'Notifiche CAF & Patronato',
            body: `<div class="alert alert-danger" role="alert">${escapeHtml(error.message || 'Errore nel caricamento delle notifiche.')}</div>`,
            footer: '',
        });
    }
}

function resolveStatusLabel(code) {
    return getStatusMeta(code).label;
}

function resolveTypeLabel(typeId) {
    if (!dataCache.types) {
        return `Tipo #${typeId}`;
    }
    const found = dataCache.types.find((type) => String(type.id) === String(typeId));
    return found ? found.nome : `Tipo #${typeId}`;
}

function resolveOperatorLabel(operatorId) {
    if (!dataCache.operators) {
        return `Operatore #${operatorId}`;
    }
    const found = dataCache.operators.find((operator) => String(operator.id) === String(operatorId));
    if (!found) {
        return `Operatore #${operatorId}`;
    }
    return `${found.nome ?? ''} ${found.cognome ?? ''}`.trim() || `Operatore #${operatorId}`;
}

function formatDateForBadge(value) {
    if (!value) {
        return '';
    }
    if (/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        const [year, month, day] = value.split('-');
        return `${day}/${month}/${year}`;
    }
    return value;
}

function refreshNotificationsPreview() {
    loadNotificationsList({ target: 'preview', limit: 5, compact: true });
}

async function loadNotificationsList(options = {}) {
    const {
        target = 'admin',
        showRead = false,
        limit = 5,
        compact = false,
    } = options;

    const containerId = target === 'admin' ? 'notifications-list' : 'notifications-preview';
    const container = document.getElementById(containerId);
    if (!container) {
        return;
    }

    if (target === 'admin') {
        showSpinner(container);
    } else {
        container.innerHTML = '<div class="small text-muted">Caricamento notifiche...</div>';
    }

    try {
        const params = { action: 'list_notifications' };
        if (showRead) {
            params.show_read = 1;
        }
        const response = await apiRequest('GET', params);
        const fullNotifications = Array.isArray(response.data.notifications) ? response.data.notifications : [];
        let notifications = fullNotifications;
        if (target === 'preview' && limit > 0) {
            notifications = fullNotifications.slice(0, limit);
        }
        container.innerHTML = buildNotificationsMarkup(notifications, {
            showActions: target === 'admin',
            compact: target === 'preview' || compact,
            emptyMessage: target === 'admin' ? 'Nessuna notifica registrata.' : 'Nessuna notifica recente.',
        });
        updateNotificationsCounters(fullNotifications);
    } catch (error) {
        container.innerHTML = `<div class="alert alert-danger" role="alert">${escapeHtml(error.message || 'Errore nel caricamento delle notifiche.')}</div>`;
        updateNotificationsCounters([]);
    }
}

function buildNotificationsMarkup(notifications, { showActions = false, compact = false, emptyMessage = 'Nessuna notifica disponibile.' } = {}) {
    if (!Array.isArray(notifications) || notifications.length === 0) {
        return `<div class="${compact ? 'small ' : ''}text-muted py-3">${escapeHtml(emptyMessage)}</div>`;
    }

    const listItems = notifications.map((notification) => {
        const isNew = notification.stato !== 'letta';
        const badgeClass = isNew ? 'badge bg-warning text-dark' : 'badge bg-secondary';
        const practiceLink = notification.pratica_id ? `view.php?id=${notification.pratica_id}` : '';
        const practiceBadge = notification.pratica_id ? `<a href="${practiceLink}" class="badge bg-primary-subtle text-primary ms-2"><i class="fa-solid fa-folder-open me-1"></i>#${notification.pratica_id}</a>` : '';
        const actions = [];
        if (showActions && notification.stato !== 'letta') {
            actions.push(`<button type="button" class="btn btn-link btn-sm text-success" data-action="mark-notification" data-notification-id="${notification.id}"><i class="fa-solid fa-circle-check me-1"></i>Segna letta</button>`);
        }
        if (showActions && notification.pratica_id) {
            actions.push(`<a class="btn btn-link btn-sm" href="view.php?id=${notification.pratica_id}"><i class="fa-solid fa-up-right-from-square me-1"></i>Apri pratica</a>`);
        }

        const actionBar = actions.length ? `<div class="mt-2 d-flex flex-wrap gap-2">${actions.join('')}</div>` : '';
        const metaLine = `<div class="small text-muted mt-1"><i class="fa-regular fa-clock me-1"></i>${escapeHtml(formatDateTime(notification.created_at))}</div>`;

        return `
            <div class="list-group-item ${compact ? 'py-3' : 'py-3'}" data-notification-id="${notification.id}">
                <div class="d-flex justify-content-between align-items-start gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <span class="${badgeClass}">${escapeHtml(notification.tipo)}</span>
                            ${practiceBadge}
                        </div>
                        <div class="fw-semibold mt-2">${escapeHtml(notification.messaggio)}</div>
                        ${metaLine}
                        ${actionBar}
                    </div>
                    ${isNew ? '<span class="text-warning"><i class="fa-solid fa-circle" aria-hidden="true"></i><span class="visually-hidden">Nuova</span></span>' : ''}
                </div>
            </div>
        `;
    });

    return `<div class="list-group list-group-flush">${listItems.join('')}</div>`;
}

function handleNotificationAction(event) {
    const target = event.target.closest('[data-action]');
    if (!target) {
        return;
    }

    const notificationId = parseInt(target.dataset.notificationId || '', 10);
    if (Number.isNaN(notificationId) || notificationId <= 0) {
        return;
    }

    switch (target.dataset.action) {
        case 'mark-notification':
            event.preventDefault();
            markNotificationAsRead(notificationId);
            break;
        default:
            break;
    }
}

async function markNotificationAsRead(notificationId) {
    try {
        await apiRequest('POST', {
            action: 'mark_notification',
            id: notificationId,
        });
        if (window.CS?.showToast) {
            window.CS.showToast('Notifica segnata come letta.', 'success');
        }
    } catch (error) {
        if (window.CS?.showToast) {
            window.CS.showToast(error.message || 'Errore nel marcare la notifica come letta.', 'error');
        }
    } finally {
        loadNotificationsList({ target: 'admin', showRead: document.getElementById('show-read-notifications')?.checked ?? false });
        refreshNotificationsPreview();
    }
}

// Dashboard Module
function initDashboard() {
    loadPracticesSummary();
    
    const refreshBtn = document.getElementById('refresh-summary');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', loadPracticesSummary);
    }
    
    const newPracticeLink = document.getElementById('new-practice-link');
    if (newPracticeLink) {
        if (cafContext.useLegacyCreate) {
            if (cafContext.createUrl) {
                newPracticeLink.setAttribute('href', cafContext.createUrl);
            }
        } else {
            newPracticeLink.addEventListener('click', (e) => {
                e.preventDefault();
                showCreatePracticeModal();
            });
        }
    }
}

async function loadPracticesSummary() {
    const container = document.getElementById('practices-summary-container');
    if (container) {
        showSpinner(container);
    }

    try {
        const response = await apiRequest('GET', { action: 'list_practices', per_page: 1 });
        const { summaries } = response.data;
        updateHeroMetrics(summaries);
        updateQuickFilterCounts(summaries);
        if (container) {
            renderPracticesSummary(container, summaries);
        }
    } catch (error) {
        if (container) {
            showError(container, 'Errore nel caricamento del riepilogo: ' + error.message);
        }
    }
}

function renderPracticesSummary(container, summaries) {
    const { totale, per_stato } = summaries;
    
    const statusColors = {
        'in_lavorazione': 'primary',
        'completata': 'success',
        'sospesa': 'warning',
        'archiviata': 'secondary'
    };
    
    const statusLabels = {
        'in_lavorazione': 'In lavorazione',
        'completata': 'Completata',
        'sospesa': 'Sospesa',
        'archiviata': 'Archiviata'
    };
    
    let html = `
        <div class="row g-3 mb-4">
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-primary bg-opacity-10 text-primary me-3">
                        <i class="fa-solid fa-folder-open"></i>
                    </div>
                    <div>
                        <div class="h5 mb-0">${totale}</div>
                        <small class="text-muted">Pratiche totali</small>
                    </div>
                </div>
            </div>
    `;
    
    Object.entries(per_stato).forEach(([stato, count]) => {
        const color = statusColors[stato] || 'secondary';
        const label = statusLabels[stato] || stato;
        html += `
            <div class="col-6 col-md-3">
                <div class="d-flex align-items-center">
                    <div class="stat-icon bg-${color} bg-opacity-10 text-${color} me-3">
                        <i class="fa-solid fa-circle"></i>
                    </div>
                    <div>
                        <div class="h6 mb-0">${count}</div>
                        <small class="text-muted">${label}</small>
                    </div>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    
    if (totale > 0) {
        html += `
            <div class="d-flex justify-content-center">
                <a href="index.php?page=practices" class="btn btn-outline-primary">
                    <i class="fa-solid fa-arrow-right me-2"></i>Visualizza tutte le pratiche
                </a>
            </div>
        `;
    } else {
        html += `
            <div class="text-center text-muted py-3">
                <i class="fa-solid fa-folder-open fa-2x mb-3 opacity-50"></i>
                <p>Nessuna pratica presente nel sistema.</p>
            </div>
        `;
    }
    
    container.innerHTML = html;
}

// Practices Module
function initPracticesModule() {
    loadFiltersData();
    loadPracticesList();
    setupQuickFilters();
    
    const filtersForm = document.getElementById('practices-filters-form');
    if (filtersForm) {
        filtersForm.addEventListener('submit', (e) => {
            e.preventDefault();
            setQuickFilterActive(null);
            loadPracticesList();
        });
    }
    
    const clearFiltersBtn = document.getElementById('clear-filters');
    if (clearFiltersBtn) {
        clearFiltersBtn.addEventListener('click', () => {
            if (filtersForm) {
                filtersForm.reset();
            }
            setQuickFilterActive(null);
            loadPracticesList();
        });
    }
    
    const refreshBtn = document.getElementById('refresh-practices');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            loadPracticesList(currentPracticePage);
            startPracticesAutoRefresh();
        });
    }
    
    const createBtn = document.getElementById('create-practice-btn');
    if (createBtn) {
        if (cafContext.useLegacyCreate) {
            if (cafContext.createUrl) {
                createBtn.setAttribute('href', cafContext.createUrl);
            }
        } else {
            createBtn.addEventListener('click', (event) => {
                event.preventDefault();
                showCreatePracticeModal();
            });
        }
    }
}

function startPracticesAutoRefresh() {
    if (practicesAutoRefreshTimer) {
        clearInterval(practicesAutoRefreshTimer);
    }
    const container = document.getElementById('practices-table-container');
    if (!container) {
        return;
    }
    practicesAutoRefreshTimer = window.setInterval(() => {
        loadPracticesList(currentPracticePage, { silent: true });
    }, PRACTICES_AUTO_REFRESH_MS);
}

function initPracticeDetailPage() {
    const container = document.getElementById('caf-patronato-practice-view');
    if (!container) return;

    const rawId = container.dataset.practiceId || '';
    const practiceId = parseInt(rawId, 10);
    if (Number.isNaN(practiceId) || practiceId <= 0) {
        showError(container, 'ID pratica non valido.');
        return;
    }

    const editBtn = document.getElementById('page-edit-practice');
    if (editBtn) {
        editBtn.addEventListener('click', () => editPractice(practiceId));
    }

    const refreshBtn = document.getElementById('page-refresh-practice');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => loadPracticeDetailInto(container, practiceId));
    }

    loadPracticeDetailInto(container, practiceId);
}

async function loadPracticeDetailInto(container, practiceId) {
    try {
        showSpinner(container);
        const [practiceResponse, statuses] = await Promise.all([
            apiRequest('GET', { action: 'get_practice', id: practiceId }),
            fetchStatuses(),
        ]);

        currentPracticeDetails = practiceResponse.data;
        updatePracticePageHero(currentPracticeDetails);
        const statusList = Array.isArray(statuses) && statuses.length ? statuses : (dataCache.statuses || []);
        container.innerHTML = buildPracticeDetailMarkup(currentPracticeDetails, statusList);
        setupPracticeDetailView(container, currentPracticeDetails, statusList, {
            mode: 'page',
            container,
        });
    } catch (error) {
        console.error('Errore nel caricamento pratica:', error);
        showError(container, 'Errore nel caricamento della pratica: ' + error.message);
    }
}

function initPracticeEditPage() {
    const container = document.getElementById('caf-patronato-practice-edit');
    if (!container) {
        return;
    }

    const rawId = container.dataset.practiceId || '';
    const practiceId = parseInt(rawId, 10);
    if (Number.isNaN(practiceId) || practiceId <= 0) {
        showError(container, 'ID pratica non valido.');
        return;
    }

    loadPracticeEditForm(container, practiceId);
}

async function loadPracticeEditForm(container, practiceId) {
    try {
        showSpinner(container);
        const [practiceResponse, types, statuses, operators] = await Promise.all([
            apiRequest('GET', { action: 'get_practice', id: practiceId }),
            fetchTypes(true),
            fetchStatuses(true),
            fetchOperators(true),
        ]);

        const practice = practiceResponse.data;
        currentPracticeDetails = practice;

        const titleEl = document.getElementById('practice-edit-title');
        if (titleEl) {
            titleEl.textContent = practice.titolo || `Pratica #${practice.id}`;
        }
        if (practice.titolo) {
            document.title = `Modifica pratica: ${practice.titolo}`;
        }

        const codeEl = document.getElementById('practice-edit-code');
        if (codeEl) {
            codeEl.textContent = `#${practice.id}`;
        }

        const statusBadge = document.getElementById('practice-edit-status');
        if (statusBadge) {
            const statusMeta = getStatusMeta(practice.stato);
            statusBadge.textContent = statusMeta.label;
            const appearance = resolveStatusBadgeAppearance(statusMeta.color);
            const classes = ['badge'];
            if (appearance.className) {
                classes.push(appearance.className);
            }
            if (!appearance.className) {
                classes.push('bg-secondary');
            }
            if (appearance.textClass) {
                classes.push(appearance.textClass);
            }
            if (!appearance.textClass && !appearance.style && !appearance.accentColor) {
                classes.push('text-white');
            }
            statusBadge.className = classes.join(' ');
            const badgeStyle = computeBadgeStyle(appearance);
            if (badgeStyle) {
                statusBadge.setAttribute('style', badgeStyle);
            } else {
                statusBadge.removeAttribute('style');
            }
        }

        const categoryBadge = document.getElementById('practice-edit-category');
        if (categoryBadge) {
            const categoria = practice.categoria || 'CAF';
            const isPatronato = categoria.toUpperCase() === 'PATRONATO';
            categoryBadge.textContent = categoria;
            categoryBadge.className = `badge ${isPatronato ? 'bg-warning text-dark' : 'bg-info'}`;
        }

        const operatorLabel = document.getElementById('practice-edit-operator');
        if (operatorLabel) {
            if (practice.assegnatario) {
                const nome = practice.assegnatario.nome || '';
                const cognome = practice.assegnatario.cognome || '';
                const fullName = `${nome} ${cognome}`.trim();
                operatorLabel.textContent = fullName || (practice.assegnatario.email || 'N/D');
            } else {
                operatorLabel.textContent = 'Non assegnata';
            }
        }

        if (practice.scadenza) {
            const dueEl = document.getElementById('practice-edit-deadline');
            if (dueEl) {
                dueEl.textContent = formatDateTime(practice.scadenza);
                const deadlineWrapper = dueEl.closest('[data-role="practice-deadline"]');
                if (deadlineWrapper) {
                    deadlineWrapper.classList.remove('d-none');
                }
            }
        }

        if (practice.data_aggiornamento) {
            const updatedEl = document.getElementById('practice-edit-updated');
            if (updatedEl) {
                updatedEl.textContent = formatDateTime(practice.data_aggiornamento);
            }
        }

        if (practice.data_creazione) {
            const createdEl = document.getElementById('practice-edit-created');
            if (createdEl) {
                createdEl.textContent = formatDateTime(practice.data_creazione);
            }
        }

        container.innerHTML = `
            <div class="card ag-card">
                <div class="card-body">
                    ${buildPracticeFormMarkup({
                        mode: 'edit',
                        types,
                        statuses,
                        operators,
                        practice,
                    })}
                    <div class="d-flex justify-content-end gap-2 mt-4">
                        <a class="btn btn-outline-secondary" href="view.php?id=${encodeURIComponent(practiceId)}">Annulla</a>
                        <button type="submit" form="caf-patronato-modal-form" id="caf-patronato-submit" class="btn btn-primary">Salva modifiche</button>
                    </div>
                </div>
            </div>
        `;

        setupPracticeForm(container, {
            mode: 'edit',
            types,
            statuses,
            operators,
            practice,
            submitButtonId: 'caf-patronato-submit',
            onSuccess: ({ response }) => {
                const nextId = response?.data?.id || practice.id;
                window.location.href = `view.php?id=${encodeURIComponent(nextId)}`;
            },
        });
    } catch (error) {
        console.error('Errore caricamento editor pratica:', error);
        showError(container, 'Impossibile caricare i dati della pratica: ' + error.message);
    }
}

function initPracticeStatusPage() {
    const container = document.getElementById('caf-patronato-practice-status');
    if (!container) {
        return;
    }

    const rawId = container.dataset.practiceId || '';
    const practiceId = parseInt(rawId, 10);
    if (Number.isNaN(practiceId) || practiceId <= 0) {
        showError(container, 'ID pratica non valido.');
        return;
    }

    loadPracticeStatusView(container, practiceId);
}

async function loadPracticeStatusView(container, practiceId) {
    try {
        showSpinner(container);
        const [practiceResponse, statuses] = await Promise.all([
            apiRequest('GET', { action: 'get_practice', id: practiceId }),
            fetchStatuses(),
        ]);

        const practice = practiceResponse.data;
        currentPracticeDetails = practice;
        updatePracticeStatusHero(practice);
        container.innerHTML = buildPracticeStatusMarkup(practice, statuses);
        setupPracticeStatusPage(container, practiceId);
    } catch (error) {
        console.error('Errore caricamento pagina stato:', error);
        showError(container, 'Impossibile caricare i dati della pratica: ' + error.message);
    }
}

function updatePracticeStatusHero(practice) {
    if (practice.titolo) {
        document.title = `Cambia stato: ${practice.titolo}`;
    }

    const titleEl = document.getElementById('practice-status-title');
    if (titleEl) {
        titleEl.textContent = practice.titolo || `Pratica #${practice.id}`;
    }

    const codeEl = document.getElementById('practice-status-code');
    if (codeEl) {
        codeEl.textContent = `#${practice.id}`;
    }

    const statusBadge = document.getElementById('practice-status-badge');
    if (statusBadge) {
        const meta = getStatusMeta(practice.stato);
        const appearance = resolveStatusBadgeAppearance(meta.color);
        const classes = ['badge'];
        if (appearance.className) {
            classes.push(appearance.className);
        }
        if (!appearance.className) {
            classes.push('bg-secondary');
        }
        if (appearance.textClass) {
            classes.push(appearance.textClass);
        }
        if (!appearance.textClass && !appearance.style && !appearance.accentColor) {
            classes.push('text-white');
        }
        statusBadge.textContent = meta.label || practice.stato;
        statusBadge.className = classes.join(' ');
        const badgeStyle = computeBadgeStyle(appearance);
        if (badgeStyle) {
            statusBadge.setAttribute('style', badgeStyle);
        } else {
            statusBadge.removeAttribute('style');
        }
    }

    const categoryBadge = document.getElementById('practice-status-category');
    if (categoryBadge) {
        const category = practice.categoria || '';
        const isPatronato = category.toUpperCase() === 'PATRONATO';
        categoryBadge.textContent = category || 'N/D';
        categoryBadge.className = `badge ${isPatronato ? 'bg-warning text-dark' : 'bg-info'}`;
    }

    const operatorEl = document.getElementById('practice-status-operator');
    if (operatorEl) {
        if (practice.assegnatario) {
            const nome = practice.assegnatario.nome || '';
            const cognome = practice.assegnatario.cognome || '';
            const fullName = `${nome} ${cognome}`.trim();
            operatorEl.textContent = fullName || practice.assegnatario.email || 'N/D';
        } else {
            operatorEl.textContent = 'Non assegnata';
        }
    }

    const updatedEl = document.getElementById('practice-status-updated');
    if (updatedEl) {
        updatedEl.textContent = practice.data_aggiornamento ? formatDateTime(practice.data_aggiornamento) : 'N/D';
    }

    const createdEl = document.getElementById('practice-status-created');
    if (createdEl) {
        createdEl.textContent = practice.data_creazione ? formatDateTime(practice.data_creazione) : 'N/D';
    }
}

function buildPracticeStatusMarkup(practice, statuses) {
    const canManage = cafContext.canManagePractices;
    const currentStatusKey = normalizeStatusCode(practice.stato);
    const statusOptions = statuses.map((status) => {
        const optionKey = normalizeStatusCode(status.codice);
        const isSelected = optionKey ? optionKey === currentStatusKey : status.codice === practice.stato;
        return `
        <option value="${escapeHtml(status.codice)}" ${isSelected ? 'selected' : ''}>${escapeHtml(status.nome)}</option>
    `;
    }).join('');

    const statusFormMarkup = canManage ? `
        <form id="practice-status-update-form" class="row g-3 align-items-end">
            <div class="col-md-6 col-lg-4">
                <label class="form-label" for="practice-status-select">Nuovo stato</label>
                <select class="form-select" id="practice-status-select" name="status" required>
                    ${statusOptions}
                </select>
            </div>
            <div class="col-md-3 col-lg-2">
                <button class="btn btn-primary w-100" type="submit">Aggiorna stato</button>
            </div>
        </form>
    ` : `
        <div class="alert alert-secondary" role="alert">
            Solo gli operatori autorizzati possono aggiornare lo stato della pratica.
        </div>
    `;

    const uploadFormMarkup = canManage ? `
        <form id="practice-status-upload-form" class="row g-3 align-items-end" enctype="multipart/form-data">
            <div class="col-md-6 col-lg-5">
                <label class="form-label" for="practice-status-document">Carica documento elaborato</label>
                <input class="form-control" type="file" id="practice-status-document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp" required>
            </div>
            <div class="col-md-3 col-lg-2">
                <button class="btn btn-outline-primary w-100" type="submit">Carica file</button>
            </div>
        </form>
        <p class="small text-muted mb-0">Il documento sarà disponibile nell'elenco pratiche per il download.</p>
    ` : `
        <div class="alert alert-secondary" role="alert">
            Solo gli operatori autorizzati possono caricare documenti elaborati.
        </div>
    `;

    const documents = Array.isArray(practice.documenti) && practice.documenti.length
        ? practice.documenti
        : (Array.isArray(practice.allegati) ? practice.allegati : []);

    const attachmentsMarkup = documents.length ? documents.map((doc) => {
        const href = resolvePracticeDocumentHref(doc, practice.id);
        return `
        <div class="list-group-item d-flex justify-content-between align-items-start" data-document-id="${doc.id}">
            <div>
                <div class="fw-semibold">${escapeHtml(doc.file_name)}</div>
                <div class="text-muted small">${formatBytes(doc.file_size)} · ${escapeHtml(doc.mime_type || '')} · ${formatDateTime(doc.created_at)}</div>
            </div>
            <div class="btn-group btn-group-sm">
                <a class="btn btn-outline-primary" href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer" title="Scarica">
                    <i class="fa-solid fa-download"></i>
                </a>
            </div>
        </div>
    `;
    }).join('') : '<div class="list-group-item text-muted">Nessun documento caricato.</div>';

    return `
        <div class="card ag-card">
            <div class="card-body">
                <section class="mb-4">
                    <h5 class="mb-3">Aggiorna stato</h5>
                    ${statusFormMarkup}
                </section>
                <hr>
                <section>
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="mb-0">Documento pratica</h5>
                        <span class="badge bg-secondary">${documents.length}</span>
                    </div>
                    ${uploadFormMarkup}
                    <div class="list-group mt-3" data-role="attachments-list">
                        ${attachmentsMarkup}
                    </div>
                </section>
            </div>
        </div>
    `;
}

function setupPracticeStatusPage(container, practiceId) {
    const statusForm = container.querySelector('#practice-status-update-form');
    if (statusForm) {
        statusForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const select = statusForm.querySelector('select[name="status"]');
            if (!select || !select.value) {
                window.CS?.showToast?.('Seleziona uno stato valido.', 'warning');
                return;
            }
            const submitBtn = statusForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.originalText = submitBtn.dataset.originalText || submitBtn.textContent;
                submitBtn.textContent = 'Aggiornamento...';
            }
            let succeeded = false;
            try {
                await apiRequest('POST', {
                    action: 'update_status',
                    id: practiceId,
                    status: select.value,
                });
                succeeded = true;
                window.CS?.showToast?.('Stato pratica aggiornato.', 'success');
            } catch (error) {
                window.CS?.showToast?.(`Errore nell'aggiornamento dello stato: ${error.message}`, 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Aggiorna stato';
                }
            }
            if (succeeded) {
                await loadPracticeStatusView(container, practiceId);
            }
        });
    }

    const uploadForm = container.querySelector('#practice-status-upload-form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const fileInput = uploadForm.querySelector('input[type="file"][name="document"]');
            if (!fileInput || !fileInput.files || !fileInput.files.length) {
                window.CS?.showToast?.('Seleziona un file da caricare.', 'warning');
                return;
            }
            const submitBtn = uploadForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.originalText = submitBtn.dataset.originalText || submitBtn.textContent;
                submitBtn.textContent = 'Caricamento...';
            }
            let succeeded = false;
            try {
                await uploadPracticeDocument(practiceId, fileInput.files[0]);
                succeeded = true;
                fileInput.value = '';
                window.CS?.showToast?.('Documento caricato con successo.', 'success');
            } catch (error) {
                window.CS?.showToast?.(`Errore durante l'upload del documento: ${error.message}`, 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Carica file';
                }
            }
            if (succeeded) {
                await loadPracticeStatusView(container, practiceId);
            }
        });
    }
}

function updatePracticePageHero(practice) {
    const titleEl = document.getElementById('practice-page-title');
    if (titleEl) {
        titleEl.textContent = practice.titolo || `Pratica #${practice.id}`;
    }

    const codeEl = document.getElementById('practice-page-code');
    if (codeEl) {
        codeEl.textContent = `#${practice.id}`;
    }

    const statusEl = document.getElementById('practice-page-status');
    if (statusEl) {
        const meta = getStatusMeta(practice.stato);
        const appearance = resolveStatusBadgeAppearance(meta.color);
        const classes = ['badge'];
        if (appearance.className) {
            classes.push(appearance.className);
        }
        if (!appearance.className) {
            classes.push('bg-secondary');
        }
        if (appearance.textClass) {
            classes.push(appearance.textClass);
        }
        if (!appearance.textClass && !appearance.style && !appearance.accentColor) {
            classes.push('text-white');
        }
        statusEl.textContent = meta.label;
        statusEl.className = classes.join(' ');
        const badgeStyle = computeBadgeStyle(appearance);
        if (badgeStyle) {
            statusEl.setAttribute('style', badgeStyle);
        } else {
            statusEl.removeAttribute('style');
        }
    }

    const categoryEl = document.getElementById('practice-page-category');
    if (categoryEl) {
        const category = practice.categoria || '';
        const isPatronato = category.toUpperCase() === 'PATRONATO';
        categoryEl.textContent = category || 'N/D';
        categoryEl.className = `badge ${isPatronato ? 'bg-warning text-dark' : 'bg-info'}`;
    }

    const operatorEl = document.getElementById('practice-page-operator');
    if (operatorEl) {
        if (practice.assegnatario) {
            operatorEl.textContent = `${practice.assegnatario.nome || ''} ${practice.assegnatario.cognome || ''}`.trim();
        } else {
            operatorEl.textContent = 'Non assegnata';
        }
    }
}

async function loadFiltersData() {
    try {
        const tasks = [fetchStatuses(), fetchTypes()];
        if (cafContext.canConfigureModule) {
            tasks.push(fetchOperators());
        }
        const [statuses, types, operators] = await Promise.all(tasks);

        populateSelect('filter-stato', statuses, 'codice', 'nome');
        populateSelect('filter-tipo', types, 'id', 'nome');
        if (cafContext.canConfigureModule) {
            populateSelect('filter-operatore', operators || [], 'id', (option) => `${option.nome} ${option.cognome}`.trim());
        }
    } catch (error) {
        console.error('Errore nel caricamento dei filtri:', error);
    }
}

function populateSelect(selectId, options, valueField, textField) {
    const select = document.getElementById(selectId);
    if (!select) return;
    
    const currentValue = select.value;
    const defaultOption = select.querySelector('option[value=""]');
    
    // Clear existing options except the default
    select.innerHTML = '';
    if (defaultOption) {
        select.appendChild(defaultOption);
    }
    
    options.forEach(option => {
        const optElement = document.createElement('option');
        const value = typeof valueField === 'function' ? valueField(option) : option[valueField];
        const label = typeof textField === 'function' ? textField(option) : option[textField];
        optElement.value = value;
        optElement.textContent = label;
        if (value == currentValue) {
            optElement.selected = true;
        }
        select.appendChild(optElement);
    });
}

function invalidateCache(keys) {
    const targetKeys = Array.isArray(keys) ? keys : [keys];
    targetKeys.forEach((key) => {
        if (Object.prototype.hasOwnProperty.call(dataCache, key)) {
            dataCache[key] = null;
        }
    });
}

async function fetchStatuses(force = false) {
    if (!force && Array.isArray(dataCache.statuses)) {
        if (Object.keys(statusDictionary).length === 0) {
            dataCache.statuses.forEach((status) => {
                const key = normalizeStatusCode(status.codice);
                if (!key) {
                    return;
                }
                statusDictionary[key] = {
                    label: status.nome || key,
                    color: sanitizeStatusColor(status.colore),
                };
            });
        }
        return dataCache.statuses;
    }
    const response = await apiRequest('GET', { action: 'list_statuses' });
    dataCache.statuses = response.data.statuses || [];
    Object.keys(statusDictionary).forEach((key) => delete statusDictionary[key]);
    dataCache.statuses.forEach((status) => {
        const key = normalizeStatusCode(status.codice);
        if (!key) {
            return;
        }
        statusDictionary[key] = {
            label: status.nome || key,
            color: sanitizeStatusColor(status.colore),
        };
    });
    return dataCache.statuses;
}

async function fetchTypes(force = false) {
    if (!force && Array.isArray(dataCache.types)) {
        return dataCache.types;
    }
    const response = await apiRequest('GET', { action: 'list_types' });
    dataCache.types = response.data.types || [];
    return dataCache.types;
}

async function fetchOperators(force = false) {
    if (!cafContext.canConfigureModule && !cafContext.canManagePractices) {
        return [];
    }
    if (!force && Array.isArray(dataCache.operators)) {
        return dataCache.operators;
    }
    try {
        const params = { action: 'list_operators', only_active: 1 };
        if (!cafContext.canConfigureModule) {
            params.categoria = 'PATRONATO';
        }
        const response = await apiRequest('GET', params);
        dataCache.operators = response.data.operators || [];
    } catch (error) {
        console.error('Errore nel caricamento operatori:', error);
        dataCache.operators = [];
    }
    return dataCache.operators;
}

async function loadPracticesList(page = 1, options = {}) {
    const { silent = false } = options;
    const container = document.getElementById('practices-table-container');
    const paginationContainer = document.getElementById('practices-pagination');
    if (!container) return;
    
    try {
        if (!silent) {
            showSpinner(container);
        }
        const filters = getFormFilters('practices-filters-form');
        currentPracticeFilters = { ...filters };
        currentPracticePage = page;
        const requestParams = {
            ...filters,
            page,
            action: 'list_practices',
        };

        const response = await apiRequest('GET', requestParams);
        await Promise.all([
            fetchStatuses(),
            fetchTypes(),
            cafContext.canConfigureModule ? fetchOperators() : Promise.resolve([]),
        ]);
        const { items, pagination, summaries } = response.data;
        if (summaries) {
            updateHeroMetrics(summaries);
            updateQuickFilterCounts(summaries);
        }
        
        renderPracticesTable(container, items, { silent });
        renderPagination(paginationContainer, pagination, loadPracticesList);
        updateActiveFiltersDisplay(currentPracticeFilters);
        if (!silent) {
            startPracticesAutoRefresh();
        }
    } catch (error) {
        showError(container, 'Errore nel caricamento delle pratiche: ' + error.message);
        if (paginationContainer) paginationContainer.style.display = 'none';
    }
}

function renderPracticesTable(container, practices, options = {}) {
    const { silent = false } = options;
    if (!practices.length) {
        container.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="fa-solid fa-folder-open fa-2x mb-3 opacity-50"></i>
                <p>Nessuna pratica trovata con i filtri selezionati.</p>
            </div>
        `;
        if (cafContext.isPatronato && cafContext.operatorId) {
            assignedPracticesSnapshot = new Set();
            assignedSnapshotInitialized = true;
        }
        return;
    }
    
    let html = `
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Pratica</th>
                        <th>Tracking</th>
                        <th>Categoria</th>
                        <th>Stato</th>
                        <th>Documento</th>
                        <th>Assegnata a</th>
                        <th>Cliente</th>
                        <th>Aggiornata</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
    `;
    const shouldTrackAssignments = Boolean(cafContext.isPatronato && cafContext.operatorId);
    const currentAssignments = shouldTrackAssignments ? new Set() : null;
    const newAssignments = [];
    const operatorId = cafContext.operatorId;
    
    practices.forEach(practice => {
        if (shouldTrackAssignments && practice?.assegnatario?.id === operatorId) {
            currentAssignments.add(practice.id);
            if (assignedSnapshotInitialized && silent && !assignedPracticesSnapshot.has(practice.id)) {
                newAssignments.push(practice);
            }
        }
        const statusMeta = getStatusMeta(practice.stato);
        const badgeAppearance = resolveStatusBadgeAppearance(statusMeta.color);
        const badgeClasses = ['badge'];
        if (badgeAppearance.className) {
            badgeClasses.push(badgeAppearance.className);
        }
        if (!badgeAppearance.className) {
            badgeClasses.push('bg-secondary');
        }
        if (badgeAppearance.textClass) {
            badgeClasses.push(badgeAppearance.textClass);
        } else if (!badgeAppearance.style && !badgeAppearance.accentColor) {
            badgeClasses.push('text-white');
        }
        const badgeStyle = computeBadgeStyle(badgeAppearance);
        const assigneeName = practice.assegnatario?.nome || 'Non assegnata';
        const clientName = practice.cliente ? 
            (practice.cliente.ragione_sociale || `${practice.cliente.nome} ${practice.cliente.cognome}`.trim()) || 'Cliente #' + practice.cliente.id :
            'Nessun cliente';
        const attachments = Array.isArray(practice.allegati) ? practice.allegati : [];
        const latestDocument = attachments.length > 0 ? attachments[0] : null;
        const downloadHref = latestDocument ? resolvePracticeDocumentHref(latestDocument, practice.id) : '#';
        
        const trackingCode = typeof practice.tracking_code === 'string' ? practice.tracking_code.trim() : '';
        const trackingUrl = trackingCode && cafContext.trackingBaseUrl
            ? `${cafContext.trackingBaseUrl}${encodeURIComponent(trackingCode)}`
            : '';

        const derivedEmail = (() => {
            const primary = typeof practice?.customer_email === 'string' ? practice.customer_email : null;
            const fallback = typeof practice?.cliente?.email === 'string' ? practice.cliente.email : null;
            const selected = primary && primary.trim() !== '' ? primary : (fallback || '');
            return selected.trim();
        })();

        const actions = [`
            <button class="btn btn-icon btn-soft-accent btn-sm" type="button" onclick="viewPractice(${practice.id})" title="Visualizza">
                <i class="fa-solid fa-eye"></i>
            </button>
        `];
        if (cafContext.canManagePractices) {
            actions.push(`
                <button class="btn btn-icon btn-outline-secondary btn-sm" type="button" onclick="editPractice(${practice.id})" title="Cambia stato">
                    <i class="fa-solid fa-arrows-rotate"></i>
                </button>
            `);
        }
        if (cafContext.canCreatePractices) {
            actions.push(`
                <button class="btn btn-icon btn-outline-primary btn-sm" type="button" data-customer-email="${escapeHtml(derivedEmail)}" onclick="resendCustomerMail(${practice.id}, this.dataset.customerEmail)" title="Reinvia email al cliente">
                    <i class="fa-solid fa-envelope"></i>
                </button>
            `);
        }
        if (cafContext.canManagePractices) {
            const encodedTitle = encodeURIComponent(String(practice?.titolo ?? ''));
            actions.push(`
                <button class="btn btn-icon btn-outline-danger btn-sm" type="button" data-practice-title="${encodedTitle}" onclick="deletePractice(${practice.id}, this.dataset.practiceTitle)" title="Elimina pratica">
                    <i class="fa-solid fa-trash"></i>
                </button>
            `);
        }

        html += `
            <tr>
                <td>
                    <div>
                        <div class="fw-semibold">${escapeHtml(practice.titolo)}</div>
                        <small class="text-muted">#${practice.id}</small>
                    </div>
                </td>
                <td>
                    ${trackingCode ? `
                        <div class="d-flex align-items-center gap-2">
                            <span class="badge bg-light text-dark border font-monospace">${escapeHtml(trackingCode)}</span>
                            ${trackingUrl ? `<a class="btn btn-icon btn-outline-secondary btn-sm" href="${escapeHtml(trackingUrl)}" target="_blank" rel="noopener noreferrer" title="Apri tracking"><i class="fa-solid fa-arrow-up-right-from-square"></i></a>` : ''}
                        </div>
                    ` : '<span class="text-muted small">—</span>'}
                </td>
                <td>
                    <span class="badge bg-${practice.categoria === 'CAF' ? 'info' : 'warning'}">${escapeHtml(practice.categoria)}</span>
                </td>
                <td>
                    <span class="${badgeClasses.join(' ')}"${badgeStyle ? ` style="${badgeStyle}"` : ''}>${escapeHtml(statusMeta.label)}</span>
                </td>
                <td>
                    ${latestDocument ? `
                        <a class="btn btn-icon btn-outline-success btn-sm" href="${escapeHtml(downloadHref)}" target="_blank" rel="noopener noreferrer" title="Scarica pratica elaborata">
                            <i class="fa-solid fa-file-arrow-down"></i>
                        </a>
                    ` : '<span class="text-muted small">—</span>'}
                </td>
                <td>${escapeHtml(assigneeName)}</td>
                <td>${escapeHtml(clientName)}</td>
                <td>
                    <small>${formatDateTime(practice.data_aggiornamento)}</small>
                </td>
                <td>
                    <div class="d-flex gap-1">
                        ${actions.join('')}
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += '</tbody></table></div>';
    container.innerHTML = html;

    if (shouldTrackAssignments) {
        assignedPracticesSnapshot = currentAssignments;
        if (!assignedSnapshotInitialized) {
            assignedSnapshotInitialized = true;
        } else if (silent && newAssignments.length && window.CS?.showToast) {
            const names = newAssignments.map((practice) => {
                const title = practice?.titolo ? String(practice.titolo).trim() : '';
                return escapeHtml(title || `Pratica #${practice.id}`);
            });
            const preview = names.slice(0, 2).join(', ');
            const remaining = names.length - Math.min(names.length, 2);
            const message = names.length === 1
                ? `Nuova pratica assegnata: ${preview}.`
                : `Hai ${names.length} nuove pratiche assegnate: ${preview}${remaining > 0 ? ` e altre ${remaining}.` : '.'}`;
            window.CS.showToast(message, 'info');
        }
    }
}

function updateActiveFiltersDisplay(filters) {
    const display = document.getElementById('active-filters-display');
    const list = document.getElementById('active-filters-list');
    if (!display || !list) return;

    const labelConfig = {
        search: {
            label: 'Ricerca',
        },
        categoria: {
            label: 'Categoria',
        },
        stato: {
            label: 'Stato',
            format: (value) => resolveStatusLabel(value),
        },
        tipo_pratica: {
            label: 'Tipologia',
            format: (value) => resolveTypeLabel(value),
        },
        operatore: {
            label: 'Operatore',
            format: (value) => resolveOperatorLabel(value),
        },
        dal: {
            label: 'Dal',
            format: formatDateForBadge,
        },
        al: {
            label: 'Al',
            format: formatDateForBadge,
        },
        assegnata: {
            label: 'Assegnazione',
            format: (value) => (value === '0' ? 'Non assegnate' : 'Assegnate'),
        },
        order: {
            label: 'Ordine',
            format: (value) => {
                switch (value) {
                    case 'recenti':
                        return 'Più recenti';
                    case 'scadenza':
                        return 'Per scadenza';
                    case 'stato':
                        return 'Per stato';
                    case 'assegnatario':
                        return 'Per assegnazione';
                    default:
                        return value;
                }
            },
        },
    };

    const activeFilters = [];
    Object.entries(filters || {}).forEach(([key, value]) => {
        if (value === undefined || value === null || value === '' || !(key in labelConfig)) {
            return;
        }
        const config = labelConfig[key];
        const formattedValue = config.format ? config.format(value) : value;
        activeFilters.push(`<span class="badge bg-light text-dark">${config.label}: ${escapeHtml(String(formattedValue))}</span>`);
    });

    const quickFilterWrapper = document.getElementById('active-quick-filter-wrapper');
    if (quickFilterWrapper) {
        quickFilterWrapper.style.display = activeQuickFilterId && activeQuickFilterId !== 'all' ? 'inline-flex' : 'none';
    }

    if (activeFilters.length > 0) {
        list.innerHTML = activeFilters.join(' ');
        display.style.display = 'block';
    } else {
        list.innerHTML = '';
        display.style.display = activeQuickFilterId && activeQuickFilterId !== 'all' ? 'block' : 'none';
    }
}

// Admin Module
function initAdminModule() {
    loadTypesList();
    loadStatusesList();
    
    const createTypeBtn = document.getElementById('create-type-btn');
    if (createTypeBtn) {
        createTypeBtn.addEventListener('click', () => showCreateTypeModal());
    }
    
    const createStatusBtn = document.getElementById('create-status-btn');
    if (createStatusBtn) {
        createStatusBtn.addEventListener('click', () => showCreateStatusModal());
    }
}

async function loadTypesList() {
    const container = document.getElementById('types-list-container');
    if (!container) return;
    
    try {
        showSpinner(container);
        const types = await fetchTypes(true);
        renderTypesList(container, types);
    } catch (error) {
        showError(container, 'Errore nel caricamento delle tipologie: ' + error.message);
    }
}

function renderTypesList(container, types) {
    if (!types.length) {
        container.innerHTML = `
            <div class="text-center text-muted py-3">
                <p>Nessuna tipologia configurata.</p>
            </div>
        `;
        return;
    }
    
    let html = '<div class="list-group list-group-flush">';
    
    types.forEach(type => {
        html += `
            <div class="list-group-item d-flex justify-content-between align-items-start">
                <div>
                    <h6 class="mb-1">${escapeHtml(type.nome)}</h6>
                    <p class="mb-1 small text-muted">Categoria: ${type.categoria}</p>
                    <small class="text-muted">Campi personalizzati: ${type.campi_personalizzati?.length || 0}</small>
                </div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="editType(${type.id})" title="Modifica">
                        <i class="fa-solid fa-edit"></i>
                    </button>
                    <button class="btn btn-outline-danger" onclick="deleteType(${type.id})" title="Elimina">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

async function loadStatusesList() {
    const container = document.getElementById('statuses-list-container');
    if (!container) return;
    
    try {
        showSpinner(container);
        const statuses = await fetchStatuses(true);
        renderStatusesList(container, statuses);
    } catch (error) {
        showError(container, 'Errore nel caricamento degli stati: ' + error.message);
    }
}

function renderStatusesList(container, statuses) {
    if (!statuses.length) {
        container.innerHTML = `
            <div class="text-center text-muted py-3">
                <p>Nessuno stato configurato.</p>
            </div>
        `;
        return;
    }
    
    const statusColors = {
        'in_lavorazione': 'primary',
        'completata': 'success',
        'sospesa': 'warning', 
        'archiviata': 'secondary'
    };
    
    let html = '<div class="list-group list-group-flush">';
    
    statuses.forEach(status => {
        const colorClass = statusColors[status.codice] || status.colore || 'secondary';
        html += `
            <div class="list-group-item d-flex justify-content-between align-items-center">
                <div class="d-flex align-items-center">
                    <span class="badge bg-${colorClass} me-3">${escapeHtml(status.nome)}</span>
                    <div>
                        <div class="small">${escapeHtml(status.codice)}</div>
                        <div class="small text-muted">Ordine: ${status.ordering}</div>
                    </div>
                </div>
                <div class="btn-group btn-group-sm">
                    <button class="btn btn-outline-primary" onclick="editStatus(${status.id})" title="Modifica">
                        <i class="fa-solid fa-edit"></i>
                    </button>
                    <button class="btn btn-outline-danger" onclick="deleteStatus(${status.id})" title="Elimina">
                        <i class="fa-solid fa-trash"></i>
                    </button>
                </div>
            </div>
        `;
    });
    
    html += '</div>';
    container.innerHTML = html;
}

// Operators Module
function initOperatorsModule() {
    loadOperatorsList();
    loadNotificationsList({ target: 'admin', showRead: document.getElementById('show-read-notifications')?.checked ?? false });
    
    const createBtn = document.getElementById('create-operator-btn');
    if (createBtn) {
        createBtn.addEventListener('click', () => showCreateOperatorModal());
    }

    const refreshBtn = document.getElementById('refresh-operators');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', () => {
            loadOperatorsList();
        });
    }

    const showReadToggle = document.getElementById('show-read-notifications');
    if (showReadToggle) {
        showReadToggle.addEventListener('change', () => {
            loadNotificationsList({ target: 'admin', showRead: showReadToggle.checked });
        });
    }

    const notificationsContainer = document.getElementById('notifications-list');
    if (notificationsContainer) {
        notificationsContainer.addEventListener('click', handleNotificationAction);
    }
}

async function loadOperatorsList() {
    const container = document.getElementById('operators-table-container');
    if (!container) return;
    
    try {
        showSpinner(container);
        const response = await apiRequest('GET', { action: 'list_operators', only_active: false });
        dataCache.operators = response.data.operators || [];
        operatorsDataset = Array.isArray(dataCache.operators) ? [...dataCache.operators] : [];
        renderOperatorsTable(container, dataCache.operators);
        updateOperatorsCounters(dataCache.operators);
    } catch (error) {
        showError(container, 'Errore nel caricamento degli operatori: ' + error.message);
        updateOperatorsCounters([]);
    }
}

function renderOperatorsTable(container, operators) {
    if (!operators.length) {
        container.innerHTML = `
            <div class="text-center text-muted py-4">
                <i class="fa-solid fa-users fa-2x mb-3 opacity-50"></i>
                <p>Nessun operatore configurato.</p>
            </div>
        `;
        return;
    }
    
    let html = `
        <div class="table-responsive">
            <table class="table table-hover align-middle">
                <thead>
                    <tr>
                        <th>Operatore</th>
                        <th>Email</th>
                        <th>Ruolo</th>
                        <th>Utente sistema</th>
                        <th>Stato</th>
                        <th>Registrato</th>
                        <th>Azioni</th>
                    </tr>
                </thead>
                <tbody>
    `;
    
    operators.forEach(operator => {
        html += `
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        <div class="avatar-sm bg-${operator.ruolo === 'CAF' ? 'info' : 'warning'} bg-opacity-10 text-${operator.ruolo === 'CAF' ? 'info' : 'warning'} me-3">
                            <i class="fa-solid fa-user"></i>
                        </div>
                        <div>
                            <div class="fw-semibold">${escapeHtml(operator.nome)} ${escapeHtml(operator.cognome)}</div>
                            <small class="text-muted">ID: ${operator.id}</small>
                        </div>
                    </div>
                </td>
                <td>${escapeHtml(operator.email)}</td>
                <td>
                    <span class="badge bg-${operator.ruolo === 'CAF' ? 'info' : 'warning'}">${operator.ruolo}</span>
                </td>
                <td>
                    ${operator.user_username ? 
                        `<small class="text-muted">${escapeHtml(operator.user_username)}</small>` : 
                        '<span class="text-muted">Non collegato</span>'
                    }
                </td>
                <td>
                    <span class="badge bg-${operator.attivo ? 'success' : 'secondary'}">
                        ${operator.attivo ? 'Attivo' : 'Disattivo'}
                    </span>
                </td>
                <td>
                    <small>${formatDateTime(operator.created_at)}</small>
                </td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-primary" onclick="editOperator(${operator.id})" title="Modifica">
                            <i class="fa-solid fa-edit"></i>
                        </button>
                        <button class="btn btn-outline-${operator.attivo ? 'warning' : 'success'}" 
                                onclick="toggleOperator(${operator.id}, ${!operator.attivo})" 
                                title="${operator.attivo ? 'Disattiva' : 'Attiva'}">
                            <i class="fa-solid fa-${operator.attivo ? 'pause' : 'play'}"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    });
    
    html += '</tbody></table></div>';
    container.innerHTML = html;
}

// Modal and form helpers will be defined below.

function initModals() {
    if (!modalRefs.root) {
        modalRefs.root = document.getElementById('cafPatronatoModal');
        if (modalRefs.root && window.bootstrap?.Modal) {
            modalRefs.instance = window.bootstrap.Modal.getOrCreateInstance(modalRefs.root, {
                backdrop: 'static'
            });
            modalRefs.title = modalRefs.root.querySelector('.modal-title');
            modalRefs.body = modalRefs.root.querySelector('.modal-body');
            modalRefs.footer = modalRefs.root.querySelector('.modal-footer');
        }
    }

    if (!modalRefs.confirmRoot) {
        modalRefs.confirmRoot = document.getElementById('cafPatronatoConfirmModal');
        if (modalRefs.confirmRoot && window.bootstrap?.Modal) {
            modalRefs.confirmInstance = window.bootstrap.Modal.getOrCreateInstance(modalRefs.confirmRoot, {
                backdrop: 'static'
            });
            modalRefs.confirmBody = modalRefs.confirmRoot.querySelector('.modal-body');
            modalRefs.confirmAction = modalRefs.confirmRoot.querySelector('#cafPatronatoConfirmAction');
        }
    }

    if (modalRefs.confirmAction) {
        modalRefs.confirmAction.addEventListener('click', () => {
            if (typeof modalRefs.confirmHandler !== 'function') {
                modalRefs.confirmAction?.blur();
                modalRefs.confirmInstance?.hide();
                return;
            }

            const handlerResult = modalRefs.confirmHandler();
            if (handlerResult instanceof Promise) {
                if (modalRefs.confirmAction) {
                    modalRefs.confirmAction.disabled = true;
                }
                handlerResult
                    .then((value) => {
                        if (value === false) {
                            return;
                        }
                        modalRefs.confirmHandler = null;
                        if (modalRefs.confirmAction) {
                            modalRefs.confirmAction.blur();
                        }
                        modalRefs.confirmInstance?.hide();
                    })
                    .catch((error) => {
                        console.error('CAF/Patronato confirm handler error:', error);
                    })
                    .finally(() => {
                        if (modalRefs.confirmAction) {
                            modalRefs.confirmAction.disabled = false;
                        }
                    });
                return;
            }

            if (handlerResult === false) {
                return;
            }

            modalRefs.confirmHandler = null;
            if (modalRefs.confirmAction) {
                modalRefs.confirmAction.blur();
            }
            modalRefs.confirmInstance?.hide();
        });
    }
}

function setModalSize(size = 'lg') {
    if (!modalRefs.root) return;
    const dialog = modalRefs.root.querySelector('.modal-dialog');
    if (!dialog) return;

    dialog.classList.remove('modal-sm', 'modal-lg', 'modal-xl');
    if (size === 'sm' || size === 'lg' || size === 'xl') {
        dialog.classList.add(`modal-${size}`);
    }
}

function openModal({ title = '', body = '', footer = '', size = 'lg', onShown = null }) {
    if (!modalRefs.instance || !modalRefs.title || !modalRefs.body || !modalRefs.footer) {
        console.warn('Modal non disponibile: assicurarsi che il markup sia presente.');
        return;
    }

    modalRefs.title.textContent = title;
    modalRefs.body.innerHTML = body;
    modalRefs.footer.innerHTML = footer || '<button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>';
    setModalSize(size);
    modalRefs.instance.show();

    if (typeof onShown === 'function') {
        setTimeout(() => onShown(modalRefs.body), 0);
    }
}

function closeModal() {
    modalRefs.instance?.hide();
}

function showConfirmDialog({ message, title = 'Conferma', confirmLabel = 'Conferma', confirmVariant = 'primary', onConfirm, onShown }) {
    if (!modalRefs.confirmInstance || !modalRefs.confirmBody || !modalRefs.confirmAction) {
        console.warn('Modal di conferma non disponibile.');
        return;
    }

    modalRefs.confirmHandler = typeof onConfirm === 'function' ? onConfirm : null;
    modalRefs.confirmRoot.querySelector('.modal-title').textContent = title;
    modalRefs.confirmBody.innerHTML = message;
    modalRefs.confirmAction.textContent = confirmLabel;
    modalRefs.confirmAction.className = `btn btn-${confirmVariant}`;
    modalRefs.confirmInstance.show();
    if (typeof onShown === 'function') {
        setTimeout(() => {
            onShown(modalRefs.confirmBody);
        }, 0);
    }
}

function renderPrimaryFooter({ showClose = true, submitLabel = 'Salva', submitId = 'caf-patronato-submit' } = {}) {
    let html = '';
    if (showClose) {
        html += '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>';
    }
    html += `<button type="submit" form="caf-patronato-modal-form" id="${submitId}" class="btn btn-primary">${escapeHtml(submitLabel)}</button>`;
    return html;
}

function setFormSubmitting(form, isSubmitting, submitButtonId = 'caf-patronato-submit') {
    if (!form) return;
    const submitBtn = document.getElementById(submitButtonId);
    if (submitBtn) {
        submitBtn.disabled = isSubmitting;
        const originalText = submitBtn.dataset.originalText || submitBtn.textContent;
        if (!submitBtn.dataset.originalText) {
            submitBtn.dataset.originalText = originalText;
        }
        submitBtn.textContent = isSubmitting ? 'Attendere...' : originalText;
    }
    Array.from(form.elements).forEach((element) => {
        if (element instanceof HTMLButtonElement || element instanceof HTMLInputElement || element instanceof HTMLSelectElement || element instanceof HTMLTextAreaElement) {
            if (element.id !== submitButtonId) {
                element.disabled = isSubmitting;
            }
        }
    });
}

function setFormAlert(form, message = '', variant = 'danger') {
    if (!form) return;
    const alertBox = form.querySelector('[data-role="form-alert"]');
    if (!alertBox) return;
    if (!message) {
        alertBox.classList.add('d-none');
        alertBox.textContent = '';
        return;
    }
    alertBox.className = `alert alert-${variant}`;
    alertBox.textContent = message;
}

function getCsrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || null;
}

function formatDateForInput(value) {
    if (!value) return '';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) {
        return '';
    }
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
}

async function showCreatePracticeModal() {
    if (cafContext.useLegacyCreate && cafContext.createUrl) {
        window.location.href = cafContext.createUrl;
        return;
    }

    if (!cafContext.canCreatePractices) {
        window.CS?.showToast?.('Non hai i permessi per creare pratiche.', 'warning');
        return;
    }

    try {
        const [types, statuses, operators] = await Promise.all([
            fetchTypes(true),
            fetchStatuses(true),
            fetchOperators(true),
        ]);

        const body = buildPracticeFormMarkup({
            mode: 'create',
            types,
            statuses,
            operators,
        });

        openModal({
            title: 'Nuova pratica',
            body,
            footer: renderPrimaryFooter({ submitLabel: 'Crea pratica' }),
            size: 'xl',
            onShown: (container) => setupPracticeForm(container, {
                mode: 'create',
                types,
                statuses,
                operators,
                practice: null,
            }),
        });
    } catch (error) {
        console.error('Errore caricamento dati creazione pratica:', error);
        window.CS?.showToast?.(`Errore nel preparare la pratica: ${error.message}`, 'error');
    }
}

function editPractice(id) {
    if (!cafContext.canManagePractices) {
        window.CS?.showToast?.('Non hai i permessi per modificare le pratiche.', 'warning');
        return;
    }
    if (!id) {
        return;
    }
    const targetUrl = `status.php?id=${encodeURIComponent(id)}`;
    window.location.href = targetUrl;
}

function viewPractice(id) {
    if (!id) {
        return;
    }
    const targetUrl = `view.php?id=${encodeURIComponent(id)}`;
    window.location.href = targetUrl;
}

function resendCustomerMail(practiceId, defaultRecipient = '') {
    if (!cafContext.canCreatePractices) {
        window.CS?.showToast?.('Non hai i permessi per inviare email al cliente.', 'warning');
        return;
    }

    const targetId = parseInt(practiceId, 10);
    if (!targetId) {
        return;
    }

    const sanitizedRecipient = typeof defaultRecipient === 'string' ? defaultRecipient.trim() : '';
    const helperText = sanitizedRecipient
        ? `Lascia invariato per usare <strong>${escapeHtml(sanitizedRecipient)}</strong>.`
        : 'Specificare un indirizzo: la pratica non ha un contatto email registrato.';

    const message = `
        <p>Vuoi reinviare la mail di conferma al cliente per questa pratica?</p>
        <div class="mt-3">
            <label class="form-label" for="caf-resend-email-input">Email destinatario</label>
            <input type="email" class="form-control" id="caf-resend-email-input" name="recipient" value="${escapeHtml(sanitizedRecipient)}" placeholder="cliente@example.com" data-role="resend-email-input">
            <small class="text-muted">${helperText}</small>
            <div class="invalid-feedback d-none" data-role="resend-email-feedback"></div>
        </div>
    `;

    showConfirmDialog({
        title: 'Reinvia email al cliente',
        message,
        confirmLabel: 'Reinvia',
        confirmVariant: 'primary',
        onConfirm: async () => {
            try {
                const input = modalRefs.confirmRoot?.querySelector('[data-role="resend-email-input"]');
                let recipient = null;
                if (input && typeof input.value === 'string') {
                    const trimmed = input.value.trim();
                    if (trimmed !== '') {
                        recipient = trimmed;
                    }
                }

                if (!sanitizedRecipient && recipient === null) {
                    window.CS?.showToast?.('Inserisci un indirizzo email per il cliente.', 'warning');
                    return false;
                }

                if (recipient !== null && !isValidEmail(recipient)) {
                    window.CS?.showToast?.('Indirizzo email non valido.', 'warning');
                    return false;
                }

                const payload = { action: 'resend_customer_mail', id: targetId };
                if (recipient !== null) {
                    payload.recipient = recipient;
                }

                await apiRequest('POST', payload);
                window.CS?.showToast?.('Email di conferma reinviata al cliente.', 'success');
                loadPracticesList(currentPracticePage, { silent: true });
                return true;
            } catch (error) {
                const message = error && error.message ? error.message : 'Invio email non riuscito.';
                window.CS?.showToast?.(`Errore: ${message}`, 'error');
                const feedback = modalRefs.confirmRoot?.querySelector('[data-role="resend-email-feedback"]');
                if (feedback) {
                    feedback.textContent = message;
                    feedback.classList.remove('d-none');
                }
                return false;
            }
        },
        onShown: (modalBody) => {
            const input = modalBody?.querySelector('[data-role="resend-email-input"]');
            if (input instanceof HTMLInputElement) {
                input.focus();
                const feedback = modalBody.querySelector('[data-role="resend-email-feedback"]');
                if (feedback) {
                    feedback.classList.add('d-none');
                }
                if (!input.dataset.cafResendBound) {
                    input.dataset.cafResendBound = '1';
                    input.addEventListener('input', () => {
                        const inlineFeedback = modalBody.querySelector('[data-role="resend-email-feedback"]');
                        if (inlineFeedback) {
                            inlineFeedback.classList.add('d-none');
                        }
                    });
                }
            }
        }
    });
}

function deletePractice(practiceId, practiceTitle = '') {
    if (!cafContext.canManagePractices) {
        window.CS?.showToast?.('Non hai i permessi per eliminare pratiche.', 'warning');
        return;
    }

    const targetId = parseInt(practiceId, 10);
    if (!targetId) {
        return;
    }

    let resolvedTitle = '';
    if (typeof practiceTitle === 'string' && practiceTitle.trim() !== '') {
        const trimmed = practiceTitle.trim();
        try {
            resolvedTitle = decodeURIComponent(trimmed);
        } catch (error) {
            resolvedTitle = trimmed;
        }
    }

    const label = resolvedTitle !== '' ? resolvedTitle : `Pratica #${targetId}`;

    showConfirmDialog({
        title: 'Elimina pratica',
        message: `<p>Eliminando <strong>${escapeHtml(label)}</strong> verranno rimossi definitivamente documenti, note, timeline e movimenti economici associati. L'operazione non può essere annullata.</p>
                  <p class="mb-0 text-danger">Confermi di voler procedere?</p>`,
        confirmLabel: 'Elimina',
        confirmVariant: 'danger',
        onConfirm: async () => {
            try {
                await apiRequest('POST', {
                    action: 'delete_practice',
                    id: targetId,
                });
                window.CS?.showToast?.('Pratica eliminata correttamente.', 'success');
                await loadPracticesList(currentPracticePage || 1, { silent: false });
                return true;
            } catch (error) {
                const message = error && error.message ? error.message : 'Eliminazione non riuscita.';
                window.CS?.showToast?.(`Errore: ${message}`, 'error');
                return false;
            }
        },
    });
}

async function refreshPracticeView(practiceId, options = {}) {
    const targetContainer = options.container || (modalRefs.instance && modalRefs.root?.classList.contains('show') ? modalRefs.body : null);
    if (!targetContainer) {
        return;
    }
    try {
        const [practiceResponse, statuses] = await Promise.all([
            apiRequest('GET', { action: 'get_practice', id: practiceId }),
            fetchStatuses(),
        ]);
        currentPracticeDetails = practiceResponse.data;
        if (options.mode === 'page') {
            updatePracticePageHero(currentPracticeDetails);
        }
        targetContainer.innerHTML = buildPracticeDetailMarkup(currentPracticeDetails, statuses);
        setupPracticeDetailView(targetContainer, currentPracticeDetails, statuses, { ...options, container: targetContainer });
    } catch (error) {
        console.error('Errore nell\'aggiornamento pratica:', error);
        showError(targetContainer, 'Errore nell\'aggiornamento pratica: ' + error.message);
    }
}

function buildPracticeFormMarkup({ mode, types, statuses, operators, practice }) {
    const selectedTypeId = practice?.tipo?.id || types[0]?.id || '';
    const selectedStatus = practice?.stato || statuses[0]?.codice || '';
    const selectedOperator = practice?.assegnatario?.id || '';
    const categoria = practice?.categoria || types.find((type) => type.id === selectedTypeId)?.categoria || 'CAF';
    const scadenza = practice?.scadenza ? formatDateForInput(practice.scadenza) : '';
    const clienteId = practice?.cliente?.id || practice?.cliente_id || '';
    const metadati = practice?.metadati || {};

    const typeOptions = types.map((type) => `
        <option value="${type.id}" data-categoria="${escapeHtml(type.categoria)}" ${type.id === selectedTypeId ? 'selected' : ''}>
            ${escapeHtml(type.nome)} (${type.categoria})
        </option>
    `).join('');

    const statusOptions = statuses.map((status) => `
        <option value="${escapeHtml(status.codice)}" ${status.codice === selectedStatus ? 'selected' : ''}>
            ${escapeHtml(status.nome)}
        </option>
    `).join('');

    const operatorOptions = ['<option value="">Nessuna assegnazione</option>']
        .concat(operators
            .filter((operator) => operator.ruolo === categoria && operator.attivo)
            .map((operator) => `
                <option value="${operator.id}" ${operator.id === selectedOperator ? 'selected' : ''}>
                    ${escapeHtml(`${operator.nome} ${operator.cognome}`)}
                </option>
            `)).join('');

    return `
        <form id="caf-patronato-modal-form" class="needs-validation" novalidate>
            <div class="alert alert-danger d-none" data-role="form-alert"></div>
            ${mode === 'edit' ? `<input type="hidden" name="id" value="${practice.id}">` : ''}
            <input type="hidden" name="categoria" value="${escapeHtml(categoria)}" data-role="categoria-input">
            <div class="row g-4">
                <div class="col-lg-8">
                    <div class="mb-3">
                        <label class="form-label" for="practice-title">Titolo</label>
                        <input type="text" class="form-control" id="practice-title" name="titolo" maxlength="200" required value="${practice ? escapeHtml(practice.titolo) : ''}">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="practice-description">Descrizione</label>
                        <textarea class="form-control" id="practice-description" name="descrizione" rows="4" placeholder="Dettagli della pratica">${practice?.descrizione ? escapeHtml(practice.descrizione) : ''}</textarea>
                    </div>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label" for="practice-type">Tipologia</label>
                            <select class="form-select" id="practice-type" name="tipo_pratica" required>${typeOptions}</select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="practice-status">Stato</label>
                            <select class="form-select" id="practice-status" name="stato" required>${statusOptions}</select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="practice-due-date">Scadenza</label>
                            <input type="date" class="form-control" id="practice-due-date" name="scadenza" value="${scadenza}">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="practice-client">ID Cliente</label>
                            <input type="number" class="form-control" id="practice-client" name="cliente_id" min="1" placeholder=" opzionale" value="${clienteId}">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label" for="practice-note">Note interne</label>
                        <textarea class="form-control" id="practice-note" name="note" rows="3" placeholder="Annotazioni visibili agli amministratori">${practice?.note ? escapeHtml(practice.note) : ''}</textarea>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="border rounded p-3 bg-light">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <span class="text-muted small">Categoria</span>
                            <span class="badge bg-${categoria === 'PATRONATO' ? 'warning' : 'info'} text-dark" data-role="categoria-badge">${escapeHtml(categoria)}</span>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="practice-operator">Operatore assegnato</label>
                            <select class="form-select" id="practice-operator" name="id_utente_caf_patronato">${operatorOptions}</select>
                            <small class="form-text text-muted">Solo operatori attivi della stessa categoria.</small>
                        </div>
                        <div class="mb-3">
                            <label class="form-label" for="practice-category">Categoria servizio</label>
                            <input type="text" class="form-control" id="practice-category" value="${escapeHtml(categoria)}" disabled>
                        </div>
                        ${practice ? `
                        <div class="small text-muted">
                            <div>Creata il: ${formatDateTime(practice.data_creazione)}</div>
                            <div>Aggiornata il: ${formatDateTime(practice.data_aggiornamento)}</div>
                        </div>` : ''}
                    </div>
                </div>
            </div>
            <hr class="my-4">
            <div>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h6 class="mb-0">Campi personalizzati</h6>
                    <span class="badge bg-secondary" data-role="custom-count"></span>
                </div>
                <div class="row g-3" data-role="custom-fields">
                    ${renderCustomFields(types.find((type) => type.id === selectedTypeId)?.campi_personalizzati || [], metadati)}
                </div>
            </div>
        </form>
    `;
}

function setupPracticeForm(container, config) {
    const form = container.querySelector('#caf-patronato-modal-form');
    if (!form) return;

    form.__cafConfig = config;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        setFormAlert(form);
        const submitButtonId = config.submitButtonId || 'caf-patronato-submit';
        setFormSubmitting(form, true, submitButtonId);
        try {
            const payload = collectPracticeFormPayload(form);
            const action = config.mode === 'edit' ? 'update_practice' : 'create_practice';
            const response = await apiRequest('POST', { action, ...payload });
            window.CS?.showToast?.(`Pratica ${config.mode === 'edit' ? 'aggiornata' : 'creata'} con successo.`, 'success');
            if (typeof config.onSuccess === 'function') {
                config.onSuccess({ payload, response, form });
            } else {
                closeModal();
                loadPracticesList();
                if (document.getElementById('practices-summary-container')) {
                    loadPracticesSummary();
                }
            }
        } catch (error) {
            console.error('Errore salvataggio pratica:', error);
            setFormAlert(form, error.message || 'Errore inatteso nel salvataggio.');
            if (typeof config.onError === 'function') {
                config.onError(error);
            }
        } finally {
            setFormSubmitting(form, false, submitButtonId);
        }
    });

    const typeSelect = form.querySelector('#practice-type');
    if (typeSelect) {
        typeSelect.addEventListener('change', () => {
            updatePracticeTypeContext(form);
        });
    }

    updatePracticeTypeContext(form);
}

function updatePracticeTypeContext(form) {
    const config = form.__cafConfig || {};
    const { types = [], operators = [], practice } = config;
    const typeSelect = form.querySelector('#practice-type');
    const categoryInput = form.querySelector('[data-role="categoria-input"]');
    const categoryBadge = form.querySelector('[data-role="categoria-badge"]');
    const operatorSelect = form.querySelector('#practice-operator');
    const customFieldsContainer = form.querySelector('[data-role="custom-fields"]');
    const customCount = form.querySelector('[data-role="custom-count"]');

    if (!typeSelect || !categoryInput || !categoryBadge || !customFieldsContainer) {
        return;
    }

    const selectedTypeId = parseInt(typeSelect.value, 10);
    const selectedType = types.find((type) => type.id === selectedTypeId);
    const categoria = selectedType?.categoria || 'CAF';

    categoryInput.value = categoria;
    categoryBadge.textContent = categoria;
    categoryBadge.className = `badge bg-${categoria === 'PATRONATO' ? 'warning' : 'info'} text-dark`;

    if (operatorSelect) {
        const currentValue = operatorSelect.value;
        operatorSelect.innerHTML = '<option value="">Nessuna assegnazione</option>';
        operators.filter((operator) => operator.ruolo === categoria && operator.attivo)
            .forEach((operator) => {
                const option = document.createElement('option');
                option.value = String(operator.id);
                option.textContent = `${operator.nome} ${operator.cognome}`;
                if (String(operator.id) === currentValue) {
                    option.selected = true;
                }
                operatorSelect.appendChild(option);
            });
    }

    const originalTypeId = practice?.tipo?.id;
    const metadati = originalTypeId === selectedTypeId ? (practice?.metadati || {}) : {};
    const markup = renderCustomFields(selectedType?.campi_personalizzati || [], metadati);
    customFieldsContainer.innerHTML = markup;
    const fieldsCount = selectedType?.campi_personalizzati?.length || 0;
    if (customCount) {
        customCount.textContent = fieldsCount ? `${fieldsCount} campi` : 'Nessun campo';
        customCount.style.display = fieldsCount ? 'inline-block' : 'none';
    }
}

function renderCustomFields(schema, values) {
    if (!Array.isArray(schema) || schema.length === 0) {
        return '<div class="col-12"><p class="text-muted small mb-0">Questa tipologia non prevede campi personalizzati.</p></div>';
    }

    return schema.map((field) => {
        if (!field || typeof field !== 'object') {
            return '';
        }
        const slug = field.slug || '';
        if (!slug) {
            return '';
        }
        const label = field.label || slug;
        const type = (field.type || 'text').toLowerCase();
        const required = coerceBoolean(field.required);
        const value = values && typeof values === 'object' ? values[slug] : undefined;
        const baseName = `meta_${slug}`;
        const help = field.help ? `<small class="form-text text-muted">${escapeHtml(field.help)}</small>` : '';

        if (type === 'textarea') {
            return `
                <div class="col-12">
                    <label class="form-label" for="${baseName}">${escapeHtml(label)}${required ? ' *' : ''}</label>
                    <textarea class="form-control" id="${baseName}" name="${baseName}" rows="3" ${required ? 'required' : ''}>${value ? escapeHtml(String(value)) : ''}</textarea>
                    ${help}
                </div>
            `;
        }

        if (type === 'select') {
            const options = Array.isArray(field.options) ? field.options : [];
            const optionsMarkup = options.map((option) => {
                const optionValue = typeof option === 'object' ? option.value ?? option.label : option;
                const optionLabel = typeof option === 'object' ? option.label ?? option.value : option;
                const selected = String(optionValue) === String(value) ? 'selected' : '';
                return `<option value="${escapeHtml(String(optionValue))}" ${selected}>${escapeHtml(String(optionLabel))}</option>`;
            }).join('');
            return `
                <div class="col-md-6">
                    <label class="form-label" for="${baseName}">${escapeHtml(label)}${required ? ' *' : ''}</label>
                    <select class="form-select" id="${baseName}" name="${baseName}" ${required ? 'required' : ''}>
                        <option value="">Seleziona...</option>
                        ${optionsMarkup}
                    </select>
                    ${help}
                </div>
            `;
        }

        if (type === 'checkbox') {
            const checked = coerceBoolean(value) ? 'checked' : '';
            return `
                <div class="col-md-6">
                    <div class="form-check mt-4">
                        <input class="form-check-input" type="checkbox" id="${baseName}" name="${baseName}" ${checked}>
                        <label class="form-check-label" for="${baseName}">${escapeHtml(label)}</label>
                        ${help}
                    </div>
                </div>
            `;
        }

        if (type === 'number') {
            return `
                <div class="col-md-6">
                    <label class="form-label" for="${baseName}">${escapeHtml(label)}${required ? ' *' : ''}</label>
                    <input type="number" class="form-control" id="${baseName}" name="${baseName}" value="${value !== undefined ? escapeHtml(String(value)) : ''}" ${required ? 'required' : ''}>
                    ${help}
                </div>
            `;
        }

        if (type === 'date') {
            const dateValue = value ? formatDateForInput(String(value)) : '';
            return `
                <div class="col-md-6">
                    <label class="form-label" for="${baseName}">${escapeHtml(label)}${required ? ' *' : ''}</label>
                    <input type="date" class="form-control" id="${baseName}" name="${baseName}" value="${dateValue}" ${required ? 'required' : ''}>
                    ${help}
                </div>
            `;
        }

        return `
            <div class="col-md-6">
                <label class="form-label" for="${baseName}">${escapeHtml(label)}${required ? ' *' : ''}</label>
                <input type="text" class="form-control" id="${baseName}" name="${baseName}" value="${value !== undefined ? escapeHtml(String(value)) : ''}" ${required ? 'required' : ''}>
                ${help}
            </div>
        `;
    }).join('');
}

function collectPracticeFormPayload(form) {
    const formData = new FormData(form);
    const payload = {};

    formData.forEach((value, key) => {
        payload[key] = value;
    });

    if (payload.id) {
        payload.id = parseInt(payload.id, 10);
        if (Number.isNaN(payload.id)) {
            delete payload.id;
        }
    }

    payload.titolo = String(payload.titolo || '').trim();
    payload.descrizione = String(payload.descrizione || '').trim();
    payload.note = String(payload.note || '').trim();
    payload.scadenza = payload.scadenza ? String(payload.scadenza) : '';
    payload.cliente_id = payload.cliente_id ? parseInt(payload.cliente_id, 10) : null;
    if (Number.isNaN(payload.cliente_id)) {
        payload.cliente_id = null;
    }
    payload.tipo_pratica = parseInt(payload.tipo_pratica, 10);
    if (Number.isNaN(payload.tipo_pratica)) {
        throw new Error('Seleziona una tipologia valida.');
    }
    payload.id_utente_caf_patronato = payload.id_utente_caf_patronato ? parseInt(payload.id_utente_caf_patronato, 10) : null;
    if (Number.isNaN(payload.id_utente_caf_patronato)) {
        payload.id_utente_caf_patronato = null;
    }

    const metadati = {};
    Object.keys(payload).forEach((key) => {
        if (key.startsWith('meta_')) {
            const slug = key.replace('meta_', '');
            const value = payload[key];
            if (typeof value === 'string') {
                if (value === '' && value !== '0') {
                    delete payload[key];
                    return;
                }
                metadati[slug] = value;
            } else if (value instanceof File) {
                metadati[slug] = value;
            } else {
                metadati[slug] = value;
            }
            delete payload[key];
        }
    });

    const schema = form.__cafConfig?.types.find((type) => type.id === payload.tipo_pratica)?.campi_personalizzati || [];
    schema.forEach((field) => {
        const slug = field.slug || '';
        if (!slug) return;
        const type = (field.type || 'text').toLowerCase();
        if (type === 'checkbox') {
            const checkbox = form.querySelector(`[name="meta_${slug}"]`);
            metadati[slug] = checkbox && checkbox.checked ? '1' : '0';
        }
    });

    payload.metadati = metadati;

    return payload;
}

function buildPracticeDetailMarkup(practice, statuses) {
    const canManage = cafContext.canManagePractices;
    const status = statuses.find((item) => item.codice === practice.stato);
    const statusBadge = `<span class="badge bg-primary">${escapeHtml(status?.nome || practice.stato)}</span>`;
    const attachments = Array.isArray(practice.documenti) ? practice.documenti : [];
    const notes = Array.isArray(practice.note_storico) ? practice.note_storico : [];
    const events = Array.isArray(practice.eventi) ? practice.eventi : [];
    const operatorLabel = practice.assegnatario ? `${escapeHtml(practice.assegnatario.nome)} (${escapeHtml(practice.assegnatario.ruolo)})` : '<span class="text-muted">Non assegnata</span>';
    const clienteLabel = practice.cliente ? escapeHtml(practice.cliente.ragione_sociale || `${practice.cliente.nome || ''} ${practice.cliente.cognome || ''}`.trim() || `Cliente #${practice.cliente.id}`) : '<span class="text-muted">Nessun cliente</span>';

    const attachmentsMarkup = attachments.length ? attachments.map((doc) => {
        const href = resolvePracticeDocumentHref(doc, practice.id);
        const actions = [`
            <a class="btn btn-outline-primary" href="${escapeHtml(href)}" target="_blank" rel="noopener noreferrer">
                <i class="fa-solid fa-download"></i>
            </a>
        `];
        if (canManage) {
            actions.push(`
                <button type="button" class="btn btn-outline-danger" data-action="delete-document" data-document-id="${doc.id}">
                    <i class="fa-solid fa-trash"></i>
                </button>
            `);
        }

        return `
            <div class="list-group-item d-flex justify-content-between align-items-start" data-document-id="${doc.id}">
                <div>
                    <div class="fw-semibold">${escapeHtml(doc.file_name)}</div>
                    <div class="text-muted small">${formatBytes(doc.file_size)} · ${escapeHtml(doc.mime_type)} · ${formatDateTime(doc.created_at)}</div>
                </div>
                <div class="btn-group btn-group-sm">
                    ${actions.join('')}
                </div>
            </div>
        `;
    }).join('') : '<div class="list-group-item text-muted">Nessun allegato presente.</div>';

    const notesMarkup = notes.length ? notes.map((note) => {
        const authorLabel = note.autore && typeof note.autore === 'object' ? note.autore.nome : note.autore;
        const visibleToOperators = coerceBoolean(note.visibile_operatore ?? note.visibile_operatori);
        const visibilityParts = [];
        if (note.visibile_admin) {
            visibilityParts.push('Amministratori');
        }
        if (visibleToOperators) {
            visibilityParts.push('Operatori');
        }
        const visibilityLabel = visibilityParts.length ? visibilityParts.join(' e ') : 'Autore';
        const canDeleteNote = canManage || note.puoi_eliminare;

        return `
            <div class="list-group-item" data-note-id="${note.id}">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <div class="fw-semibold">${escapeHtml(authorLabel || 'Sconosciuto')}</div>
                        <div class="text-muted small">${formatDateTime(note.created_at)}</div>
                    </div>
                    ${canDeleteNote ? `
                    <button type="button" class="btn btn-sm btn-outline-danger" data-action="delete-note" data-note-id="${note.id}">
                        <i class="fa-solid fa-trash"></i>
                    </button>` : ''}
                </div>
                <p class="mb-2 mt-2">${escapeHtml(note.contenuto || '')}</p>
                <div class="small text-muted">Visibile a: ${escapeHtml(visibilityLabel)}</div>
            </div>
        `;
    }).join('') : '<div class="list-group-item text-muted">Nessuna nota disponibile.</div>';

    const statusFormMarkup = canManage ? `
        <form id="practice-status-form" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label" for="practice-status-select">Aggiorna stato</label>
                <select class="form-select" id="practice-status-select" name="status">
                    ${statuses.map((item) => `<option value="${escapeHtml(item.codice)}" ${item.codice === practice.stato ? 'selected' : ''}>${escapeHtml(item.nome)}</option>`).join('')}
                </select>
            </div>
            <div class="col-md-2">
                <button class="btn btn-primary w-100" type="submit">Aggiorna</button>
            </div>
        </form>
    ` : `
        <div class="alert alert-secondary" role="alert">
            Aggiornamento stato disponibile solo per gli operatori Patronato.
        </div>
    `;

    const attachmentsFormMarkup = canManage ? `
        <form id="practice-upload-form" class="row g-2 align-items-end" enctype="multipart/form-data">
            <div class="col-md-8">
                <label class="form-label" for="practice-document">Carica nuovo documento</label>
                <input type="file" class="form-control" id="practice-document" name="document" accept=".pdf,.jpg,.jpeg,.png,.webp">
            </div>
            <div class="col-md-2">
                <button class="btn btn-outline-primary w-100" type="submit">Carica</button>
            </div>
        </form>
    ` : `
        <div class="alert alert-secondary" role="alert">
            Caricamento ed eliminazione documenti disponibili solo agli operatori Patronato.
        </div>
    `;

    const notesFormMarkup = canManage ? `
        <form id="practice-note-form" class="border rounded p-3 mb-3">
            <div class="mb-3">
                <label class="form-label" for="practice-note-text">Aggiungi nota</label>
                <textarea class="form-control" id="practice-note-text" name="content" rows="3" required placeholder="Testo della nota"></textarea>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="note-visible-admin" name="visible_admin" checked>
                <label class="form-check-label" for="note-visible-admin">Mostra agli amministratori</label>
            </div>
            <div class="form-check form-check-inline">
                <input class="form-check-input" type="checkbox" id="note-visible-operators" name="visible_operator" ${practice.assegnatario ? 'checked' : ''}>
                <label class="form-check-label" for="note-visible-operators">Mostra agli operatori</label>
            </div>
            <div class="mt-3">
                <button class="btn btn-primary" type="submit">Salva nota</button>
            </div>
        </form>
    ` : `
        <div class="alert alert-secondary" role="alert">
            Solo gli operatori Patronato possono aggiungere note operative.
        </div>
    `;

    const customFields = renderCustomFieldsReadOnly(practice.tipo?.campi_personalizzati || [], practice.metadati || {});

    const eventsMarkup = events.length ? events.map((event) => `
        <div class="list-group-item">
            <div class="d-flex justify-content-between">
                <div class="fw-semibold">${escapeHtml(event.evento)}</div>
                <div class="text-muted small">${formatDateTime(event.created_at)}</div>
            </div>
            <div class="small">${escapeHtml(event.messaggio || '')}</div>
        </div>
    `).join('') : '<div class="list-group-item text-muted">Nessun evento registrato.</div>';

    return `
        <div class="practice-details">
            <section class="mb-4">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0">Informazioni principali</h6>
                                ${statusBadge}
                            </div>
                            <div class="mb-2"><span class="text-muted small">Titolo</span><div class="fw-semibold">${escapeHtml(practice.titolo)}</div></div>
                            <div class="mb-2"><span class="text-muted small">Tipologia</span><div>${escapeHtml(practice.tipo?.nome || '')}</div></div>
                            <div class="mb-2"><span class="text-muted small">Categoria</span><div>${escapeHtml(practice.categoria)}</div></div>
                            <div class="mb-2"><span class="text-muted small">Operatore</span><div>${operatorLabel}</div></div>
                            <div class="mb-2"><span class="text-muted small">Cliente</span><div>${clienteLabel}</div></div>
                            <div class="text-muted small">Creata il ${formatDateTime(practice.data_creazione)} · Aggiornata il ${formatDateTime(practice.data_aggiornamento)}</div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="border rounded p-3 h-100">
                            <h6>Descrizione</h6>
                            <p class="mb-0">${practice.descrizione ? escapeHtml(practice.descrizione) : '<span class="text-muted">Nessuna descrizione.</span>'}</p>
                            ${practice.scadenza ? `<div class="mt-3"><span class="badge bg-warning text-dark">Scadenza: ${formatDateTime(practice.scadenza)}</span></div>` : ''}
                        </div>
                    </div>
                </div>
            </section>

            <section class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Stato pratica</h5>
                </div>
                ${statusFormMarkup}
            </section>

            <section class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Allegati</h5>
                </div>
                ${attachmentsFormMarkup}
                <div class="list-group mt-3" data-role="attachments-list">
                    ${attachmentsMarkup}
                </div>
            </section>

            <section class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">Note</h5>
                </div>
                ${notesFormMarkup}
                <div class="list-group" data-role="notes-list">
                    ${notesMarkup}
                </div>
            </section>

            <section class="mb-4">
                <h5>Campi personalizzati</h5>
                <div class="list-group">
                    ${customFields}
                </div>
            </section>

            <section>
                <h5>Storico eventi</h5>
                <div class="list-group">
                    ${eventsMarkup}
                </div>
            </section>
        </div>
    `;
}

function setupPracticeDetailView(container, practice, statuses, options = {}) {
    const viewOptions = { ...options, container };
    const mode = viewOptions.mode || 'modal';

    if (mode === 'modal') {
        const editBtn = document.getElementById('practice-edit-button');
        if (editBtn) {
            editBtn.onclick = () => {
                closeModal();
                setTimeout(() => editPractice(practice.id), 120);
            };
        }
    }

    const statusForm = container.querySelector('#practice-status-form');
    if (statusForm) {
        statusForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const select = statusForm.querySelector('select[name="status"]');
            const statusValue = select ? select.value : null;
            if (!statusValue) {
                return;
            }
            if (!appearance.className) {
                classes.push('bg-secondary');
            }
            const submitBtn = statusForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
            if (!appearance.textClass && !appearance.style) {
                classes.push('text-white');
            }
                submitBtn.dataset.originalText = submitBtn.dataset.originalText || submitBtn.textContent;
                submitBtn.textContent = 'Aggiornamento...';
            }
            try {
                await apiRequest('POST', {
                    action: 'update_status',
                    id: practice.id,
                    status: statusValue,
                });
                window.CS?.showToast?.('Stato pratica aggiornato.', 'success');
                loadPracticesList();
                refreshPracticeView(practice.id, viewOptions);
            } catch (error) {
                window.CS?.showToast?.(`Errore aggiornamento stato: ${error.message}`, 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Aggiorna';
                }
            }
        });
    }

    const uploadForm = container.querySelector('#practice-upload-form');
    if (uploadForm) {
        uploadForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const fileInput = uploadForm.querySelector('input[type="file"][name="document"]');
            if (!fileInput || !fileInput.files || !fileInput.files.length) {
                window.CS?.showToast?.('Seleziona un file da caricare.', 'warning');
                return;
            }
            const submitBtn = uploadForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.originalText = submitBtn.dataset.originalText || submitBtn.textContent;
                submitBtn.textContent = 'Caricamento...';
            }
            try {
                await uploadPracticeDocument(practice.id, fileInput.files[0]);
                window.CS?.showToast?.('Documento caricato con successo.', 'success');
                fileInput.value = '';
                refreshPracticeView(practice.id, viewOptions);
            } catch (error) {
                window.CS?.showToast?.(`Errore upload documento: ${error.message}`, 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Carica';
                }
            }
        });
    }

    const noteForm = container.querySelector('#practice-note-form');
    if (noteForm) {
        noteForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const content = noteForm.querySelector('[name="content"]')?.value.trim();
            if (!content) {
                window.CS?.showToast?.('Inserisci il testo della nota.', 'warning');
                return;
            }
            const submitBtn = noteForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.dataset.originalText = submitBtn.dataset.originalText || submitBtn.textContent;
                submitBtn.textContent = 'Salvataggio...';
            }
            try {
                await apiRequest('POST', {
                    action: 'add_note',
                    id: practice.id,
                    content,
                    visible_admin: noteForm.querySelector('[name="visible_admin"]')?.checked ? 1 : 0,
                    visible_operator: noteForm.querySelector('[name="visible_operator"]')?.checked ? 1 : 0,
                });
                noteForm.reset();
                const adminCheckbox = noteForm.querySelector('[name="visible_admin"]');
                if (adminCheckbox) {
                    adminCheckbox.checked = true;
                }
                const operatorCheckbox = noteForm.querySelector('[name="visible_operator"]');
                if (operatorCheckbox && practice.assegnatario) {
                    operatorCheckbox.checked = true;
                }
                window.CS?.showToast?.('Nota aggiunta correttamente.', 'success');
                refreshPracticeView(practice.id, viewOptions);
            } catch (error) {
                window.CS?.showToast?.(`Errore creazione nota: ${error.message}`, 'error');
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = submitBtn.dataset.originalText || 'Salva nota';
                }
            }
        });
    }

    const attachmentsList = container.querySelector('[data-role="attachments-list"]');
    if (attachmentsList) {
        attachmentsList.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) return;
            const target = event.target.closest('[data-action="delete-document"]');
            if (!target) return;
            const documentId = parseInt(target.getAttribute('data-document-id'), 10);
            if (!documentId) return;
            showConfirmDialog({
                title: 'Elimina documento',
                message: 'Confermi l\'eliminazione del documento selezionato? L\'operazione non può essere annullata.',
                confirmLabel: 'Elimina',
                confirmVariant: 'danger',
                onConfirm: async () => {
                    try {
                        await apiRequest('POST', {
                            action: 'delete_document',
                            practice_id: practice.id,
                            document_id: documentId,
                        });
                        window.CS?.showToast?.('Documento eliminato.', 'success');
                        refreshPracticeView(practice.id, viewOptions);
                    } catch (error) {
                        window.CS?.showToast?.(`Errore eliminazione documento: ${error.message}`, 'error');
                    }
                },
            });
        });
    }

    const notesList = container.querySelector('[data-role="notes-list"]');
    if (notesList) {
        notesList.addEventListener('click', (event) => {
            if (!(event.target instanceof Element)) return;
            const target = event.target.closest('[data-action="delete-note"]');
            if (!target) return;
            const noteId = parseInt(target.getAttribute('data-note-id'), 10);
            if (!noteId) return;
            showConfirmDialog({
                title: 'Elimina nota',
                message: 'Vuoi realmente eliminare questa nota?',
                confirmLabel: 'Elimina',
                confirmVariant: 'danger',
                onConfirm: async () => {
                    try {
                        await apiRequest('POST', {
                            action: 'delete_note',
                            practice_id: practice.id,
                            note_id: noteId,
                        });
                        window.CS?.showToast?.('Nota eliminata.', 'success');
                        refreshPracticeView(practice.id, viewOptions);
                    } catch (error) {
                        window.CS?.showToast?.(`Errore eliminazione nota: ${error.message}`, 'error');
                    }
                },
            });
        });
    }
}

function renderCustomFieldsReadOnly(schema, values) {
    if (!Array.isArray(schema) || schema.length === 0) {
        return '<div class="list-group-item text-muted">Nessun campo personalizzato definito.</div>';
    }

    return schema.map((field) => {
        if (!field || typeof field !== 'object') {
            return '';
        }
        const slug = field.slug || '';
        if (!slug) {
            return '';
        }
        const label = field.label || slug;
        const type = (field.type || 'text').toLowerCase();
        const rawValue = values ? values[slug] : undefined;
        let displayValue = '';

        if (type === 'checkbox') {
            displayValue = coerceBoolean(rawValue) ? 'Sì' : 'No';
        } else if (Array.isArray(rawValue)) {
            displayValue = rawValue.map((item) => escapeHtml(String(item))).join(', ');
        } else if (rawValue !== undefined && rawValue !== null && rawValue !== '') {
            displayValue = escapeHtml(String(rawValue));
        } else {
            displayValue = '<span class="text-muted">Non valorizzato</span>';
        }

        return `
            <div class="list-group-item d-flex justify-content-between">
                <span class="fw-semibold">${escapeHtml(label)}</span>
                <span>${displayValue}</span>
            </div>
        `;
    }).join('');
}

async function uploadPracticeDocument(practiceId, file) {
    const formData = new FormData();
    formData.append('action', 'add_document');
    formData.append('id', practiceId);
    formData.append('document', file);

    const csrf = getCsrfToken();
    const response = await fetch(API_BASE, {
        method: 'POST',
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
            ...(csrf ? { 'X-CSRF-Token': csrf } : {}),
        },
        body: formData,
    });

    const data = await response.json();
    if (!response.ok) {
        throw new Error(data.error || `HTTP ${response.status}`);
    }
    return data;
}

async function showCreateTypeModal() {
    try {
        const body = buildTypeFormMarkup();
        openModal({
            title: 'Nuova tipologia',
            body,
            footer: renderPrimaryFooter({ submitLabel: 'Crea tipologia', submitId: 'type-submit' }),
            size: 'lg',
            onShown: (container) => setupTypeForm(container, { type: null }),
        });
    } catch (error) {
        window.CS?.showToast?.(`Impossibile aprire la modale: ${error.message}`, 'error');
    }
}

async function editType(id) {
    try {
        const types = await fetchTypes(true);
        const target = types.find((type) => type.id === id);
        if (!target) {
            throw new Error('Tipologia non trovata');
        }
        const body = buildTypeFormMarkup(target);
        openModal({
            title: `Modifica tipologia #${target.id}`,
            body,
            footer: renderPrimaryFooter({ submitLabel: 'Salva tipologia', submitId: 'type-submit' }),
            size: 'lg',
            onShown: (container) => setupTypeForm(container, { type: target }),
        });
    } catch (error) {
        window.CS?.showToast?.(`Errore caricamento tipologia: ${error.message}`, 'error');
    }
}

function buildTypeFormMarkup(type = null) {
    const customFields = type?.campi_personalizzati ? JSON.stringify(type.campi_personalizzati, null, 2) : '';
    return `
        <form id="caf-patronato-modal-form" class="needs-validation" novalidate>
            <div class="alert alert-danger d-none" data-role="form-alert"></div>
            ${type ? `<input type="hidden" name="id" value="${type.id}">` : ''}
            <div class="mb-3">
                <label class="form-label" for="type-name">Nome</label>
                <input type="text" class="form-control" id="type-name" name="nome" required maxlength="160" value="${type ? escapeHtml(type.nome) : ''}">
            </div>
            <div class="mb-3">
                <label class="form-label" for="type-category">Categoria</label>
                <select class="form-select" id="type-category" name="categoria" required>
                    <option value="">Seleziona...</option>
                    <option value="CAF" ${type?.categoria === 'CAF' ? 'selected' : ''}>CAF</option>
                    <option value="PATRONATO" ${type?.categoria === 'PATRONATO' ? 'selected' : ''}>Patronato</option>
                </select>
            </div>
            <div class="mb-3">
                <label class="form-label" for="type-custom-fields">Campi personalizzati (JSON)</label>
                <textarea class="form-control font-monospace" id="type-custom-fields" name="campi_personalizzati" rows="8" placeholder="[]">${customFields ? escapeHtml(customFields) : ''}</textarea>
                <small class="text-muted">Inserisci un array JSON di campi con chiavi: slug, label, type, required, options.</small>
            </div>
        </form>
    `;
}

function setupTypeForm(container, { type }) {
    const form = container.querySelector('#caf-patronato-modal-form');
    if (!form) return;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        setFormAlert(form);
        setFormSubmitting(form, true, 'type-submit');
        try {
            const payload = collectTypeFormPayload(form);
            const action = type ? 'update_type' : 'create_type';
            await apiRequest('POST', { action, ...payload });
            window.CS?.showToast?.(`Tipologia ${type ? 'aggiornata' : 'creata'} con successo.`, 'success');
            invalidateCache('types');
            closeModal();
            loadTypesList();
        } catch (error) {
            setFormAlert(form, error.message || 'Errore nel salvataggio della tipologia.');
        } finally {
            setFormSubmitting(form, false, 'type-submit');
        }
    });
}

function collectTypeFormPayload(form) {
    const payload = {
        nome: form.querySelector('[name="nome"]').value.trim(),
        categoria: form.querySelector('[name="categoria"]').value,
        campi_personalizzati: [],
    };
    const idField = form.querySelector('[name="id"]');
    if (idField) {
        const numericId = parseInt(idField.value, 10);
        if (!Number.isNaN(numericId)) {
            payload.id = numericId;
        }
    }
    const customFieldsRaw = form.querySelector('[name="campi_personalizzati"]').value.trim();
    if (customFieldsRaw) {
        try {
            const parsed = JSON.parse(customFieldsRaw);
            payload.campi_personalizzati = Array.isArray(parsed) ? parsed : [];
        } catch (error) {
            throw new Error('I campi personalizzati devono essere un JSON valido.');
        }
    }
    return payload;
}

function deleteType(id) {
    showConfirmDialog({
        title: 'Elimina tipologia',
        message: 'Confermi l\'eliminazione della tipologia selezionata? Le pratiche associate potrebbero risultare orfane.',
        confirmLabel: 'Elimina',
        confirmVariant: 'danger',
        onConfirm: async () => {
            try {
                await apiRequest('POST', { action: 'delete_type', id });
                window.CS?.showToast?.('Tipologia eliminata.', 'success');
                invalidateCache('types');
                loadTypesList();
            } catch (error) {
                window.CS?.showToast?.(`Errore eliminazione tipologia: ${error.message}`, 'error');
            }
        },
    });
}

async function showCreateStatusModal() {
    const body = buildStatusFormMarkup();
    openModal({
        title: 'Nuovo stato pratica',
        body,
        footer: renderPrimaryFooter({ submitLabel: 'Crea stato', submitId: 'status-submit' }),
        size: 'lg',
        onShown: (container) => setupStatusForm(container, { status: null }),
    });
}

async function editStatus(id) {
    try {
        const statuses = await fetchStatuses(true);
        const status = statuses.find((item) => item.id === id);
        if (!status) {
            throw new Error('Stato non trovato');
        }
        const body = buildStatusFormMarkup(status);
        openModal({
            title: `Modifica stato #${status.id}`,
            body,
            footer: renderPrimaryFooter({ submitLabel: 'Salva stato', submitId: 'status-submit' }),
            size: 'lg',
            onShown: (container) => setupStatusForm(container, { status }),
        });
    } catch (error) {
        window.CS?.showToast?.(`Errore caricamento stato: ${error.message}`, 'error');
    }
}

function buildStatusFormMarkup(status = null) {
    return `
        <form id="caf-patronato-modal-form" class="needs-validation" novalidate>
            <div class="alert alert-danger d-none" data-role="form-alert"></div>
            ${status ? `<input type="hidden" name="id" value="${status.id}">` : ''}
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="status-name">Nome</label>
                    <input type="text" class="form-control" id="status-name" name="nome" required value="${status ? escapeHtml(status.nome) : ''}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="status-code">Codice</label>
                    <input type="text" class="form-control" id="status-code" name="codice" required pattern="[a-z0-9_\-]+" ${status ? 'readonly' : ''} value="${status ? escapeHtml(status.codice) : ''}">
                    <small class="text-muted">Minuscole, numeri, trattini e underscore.</small>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="status-color">Colore badge</label>
                    <input type="text" class="form-control" id="status-color" name="colore" value="${status ? escapeHtml(status.colore) : 'primary'}" placeholder="Esempio: primary, success, warning">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="status-ordering">Ordine visualizzazione</label>
                    <input type="number" class="form-control" id="status-ordering" name="ordering" min="0" value="${status ? parseInt(status.ordering, 10) : 0}">
                </div>
            </div>
        </form>
    `;
}

function setupStatusForm(container, { status }) {
    const form = container.querySelector('#caf-patronato-modal-form');
    if (!form) return;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        setFormAlert(form);
        setFormSubmitting(form, true, 'status-submit');
        try {
            const payload = collectStatusFormPayload(form);
            const action = status ? 'update_status_definition' : 'create_status';
            await apiRequest('POST', { action, ...payload });
            window.CS?.showToast?.(`Stato ${status ? 'aggiornato' : 'creato'} con successo.`, 'success');
            invalidateCache('statuses');
            closeModal();
            loadStatusesList();
            if (document.getElementById('practices-filters-form')) {
                loadFiltersData();
            }
        } catch (error) {
            setFormAlert(form, error.message || 'Errore nel salvataggio dello stato.');
        } finally {
            setFormSubmitting(form, false, 'status-submit');
        }
    });
}

function collectStatusFormPayload(form) {
    const payload = {
        nome: form.querySelector('[name="nome"]').value.trim(),
        codice: form.querySelector('[name="codice"]').value.trim(),
        colore: form.querySelector('[name="colore"]').value.trim() || 'primary',
        ordering: parseInt(form.querySelector('[name="ordering"]').value || '0', 10) || 0,
    };
    const idField = form.querySelector('[name="id"]');
    if (idField) {
        const numericId = parseInt(idField.value, 10);
        if (!Number.isNaN(numericId)) {
            payload.id = numericId;
        }
    }
    return payload;
}

function deleteStatus(id) {
    showConfirmDialog({
        title: 'Elimina stato',
        message: 'Eliminando lo stato le pratiche associate saranno impostate allo stato predefinito.',
        confirmLabel: 'Elimina',
        confirmVariant: 'danger',
        onConfirm: async () => {
            try {
                await apiRequest('POST', { action: 'delete_status_definition', id });
                window.CS?.showToast?.('Stato eliminato.', 'success');
                invalidateCache('statuses');
                loadStatusesList();
                if (document.getElementById('practices-filters-form')) {
                    loadFiltersData();
                }
            } catch (error) {
                window.CS?.showToast?.(`Errore eliminazione stato: ${error.message}`, 'error');
            }
        },
    });
}

function showCreateOperatorModal() {
    const body = buildOperatorFormMarkup();
    openModal({
        title: 'Nuovo operatore',
        body,
        footer: renderPrimaryFooter({ submitLabel: 'Crea operatore', submitId: 'operator-submit' }),
        size: 'lg',
        onShown: (container) => setupOperatorForm(container, { operator: null }),
    });
}

async function editOperator(id) {
    try {
        if (!Array.isArray(operatorsDataset) || !operatorsDataset.length) {
            await loadOperatorsList();
        }
        const operator = operatorsDataset.find((item) => item.id === id);
        if (!operator) {
            throw new Error('Operatore non trovato');
        }
        const body = buildOperatorFormMarkup(operator);
        openModal({
            title: `Modifica operatore #${operator.id}`,
            body,
            footer: renderPrimaryFooter({ submitLabel: 'Salva operatore', submitId: 'operator-submit' }),
            size: 'lg',
            onShown: (container) => setupOperatorForm(container, { operator }),
        });
    } catch (error) {
        window.CS?.showToast?.(`Errore apertura operatore: ${error.message}`, 'error');
    }
}

function buildOperatorFormMarkup(operator = null) {
    const isActive = operator ? coerceBoolean(operator.attivo) : true;
    const activeAttr = isActive ? 'checked' : '';
    return `
        <form id="caf-patronato-modal-form" class="needs-validation" novalidate>
            <div class="alert alert-danger d-none" data-role="form-alert"></div>
            ${operator ? `<input type="hidden" name="id" value="${operator.id}">` : ''}
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="operator-name">Nome</label>
                    <input type="text" class="form-control" id="operator-name" name="nome" required value="${operator ? escapeHtml(operator.nome) : ''}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-surname">Cognome</label>
                    <input type="text" class="form-control" id="operator-surname" name="cognome" required value="${operator ? escapeHtml(operator.cognome) : ''}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-email">Email</label>
                    <input type="email" class="form-control" id="operator-email" name="email" required value="${operator ? escapeHtml(operator.email) : ''}">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-role">Ruolo</label>
                    <select class="form-select" id="operator-role" name="ruolo" required>
                        <option value="">Seleziona...</option>
                        <option value="CAF" ${operator?.ruolo === 'CAF' ? 'selected' : ''}>CAF</option>
                        <option value="PATRONATO" ${operator?.ruolo === 'PATRONATO' ? 'selected' : ''}>Patronato</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-user-id">ID Utente sistema (opzionale)</label>
                    <input type="number" class="form-control" id="operator-user-id" name="user_id" min="1" value="${operator?.user_id || ''}" placeholder="Collega a un utente interno">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="operator-password">Password (facoltativa)</label>
                    <input type="password" class="form-control" id="operator-password" name="password" placeholder="Lascia vuoto per generare automaticamente">
                    ${operator ? '<small class="text-muted">Compila per reimpostare la password.</small>' : '<small class="text-muted">Lascia vuoto per generare una password temporanea.</small>'}
                </div>
                <div class="col-md-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="operator-active" name="attivo" ${activeAttr}>
                        <label class="form-check-label" for="operator-active">Operatore attivo</label>
                    </div>
                </div>
            </div>
        </form>
    `;
}

function setupOperatorForm(container, { operator }) {
    const form = container.querySelector('#caf-patronato-modal-form');
    if (!form) return;
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        setFormAlert(form);
        setFormSubmitting(form, true, 'operator-submit');
        try {
            const payload = collectOperatorFormPayload(form);
            await apiRequest('POST', { action: 'save_operator', ...payload });
            window.CS?.showToast?.(`Operatore ${operator ? 'aggiornato' : 'creato'} con successo.`, 'success');
            invalidateCache('operators');
            closeModal();
            loadOperatorsList();
            if (cafContext.canConfigureModule) {
                fetchOperators(true);
            }
        } catch (error) {
            setFormAlert(form, error.message || 'Errore nel salvataggio operatore.');
        } finally {
            setFormSubmitting(form, false, 'operator-submit');
        }
    });
}

function collectOperatorFormPayload(form) {
    const userIdField = form.querySelector('[name="user_id"]');
    const rawUserId = userIdField ? userIdField.value.trim() : '';
    const idField = form.querySelector('[name="id"]');

    const payload = {
        nome: form.querySelector('[name="nome"]').value.trim(),
        cognome: form.querySelector('[name="cognome"]').value.trim(),
        email: form.querySelector('[name="email"]').value.trim(),
        ruolo: form.querySelector('[name="ruolo"]').value,
        user_id: rawUserId ? parseInt(rawUserId, 10) : null,
        password: form.querySelector('[name="password"]').value,
        attivo: form.querySelector('[name="attivo"]').checked ? 1 : 0,
    };

    if (Number.isNaN(payload.user_id)) {
        payload.user_id = null;
    }

    if (idField) {
        const numericId = parseInt(idField.value, 10);
        if (!Number.isNaN(numericId)) {
            payload.id = numericId;
        }
    }
    return payload;
}



async function toggleOperator(id, enable) {
    try {
        await apiRequest('POST', {
            action: 'toggle_operator',
            id: id,
            enable: enable
        });
        
        if (window.CS?.showToast) {
            window.CS.showToast(
                `Operatore ${enable ? 'attivato' : 'disattivato'} con successo`, 
                'success'
            );
        }
        
        invalidateCache('operators');
        loadOperatorsList();
    } catch (error) {
        if (window.CS?.showToast) {
            window.CS.showToast('Errore: ' + error.message, 'error');
        }
    }
}

// Utility Functions
async function apiRequest(method, params = {}) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
    const isGet = method === 'GET';
    const url = isGet && Object.keys(params).length ? 
        `${API_BASE}?${new URLSearchParams(params).toString()}` : 
        API_BASE;
    
    const options = {
        method,
        headers: {
            'Content-Type': isGet ? 'application/x-www-form-urlencoded' : 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        }
    };
    
    if (csrfToken && !isGet) {
        options.headers['X-CSRF-Token'] = csrfToken;
    }
    
    if (!isGet && Object.keys(params).length) {
        options.body = JSON.stringify(params);
    }
    
    const response = await fetch(url, options);
    const rawBody = await response.text();
    let data = null;

    if (rawBody && rawBody.trim() !== '') {
        try {
            data = JSON.parse(rawBody);
        } catch (parseError) {
            console.error('CAF/Patronato API JSON parse error:', parseError, rawBody);
        }
    }

    if (!response.ok) {
        const errorMessage = (data && typeof data.error === 'string') ? data.error : (rawBody || `HTTP ${response.status}`);
        throw new Error(errorMessage);
    }

    if (data === null) {
        if (!rawBody || rawBody.trim() === '') {
            return {};
        }
        throw new Error('Risposta non valida dal server.');
    }

    return data;
}

function setFormValues(form, values = {}) {
    if (!form || typeof values !== 'object' || values === null) {
        return;
    }

    Object.entries(values).forEach(([key, value]) => {
        const fields = form.querySelectorAll(`[name="${key}"]`);
        if (!fields.length) {
            return;
        }

        fields.forEach((field) => {
            if (field instanceof HTMLInputElement) {
                if (field.type === 'checkbox') {
                    field.checked = coerceBoolean(value);
                } else if (field.type === 'radio') {
                    field.checked = field.value === String(value);
                } else {
                    field.value = value ?? '';
                }
            } else if (field instanceof HTMLSelectElement || field instanceof HTMLTextAreaElement) {
                field.value = value ?? '';
            }
        });
    });
}

function getFormFilters(formId) {
    const form = document.getElementById(formId);
    if (!form) return {};
    
    const formData = new FormData(form);
    const filters = {};
    
    for (const [key, value] of formData.entries()) {
        if (value.trim() !== '') {
            filters[key] = value.trim();
        }
    }
    
    return filters;
}

function showSpinner(container) {
    container.innerHTML = `
        <div class="d-flex justify-content-center py-4">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Caricamento...</span>
            </div>
        </div>
    `;
}

function showError(container, message) {
    container.innerHTML = `
        <div class="alert alert-danger" role="alert">
            <i class="fa-solid fa-triangle-exclamation me-2"></i>
            ${escapeHtml(message)}
        </div>
    `;
}

function renderPagination(container, pagination, callback) {
    if (!container || !pagination || pagination.pages <= 1) {
        if (container) container.style.display = 'none';
        return;
    }
    
    const { page, pages } = pagination;
    let html = '';
    
    // Previous button
    if (page > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault(); (${callback})(${page - 1})">Precedente</a></li>`;
    }
    
    // Page numbers
    const start = Math.max(1, page - 2);
    const end = Math.min(pages, page + 2);
    
    if (start > 1) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault(); (${callback})(1)">1</a></li>`;
        if (start > 2) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
    }
    
    for (let i = start; i <= end; i++) {
        html += `<li class="page-item ${i === page ? 'active' : ''}">
            <a class="page-link" href="#" onclick="event.preventDefault(); (${callback})(${i})">${i}</a>
        </li>`;
    }
    
    if (end < pages) {
        if (end < pages - 1) {
            html += `<li class="page-item disabled"><span class="page-link">...</span></li>`;
        }
        html += `<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault(); (${callback})(${pages})">${pages}</a></li>`;
    }
    
    // Next button
    if (page < pages) {
        html += `<li class="page-item"><a class="page-link" href="#" onclick="event.preventDefault(); (${callback})(${page + 1})">Successivo</a></li>`;
    }
    
    container.querySelector('ul').innerHTML = html;
    container.style.display = 'block';
}

function updateOperatorsCounters(operators) {
    const total = Array.isArray(operators) ? operators.length : 0;
    const active = Array.isArray(operators) ? operators.filter((operator) => coerceBoolean(operator.attivo)).length : 0;
    updateSummaryElement('operators-count-total', total);
    updateSummaryElement('operators-count-active', active);
}

function updateNotificationsCounters(notifications) {
    const open = Array.isArray(notifications) ? notifications.filter((notification) => notification.stato !== 'letta').length : 0;
    updateSummaryElement('notifications-count-open', open);
}