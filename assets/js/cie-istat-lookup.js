(function () {
    const globalConfig = window.CIEIstatLookupConfig || {};
    const primaryUrl = typeof globalConfig.datasetUrl === 'string' && globalConfig.datasetUrl !== ''
        ? globalConfig.datasetUrl
        : 'https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json';
    const fallbackUrl = typeof globalConfig.fallbackUrl === 'string' && globalConfig.fallbackUrl !== '' && globalConfig.fallbackUrl !== primaryUrl
        ? globalConfig.fallbackUrl
        : null;
    const defaultMaxResults = Number(globalConfig.maxResults) || 12;
    const defaultMinChars = Number(globalConfig.minChars) || 2;
    const defaultDebounce = Number(globalConfig.debounceMs) || 160;

    let dataset = null;
    let datasetPromise = null;

    const normalize = (value) => {
        if (value === undefined || value === null) {
            return '';
        }
        let normalized = String(value);
        if (typeof normalized.normalize === 'function') {
            normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return normalized.replace(/'/g, '').replace(/\s+/g, ' ').trim().toUpperCase();
    };

    const prepareDataset = (entries) => {
        if (!Array.isArray(entries)) {
            return [];
        }
        return entries.map((entry) => {
            const nome = typeof entry.nome === 'string' ? entry.nome.trim() : '';
            const sigla = typeof entry.sigla === 'string' ? entry.sigla.trim().toUpperCase() : (typeof entry.provincia === 'string' ? entry.provincia.trim().toUpperCase() : '');
            let caps = [];
            if (Array.isArray(entry.cap)) {
                caps = entry.cap.filter((value) => typeof value === 'string' && value.trim() !== '').map((value) => value.trim());
            } else if (typeof entry.cap === 'string' && entry.cap.trim() !== '') {
                caps = [entry.cap.trim()];
            }
            return {
                nome,
                normalized: normalize(nome),
                sigla,
                cap: caps,
            };
        }).filter((item) => item.nome !== '' && item.normalized !== '');
    };

    const fetchDataset = (url, label) => fetch(url, { cache: 'force-cache' })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`${label} request failed (${response.status})`);
            }
            return response.json();
        });

    const loadDataset = () => {
        if (dataset) {
            return Promise.resolve(dataset);
        }
        if (datasetPromise) {
            return datasetPromise;
        }

        const hydrate = (payload) => {
            dataset = prepareDataset(payload);
            return dataset;
        };

        datasetPromise = fetchDataset(primaryUrl, 'ISTAT dataset')
            .catch((error) => {
                if (!fallbackUrl) {
                    throw error;
                }
                console.warn('CIE ISTAT lookup: primary dataset unavailable', error);
                return fetchDataset(fallbackUrl, 'ISTAT fallback dataset');
            })
            .then(hydrate)
            .catch((error) => {
                console.warn('CIE ISTAT lookup disabled', error);
                dataset = [];
                return dataset;
            });

        return datasetPromise;
    };

    const debounce = (fn, delay) => {
        let timer = null;
        return function debounced() {
            const context = this;
            const args = arguments;
            if (timer) {
                window.clearTimeout(timer);
            }
            timer = window.setTimeout(() => {
                fn.apply(context, args);
            }, delay);
        };
    };

    const renderOptions = (datalist, matches) => {
        while (datalist.firstChild) {
            datalist.removeChild(datalist.firstChild);
        }
        matches.forEach((match) => {
            const option = document.createElement('option');
            option.value = match.nome;
            option.textContent = match.sigla ? `${match.nome} (${match.sigla})` : match.nome;
            datalist.appendChild(option);
        });
    };

    const applyMatch = (input, match) => {
        if (!match) {
            return;
        }
        const provinceSelector = input.dataset.istatProvinceTarget;
        if (provinceSelector && match.sigla) {
            const provinceInput = document.querySelector(provinceSelector);
            if (provinceInput && provinceInput.value !== match.sigla) {
                provinceInput.value = match.sigla;
                provinceInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
        const capSelector = input.dataset.istatCapTarget;
        if (capSelector && Array.isArray(match.cap) && match.cap.length === 1) {
            const capInput = document.querySelector(capSelector);
            if (capInput && capInput.value !== match.cap[0]) {
                capInput.value = match.cap[0];
                capInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }
    };

    const findMatch = (value, matches) => {
        const normalized = normalize(value);
        if (!normalized) {
            return null;
        }
        return matches.find((entry) => entry.normalized === normalized) || null;
    };

    const initInput = (input) => {
        const datalistId = input.getAttribute('list');
        if (!datalistId) {
            return;
        }
        const datalist = document.getElementById(datalistId);
        if (!datalist) {
            return;
        }

        const localMax = Number(input.dataset.istatMaxResults) || defaultMaxResults;
        const localMin = Number(input.dataset.istatMinChars) || defaultMinChars;
        const localDebounce = Number(input.dataset.istatDebounce) || defaultDebounce;
        const state = { matches: [] };

        const performLookup = () => {
            const term = input.value.trim();
            if (term.length < localMin) {
                state.matches = [];
                renderOptions(datalist, state.matches);
                return;
            }
            loadDataset()
                .then((data) => {
                    const normalizedTerm = normalize(term);
                    const matches = data
                        .filter((entry) => entry.normalized.includes(normalizedTerm))
                        .slice(0, localMax);
                    state.matches = matches;
                    renderOptions(datalist, matches);
                })
                .catch(() => {
                    state.matches = [];
                    renderOptions(datalist, state.matches);
                });
        };

        const confirmSelection = () => {
            const value = input.value.trim();
            if (!value) {
                return;
            }
            const directMatch = findMatch(value, state.matches);
            if (directMatch) {
                applyMatch(input, directMatch);
                return;
            }
            loadDataset()
                .then((data) => {
                    applyMatch(input, findMatch(value, data));
                })
                .catch(() => {
                    /* noop */
                });
        };

        const debouncedLookup = debounce(performLookup, localDebounce);

        input.addEventListener('input', debouncedLookup);
        input.addEventListener('focus', performLookup);
        input.addEventListener('change', confirmSelection);
        input.addEventListener('blur', confirmSelection);
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-istat-comune="true"]').forEach(initInput);
    });
})();
