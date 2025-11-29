(function () {
    const ready = (callback) => {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback, { once: true });
        } else {
            callback();
        }
    };

    const ensureTrailingSlash = (value) => {
        if (typeof value !== 'string' || value.length === 0) {
            return '';
        }
        return value.endsWith('/') ? value : `${value}/`;
    };

    const resolveApiBaseUrl = () => {
        const portalBase = window.portalConfig && typeof window.portalConfig.apiBaseUrl === 'string'
            ? window.portalConfig.apiBaseUrl.trim()
            : '';
        if (portalBase) {
            return ensureTrailingSlash(portalBase);
        }

        const csBase = window.CS && typeof window.CS.apiBaseUrl === 'string'
            ? window.CS.apiBaseUrl.trim()
            : '';
        if (csBase) {
            return ensureTrailingSlash(csBase);
        }

        const scripts = document.querySelectorAll('script[src*="pudo-map.js"]');
        const current = document.currentScript && document.currentScript.src
            ? document.currentScript
            : (scripts.length > 0 ? scripts[scripts.length - 1] : null);

        if (current && current.src) {
            try {
                const scriptUrl = new URL(current.src, window.location.href);
                const segments = scriptUrl.pathname.split('/').filter((segment) => segment.length > 0);
                const modulesIndex = segments.indexOf('modules');
                let basePath = '';

                if (modulesIndex > -1) {
                    const baseSegments = segments.slice(0, modulesIndex);
                    basePath = baseSegments.length > 0 ? `/${baseSegments.join('/')}` : '';
                }

                const origin = scriptUrl.origin;
                return ensureTrailingSlash(`${origin}${basePath}/api/`);
            } catch (error) {
                console.warn('Unable to resolve API base URL for Leaflet tiles', error);
            }
        }

        return ensureTrailingSlash('/api/');
    };

    const getTileTemplate = () => `${resolveApiBaseUrl()}leaflet-tiles.php?z={z}&x={x}&y={y}`;

    const resolveAssetUrl = (path) => {
        const portalBase = window.portalConfig && typeof window.portalConfig.assetsBaseUrl === 'string'
            ? window.portalConfig.assetsBaseUrl.trim()
            : '';
        if (portalBase) {
            return `${portalBase.replace(/\/?$/, '/')}${path.replace(/^\//, '')}`;
        }

        const csBase = window.CS && typeof window.CS.assetsBaseUrl === 'string'
            ? window.CS.assetsBaseUrl.trim()
            : '';
        if (csBase) {
            return `${csBase.replace(/\/?$/, '/')}${path.replace(/^\//, '')}`;
        }

        return `${window.location.origin}/${path.replace(/^\//, '')}`;
    };

    const ensureLeafletDefaults = () => {
        if (typeof L === 'undefined' || ensureLeafletDefaults._applied) {
            return;
        }

        const markerUrl = window.CS?.assets?.leafletMarker ?? resolveAssetUrl('assets/img/leaflet-marker.png');
        const markerRetinaUrl = window.CS?.assets?.leafletMarkerRetina ?? resolveAssetUrl('assets/img/leaflet-marker@2x.png');

        try {
            L.Icon.Default.mergeOptions({
                iconUrl: markerUrl,
                iconRetinaUrl: markerRetinaUrl,
                shadowUrl: null,
                shadowRetinaUrl: null,
            });
        } catch (error) {
            console.warn('Leaflet default icon configuration failed', error);
        }

        ensureLeafletDefaults._applied = true;
    };

    const formatDistance = (distanceKm) => {
        if (!Number.isFinite(distanceKm)) {
            return '';
        }
        if (distanceKm >= 1) {
            return `${distanceKm.toFixed(1)} km`;
        }
        return `${Math.round(distanceKm * 1000)} m`;
    };

    const buildListItem = (pudo, onSelect) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = 'list-group-item list-group-item-action text-start';
        item.dataset.pudoId = pudo.id;

        const title = document.createElement('div');
        title.className = 'fw-semibold';
        title.textContent = pudo.name || `PUDO ${pudo.id}`;

        const address = document.createElement('div');
        address.className = 'small text-muted';
        const parts = [pudo.address, pudo.zipCode, pudo.city];
        address.textContent = parts.filter((part) => part && part.trim() !== '').join(' - ');

        const distanceText = formatDistance(pudo.distanceKm);
        if (distanceText) {
            const distance = document.createElement('div');
            distance.className = 'small text-muted';
            distance.textContent = `Distanza indicativa: ${distanceText}`;
            address.appendChild(document.createElement('br'));
            address.appendChild(distance);
        }

        item.append(title, address);
        item.addEventListener('click', () => {
            onSelect(pudo, { focusMarker: true });
        });

        return item;
    };

    const selectPudo = (root, pudo, options = {}) => {
    const hiddenFieldSelector = root.dataset.hiddenField;
    const hiddenLabelSelector = root.dataset.hiddenLabelField;
        const selectedAlert = root.querySelector('[data-pudo-selected-alert]');
        const selectedLabelElement = root.querySelector('[data-pudo-selected-label]');

        if (hiddenFieldSelector) {
            const hiddenField = root.querySelector(hiddenFieldSelector) || document.querySelector(hiddenFieldSelector);
            if (hiddenField) {
                hiddenField.value = pudo ? pudo.id : '';
            }
        }

        if (hiddenLabelSelector) {
            const hiddenLabelField = root.querySelector(hiddenLabelSelector) || document.querySelector(hiddenLabelSelector);
            if (hiddenLabelField) {
                hiddenLabelField.value = pudo ? pudo.label : '';
            }
        }

        if (selectedAlert && selectedLabelElement) {
            if (pudo) {
                selectedLabelElement.textContent = pudo.label;
                selectedAlert.classList.remove('d-none');
            } else {
                selectedLabelElement.textContent = '';
                selectedAlert.classList.add('d-none');
            }
        }

        root.dataset.selectedId = pudo ? pudo.id : '';
        root.dataset.selectedLabel = pudo ? pudo.label : '';

        if (pudo && options.focusMarker && root._pudoMarkers) {
            const marker = root._pudoMarkers.find((entry) => entry.id === pudo.id);
            if (marker && marker.marker) {
                marker.marker.openPopup();
            }
        }
    };

    const setStatus = (root, message, variant = 'muted') => {
        const statusElement = root.querySelector('[data-pudo-status]');
        if (!statusElement) {
            return;
        }
        if (!message) {
            statusElement.textContent = '';
            statusElement.classList.remove('text-danger', 'text-warning');
            statusElement.classList.add('text-muted');
            return;
        }
        statusElement.textContent = message;
        statusElement.classList.remove('text-muted', 'text-danger', 'text-warning');
        if (variant === 'danger') {
            statusElement.classList.add('text-danger');
        } else if (variant === 'warning') {
            statusElement.classList.add('text-warning');
        } else {
            statusElement.classList.add('text-muted');
        }
    };

    const maybeAutoSearch = (root) => {
        if (!root) {
            return;
        }

        const zipInput = root.querySelector('[data-pudo-search-zip]');
        const cityInput = root.querySelector('[data-pudo-search-city]');
        const provinceInput = root.querySelector('[data-pudo-search-province]');
        const countryInput = root.querySelector('[data-pudo-search-country]');

        const zipValue = zipInput?.value?.trim() || '';
        const cityValue = cityInput?.value?.trim() || '';
        const provinceValue = provinceInput?.value?.trim() || '';
        const countryValue = countryInput?.value?.trim() || '';

        if (zipValue === '' || cityValue === '') {
            root._pudoLastAutoCriteria = null;
            renderResults(root, []);
            setStatus(root, 'Inserisci CAP e città per cercare un PUDO.', 'warning');
            return;
        }

        const fingerprint = [zipValue, cityValue, provinceValue, countryValue].join('|');
        if (fingerprint === root._pudoLastAutoCriteria) {
            return;
        }

        root._pudoLastAutoCriteria = fingerprint;
        performSearch(root);
    };

    const scheduleAutoSearch = (root, delay = 500) => {
        if (!root) {
            return;
        }
        if (root._pudoAutoSearchTimer) {
            window.clearTimeout(root._pudoAutoSearchTimer);
        }
        root._pudoAutoSearchTimer = window.setTimeout(() => {
            root._pudoAutoSearchTimer = null;
            maybeAutoSearch(root);
        }, delay);
    };

    const clearMarkers = (root) => {
        if (!root._pudoMarkers) {
            return;
        }
        root._pudoMarkers.forEach((entry) => {
            if (entry.marker) {
                entry.marker.remove();
            }
        });
        root._pudoMarkers = [];
    };

    const ensureMap = (root) => {
        if (root._pudoMap || typeof L === 'undefined') {
            return root._pudoMap;
        }
        const mapElement = root.querySelector('[data-pudo-map]');
        if (!mapElement) {
            return null;
        }
        const defaultLat = Number.parseFloat(root.dataset.defaultLat || '41.8719');
        const defaultLng = Number.parseFloat(root.dataset.defaultLng || '12.5674');

        ensureLeafletDefaults();

        const map = L.map(mapElement, {
            center: [defaultLat, defaultLng],
            zoom: 5,
            scrollWheelZoom: false,
        });

        L.tileLayer(getTileTemplate(), {
            attribution: '&copy; OpenStreetMap contributors',
            maxZoom: 19,
        }).addTo(map);

        root._pudoMap = map;
        root._pudoMarkers = [];
        return map;
    };

    const renderResults = (root, pudos) => {
        const listContainer = root.querySelector('[data-pudo-list]');
        if (listContainer) {
            listContainer.innerHTML = '';
        }

        const map = ensureMap(root);
        clearMarkers(root);

        if (!Array.isArray(pudos) || pudos.length === 0) {
            if (map) {
                const defaultLat = Number.parseFloat(root.dataset.defaultLat || '41.8719');
                const defaultLng = Number.parseFloat(root.dataset.defaultLng || '12.5674');
                map.setView([defaultLat, defaultLng], 5);
            }
            setStatus(root, 'Nessun PUDO trovato per i criteri indicati.', 'warning');
            return;
        }

        setStatus(root, `Trovati ${pudos.length} PUDO.`);

        const bounds = [];
        const markers = [];

        pudos.forEach((pudo) => {
            const labelParts = [pudo.name || `PUDO ${pudo.id}`, pudo.address, pudo.zipCode, pudo.city];
            const label = labelParts.filter((value) => value && value.trim() !== '').join(' - ');
            const latitude = Number.parseFloat(pudo.latitude);
            const longitude = Number.parseFloat(pudo.longitude);
            const normalized = {
                ...pudo,
                label: label || `PUDO ${pudo.id}`,
                latitude,
                longitude,
            };

            if (listContainer) {
                const listItem = buildListItem(normalized, (selected, options) => {
                    selectPudo(root, selected, options);
                });
                listContainer.appendChild(listItem);
            }

            if (map && Number.isFinite(latitude) && Number.isFinite(longitude)) {
                const marker = L.marker([latitude, longitude]).addTo(map);
                const popupLines = [
                    `<strong>${normalized.name || `PUDO ${normalized.id}`}</strong>`,
                ];
                const popupAddress = [normalized.address, `${normalized.zipCode || ''} ${normalized.city || ''}`.trim()]
                    .filter((value) => value && value.trim() !== '')
                    .join('<br>');
                if (popupAddress) {
                    popupLines.push(popupAddress);
                }
                const distance = formatDistance(normalized.distanceKm);
                if (distance) {
                    popupLines.push(`Distanza: ${distance}`);
                }
                if (Array.isArray(normalized.openingHours) && normalized.openingHours.length > 0) {
                    popupLines.push(`Orari: ${normalized.openingHours.join(', ')}`);
                }
                marker.bindPopup(popupLines.join('<br>'));
                marker.on('click', () => {
                    selectPudo(root, normalized);
                });
                markers.push({ id: normalized.id, marker });
                root._pudoMarkers.push({ id: normalized.id, marker });
                bounds.push([latitude, longitude]);
            }
        });

        if (map && bounds.length > 0) {
            map.fitBounds(bounds, { padding: [24, 24], maxZoom: 15 });
        }

        if (markers.length === 0 && map) {
            const defaultLat = Number.parseFloat(root.dataset.defaultLat || '41.8719');
            const defaultLng = Number.parseFloat(root.dataset.defaultLng || '12.5674');
            map.setView([defaultLat, defaultLng], 7);
        }
    };

    async function performSearch(root) {
        const apiUrl = root.dataset.apiUrl || 'pudo-search.php';
        const zipInput = root.querySelector('[data-pudo-search-zip]');
        const cityInput = root.querySelector('[data-pudo-search-city]');
        const provinceInput = root.querySelector('[data-pudo-search-province]');
        const countryInput = root.querySelector('[data-pudo-search-country]');

        const zipValue = zipInput?.value?.trim() || '';
        const cityValue = cityInput?.value?.trim() || '';
        const provinceValue = provinceInput?.value?.trim() || '';
        const countryValue = countryInput?.value?.trim() || root.dataset.defaultCountry || 'IT';

        if (zipValue === '' || cityValue === '') {
            setStatus(root, 'Inserisci CAP e città per cercare un PUDO.', 'warning');
            return;
        }

        setStatus(root, 'Ricerca in corso…');

        const params = new URLSearchParams();
        if (zipValue) params.append('zip', zipValue);
        if (cityValue) params.append('city', cityValue);
        if (provinceValue) params.append('province', provinceValue);
        if (countryValue) params.append('country', countryValue);

        try {
            const response = await fetch(`${apiUrl}?${params.toString()}`, {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
            });

            let payload;

            if (!response.ok) {
                try {
                    payload = await response.json();
                } catch (error) {
                    payload = null;
                }

                const errorMessage = payload?.message
                    ? `${payload.message} (HTTP ${response.status}).`
                    : `Ricerca PUDO non riuscita (HTTP ${response.status}).`;

                if (payload?.debug) {
                    console.error('BRT PUDO search error:', payload.debug);
                }

                setStatus(root, errorMessage, 'danger');
                return;
            }

            payload = await response.json();
            if (!payload || payload.success !== true || !Array.isArray(payload.pudos)) {
                setStatus(root, payload?.message || 'Nessun PUDO disponibile.', 'warning');
                renderResults(root, []);
                return;
            }

            renderResults(root, payload.pudos);
        } catch (error) {
            setStatus(root, error instanceof Error ? error.message : 'Errore inatteso durante la ricerca PUDO.', 'danger');
        }
    }

    const syncFromConsignee = (root, options = {}) => {
        const zipSource = root.dataset.zipSource;
        const citySource = root.dataset.citySource;
        const provinceSource = root.dataset.provinceSource;
        const countrySource = root.dataset.countrySource;

        const zipTarget = root.querySelector('[data-pudo-search-zip]');
        const cityTarget = root.querySelector('[data-pudo-search-city]');
        const provinceTarget = root.querySelector('[data-pudo-search-province]');
        const countryTarget = root.querySelector('[data-pudo-search-country]');

        if (zipSource && zipTarget) {
            const sourceField = document.querySelector(zipSource);
            if (sourceField && sourceField.value) {
                zipTarget.value = sourceField.value;
            }
        }

        if (citySource && cityTarget) {
            const sourceField = document.querySelector(citySource);
            if (sourceField && sourceField.value) {
                cityTarget.value = sourceField.value;
            }
        }

        if (provinceSource && provinceTarget) {
            const sourceField = document.querySelector(provinceSource);
            if (sourceField && sourceField.value) {
                provinceTarget.value = sourceField.value;
            }
        }

        if (countrySource && countryTarget) {
            const sourceField = document.querySelector(countrySource);
            if (sourceField && sourceField.value) {
                countryTarget.value = sourceField.value;
            }
        }

        if (options.autoSearch !== false) {
            scheduleAutoSearch(root, 100);
        }
    };

    const setupConsigneeListeners = (root) => {
        const mappings = [
            { source: root.dataset.zipSource, target: '[data-pudo-search-zip]' },
            { source: root.dataset.citySource, target: '[data-pudo-search-city]' },
            { source: root.dataset.provinceSource, target: '[data-pudo-search-province]' },
            { source: root.dataset.countrySource, target: '[data-pudo-search-country]' },
        ];

        mappings.forEach(({ source, target }) => {
            if (!source || !target) {
                return;
            }

            const sourceField = document.querySelector(source);
            const targetField = root.querySelector(target);

            if (!sourceField || !targetField) {
                return;
            }

            const sync = () => {
                targetField.value = sourceField.value || '';
            };

            sync();

            const handler = () => {
                sync();
                scheduleAutoSearch(root);
            };

            sourceField.addEventListener('input', handler);
            sourceField.addEventListener('change', handler);
        });
    };

    const wireWidget = (root) => {
        if (!root || root.dataset.pudoInitialised === '1') {
            return;
        }
        if (typeof L === 'undefined') {
            setStatus(root, 'Impossibile inizializzare la mappa PUDO: libreria Leaflet non caricata.', 'danger');
            return;
        }

        root.dataset.pudoInitialised = '1';
        ensureMap(root);

        setupConsigneeListeners(root);

        const searchButton = root.querySelector('[data-pudo-search]');
        if (searchButton) {
            searchButton.addEventListener('click', (event) => {
                event.preventDefault();
                performSearch(root);
            });
        }

        const syncButton = root.querySelector('[data-pudo-sync-from-consignee]');
        if (syncButton) {
            syncButton.addEventListener('click', (event) => {
                event.preventDefault();
                syncFromConsignee(root, { autoSearch: true });
            });
        }

        const clearButton = root.querySelector('[data-pudo-clear]');
        if (clearButton) {
            clearButton.addEventListener('click', (event) => {
                event.preventDefault();
                selectPudo(root, null);
            });
        }

        const selectedId = root.dataset.selectedId || '';
        const selectedLabel = root.dataset.selectedLabel || '';
        if (selectedId) {
            const fallbackLabel = selectedLabel || `PUDO ${selectedId}`;
            selectPudo(root, { id: selectedId, label: fallbackLabel });
        } else {
            selectPudo(root, null);
        }

        syncFromConsignee(root, { autoSearch: true });
    };

    ready(() => {
        document.querySelectorAll('[data-pudo-root="true"]').forEach((root) => {
            wireWidget(root);
        });
    });
})();
