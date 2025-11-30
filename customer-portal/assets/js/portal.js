/**
 * JavaScript principale per il Pickup Portal
 */

// Configurazione globale
window.PickupPortal = {
    config: window.portalConfig || {},
    apiUrl: function(endpoint) {
        const baseUrl = typeof this.config.apiBaseUrl === 'string' && this.config.apiBaseUrl.length > 0
            ? this.config.apiBaseUrl
            : 'api/';
        return baseUrl + endpoint;
    },
    
    // Utility per le chiamate API
    api: {
        call: async function(endpoint, options = {}) {
            const defaultOptions = {
                method: 'GET',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': window.portalConfig?.csrfToken || ''
                }
            };
            
            const finalOptions = { ...defaultOptions, ...options };
            
            if (finalOptions.body && typeof finalOptions.body === 'object') {
                finalOptions.body = JSON.stringify(finalOptions.body);
            }
            
            try {
                const response = await fetch(window.PickupPortal.apiUrl(endpoint), finalOptions);
                const rawBody = await response.text();
                let parsedBody = null;

                if (rawBody) {
                    try {
                        parsedBody = JSON.parse(rawBody);
                    } catch (jsonError) {
                        parsedBody = null;
                    }
                }

                if (!response.ok) {
                    const errorMessage = parsedBody?.message
                        || parsedBody?.error
                        || response.statusText
                        || `HTTP ${response.status}`;
                    const error = new Error(errorMessage);
                    error.status = response.status;
                    error.body = parsedBody;
                    throw error;
                }

                return parsedBody ?? {};
            } catch (error) {
                console.error('API Error:', error);
                window.PickupPortal.showAlert(error.message || 'Errore di comunicazione con il server', 'danger');
                throw error;
            }
        },
        
        get: function(endpoint) {
            return this.call(endpoint);
        },
        
        post: function(endpoint, data) {
            return this.call(endpoint, {
                method: 'POST',
                body: data
            });
        },
        
        put: function(endpoint, data) {
            return this.call(endpoint, {
                method: 'PUT',
                body: data
            });
        },
        
        delete: function(endpoint) {
            return this.call(endpoint, {
                method: 'DELETE'
            });
        }
    },
    
    CookieConsent: {
        storageKey: 'portalCookieConsent',
        cookieName: 'portalCookieConsent',
        bannerId: 'portalCookieBanner',
        acceptButtonId: 'portalCookieAccept',

        init: function() {
            const banner = document.getElementById(this.bannerId);
            if (!banner) {
                return;
            }

            if (this.hasConsent()) {
                this.hideBanner(banner);
                return;
            }

            const acceptButton = document.getElementById(this.acceptButtonId);
            if (acceptButton) {
                acceptButton.addEventListener('click', () => this.accept(banner));
            }

            banner.removeAttribute('hidden');
        },

        accept: function(banner) {
            this.persistStatus('accepted');
            this.hideBanner(banner);
        },

        hasConsent: function() {
            return this.readStatus() === 'accepted';
        },

        readStatus: function() {
            const stored = this.readFromStorage();
            if (stored) {
                return stored;
            }
            return this.readFromCookie();
        },

        persistStatus: function(status) {
            this.writeToStorage(status);
            this.writeToCookie(status, 365);
        },

        hideBanner: function(banner) {
            const element = banner || document.getElementById(this.bannerId);
            if (!element) {
                return;
            }
            element.setAttribute('hidden', 'hidden');
        },

        readFromStorage: function() {
            try {
                return window.localStorage.getItem(this.storageKey);
            } catch (error) {
                return null;
            }
        },

        writeToStorage: function(value) {
            try {
                window.localStorage.setItem(this.storageKey, value);
            } catch (error) {
                // Ignore storage errors (e.g. private mode)
            }
        },

        readFromCookie: function() {
            const name = `${this.cookieName}=`;
            const decoded = decodeURIComponent(document.cookie || '');
            const cookies = decoded.split(';');
            for (let i = 0; i < cookies.length; i += 1) {
                const cookie = cookies[i].trim();
                if (cookie.indexOf(name) === 0) {
                    return cookie.substring(name.length);
                }
            }
            return null;
        },

        writeToCookie: function(value, days) {
            const maxAge = typeof days === 'number' ? days * 24 * 60 * 60 : 31536000;
            const secure = window.location.protocol === 'https:' ? ';Secure' : '';
            document.cookie = `${this.cookieName}=${value};path=/;SameSite=Strict;Max-Age=${maxAge}${secure}`;
        }
    },

    // Utility per gli alert
    showAlert: function(message, type = 'info', duration = 5000) {
        const alertContainer = document.getElementById('global-alert-container');
        const alertElement = document.getElementById('global-alert');
        const alertMessage = document.getElementById('global-alert-message');
        
        if (!alertContainer || !alertElement || !alertMessage) {
            const fallbackContainer = document.getElementById('alert-container');
            if (!fallbackContainer) {
                console.warn('Alert container not found');
                return;
            }
            const wrapper = document.createElement('div');
            wrapper.className = `alert alert-${type} alert-dismissible fade show shadow-sm`;
            wrapper.innerHTML = `
                <div>${message}</div>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Chiudi"></button>
            `;
            fallbackContainer.appendChild(wrapper);
            if (duration > 0) {
                setTimeout(() => {
                    wrapper.classList.remove('show');
                    setTimeout(() => wrapper.remove(), 300);
                }, duration);
            }
            return;
        }
        
        // Rimuovi classi precedenti
        alertElement.className = 'alert alert-dismissible fade show';
        alertElement.classList.add(`alert-${type}`);
        
        alertMessage.textContent = message;
        alertContainer.style.display = 'block';
        
        // Auto-hide dopo duration
        if (duration > 0) {
            setTimeout(() => {
                alertContainer.style.display = 'none';
            }, duration);
        }
    },

    capLookup: {
        dataUrl: 'assets/data/comuni.json?v=20251109',
        fallbackDataUrl: 'https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json',
        zipRegex: /^\d{5}$/,
        index: null,
        loadPromise: null,

        normalize(value) {
            if (value === undefined || value === null) {
                return '';
            }
            let normalized = String(value);
            if (typeof normalized.normalize === 'function') {
                normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
            }
            return normalized.replace(/'/g, '').replace(/\s+/g, ' ').trim().toUpperCase();
        },

        buildIndex(entries) {
            const index = Object.create(null);
            if (!Array.isArray(entries)) {
                return index;
            }
            entries.forEach((entry) => {
                if (!entry || typeof entry !== 'object') {
                    return;
                }
                const city = typeof entry.nome === 'string' ? entry.nome.trim() : '';
                if (!city) {
                    return;
                }
                const province = typeof entry.sigla === 'string' ? entry.sigla.trim().toUpperCase() : '';
                let caps = entry.cap;
                if (typeof caps === 'string') {
                    caps = [caps];
                } else if (!Array.isArray(caps)) {
                    return;
                }

                caps.forEach((capValue) => {
                    const cap = typeof capValue === 'string' ? capValue.trim() : '';
                    if (!cap) {
                        return;
                    }
                    if (!index[cap]) {
                        index[cap] = [];
                    }
                    const alreadyPresent = index[cap].some((item) => item.city === city && item.province === province);
                    if (!alreadyPresent) {
                        index[cap].push({ city, province });
                    }
                });
            });
            return index;
        },

        loadIndex() {
            if (this.index) {
                return Promise.resolve(this.index);
            }
            if (this.loadPromise) {
                return this.loadPromise;
            }

            const fetchDataset = (url, label) => fetch(url, { cache: 'force-cache' })
                .then((response) => {
                    if (!response.ok) {
                        throw new Error(`${label} request failed (${response.status})`);
                    }
                    return response.json();
                });

            this.loadPromise = fetchDataset(this.dataUrl, 'CAP dataset')
                .catch((error) => {
                    console.warn('CAP lookup: local dataset unavailable', error);
                    if (this.fallbackDataUrl) {
                        return fetchDataset(this.fallbackDataUrl, 'CAP dataset fallback');
                    }
                    throw error;
                })
                .then((payload) => {
                    this.index = this.buildIndex(payload);
                    return this.index;
                })
                .catch((error) => {
                    console.warn('CAP lookup: dataset unavailable', error);
                    this.index = null;
                    throw error;
                });

            return this.loadPromise;
        },

        attach(config) {
            const resolveElement = (ref) => {
                if (!ref) {
                    return null;
                }
                if (typeof ref === 'string') {
                    return document.querySelector(ref);
                }
                return ref;
            };

            const zipInput = resolveElement(config.zip);
            const cityInput = resolveElement(config.city);
            const provinceInput = resolveElement(config.province);
            const countryInput = resolveElement(config.country);
            const datalist = resolveElement(config.datalist);

            if (!zipInput || !cityInput || !datalist) {
                return null;
            }

            const state = {
                matches: [],
                originalZipPlaceholder: zipInput.getAttribute('placeholder') || '',
                irelandPlaceholder: 'Eircode (es. D02X285) oppure scrivi EIRE',
            };

            const shouldUseLookup = () => {
                if (!countryInput) {
                    return true;
                }
                const countryValue = (countryInput.value || '').toUpperCase();
                return countryValue === '' || countryValue === 'IT';
            };

            const applyCountrySpecificBehavior = () => {
                if (!zipInput) {
                    return;
                }
                const countryValue = countryInput ? (countryInput.value || '').toUpperCase() : 'IT';
                const isIreland = countryValue === 'IE';
                if (isIreland) {
                    if (zipInput.getAttribute('placeholder') !== state.irelandPlaceholder) {
                        zipInput.setAttribute('placeholder', state.irelandPlaceholder);
                    }
                    if (zipInput.value.trim() === '') {
                        zipInput.value = 'EIRE';
                        zipInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                } else {
                    if (state.originalZipPlaceholder) {
                        zipInput.setAttribute('placeholder', state.originalZipPlaceholder);
                    } else {
                        zipInput.removeAttribute('placeholder');
                    }
                    if (zipInput.value.trim().toUpperCase() === 'EIRE') {
                        zipInput.value = '';
                        zipInput.dispatchEvent(new Event('input', { bubbles: true }));
                    }
                }
            };

            const clearSuggestions = () => {
                state.matches = [];
                while (datalist.firstChild) {
                    datalist.removeChild(datalist.firstChild);
                }
            };

            const fillProvinceByCityValue = () => {
                if (!provinceInput || !state.matches.length) {
                    return;
                }
                const normalizedCity = this.normalize(cityInput.value);
                if (!normalizedCity) {
                    return;
                }
                const match = state.matches.find((item) => this.normalize(item.city) === normalizedCity);
                if (match && match.province && provinceInput.value !== match.province) {
                    provinceInput.value = match.province;
                    provinceInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
            };

            const applyMatches = (cap, matches) => {
                clearSuggestions();
                if (!Array.isArray(matches) || matches.length === 0) {
                    return;
                }
                state.matches = matches.slice();
                matches.forEach((item) => {
                    const option = document.createElement('option');
                    option.value = item.city;
                    option.setAttribute('data-province', item.province || '');
                    option.setAttribute('data-cap', cap);
                    datalist.appendChild(option);
                });

                const normalizedCity = this.normalize(cityInput.value);
                const hasExactMatch = normalizedCity
                    ? state.matches.some((item) => this.normalize(item.city) === normalizedCity)
                    : false;

                if (state.matches.length > 0 && (!normalizedCity || !hasExactMatch)) {
                    const [firstMatch] = state.matches;
                    if (firstMatch) {
                        if (cityInput.value !== firstMatch.city) {
                            cityInput.value = firstMatch.city;
                            cityInput.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                        if (provinceInput && firstMatch.province && provinceInput.value !== firstMatch.province) {
                            provinceInput.value = firstMatch.province;
                            provinceInput.dispatchEvent(new Event('input', { bubbles: true }));
                        }
                    }
                }

                fillProvinceByCityValue();
            };

            const handleZipChange = () => {
                if (!shouldUseLookup()) {
                    clearSuggestions();
                    return;
                }

                const rawValue = zipInput.value.trim();
                if (!this.zipRegex.test(rawValue)) {
                    clearSuggestions();
                    return;
                }

                this.loadIndex()
                    .then((index) => {
                        if (!index) {
                            return;
                        }
                        const matches = index[rawValue] || [];
                        applyMatches(rawValue, matches);
                        fillProvinceByCityValue();
                    })
                    .catch(() => {
                        clearSuggestions();
                    });
            };

            const debounce = (fn, delay) => {
                let timer = null;
                return function debounced() {
                    if (timer) {
                        window.clearTimeout(timer);
                    }
                    const args = arguments;
                    timer = window.setTimeout(() => {
                        fn.apply(null, args);
                    }, delay);
                };
            };

            const debouncedZipHandler = debounce(handleZipChange, 160);

            zipInput.addEventListener('input', debouncedZipHandler);
            zipInput.addEventListener('change', handleZipChange);
            zipInput.addEventListener('blur', handleZipChange);
            cityInput.addEventListener('change', fillProvinceByCityValue);
            cityInput.addEventListener('input', fillProvinceByCityValue);

            applyCountrySpecificBehavior();

            if (countryInput) {
                countryInput.addEventListener('change', () => {
                    applyCountrySpecificBehavior();
                    if (shouldUseLookup()) {
                        handleZipChange();
                    } else {
                        clearSuggestions();
                    }
                });
            }

            if (zipInput.value) {
                handleZipChange();
            }

            return {
                refresh: handleZipChange,
                clear: clearSuggestions,
            };
        },
    },

    getStaticBaseUrl: function() {
        const configured = window.portalConfig && typeof window.portalConfig.staticBaseUrl === 'string'
            ? window.portalConfig.staticBaseUrl.trim()
            : '';
        let base = configured.length > 0 ? configured : 'assets/';
        if (!base.endsWith('/')) {
            base += '/';
        }
        return base;
    },

    getLeafletAssetsBase: function() {
        const staticBase = this.getStaticBaseUrl();
        return `${staticBase}vendor/leaflet/`;
    },

    getLeafletTileUrlTemplate: function() {
        const configured = window.portalConfig && typeof window.portalConfig.apiBaseUrl === 'string'
            ? window.portalConfig.apiBaseUrl.trim()
            : '';
        let apiBase = configured.length > 0 ? configured : 'api/';
        if (!apiBase.endsWith('/')) {
            apiBase += '/';
        }
        return `${apiBase}leaflet-tiles.php?z={z}&x={x}&y={y}`;
    },

    ensureLeaflet: function(callback) {
        const ready = () => {
            if (window.L && typeof window.L.Icon !== 'undefined' && !window.PickupPortal._leafletDefaultsApplied) {
                const markerSvg = `<svg xmlns="http://www.w3.org/2000/svg" width="25" height="41" viewBox="0 0 25 41"><path fill="%233467d9" d="M12.5 0C5.596 0 0 5.596 0 12.5c0 9.375 12.5 28.5 12.5 28.5S25 21.875 25 12.5C25 5.596 19.404 0 12.5 0z"/><circle fill="white" cx="12.5" cy="12.5" r="6"/></svg>`;
                const markerUrl = `data:image/svg+xml;charset=UTF-8,${encodeURIComponent(markerSvg)}`;
                try {
                    window.L.Icon.Default.mergeOptions({
                        iconUrl: markerUrl,
                        iconRetinaUrl: markerUrl,
                        shadowUrl: null,
                        shadowRetinaUrl: null
                    });
                } catch (error) {
                    console.warn('Leaflet default icon configuration failed', error);
                }
                window.PickupPortal._leafletDefaultsApplied = true;
            }

            if (typeof callback === 'function') {
                callback();
            }
        };

        if (window.L && typeof window.L.map === 'function') {
            ready();
            return;
        }

        const leafletBase = window.PickupPortal.getLeafletAssetsBase();

        const cssId = 'leaflet-css-loader';
        if (!document.getElementById(cssId)) {
            const link = document.createElement('link');
            link.rel = 'stylesheet';
            link.href = `${leafletBase}leaflet.css`;
            link.id = cssId;
            document.head.appendChild(link);
        }

        const scriptId = 'leaflet-js-loader';
        const existingScript = document.getElementById(scriptId);
        if (existingScript) {
            if (existingScript.dataset.loaded === '1') {
                ready();
            } else {
                existingScript.addEventListener('load', () => {
                    existingScript.dataset.loaded = '1';
                    ready();
                }, { once: true });
            }
            return;
        }

        const script = document.createElement('script');
        script.src = `${leafletBase}leaflet.js`;
        script.id = scriptId;
        script.addEventListener('load', () => {
            script.dataset.loaded = '1';
            ready();
        }, { once: true });
        document.head.appendChild(script);
    },
    
    // Utility per il loading
    showLoading: function(element) {
        if (typeof element === 'string') {
            element = document.getElementById(element);
        }
        
        if (element) {
            element.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"><span class="visually-hidden">Caricamento...</span></div></div>';
        }
    },
    
    // Formattazione date
    formatDate: function(dateString, options = {}) {
        const defaultOptions = {
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
            hour: '2-digit',
            minute: '2-digit'
        };
        
        const finalOptions = { ...defaultOptions, ...options };
        
        try {
            const date = new Date(dateString);
            return date.toLocaleDateString('it-IT', finalOptions);
        } catch (error) {
            console.error('Date formatting error:', error);
            return dateString;
        }
    },
    
    // Formattazione relative time
    formatRelativeTime: function(dateString) {
        try {
            const date = new Date(dateString);
            const now = new Date();
            const diffMs = now - date;
            const diffSecs = Math.floor(diffMs / 1000);
            const diffMins = Math.floor(diffSecs / 60);
            const diffHours = Math.floor(diffMins / 60);
            const diffDays = Math.floor(diffHours / 24);
            
            if (diffSecs < 60) return 'Ora';
            if (diffMins < 60) return `${diffMins}m fa`;
            if (diffHours < 24) return `${diffHours}h fa`;
            if (diffDays < 7) return `${diffDays}g fa`;
            
            return this.formatDate(dateString, { year: 'numeric', month: '2-digit', day: '2-digit' });
        } catch (error) {
            console.error('Relative time formatting error:', error);
            return dateString;
        }
    },
    
    // Copia testo negli appunti
    copyToClipboard: function(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text).then(() => {
                this.showAlert('Copiato negli appunti!', 'success', 2000);
            }).catch(err => {
                console.error('Errore copia:', err);
                this.showAlert('Errore durante la copia', 'danger', 3000);
            });
        } else {
            // Fallback per browser più vecchi
            const textArea = document.createElement('textarea');
            textArea.value = text;
            textArea.style.position = 'fixed';
            textArea.style.left = '-999999px';
            textArea.style.top = '-999999px';
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            
            try {
                document.execCommand('copy');
                textArea.remove();
                this.showAlert('Copiato negli appunti!', 'success', 2000);
            } catch (err) {
                console.error('Errore copia fallback:', err);
                textArea.remove();
                this.showAlert('Errore durante la copia', 'danger', 3000);
            }
        }
    }
};

// Modulo gestione spedizioni BRT
window.PickupPortal.BrtShipments = {
    state: {
        limit: 10,
        offset: 0,
        search: '',
        status: 'all',
        total: 0,
        hasMore: false
    },
    init: function() {
        this.root = document.getElementById('brt-shipments-app');
        if (!this.root) {
            return;
        }

        const limitAttr = parseInt(this.root.getAttribute('data-limit') || '', 10);
        if (!Number.isNaN(limitAttr) && limitAttr > 0) {
            this.state.limit = Math.min(limitAttr, 100);
        }

        this.listContainer = this.root.querySelector('[data-role="shipments-list"]');
        this.emptyContainer = this.root.querySelector('[data-role="shipments-empty"]');
        this.errorContainer = this.root.querySelector('[data-role="shipments-error"]');
        this.paginationContainer = this.root.querySelector('[data-role="shipments-pagination"]');
        this.totalElement = this.root.querySelector('[data-role="shipments-total"]');
        this.updatedElement = this.root.querySelector('[data-role="shipments-updated"]');
        this.filtersForm = this.root.querySelector('#brtFilters');
        this.searchInput = this.root.querySelector('#brtSearch');
        this.statusSelect = this.root.querySelector('#brtStatus');
        this.resetFiltersButton = this.root.querySelector('[data-action="reset-filters"]');

        if (this.searchInput && this.searchInput.value) {
            this.state.search = this.searchInput.value.trim();
        }
        if (this.statusSelect && this.statusSelect.value) {
            this.state.status = this.statusSelect.value;
        }

        const readiness = this.root.getAttribute('data-ready');
        if (readiness === '0') {
            const moduleError = this.root.getAttribute('data-error') || 'Modulo BRT non disponibile.';
            if (this.errorContainer) {
                this.errorContainer.textContent = moduleError;
                this.errorContainer.classList.remove('d-none');
            }
            return;
        }

        this.bindEvents();
        this.loadShipments({ resetOffset: true });
    },
    bindEvents: function() {
        if (this.filtersForm) {
            this.filtersForm.addEventListener('submit', (event) => {
                event.preventDefault();
                this.state.search = this.searchInput ? this.searchInput.value.trim() : '';
                this.state.status = this.statusSelect ? this.statusSelect.value : 'all';
                this.state.offset = 0;
                this.loadShipments({ resetOffset: true });
            });
        }

        if (this.resetFiltersButton) {
            this.resetFiltersButton.addEventListener('click', (event) => {
                event.preventDefault();
                if (this.searchInput) {
                    this.searchInput.value = '';
                }
                if (this.statusSelect) {
                    this.statusSelect.value = 'all';
                }
                this.state.search = '';
                this.state.status = 'all';
                this.state.offset = 0;
                this.loadShipments({ resetOffset: true });
            });
        }

        const reloadButton = this.root.querySelector('[data-action="reload-shipments"]');
        if (reloadButton) {
            reloadButton.addEventListener('click', (event) => {
                event.preventDefault();
                this.loadShipments();
            });
        }

        this.root.addEventListener('click', (event) => {
            const target = event.target.closest('[data-action]');
            if (!target) {
                return;
            }
            const action = target.getAttribute('data-action');
            if (!action) {
                return;
            }
            if (action === 'reload-shipments' || action === 'reset-filters') {
                return;
            }
            event.preventDefault();
            const shipmentId = Number(target.getAttribute('data-id') || '0');
            switch (action) {
                case 'download-label':
                    this.downloadLabel(shipmentId);
                    break;
                case 'refresh-tracking':
                    this.mutateShipment(target, shipmentId, 'refresh_tracking');
                    break;
                case 'reprint-label':
                    this.mutateShipment(target, shipmentId, 'reprint_label');
                    break;
                case 'next-page':
                    if (this.state.hasMore) {
                        this.state.offset += this.state.limit;
                        this.loadShipments();
                    }
                    break;
                case 'prev-page':
                    this.state.offset = Math.max(0, this.state.offset - this.state.limit);
                    this.loadShipments();
                    break;
                case 'copy-tracking':
                    this.copyTracking(target.getAttribute('data-tracking') || '');
                    break;
                default:
                    break;
            }
        });
    },
    loadShipments: async function(options = {}) {
        if (!this.listContainer) {
            return;
        }
        if (options.resetOffset) {
            this.state.offset = 0;
        }

        const params = new URLSearchParams({
            limit: String(this.state.limit),
            offset: String(this.state.offset)
        });
        if (this.state.search) {
            params.append('search', this.state.search);
        }
        if (this.state.status && this.state.status !== 'all') {
            params.append('status', this.state.status);
        }

        window.PickupPortal.showLoading(this.listContainer);
        if (this.emptyContainer) {
            this.emptyContainer.classList.add('d-none');
        }

        try {
            const response = await window.PickupPortal.api.get(`brt/shipments.php?${params.toString()}`);
            const shipments = Array.isArray(response.shipments) ? response.shipments : [];
            this.state.total = Number(response.total ?? shipments.length);
            this.state.hasMore = Boolean(response.has_more);
            this.renderShipments(shipments);
            this.renderPagination();
            if (this.errorContainer) {
                this.errorContainer.classList.add('d-none');
                this.errorContainer.textContent = '';
            }
            if (this.totalElement) {
                this.totalElement.textContent = this.state.total.toLocaleString('it-IT');
            }
            if (this.updatedElement) {
                this.updatedElement.textContent = window.PickupPortal.formatDate(new Date().toISOString(), {
                    year: 'numeric',
                    month: '2-digit',
                    day: '2-digit',
                    hour: '2-digit',
                    minute: '2-digit'
                });
            }
        } catch (error) {
            this.listContainer.innerHTML = '';
            if (this.errorContainer) {
                this.errorContainer.textContent = error.message || 'Errore durante il caricamento delle spedizioni.';
                this.errorContainer.classList.remove('d-none');
            }
        }
    },
    renderShipments: function(shipments) {
        if (!this.listContainer) {
            return;
        }
        if (!shipments.length) {
            this.listContainer.innerHTML = '';
            if (this.emptyContainer) {
                this.emptyContainer.classList.remove('d-none');
            }
            return;
        }
        if (this.emptyContainer) {
            this.emptyContainer.classList.add('d-none');
        }
        const cards = shipments.map((shipment) => this.renderShipmentCard(shipment)).join('');
        this.listContainer.innerHTML = cards;
    },
    renderShipmentCard: function(shipment) {
        const reference = shipment.reference || {};
        const destination = shipment.destination || {};
        const referenceAlpha = reference.alphanumeric ? this.escapeHtml(reference.alphanumeric) : '';
        const referenceNumericValue = typeof reference.numeric !== 'undefined' ? String(reference.numeric) : '';
        const referenceNumeric = referenceNumericValue !== '' ? this.escapeHtml(referenceNumericValue) : '';
        const destinationParts = [];
        if (destination.name) {
            destinationParts.push(this.escapeHtml(destination.name));
        }
        if (destination.city) {
            destinationParts.push(this.escapeHtml(destination.city));
        }
        if (destination.zip) {
            destinationParts.push(this.escapeHtml(destination.zip));
        }
        const destinationLine = destinationParts.join(' · ');

        const trackingIdValue = shipment.tracking_id ? String(shipment.tracking_id) : '';
        const trackingId = trackingIdValue !== '' ? this.escapeHtml(trackingIdValue) : '';
        const parcelIdValue = shipment.parcel_id ? String(shipment.parcel_id) : '';
        const parcelId = parcelIdValue !== '' ? this.escapeHtml(parcelIdValue) : '';

        const parcels = Number(shipment.parcels || 0);
        const weight = this.formatNumber(shipment.weight_kg || 0, 2);
        const volume = this.formatNumber(shipment.volume_m3 || 0, 3);

        const statusLabel = this.escapeHtml(shipment.status_label || shipment.status || '');
        const statusHint = shipment.status_hint ? this.escapeHtml(shipment.status_hint) : '';
        const badgeClass = this.getBadgeClass(shipment.status_badge || '');

        const updatedAt = shipment.updated_at ? window.PickupPortal.formatRelativeTime(shipment.updated_at) : '';
        const updatedText = updatedAt !== '' ? this.escapeHtml(`Ultimo aggiornamento ${updatedAt}`) : '';

        const confirmedAt = shipment.confirmed_at ? window.PickupPortal.formatDate(shipment.confirmed_at, {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }) : '';
        const confirmedText = confirmedAt !== '' ? this.escapeHtml(`Confermato il ${confirmedAt}`) : '';

        const lastSynced = shipment.last_synced_at ? window.PickupPortal.formatRelativeTime(shipment.last_synced_at) : '';
        const lastSyncedBadge = lastSynced !== ''
            ? `<span class="badge rounded-pill bg-light text-muted">Sync ${this.escapeHtml(lastSynced)}</span>`
            : '';

        const trackingButton = trackingId !== ''
            ? `<button type="button" class="btn btn-link btn-sm p-0 text-decoration-none" data-action="copy-tracking" data-tracking="${trackingId}"><i class="fa-regular fa-copy me-1"></i>${trackingId}</button>`
            : '<span class="text-muted small">Tracking non disponibile</span>';

        const parcelBadge = parcelId !== '' ? `<span class="badge rounded-pill bg-light text-muted">Parcel ${parcelId}</span>` : '';

        let referenceLine = '';
        if (referenceAlpha !== '' || referenceNumeric !== '') {
            const numericFragment = referenceNumeric !== '' ? ` <span class="text-muted">(${referenceNumeric})</span>` : '';
            referenceLine = `<span class="small text-uppercase text-muted">Riferimento</span><div class="fw-semibold">${referenceAlpha}${numericFragment}</div>`;
        }

        const destinationFragment = destinationLine !== ''
            ? `<div class="small text-muted mt-1"><i class="fa-solid fa-location-dot me-1"></i>${destinationLine}</div>`
            : '';

        const hintLine = statusHint !== '' ? `<div class="text-muted small mt-2">${statusHint}</div>` : '';
        const confirmedLine = confirmedText !== '' ? `<div class="small text-muted">${confirmedText}</div>` : '';
        const updatedLine = updatedText !== '' ? `<div class="text-muted small mt-1">${updatedText}</div>` : '';

        const labelDisabled = shipment.label_available ? '' : ' disabled';
        const shipmentId = Number(shipment.id || 0);

        return `
            <div class="list-group-item py-3">
                <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
                    <div class="flex-grow-1">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                            <span class="${badgeClass}">${statusLabel}</span>
                            ${parcelBadge}
                            ${lastSyncedBadge}
                        </div>
                        <div class="d-sm-flex align-items-start gap-4">
                            <div class="flex-grow-1">
                                ${referenceLine}
                                ${destinationFragment}
                                <div class="small text-muted mt-1">
                                    <i class="fa-solid fa-box me-1"></i>${parcels} colli · ${weight} kg · ${volume} m³
                                </div>
                                <div class="small mt-2">${trackingButton}</div>
                            </div>
                        </div>
                        ${hintLine}
                        ${confirmedLine}
                        ${updatedLine}
                    </div>
                    <div class="d-flex flex-column gap-2 align-items-stretch align-items-xl-end">
                        <button class="btn btn-sm btn-outline-primary" data-action="download-label" data-id="${shipmentId}"${labelDisabled}>
                            <i class="fa-solid fa-file-arrow-down me-1"></i>Scarica etichetta
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" data-action="reprint-label" data-id="${shipmentId}">
                            <i class="fa-solid fa-print me-1"></i>Ristampa etichetta
                        </button>
                        <button class="btn btn-sm btn-outline-secondary" data-action="refresh-tracking" data-id="${shipmentId}">
                            <i class="fa-solid fa-arrows-rotate me-1"></i>Aggiorna tracking
                        </button>
                    </div>
                </div>
            </div>
        `;
    },
    renderPagination: function() {
        if (!this.paginationContainer) {
            return;
        }
        const currentPage = Math.floor(this.state.offset / this.state.limit) + 1;
        const totalPages = this.state.total > 0
            ? Math.ceil(this.state.total / this.state.limit)
            : currentPage + (this.state.hasMore ? 1 : 0);
        this.paginationContainer.innerHTML = `
            <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
                <div class="small text-muted">Pagina ${currentPage}${totalPages ? ` di ${totalPages}` : ''}</div>
                <div class="btn-group">
                    <button class="btn btn-outline-secondary" data-action="prev-page"${this.state.offset === 0 ? ' disabled' : ''}>
                        <i class="fa-solid fa-arrow-left me-1"></i>Precedente
                    </button>
                    <button class="btn btn-outline-secondary" data-action="next-page"${this.state.hasMore ? '' : ' disabled'}>
                        Successiva<i class="fa-solid fa-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>
        `;
    },
    mutateShipment: async function(button, shipmentId, action) {
        if (!shipmentId || !button) {
            return;
        }
        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Attendi...';
        try {
            const response = await window.PickupPortal.api.post('brt/shipment.php', {
                id: shipmentId,
                action,
                csrf_token: window.portalConfig?.csrfToken || ''
            });
            await this.loadShipments();
            if (response && response.message) {
                window.PickupPortal.showAlert(response.message, 'success', 4000);
            }
        } catch (error) {
            // Error already mostrato dal gestore API
        } finally {
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    },
    downloadLabel: function(shipmentId) {
        if (!shipmentId) {
            return;
        }
        const url = window.PickupPortal.apiUrl(`brt/label.php?id=${shipmentId}`);
        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener';
        link.download = '';
        link.style.display = 'none';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    },
    copyTracking: function(tracking) {
        if (!tracking) {
            return;
        }
        window.PickupPortal.copyToClipboard(tracking);
    },
    formatNumber: function(value, digits) {
        const number = Number(value);
        if (Number.isNaN(number)) {
            return '0';
        }
        return number.toLocaleString('it-IT', {
            minimumFractionDigits: digits,
            maximumFractionDigits: digits
        });
    },
    getBadgeClass: function(key) {
        switch (key) {
            case 'success':
                return 'badge bg-success-subtle text-success-emphasis';
            case 'warning':
                return 'badge bg-warning-subtle text-warning-emphasis';
            case 'danger':
                return 'badge bg-danger-subtle text-danger-emphasis';
            case 'info':
                return 'badge bg-info-subtle text-info-emphasis';
            default:
                return 'badge bg-secondary-subtle text-secondary-emphasis';
        }
    },
    escapeHtml: function(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).replace(/[&<>"']/g, (char) => {
            switch (char) {
                case '&':
                    return '&amp;';
                case '<':
                    return '&lt;';
                case '>':
                    return '&gt;';
                case '"':
                    return '&quot;';
                case '\'':
                    return '&#39;';
                default:
                    return char;
            }
        });
    }
};

// Inizializzazione login
function initializeLogin() {
    const loginForm = document.getElementById('loginForm');
    const otpForm = document.getElementById('otpForm');
    
    if (loginForm) {
        loginForm.addEventListener('submit', handleLogin);
    }
    
    if (otpForm) {
        otpForm.addEventListener('submit', handleOtpVerification);
        
        // Auto-submit OTP quando raggiunge 6 cifre
        const otpInput = document.getElementById('otp');
        if (otpInput) {
            otpInput.addEventListener('input', function() {
                const value = this.value.replace(/\D/g, ''); // Solo numeri
                this.value = value;
                
                if (value.length === 6) {
                    setTimeout(() => handleOtpVerification(null), 500);
                }
            });
        }
        
        // Resend OTP
        const resendBtn = document.getElementById('resendOtp');
        if (resendBtn) {
            resendBtn.addEventListener('click', handleResendOtp);
        }
    }
}

// Gestione login
async function handleLogin(event) {
    if (event) event.preventDefault();
    
    const form = event.target;
    const formData = new FormData(form);
    const email = (formData.get('email') || '').toString().trim();
    const csrfToken = formData.get('csrf_token');
    const rememberLoginCheckbox = formData.get('remember_login');
    const rememberLogin = rememberLoginCheckbox !== null;
    const rememberFlagInput = document.getElementById('remember_login_choice');
    if (rememberFlagInput) {
        rememberFlagInput.value = rememberLogin ? '1' : '0';
    }

    if (!window.portalConfig) {
        window.portalConfig = {};
    }
    window.portalConfig.rememberLogin = rememberLogin;

    const data = {
        email,
        csrf_token: csrfToken
    };
    
    // Validazione base
    if (email === '') {
        window.PickupPortal.showAlert('Email richiesta', 'danger');
        return;
    }
    
    showLoginStep('loading');
    
    try {
    const response = await window.PickupPortal.api.post('auth/login.php', data);
        
        if (response.success) {
            // Mostra form OTP
            document.getElementById('customer_id').value = response.customer_id;
            document.getElementById('otp-destination-text').textContent = 
                `Abbiamo inviato un codice di 6 cifre a ${response.destination}`;
            
            showLoginStep('otp-form');
            startOtpCountdown(response.expires_in || 300);
            
            // Focus su input OTP
            document.getElementById('otp').focus();
        } else {
            throw new Error(response.message || 'Errore durante il login');
        }
    } catch (error) {
        console.error('Login error:', error);
        window.PickupPortal.showAlert(error.message || 'Errore durante il login', 'danger');
        showLoginStep('login-form');
    }
}

// Gestione verifica OTP
async function handleOtpVerification(event) {
    if (event) event.preventDefault();
    
    const otpInput = document.getElementById('otp');
    const customerIdInput = document.getElementById('customer_id');
    const rememberFlagInput = document.getElementById('remember_login_choice');
    
    if (!otpInput.value || otpInput.value.length !== 6) {
        window.PickupPortal.showAlert('Inserisci il codice di 6 cifre', 'danger');
        otpInput.focus();
        return;
    }
    
    showLoginStep('loading');
    
    try {
        const rememberChoice = rememberFlagInput ? rememberFlagInput.value === '1' : Boolean(window.portalConfig?.rememberLogin);
        const response = await window.PickupPortal.api.post('auth/verify-otp.php', {
            customer_id: customerIdInput.value,
            otp: otpInput.value,
            remember_login: rememberChoice ? 1 : 0,
            csrf_token: window.portalConfig.csrfToken
        });
        
        if (response.success) {
            window.PickupPortal.showAlert('Accesso effettuato con successo!', 'success');
            setTimeout(() => {
                window.location.href = 'dashboard.php';
            }, 1000);
        } else {
            throw new Error(response.message || 'Codice non valido');
        }
    } catch (error) {
        console.error('OTP verification error:', error);
        window.PickupPortal.showAlert(error.message || 'Codice non valido', 'danger');
        showLoginStep('otp-form');
        otpInput.focus();
        otpInput.select();
    }
}

// Gestione reinvio OTP
async function handleResendOtp() {
    const customerIdInput = document.getElementById('customer_id');
    const resendBtn = document.getElementById('resendOtp');
    
    if (!customerIdInput.value) {
        window.PickupPortal.showAlert('Errore: riprova dall\'inizio', 'danger');
        showLoginStep('login-form');
        return;
    }
    
    resendBtn.disabled = true;
    resendBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Invio...';
    
    try {
        const response = await window.PickupPortal.api.post('auth/resend-otp.php', {
            customer_id: customerIdInput.value,
            csrf_token: window.portalConfig.csrfToken
        });
        
        if (response.success) {
            window.PickupPortal.showAlert('Nuovo codice inviato!', 'success');
            startOtpCountdown(response.expires_in || 300);
            document.getElementById('otp').value = '';
            document.getElementById('otp').focus();
        } else {
            throw new Error(response.message || 'Errore durante il reinvio');
        }
    } catch (error) {
        console.error('Resend OTP error:', error);
        window.PickupPortal.showAlert(error.message || 'Errore durante il reinvio', 'danger');
    } finally {
        resendBtn.disabled = false;
        resendBtn.innerHTML = '<i class="fas fa-redo me-2"></i>Invia di nuovo';
    }
}

// Mostra step specifico del login
function showLoginStep(stepId) {
    const steps = document.querySelectorAll('.login-step');
    steps.forEach(step => {
        step.classList.remove('active');
    });
    
    const targetStep = document.getElementById(stepId);
    if (targetStep) {
        targetStep.classList.add('active');
    }
}

// Countdown per OTP
function startOtpCountdown(seconds) {
    const countdownElement = document.getElementById('countdown-text');
    if (!countdownElement) return;
    
    let remaining = seconds;
    
    const updateCountdown = () => {
        const minutes = Math.floor(remaining / 60);
        const secs = remaining % 60;
        countdownElement.textContent = `Codice valido per ${minutes}:${secs.toString().padStart(2, '0')}`;
        
        if (remaining <= 0) {
            countdownElement.textContent = 'Codice scaduto';
            countdownElement.classList.add('text-danger');
        } else {
            remaining--;
            setTimeout(updateCountdown, 1000);
        }
    };
    
    updateCountdown();
}

window.PickupPortal.BrtShipmentForm = {
    init: function() {
        this.form = document.getElementById('brtShipmentForm');
        if (!this.form) {
            return;
        }
        this.submitButton = this.form.querySelector('[type="submit"]');
        this.redirectUrl = this.form.getAttribute('data-redirect') || 'brt-shipments.php';
        this.lengthInput = this.form.querySelector('#brtLength');
        this.depthInput = this.form.querySelector('#brtDepth');
        this.heightInput = this.form.querySelector('#brtHeight');
        this.parcelsInput = this.form.querySelector('#brtParcels');
        this.volumeInput = this.form.querySelector('#brtVolume');
        this.weightInput = this.form.querySelector('#brtWeight');
        this.volumetricInput = this.form.querySelector('#brtVolumetricWeight');
        this.codAmountInput = this.form.querySelector('#brtCodAmount');
        this.codMandatoryInput = this.form.querySelector('#brtCodMandatory');
        this.codSection = this.form.querySelector('[data-cod-section]');
        this.serviceTypeInput = this.form.querySelector('#brtServiceType');
        this.deliveryTypeInput = this.form.querySelector('#brtDeliveryType');
        this.networkInput = this.form.querySelector('#brtNetwork');
        this.pricingConditionInput = this.form.querySelector('#brtPricingCondition');
        this.routingStatus = this.form.querySelector('[data-routing-status]');
        this.routingButton = this.form.querySelector('[data-action="brt-routing-suggest"]');
        this.routingButtonOriginalHtml = this.routingButton ? this.routingButton.innerHTML : '';
        this.pricingConfig = window.portalConfig?.brtPricing && Array.isArray(window.portalConfig.brtPricing?.tiers)
            ? window.portalConfig.brtPricing
            : null;
        this.pricingRows = Array.from(document.querySelectorAll('#portalBrtPricingTiers .portal-brt-pricing-tier'));
        this.pricingEstimate = document.getElementById('portalBrtPricingEstimate');
        this.pricingEstimateHint = document.getElementById('portalBrtPricingEstimateHint');
        this.lastMeasurements = null;

        this.bindEvents();
        this.applyDefaults();
        this.initCapLookup();
        this.initPudoPicker();
        this.updateVolume();
        this.updateCodState();
        this.setRoutingStatus('muted', 'Premi "Suggerisci" per ottenere rete, servizio e tariffa consigliati da BRT.');
    },
    initCapLookup: function() {
        if (!window.PickupPortal?.capLookup) {
            return;
        }
        const datalist = document.getElementById('brtCityOptions');
        if (!datalist) {
            return;
        }
        this.capLookupHandle = window.PickupPortal.capLookup.attach({
            zip: '#brtZip',
            city: '#brtCity',
            province: '#brtProvince',
            country: '#brtCountry',
            datalist,
        });
    },
    applyDefaults: function() {
        const uppercase = (value) => (typeof value === 'string' ? value.toUpperCase() : '');

        const defaultNetwork = uppercase(this.form.getAttribute('data-default-network'));
        if (defaultNetwork) {
            if (this.networkInput && !this.networkInput.value) {
                this.networkInput.value = defaultNetwork;
            }
        }

        const defaultService = uppercase(this.form.getAttribute('data-default-service'));
        if (defaultService) {
            if (this.serviceTypeInput && !this.serviceTypeInput.value) {
                this.serviceTypeInput.value = defaultService;
            }
        }

        const defaultPudo = this.form.getAttribute('data-default-pudo');
        if (defaultPudo) {
            const pudoField = this.form.querySelector('#brtPudoId');
            if (pudoField && !pudoField.value) {
                pudoField.value = defaultPudo;
            }
        }

        const defaultCountry = this.form.getAttribute('data-default-country');
        if (defaultCountry) {
            const countrySelect = this.form.querySelector('#brtCountry');
            if (countrySelect && !countrySelect.value) {
                countrySelect.value = defaultCountry;
            }
        }

        const labelRequiredDefault = this.form.getAttribute('data-default-label-required');
        const labelCheckbox = this.form.querySelector('#brtLabelRequired');
        if (labelCheckbox && labelRequiredDefault !== null) {
            labelCheckbox.checked = labelRequiredDefault === '1';
        }

        const defaultPricing = this.form.getAttribute('data-default-pricing-code') || '';
        if (this.pricingConditionInput && !this.pricingConditionInput.value && defaultPricing) {
            this.pricingConditionInput.value = defaultPricing;
        }
    },
    bindEvents: function() {
        this.form.addEventListener('submit', (event) => this.handleSubmit(event));

        [this.lengthInput, this.depthInput, this.heightInput, this.parcelsInput].forEach((input) => {
            if (!input) {
                return;
            }
            input.addEventListener('input', () => this.updateVolume());
            input.addEventListener('change', () => this.updateVolume());
        });

        if (this.weightInput) {
            this.weightInput.addEventListener('input', () => this.updateVolume());
            this.weightInput.addEventListener('change', () => this.updateVolume());
        }

        if (this.codAmountInput) {
            this.codAmountInput.addEventListener('input', () => this.updateCodState());
        }

        if (this.codMandatoryInput) {
            this.codMandatoryInput.addEventListener('change', () => this.updateCodState());
        }

        if (this.routingButton) {
            this.routingButton.addEventListener('click', (event) => {
                event.preventDefault();
                this.fetchRoutingSuggestion();
            });
        }
    },
    initPudoPicker: function() {
        this.pudo = {
            idInput: this.form.querySelector('#brtPudoId'),
            descriptionInput: this.form.querySelector('#brtPudoDescription'),
            summaryAlert: this.form.querySelector('[data-pudo-selection-alert]'),
            summaryLabel: this.form.querySelector('[data-pudo-selection-label]'),
            summaryEmpty: this.form.querySelector('[data-pudo-selection-empty]'),
            openButton: this.form.querySelector('[data-action="brt-pudo-open"]'),
            clearButton: this.form.querySelector('[data-action="brt-pudo-clear"]'),
            modalElement: document.getElementById('brtPudoModal'),
            modalInstance: null,
            modalRoot: null,
            resultsContainer: null,
            resultsPlaceholder: null,
            statusElement: null,
            countBadge: null,
            mapContainer: null,
            map: null,
            markerGroup: null,
            markerMap: typeof Map === 'function' ? new Map() : null,
            results: [],
            hasSearched: false,
            lastCriteria: null,
            searchForm: null,
            searchButton: null,
            searchButtonOriginalHtml: '',
            syncButton: null,
            zipInput: null,
            cityInput: null,
            provinceInput: null,
            countryInput: null,
        };

        if (!this.pudo.idInput) {
            return;
        }

        this.updatePudoSummary();

        ['#brtZip', '#brtCity', '#brtProvince', '#brtCountry'].forEach((selector) => {
            const field = this.form.querySelector(selector);
            if (!field) {
                return;
            }
            field.addEventListener('change', () => {
                if (this.pudo) {
                    this.pudo.lastCriteria = null;
                }
            });
            field.addEventListener('input', () => {
                if (this.pudo) {
                    this.pudo.lastCriteria = null;
                }
            });
        });

        if (this.pudo.openButton) {
            this.pudo.openButton.addEventListener('click', (event) => {
                event.preventDefault();
                this.openPudoModal();
            });
        }

        if (this.pudo.clearButton) {
            this.pudo.clearButton.addEventListener('click', (event) => {
                event.preventDefault();
                this.clearPudoSelection();
            });
        }

        if (!this.pudo.modalElement) {
            return;
        }

        const modal = this.pudo.modalElement;
        this.pudo.modalRoot = modal.querySelector('[data-pudo-modal-root]');
        this.pudo.resultsContainer = modal.querySelector('[data-pudo-results]');
        this.pudo.resultsPlaceholder = modal.querySelector('[data-pudo-placeholder]');
        this.pudo.statusElement = modal.querySelector('[data-pudo-status]');
        this.pudo.countBadge = modal.querySelector('[data-pudo-count]');
        this.pudo.mapContainer = modal.querySelector('#brtPudoMap');
        this.pudo.searchForm = modal.querySelector('#brtPudoSearchForm');
        this.pudo.searchButton = modal.querySelector('#brtPudoSearchButton');
        this.pudo.searchButtonOriginalHtml = this.pudo.searchButton ? this.pudo.searchButton.innerHTML : '';
        this.pudo.syncButton = modal.querySelector('[data-action="brt-pudo-sync"]');
        this.pudo.zipInput = modal.querySelector('#brtPudoSearchZip');
        this.pudo.cityInput = modal.querySelector('#brtPudoSearchCity');
        this.pudo.provinceInput = modal.querySelector('#brtPudoSearchProvince');
        this.pudo.countryInput = modal.querySelector('#brtPudoSearchCountry');

        if (this.pudo.searchForm) {
            this.pudo.searchForm.addEventListener('submit', (event) => {
                event.preventDefault();
                this.performPudoSearch();
            });
        }

        if (this.pudo.syncButton) {
            this.pudo.syncButton.addEventListener('click', (event) => {
                event.preventDefault();
                this.populatePudoSearchFromConsignee({ overwrite: true, autoSearch: true });
            });
        }

        modal.addEventListener('shown.bs.modal', () => {
            this.ensurePudoMap();
            if (this.pudo.map) {
                setTimeout(() => this.pudo.map.invalidateSize(), 120);
            }
        });
    },
    populatePudoSearchFromConsignee: function(options = {}) {
        if (!this.pudo) {
            return;
        }

        const settings = {
            overwrite: true,
            autoSearch: false,
            ...options,
        };

        const zipField = this.form.querySelector('#brtZip');
        const cityField = this.form.querySelector('#brtCity');
        const provinceField = this.form.querySelector('#brtProvince');
        const countryField = this.form.querySelector('#brtCountry');

        if (this.pudo.zipInput && zipField && (settings.overwrite || this.pudo.zipInput.value.trim() === '')) {
            this.pudo.zipInput.value = zipField.value || '';
        }

        if (this.pudo.cityInput && cityField && (settings.overwrite || this.pudo.cityInput.value.trim() === '')) {
            this.pudo.cityInput.value = cityField.value || '';
        }

        if (this.pudo.provinceInput && provinceField && (settings.overwrite || this.pudo.provinceInput.value.trim() === '')) {
            this.pudo.provinceInput.value = provinceField.value || '';
        }

        if (this.pudo.countryInput && countryField && (settings.overwrite || this.pudo.countryInput.value.trim() === '')) {
            this.pudo.countryInput.value = countryField.value || '';
        }

        if (settings.autoSearch) {
            this.performPudoSearch({ auto: true });
        }
    },
    getPudoModalInstance: function() {
        if (!this.pudo || !this.pudo.modalElement) {
            return null;
        }
        if (!this.pudo.modalInstance) {
            this.pudo.modalInstance = new bootstrap.Modal(this.pudo.modalElement, {
                backdrop: 'static',
                keyboard: true
            });
        }
        return this.pudo.modalInstance;
    },
    openPudoModal: function() {
        if (!this.pudo || !this.pudo.modalElement) {
            return;
        }

        this.populatePudoSearchFromConsignee({ overwrite: true });
        const instance = this.getPudoModalInstance();
        if (!instance) {
            return;
        }

        const ensureMapReady = () => {
            if (!this.pudo) {
                return;
            }
            const mapInstance = this.ensurePudoMap();
            if (mapInstance && typeof mapInstance.invalidateSize === 'function') {
                setTimeout(() => {
                    mapInstance.invalidateSize();
                    this.updatePudoMarkers(this.pudo.results);
                }, 120);
            } else if (this.pudo.map && typeof this.pudo.map.invalidateSize === 'function') {
                setTimeout(() => {
                    this.pudo.map.invalidateSize();
                    this.updatePudoMarkers(this.pudo.results);
                }, 120);
            }
        };

        const handleShown = () => {
            this.performPudoSearch({ auto: true });

            if (window.L && typeof window.L.map === 'function') {
                ensureMapReady();
            } else {
                window.PickupPortal.ensureLeaflet(() => {
                    ensureMapReady();
                });
            }
        };

        this.pudo.modalElement.addEventListener('shown.bs.modal', handleShown, { once: true });

        instance.show();

        window.PickupPortal.ensureLeaflet(() => {
            if (this.pudo && this.pudo.modalElement && this.pudo.modalElement.classList.contains('show')) {
                ensureMapReady();
            }
        });
    },
    ensurePudoMap: function() {
        if (!this.pudo || this.pudo.map || !this.pudo.mapContainer) {
            return this.pudo ? this.pudo.map : null;
        }

        if (typeof L === 'undefined') {
            return null;
        }

        this.pudo.map = L.map(this.pudo.mapContainer, {
            center: [41.8719, 12.5674],
            zoom: 6,
            scrollWheelZoom: false,
            zoomControl: true
        });

        const tileTemplate = (typeof window.PickupPortal.getLeafletTileUrlTemplate === 'function')
            ? window.PickupPortal.getLeafletTileUrlTemplate()
            : 'api/leaflet-tiles.php?z={z}&x={x}&y={y}';

        L.tileLayer(tileTemplate, {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19
        }).addTo(this.pudo.map);

        this.pudo.markerGroup = L.layerGroup().addTo(this.pudo.map);
        this.pudo.markerMap = typeof Map === 'function' ? new Map() : null;
        return this.pudo.map;
    },
    performPudoSearch: async function(options = {}) {
        if (!this.pudo) {
            return;
        }

        const settings = {
            auto: false,
            ...options,
        };

        const zip = this.pudo.zipInput ? this.pudo.zipInput.value.trim() : '';
        const city = this.pudo.cityInput ? this.pudo.cityInput.value.trim() : '';
        const province = this.pudo.provinceInput ? this.pudo.provinceInput.value.trim() : '';
        let country = this.pudo.countryInput ? this.pudo.countryInput.value.trim() : '';

        if (country === '') {
            country = this.form.getAttribute('data-default-country') || '';
        }

        if (zip === '' || city === '') {
            this.setPudoStatus('Inserisci CAP e città per cercare un punto di ritiro.', 'warning');
            if (this.pudo.hasSearched) {
                this.renderPudoResults([]);
            }
            return;
        }

        const fingerprint = [zip, city, province, country].map((value) => value.toUpperCase()).join('|');
        if (settings.auto && this.pudo.hasSearched && this.pudo.lastCriteria === fingerprint) {
            return;
        }

        this.pudo.lastCriteria = fingerprint;
        this.pudo.hasSearched = true;

        const params = new URLSearchParams();
        params.append('zip', zip);
        params.append('city', city);
        if (province !== '') {
            params.append('province', province);
        }
        if (country !== '') {
            params.append('country', country);
        }
        params.append('limit', '15');

        this.setPudoStatus('Ricerca in corso…');
        if (this.pudo.searchButton) {
            this.pudo.searchButton.disabled = true;
            this.pudo.searchButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Ricerca in corso…';
        }

        try {
            const payload = await window.PickupPortal.api.get(`brt/pudos.php?${params.toString()}`);
            const pudos = Array.isArray(payload?.pudos) ? payload.pudos : [];
            this.renderPudoResults(pudos);
            const count = Number.isInteger(payload?.count) ? payload.count : pudos.length;
            const message = typeof payload?.message === 'string' && payload.message.trim() !== ''
                ? payload.message
                : (count > 0 ? `Trovati ${count} punti di ritiro.` : 'Nessun punto di ritiro trovato.');
            this.setPudoStatus(message, count > 0 ? 'muted' : 'warning');
        } catch (error) {
            const message = (error && error.body && (error.body.message || error.body.error))
                || (error && error.message)
                || 'Ricerca PUDO non riuscita.';
            this.renderPudoResults([]);
            this.setPudoStatus(message, 'danger');
        } finally {
            if (this.pudo.searchButton) {
                this.pudo.searchButton.disabled = false;
                this.pudo.searchButton.innerHTML = this.pudo.searchButtonOriginalHtml || 'Cerca PUDO';
            }
        }
    },
    setPudoStatus: function(message, variant = 'muted') {
        if (!this.pudo || !this.pudo.statusElement) {
            return;
        }

        const element = this.pudo.statusElement;
        element.textContent = message || '';
        element.classList.remove('text-muted', 'text-danger', 'text-warning', 'text-success');

        if (!message) {
            element.classList.add('text-muted');
            return;
        }

        if (variant === 'danger') {
            element.classList.add('text-danger');
        } else if (variant === 'warning') {
            element.classList.add('text-warning');
        } else if (variant === 'success') {
            element.classList.add('text-success');
        } else {
            element.classList.add('text-muted');
        }
    },
    renderPudoResults: function(pudos) {
        if (!this.pudo) {
            return;
        }

        this.pudo.results = Array.isArray(pudos) ? pudos : [];

        if (this.pudo.countBadge) {
            if (this.pudo.results.length > 0) {
                this.pudo.countBadge.textContent = String(this.pudo.results.length);
                this.pudo.countBadge.classList.remove('d-none');
            } else {
                this.pudo.countBadge.classList.add('d-none');
            }
        }

        if (this.pudo.resultsContainer) {
            this.pudo.resultsContainer.innerHTML = '';
        }

        if (this.pudo.results.length === 0) {
            if (this.pudo.resultsPlaceholder) {
                const placeholderText = this.pudo.hasSearched
                    ? 'Nessun punto di ritiro trovato.'
                    : 'Nessuna ricerca eseguita.';
                this.pudo.resultsPlaceholder.textContent = placeholderText;
                this.pudo.resultsPlaceholder.classList.remove('d-none');
                if (this.pudo.resultsContainer) {
                    this.pudo.resultsContainer.appendChild(this.pudo.resultsPlaceholder);
                }
            }
            this.clearPudoMarkers();
            this.highlightPudoInList('');
            return;
        }

        if (this.pudo.resultsPlaceholder) {
            this.pudo.resultsPlaceholder.classList.add('d-none');
        }

        const selectedId = this.pudo.idInput ? this.pudo.idInput.value.trim() : '';
        const fragment = document.createDocumentFragment();

        this.pudo.results.forEach((pudo) => {
            const displayZip = pudo.zip || pudo.search_context?.zip || '';
            const displayCity = pudo.city || pudo.search_context?.city || '';
            const displayProvince = pudo.province || pudo.search_context?.province || '';
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'list-group-item list-group-item-action text-start';
            item.dataset.pudoId = pudo.id || '';
            if (selectedId && pudo.id === selectedId) {
                item.classList.add('active');
            }

            const title = document.createElement('div');
            title.className = 'fw-semibold';
            title.textContent = pudo.name && pudo.name !== '' ? pudo.name : `PUDO ${pudo.id || ''}`;

            const addressLine = document.createElement('div');
            addressLine.className = 'small text-muted';
            const addressParts = [];
            if (pudo.address) {
                addressParts.push(pudo.address);
            }
            const cityLine = [displayZip, displayCity]
                .filter((value) => value && value.trim() !== '')
                .join(' ');
            if (cityLine) {
                addressParts.push(cityLine);
            }
            if (displayProvince) {
                addressParts.push(displayProvince);
            }
            addressLine.textContent = addressParts.join(' · ');

            item.appendChild(title);
            item.appendChild(addressLine);

            const distanceLabel = this.formatDistanceKm(pudo.distance_km);
            if (distanceLabel) {
                const distanceRow = document.createElement('div');
                distanceRow.className = 'small text-muted';
                distanceRow.textContent = `Distanza indicativa: ${distanceLabel}`;
                item.appendChild(distanceRow);
            }

            item.addEventListener('click', () => {
                this.onPudoSelected(pudo, { focusMarker: true, closeModal: true });
            });

            fragment.appendChild(item);
        });

        if (this.pudo.resultsContainer) {
            this.pudo.resultsContainer.appendChild(fragment);
        }

        this.updatePudoMarkers(this.pudo.results);
        this.highlightPudoInList(selectedId);
    },
    updatePudoMarkers: function(pudos) {
        if (!this.pudo) {
            return;
        }

        const map = this.ensurePudoMap();
        if (!map || typeof L === 'undefined') {
            return;
        }

        if (!this.pudo.markerGroup) {
            this.pudo.markerGroup = L.layerGroup().addTo(map);
        } else if (typeof this.pudo.markerGroup.clearLayers === 'function') {
            this.pudo.markerGroup.clearLayers();
        }

        this.pudo.markerMap = typeof Map === 'function' ? new Map() : null;

        const bounds = [];

        pudos.forEach((pudo) => {
            const lat = typeof pudo.latitude === 'number' ? pudo.latitude : Number.parseFloat(pudo.latitude);
            const lng = typeof pudo.longitude === 'number' ? pudo.longitude : Number.parseFloat(pudo.longitude);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return;
            }

            const marker = L.marker([lat, lng]);
            const label = this.buildPudoLabel(pudo);
            const popupParts = [`<strong>${this.escapeHtml(label)}</strong>`];

            const addressSegments = [];
            if (pudo.address) {
                addressSegments.push(this.escapeHtml(pudo.address));
            }
            const popupZip = pudo.zip || pudo.search_context?.zip || '';
            const popupCity = pudo.city || pudo.search_context?.city || '';
            const popupProvince = pudo.province || pudo.search_context?.province || '';
            const cityLine = [popupZip, popupCity]
                .filter((value) => value && value.trim() !== '')
                .map((value) => this.escapeHtml(value))
                .join(' ');
            if (cityLine) {
                addressSegments.push(cityLine);
            }
            if (popupProvince) {
                addressSegments.push(this.escapeHtml(popupProvince));
            }
            if (addressSegments.length > 0) {
                popupParts.push(addressSegments.join('<br>'));
            }

            const distanceLabel = this.formatDistanceKm(pudo.distance_km);
            if (distanceLabel) {
                popupParts.push(`Distanza: ${this.escapeHtml(distanceLabel)}`);
            }

            if (Array.isArray(pudo.opening_hours) && pudo.opening_hours.length > 0) {
                const hours = pudo.opening_hours
                    .map((slot) => this.escapeHtml(slot))
                    .join(', ');
                if (hours) {
                    popupParts.push(`Orari: ${hours}`);
                }
            }

            marker.bindPopup(popupParts.join('<br>'));
            marker.on('click', () => {
                this.onPudoSelected(pudo, { closeModal: true, silent: true });
            });
            marker.addTo(this.pudo.markerGroup);

            if (this.pudo.markerMap && typeof this.pudo.markerMap.set === 'function') {
                this.pudo.markerMap.set(pudo.id, marker);
            }

            bounds.push([lat, lng]);
        });

        if (bounds.length > 0) {
            map.fitBounds(bounds, { padding: [24, 24], maxZoom: 15 });
        } else {
            map.setView([41.8719, 12.5674], 6);
        }
    },
    clearPudoMarkers: function() {
        if (!this.pudo) {
            return;
        }
        if (this.pudo.markerGroup && typeof this.pudo.markerGroup.clearLayers === 'function') {
            this.pudo.markerGroup.clearLayers();
        }
        if (this.pudo.markerMap && typeof this.pudo.markerMap.clear === 'function') {
            this.pudo.markerMap.clear();
        }
    },
    highlightPudoInList: function(pudoId) {
        if (!this.pudo || !this.pudo.resultsContainer) {
            return;
        }
        const items = this.pudo.resultsContainer.querySelectorAll('.list-group-item');
        items.forEach((item) => {
            if (!(item instanceof HTMLElement)) {
                return;
            }
            const isActive = Boolean(pudoId) && item.dataset.pudoId === pudoId;
            item.classList.toggle('active', isActive);
        });
    },
    onPudoSelected: function(pudo, options = {}) {
        if (!this.pudo || !pudo) {
            return;
        }

        const settings = {
            focusMarker: false,
            closeModal: true,
            silent: false,
            ...options,
        };

        const label = this.buildPudoLabel(pudo);

        if (this.pudo.idInput) {
            this.pudo.idInput.value = pudo.id || '';
        }
        if (this.pudo.descriptionInput) {
            this.pudo.descriptionInput.value = label;
        }

        this.updatePudoSummary();
        this.highlightPudoInList(pudo.id || '');

        if (settings.focusMarker && this.pudo.markerMap && typeof this.pudo.markerMap.get === 'function' && this.pudo.markerMap.has(pudo.id)) {
            const marker = this.pudo.markerMap.get(pudo.id);
            if (marker && typeof marker.openPopup === 'function') {
                marker.openPopup();
            }
        }

        if (settings.closeModal) {
            const instance = this.getPudoModalInstance();
            if (instance) {
                instance.hide();
            }
        }

        if (!settings.silent) {
            window.PickupPortal.showAlert(`Punto di ritiro selezionato: ${label}`, 'success', 3200);
        }
    },
    updatePudoSummary: function() {
        if (!this.pudo) {
            return;
        }
        const idValue = this.pudo.idInput ? this.pudo.idInput.value.trim() : '';
        const descriptionValue = this.pudo.descriptionInput ? this.pudo.descriptionInput.value.trim() : '';
        const label = descriptionValue !== '' ? descriptionValue : (idValue !== '' ? `PUDO ${idValue}` : '');

        if (this.pudo.summaryLabel) {
            this.pudo.summaryLabel.textContent = label;
        }

        if (label !== '') {
            if (this.pudo.summaryAlert) {
                this.pudo.summaryAlert.classList.remove('d-none');
            }
            if (this.pudo.summaryEmpty) {
                this.pudo.summaryEmpty.classList.add('d-none');
            }
            if (this.pudo.clearButton) {
                this.pudo.clearButton.disabled = false;
            }
        } else {
            if (this.pudo.summaryAlert) {
                this.pudo.summaryAlert.classList.add('d-none');
            }
            if (this.pudo.summaryEmpty) {
                this.pudo.summaryEmpty.classList.remove('d-none');
            }
            if (this.pudo.clearButton) {
                this.pudo.clearButton.disabled = true;
            }
        }
    },
    clearPudoSelection: function() {
        if (!this.pudo) {
            return;
        }
        if (this.pudo.idInput) {
            this.pudo.idInput.value = '';
        }
        if (this.pudo.descriptionInput) {
            this.pudo.descriptionInput.value = '';
        }
        this.updatePudoSummary();
        this.highlightPudoInList('');
        window.PickupPortal.showAlert('Consegna a domicilio ripristinata.', 'info', 2800);
    },
    buildPudoLabel: function(pudo) {
        if (pudo && typeof pudo.label === 'string' && pudo.label.trim() !== '') {
            return pudo.label.trim();
        }
        if (!pudo) {
            return '';
        }
        const parts = [];
        if (pudo.name) {
            parts.push(pudo.name);
        }
        if (pudo.address) {
            parts.push(pudo.address);
        }
            const displayZip = pudo.zip || pudo.search_context?.zip || '';
            const displayCity = pudo.city || pudo.search_context?.city || '';
            const displayProvince = pudo.province || pudo.search_context?.province || '';
            const cityLine = [displayZip, displayCity]
            .filter((value) => value && value.trim() !== '')
            .join(' ');
        if (cityLine) {
            parts.push(cityLine);
        }
            if (displayProvince) {
                parts.push(displayProvince);
        }
        if (parts.length === 0 && pudo.id) {
            parts.push(`PUDO ${pudo.id}`);
        }
        return parts.join(' · ');
    },
    escapeHtml: function(value) {
        if (value === null || value === undefined) {
            return '';
        }
        const div = document.createElement('div');
        div.textContent = String(value);
        return div.innerHTML;
    },
    formatDistanceKm: function(distance) {
        const numeric = typeof distance === 'number' ? distance : Number(distance);
        if (!Number.isFinite(numeric) || numeric <= 0) {
            return '';
        }
        if (numeric >= 1) {
            return `${numeric.toFixed(1)} km`;
        }
        return `${Math.round(numeric * 1000)} m`;
    },
    parseNumber: function(value, fallback = 0) {
        if (value === null || value === undefined) {
            return fallback;
        }
        const normalized = value.toString().replace(/\s+/g, '').replace(',', '.');
        const parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : fallback;
    },
    formatNumber: function(value, digits) {
        if (!Number.isFinite(value) || value <= 0) {
            return '';
        }
        return value.toFixed(digits).replace('.', ',');
    },
    updateVolume: function() {
        const length = this.parseNumber(this.lengthInput ? this.lengthInput.value : '', 0);
        const depth = this.parseNumber(this.depthInput ? this.depthInput.value : '', 0);
        const height = this.parseNumber(this.heightInput ? this.heightInput.value : '', 0);
        const parcels = this.parcelsInput
            ? Math.max(0, parseInt(this.parcelsInput.value, 10) || 0)
            : 0;
        const weight = this.parseNumber(this.weightInput ? this.weightInput.value : '', 0);

        let volume = 0;
        let volumetricWeight = 0;

        if (length > 0 && depth > 0 && height > 0 && parcels > 0) {
            const parcelVolumeM3 = (height * length * depth) / 1000000; // cm³ -> m³
            volume = parcelVolumeM3 * parcels;
            volumetricWeight = ((height * length * depth) / 4000) * parcels;
        }

        if (this.volumeInput) {
            this.volumeInput.value = volume > 0 ? this.formatNumber(volume, 3) : '';
        }

        if (this.volumetricInput) {
            this.volumetricInput.value = volumetricWeight > 0 ? this.formatNumber(volumetricWeight, 2) : '';
        }

        const measurements = {
            length,
            depth,
            height,
            parcels,
            volume,
            volumetricWeight,
            weight
        };

        this.lastMeasurements = measurements;
        this.updatePricingEstimate(measurements);
        return measurements;
    },
    updateCodState: function() {
        if (!this.codSection) {
            return;
        }
        const hasAmount = Boolean(this.codAmountInput && this.codAmountInput.value.trim() !== '');
        const isMandatory = Boolean(this.codMandatoryInput && this.codMandatoryInput.checked);
        this.codSection.classList.toggle('opacity-50', !hasAmount && !isMandatory);
    },
    updatePricingEstimate: function(measurements) {
        if (!this.pricingConfig || !this.pricingEstimate) {
            return;
        }

        const tiers = Array.isArray(this.pricingConfig.tiers) ? this.pricingConfig.tiers : [];
        if (!tiers.length) {
            this.pricingEstimate.textContent = '—';
            if (this.pricingEstimateHint) {
                this.pricingEstimateHint.textContent = 'Listino non disponibile.';
                this.pricingEstimateHint.classList.add('text-danger');
            }
            return;
        }

        const data = measurements || this.lastMeasurements || {};
        const weight = Number.isFinite(data.weight) ? data.weight : this.parseNumber(this.weightInput ? this.weightInput.value : '', 0);
        const volume = Number.isFinite(data.volume) ? data.volume : this.parseNumber(this.volumeInput ? this.volumeInput.value : '', 0);

        if (!weight || !volume) {
            this.pricingEstimate.textContent = '—';
            if (this.pricingEstimateHint) {
                this.pricingEstimateHint.textContent = 'Indica peso e dimensioni per stimare il prezzo.';
                this.pricingEstimateHint.classList.remove('text-danger');
            }
            this.pricingRows.forEach((row) => {
                row.classList.remove('border-primary', 'bg-primary-subtle');
            });
            return;
        }

        const epsilon = 0.0001;
        let matchedTier = null;
        let matchedIndex = -1;

        tiers.some((tier, index) => {
            const maxWeight = tier.max_weight;
            const maxVolume = tier.max_volume;
            const weightOk = maxWeight === null || weight <= (maxWeight + epsilon);
            const volumeOk = maxVolume === null || volume <= (maxVolume + epsilon);
            if (weightOk && volumeOk) {
                matchedTier = tier;
                matchedIndex = index;
                return true;
            }
            return false;
        });

        this.pricingRows.forEach((row) => {
            row.classList.remove('border-primary', 'bg-primary-subtle');
        });

        if (matchedTier) {
            const priceLabel = matchedTier.display?.price || this.formatMoney(matchedTier.price, this.pricingConfig.currency_symbol || this.pricingConfig.currency || '');
            this.pricingEstimate.textContent = priceLabel;
            if (this.pricingEstimateHint) {
                const criteria = matchedTier.display?.criteria || '';
                const name = matchedTier.display?.label || matchedTier.label || '';
                const parts = [];
                if (name) {
                    parts.push('Scaglione: ' + name);
                }
                if (criteria) {
                    parts.push(criteria);
                }
                this.pricingEstimateHint.textContent = parts.join(' · ');
                this.pricingEstimateHint.classList.remove('text-danger');
            }

            const activeRow = this.pricingRows.find((row) => Number(row.getAttribute('data-tier-index')) === matchedIndex);
            if (activeRow) {
                activeRow.classList.add('border-primary', 'bg-primary-subtle');
            }
        } else {
            this.pricingEstimate.textContent = '—';
            if (this.pricingEstimateHint) {
                this.pricingEstimateHint.textContent = 'I valori inseriti non rientrano negli scaglioni configurati.';
                this.pricingEstimateHint.classList.add('text-danger');
            }
        }
    },
    formatMoney: function(value, symbol) {
        if (!Number.isFinite(value)) {
            return symbol ? symbol + ' —' : '—';
        }
        const formatted = value.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        return symbol ? `${symbol} ${formatted}` : formatted;
    },
    buildPayload: function(formData, measurements) {
        const getValue = (name) => {
            const value = formData.get(name);
            return value === null ? '' : value.toString().trim();
        };

        const toFixedOrEmpty = (value, digits) => {
            if (!Number.isFinite(value) || value <= 0) {
                return '';
            }
            return value.toFixed(digits);
        };

        const parcels = measurements && measurements.parcels > 0
            ? measurements.parcels
            : Math.max(1, parseInt(getValue('parcels'), 10) || 0);

        const volume = measurements ? toFixedOrEmpty(measurements.volume, 3) : getValue('volume');
        const volumetricWeight = measurements ? toFixedOrEmpty(measurements.volumetricWeight, 2) : '';

        return {
            csrf_token: getValue('csrf_token') || window.portalConfig?.csrfToken || '',
            recipient_name: getValue('recipient_name'),
            contact_name: getValue('contact_name'),
            address: getValue('address'),
            zip: getValue('zip'),
            city: getValue('city'),
            province: getValue('province'),
            country: getValue('country'),
            email: getValue('email'),
            phone: getValue('phone'),
            mobile: getValue('mobile'),
            notes: getValue('notes'),
            parcels,
            weight: this.parseNumber(getValue('weight'), 0),
            length_cm: getValue('length_cm'),
            depth_cm: getValue('depth_cm'),
            height_cm: getValue('height_cm'),
            volume,
            delivery_type: getValue('delivery_type'),
            network: getValue('network'),
            pudo_id: getValue('pudo_id'),
            pudo_description: getValue('pudo_description'),
            service_type: getValue('service_type'),
            pricing_condition_code: getValue('pricing_condition_code'),
            insurance_amount: getValue('insurance_amount'),
            insurance_currency: getValue('insurance_currency') || 'EUR',
            cod_amount: getValue('cod_amount'),
            cod_currency: getValue('cod_currency') || 'EUR',
            cod_payment_type: getValue('cod_payment_type'),
            cod_mandatory: formData.get('cod_mandatory') ? '1' : '0',
            label_required: formData.get('label_required') ? '1' : '0',
            alphanumeric_reference: getValue('alphanumeric_reference'),
            volumetric_weight: volumetricWeight
        };
    },
    setRoutingStatus: function(kind, message) {
        if (!this.routingStatus) {
            return;
        }

        if (!message) {
            this.routingStatus.classList.add('d-none');
            this.routingStatus.textContent = '';
            this.routingStatus.classList.remove('text-success', 'text-danger', 'text-primary', 'text-muted');
            return;
        }

        const kindToClass = {
            success: 'text-success',
            error: 'text-danger',
            info: 'text-primary',
            muted: 'text-muted'
        };

        this.routingStatus.classList.remove('d-none', 'text-success', 'text-danger', 'text-primary', 'text-muted');
        const cssClass = kindToClass[kind] || 'text-muted';
        this.routingStatus.classList.add(cssClass);
        this.routingStatus.textContent = message;
    },
    setRoutingLoading: function(isLoading) {
        if (!this.routingButton) {
            return;
        }

        if (isLoading) {
            this.routingButton.disabled = true;
            this.routingButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Calcolo...';
        } else {
            this.routingButton.disabled = false;
            this.routingButton.innerHTML = this.routingButtonOriginalHtml;
        }
    },
    buildRoutingPayload: function(formData, measurements) {
        const payload = this.buildPayload(formData, measurements);
        payload.routing_request = '1';
        return payload;
    },
    fetchRoutingSuggestion: async function() {
        if (!this.form) {
            return;
        }

        const measurements = this.updateVolume();
        if (!measurements || measurements.parcels <= 0 || measurements.volume <= 0 || measurements.weight <= 0) {
            this.setRoutingStatus('error', 'Completa peso, dimensioni e numero di colli prima di chiedere suggerimenti BRT.');
            window.PickupPortal.showAlert('Compila peso, dimensioni e numero di colli per ottenere i suggerimenti BRT.', 'warning', 5000);
            return;
        }

        const zipInput = this.form.querySelector('#brtZip');
        const countrySelect = this.form.querySelector('#brtCountry');
        const zip = zipInput ? zipInput.value.trim() : '';
        const country = countrySelect ? countrySelect.value.trim() : '';

        if (!zip || !country) {
            this.setRoutingStatus('error', 'Inserisci CAP e paese del destinatario per calcolare la proposta BRT.');
            window.PickupPortal.showAlert('Inserisci CAP e paese del destinatario per calcolare la proposta BRT.', 'warning', 5000);
            return;
        }

        const formData = new FormData(this.form);
        const payload = this.buildRoutingPayload(formData, measurements);

        this.setRoutingLoading(true);
        this.setRoutingStatus('info', 'Richiesta in corso al webservice BRT...');

        try {
            const response = await window.PickupPortal.api.post('brt/routing.php', payload);
            this.applyRoutingSuggestion(response);
        } catch (error) {
            this.setRoutingStatus('error', error.message || 'Impossibile ottenere i suggerimenti BRT.');
        } finally {
            this.setRoutingLoading(false);
        }
    },
    applyRoutingSuggestion: function(response) {
        if (!response || typeof response !== 'object') {
            this.setRoutingStatus('error', 'Risposta non valida dal webservice BRT.');
            return;
        }

        const suggestion = response.suggestion || {};
        const summary = response.summary || null;
        const message = typeof response.message === 'string' && response.message !== '' ? response.message : '';

        const upper = (value) => (typeof value === 'string' ? value.toUpperCase() : '');

        if (suggestion.service_type && this.serviceTypeInput) {
            this.serviceTypeInput.value = upper(suggestion.service_type);
        }

        if (suggestion.delivery_type && this.deliveryTypeInput) {
            this.deliveryTypeInput.value = upper(suggestion.delivery_type);
        }

        if (suggestion.network && this.networkInput) {
            this.networkInput.value = upper(suggestion.network);
        }

        if (suggestion.pricing_condition_code && this.pricingConditionInput) {
            this.pricingConditionInput.value = suggestion.pricing_condition_code;
        }

        const parts = [];
        if (suggestion.network) {
            parts.push(`Rete ${upper(suggestion.network)}`);
        }
        if (suggestion.service_type) {
            parts.push(`Servizio ${upper(suggestion.service_type)}`);
        }
        if (suggestion.delivery_type) {
            parts.push(`Consegna ${upper(suggestion.delivery_type)}`);
        }
        if (suggestion.pricing_condition_code) {
            parts.push(`Tariffa ${suggestion.pricing_condition_code}`);
        }

        if (summary && typeof summary === 'object') {
            const amount = Number(summary.amount ?? 0);
            if (Number.isFinite(amount) && amount > 0) {
                const formatted = this.formatMoney(amount, summary.currency || '');
                parts.push(`Costo stimato ${formatted}`);
            }
        }

        if (message) {
            parts.push(message);
        }

        if (parts.length === 0) {
            this.setRoutingStatus('muted', 'Nessun suggerimento disponibile per i dati indicati.');
        } else {
            this.setRoutingStatus('success', parts.join(' · '));
        }
    },
    handleSubmit: async function(event) {
        event.preventDefault();
        if (!this.form.checkValidity()) {
            this.form.classList.add('was-validated');
            return;
        }

        this.form.classList.add('was-validated');
        const submitButton = this.submitButton;
        const originalHtml = submitButton ? submitButton.innerHTML : '';
        const measurements = this.updateVolume();

        if (!measurements || measurements.parcels <= 0) {
            window.PickupPortal.showAlert('Indica un numero di colli valido per calcolare il volume.', 'warning', 6000);
            return;
        }

        if (measurements.length <= 0 || measurements.depth <= 0 || measurements.height <= 0 || measurements.volume <= 0) {
            window.PickupPortal.showAlert('Il volume risulta nullo. Verifica che altezza, lunghezza e profondità siano maggiori di zero.', 'warning', 6000);
            return;
        }

        const formData = new FormData(this.form);
        const payload = this.buildPayload(formData, measurements);
        if (submitButton) {
            submitButton.disabled = true;
            submitButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Creazione...';
        }

        try {
            const response = await window.PickupPortal.api.post('brt/shipments.php', payload);
            if (response && response.payment && response.payment.checkout_url) {
                const redirectUrl = response.payment.checkout_url;
                window.PickupPortal.showAlert('Reindirizzamento al pagamento...', 'info', 3000);
                window.location.href = redirectUrl;
                return;
            }

            const success = Boolean(response && response.shipment);
            if (success) {
                const message = response.message || 'Spedizione creata con successo';
                window.PickupPortal.showAlert(message, 'success', 5000);
                this.form.reset();
                this.form.classList.remove('was-validated');
                this.applyDefaults();
                this.setRoutingStatus('muted', 'Premi "Suggerisci" per ottenere rete, servizio e tariffa consigliati da BRT.');
                this.updateVolume();
                this.updateCodState();
                setTimeout(() => {
                    window.location.href = this.redirectUrl;
                }, 1200);
            } else if (response && response.message) {
                window.PickupPortal.showAlert(response.message, 'info', 5000);
            }
        } catch (error) {
            // L'helper API mostra già l'errore
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = originalHtml;
            }
        }
    }
};

// Inizializzazione generale
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebarMenu');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebarMobileToggle = document.getElementById('sidebarMobileToggle');
    const mobileBreakpoint = window.matchMedia('(max-width: 991.98px)');

    const updateSidebarToggleState = (isCollapsed) => {
        if (!sidebarToggle) {
            return;
        }
        sidebarToggle.setAttribute('aria-expanded', String(!isCollapsed));
        sidebarToggle.setAttribute('aria-label', isCollapsed ? 'Espandi barra laterale' : 'Riduci barra laterale');

        const toggleIcon = sidebarToggle.querySelector('i');
        if (toggleIcon) {
            toggleIcon.classList.toggle('fa-angles-left', !isCollapsed);
            toggleIcon.classList.toggle('fa-angles-right', isCollapsed);
        }
    };

    const syncSidebarState = () => {
        if (!sidebar) {
            return;
        }
        if (mobileBreakpoint.matches) {
            sidebar.classList.remove('collapsed');
            sidebar.classList.remove('open');
            document.body.classList.remove('offcanvas-active');
            document.body.classList.remove('sidebar-collapsed');
            updateSidebarToggleState(false);
            sidebarMobileToggle?.setAttribute('aria-expanded', 'false');
        } else {
            const storedState = localStorage.getItem('pickupPortalSidebar');
            const shouldCollapse = storedState === 'collapsed';
            sidebar.classList.toggle('collapsed', shouldCollapse);
            document.body.classList.toggle('sidebar-collapsed', shouldCollapse);
            updateSidebarToggleState(shouldCollapse);
        }
    };

    const toggleDesktopSidebar = () => {
        if (!sidebar || mobileBreakpoint.matches) {
            return;
        }
        const shouldCollapse = !sidebar.classList.contains('collapsed');
        sidebar.classList.toggle('collapsed', shouldCollapse);
        document.body.classList.toggle('sidebar-collapsed', shouldCollapse);
        updateSidebarToggleState(shouldCollapse);
        localStorage.setItem('pickupPortalSidebar', shouldCollapse ? 'collapsed' : 'expanded');
    };

    const toggleMobileSidebar = () => {
        if (!sidebar) {
            return;
        }
        const willOpen = !sidebar.classList.contains('open');
        sidebar.classList.toggle('open', willOpen);
        document.body.classList.toggle('offcanvas-active', willOpen);
        if (willOpen) {
            document.body.classList.remove('sidebar-collapsed');
        }
        updateSidebarToggleState(!willOpen && sidebar?.classList.contains('collapsed'));
        sidebarMobileToggle?.setAttribute('aria-expanded', String(willOpen));
    };

    syncSidebarState();

    const breakpointListener = mobileBreakpoint.addEventListener ? 'addEventListener' : 'addListener';
    mobileBreakpoint[breakpointListener]('change', syncSidebarState);

    sidebarToggle?.addEventListener('click', () => {
        if (mobileBreakpoint.matches) {
            toggleMobileSidebar();
        } else {
            toggleDesktopSidebar();
        }
    });

    sidebarMobileToggle?.addEventListener('click', toggleMobileSidebar);

    sidebar?.querySelectorAll('a').forEach((link) => {
        link.addEventListener('click', () => {
            if (!mobileBreakpoint.matches) {
                return;
            }
            sidebar.classList.remove('open');
            document.body.classList.remove('offcanvas-active');
            document.body.classList.remove('sidebar-collapsed');
            updateSidebarToggleState(false);
            sidebarMobileToggle?.setAttribute('aria-expanded', 'false');
        });
    });

    // Inizializza tooltips Bootstrap
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Inizializza popovers Bootstrap
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
    
    // Auto-hide alerts
    const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(alert => {
        if (!alert.querySelector('.btn-close')) {
            setTimeout(() => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            }, 5000);
        }
    });
    
    // Gestione click tracking codes per copia
    const trackingCodes = document.querySelectorAll('.package-tracking, .tracking-code');
    trackingCodes.forEach(element => {
        element.addEventListener('click', function() {
            const text = this.textContent.trim();
            window.PickupPortal.copyToClipboard(text);
        });
        
        element.style.cursor = 'pointer';
        element.title = 'Clicca per copiare';
    });
    
    // Gestione form con validazione
    const forms = document.querySelectorAll('form[data-validate="true"]');
    forms.forEach(form => {
        form.addEventListener('submit', function(event) {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            form.classList.add('was-validated');
        });
    });

    if (window.PickupPortal && window.PickupPortal.BrtShipments) {
        window.PickupPortal.BrtShipments.init();
    }

    if (window.PickupPortal && window.PickupPortal.BrtShipmentForm) {
        window.PickupPortal.BrtShipmentForm.init();
    }
    
    console.log('Pickup Portal initialized successfully');
});

// Service Worker registration (se disponibile)
if (
    'serviceWorker' in navigator &&
    window.PickupPortal &&
    window.PickupPortal.config &&
    window.PickupPortal.config.enableServiceWorker
) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js')
            .then((registration) => {
                console.log('SW registered: ', registration);
            })
            .catch((registrationError) => {
                console.log('SW registration failed: ', registrationError);
            });
    });
}