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

    const injectStyles = (() => {
        let injected = false;
        return () => {
            if (injected) {
                return;
            }
            const style = document.createElement('style');
            style.id = 'cie-istat-lookup-styles';
            style.textContent = `
                .cie-istat-dropdown-parent {
                    position: relative;
                }
                .cie-istat-dropdown {
                    position: absolute;
                    left: 0;
                    right: 0;
                    z-index: 20;
                    margin-top: 2px;
                    background: #1f1f1f;
                    border: 1px solid rgba(255, 255, 255, 0.08);
                    border-radius: 0.5rem;
                    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
                    max-height: 260px;
                    overflow-y: auto;
                }
                .cie-istat-dropdown[hidden] {
                    display: none !important;
                }
                .cie-istat-option {
                    width: 100%;
                    text-align: left;
                    border: 0;
                    background: transparent;
                    padding: 0.45rem 0.85rem;
                    color: inherit;
                    font-size: 0.95rem;
                    cursor: pointer;
                }
                .cie-istat-option:hover,
                .cie-istat-option.is-active {
                    background: rgba(255, 255, 255, 0.08);
                }
            `;
            document.head.appendChild(style);
            injected = true;
        };
    })();

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

    const renderNativeOptions = (datalist, matches) => {
        if (!datalist) {
            return;
        }
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

    const createDropdown = (input) => {
        const parent = input.parentElement;
        if (!parent) {
            return null;
        }
        parent.classList.add('cie-istat-dropdown-parent');
        const container = document.createElement('div');
        container.className = 'cie-istat-dropdown';
        container.setAttribute('role', 'listbox');
        container.hidden = true;
        const list = document.createElement('div');
        list.className = 'cie-istat-dropdown-list';
        container.appendChild(list);
        const anchor = input.nextElementSibling && input.nextElementSibling.tagName === 'DATALIST'
            ? input.nextElementSibling
            : input;
        anchor.insertAdjacentElement('afterend', container);
        return { container, list };
    };

    const renderDropdownOptions = (dropdown, matches, activeIndex, clickHandler) => {
        if (!dropdown) {
            return;
        }
        dropdown.list.innerHTML = '';
        matches.forEach((match, index) => {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'cie-istat-option' + (index === activeIndex ? ' is-active' : '');
            option.textContent = match.sigla ? `${match.nome} (${match.sigla})` : match.nome;
            option.dataset.index = String(index);
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                clickHandler(index);
            });
            dropdown.list.appendChild(option);
        });
        dropdown.container.hidden = matches.length === 0;
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
        const datalist = datalistId ? document.getElementById(datalistId) : null;
        const dropdown = createDropdown(input);
        injectStyles();

        const localMax = Number(input.dataset.istatMaxResults) || defaultMaxResults;
        const localMin = Number(input.dataset.istatMinChars) || defaultMinChars;
        const localDebounce = Number(input.dataset.istatDebounce) || defaultDebounce;
        const state = {
            matches: [],
            activeIndex: -1,
        };

        const hideDropdown = () => {
            if (dropdown) {
                dropdown.container.hidden = true;
            }
            state.activeIndex = -1;
        };

        const renderMatches = () => {
            renderNativeOptions(datalist, state.matches);
            renderDropdownOptions(dropdown, state.matches, state.activeIndex, (index) => {
                input.value = state.matches[index].nome;
                applyMatch(input, state.matches[index]);
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
                hideDropdown();
            });
        };

        const updateMatches = (matches) => {
            state.matches = matches;
            if (state.activeIndex >= matches.length) {
                state.activeIndex = matches.length ? 0 : -1;
            }
            renderMatches();
        };

        const performLookup = () => {
            const term = input.value.trim();
            if (term.length < localMin) {
                updateMatches([]);
                return;
            }
            loadDataset()
                .then((data) => {
                    const normalizedTerm = normalize(term);
                    const matches = data
                        .filter((entry) => entry.normalized.includes(normalizedTerm))
                        .slice(0, localMax);
                    updateMatches(matches);
                })
                .catch(() => {
                    updateMatches([]);
                });
        };

        const confirmSelection = () => {
            const value = input.value.trim();
            if (!value) {
                hideDropdown();
                return;
            }
            const directMatch = findMatch(value, state.matches);
            if (directMatch) {
                applyMatch(input, directMatch);
                hideDropdown();
                return;
            }
            loadDataset()
                .then((data) => {
                    applyMatch(input, findMatch(value, data));
                    hideDropdown();
                })
                .catch(() => {
                    hideDropdown();
                });
        };

        const debouncedLookup = debounce(() => {
            performLookup();
        }, localDebounce);

        const moveActive = (direction) => {
            if (!state.matches.length) {
                return;
            }
            if (direction === 'down') {
                state.activeIndex = state.activeIndex + 1;
                if (state.activeIndex >= state.matches.length) {
                    state.activeIndex = 0;
                }
            } else {
                state.activeIndex = state.activeIndex - 1;
                if (state.activeIndex < 0) {
                    state.activeIndex = state.matches.length - 1;
                }
            }
            renderMatches();
            if (dropdown) {
                dropdown.container.hidden = false;
                const activeEl = dropdown.list.querySelector('.cie-istat-option.is-active');
                if (activeEl && typeof activeEl.scrollIntoView === 'function') {
                    activeEl.scrollIntoView({ block: 'nearest' });
                }
            }
        };

        input.addEventListener('input', debouncedLookup);
        input.addEventListener('focus', () => {
            performLookup();
            if (dropdown && state.matches.length) {
                dropdown.container.hidden = false;
            }
        });
        input.addEventListener('change', confirmSelection);
        input.addEventListener('blur', () => {
            window.setTimeout(() => {
                hideDropdown();
                confirmSelection();
            }, 150);
        });
        input.addEventListener('keydown', (event) => {
            switch (event.key) {
                case 'ArrowDown':
                    event.preventDefault();
                    if (!state.matches.length) {
                        performLookup();
                    } else {
                        moveActive('down');
                    }
                    if (dropdown && state.matches.length) {
                        dropdown.container.hidden = false;
                    }
                    break;
                case 'ArrowUp':
                    event.preventDefault();
                    if (state.matches.length) {
                        moveActive('up');
                        if (dropdown) {
                            dropdown.container.hidden = false;
                        }
                    }
                    break;
                case 'Enter':
                    if (state.activeIndex >= 0 && state.matches[state.activeIndex]) {
                        event.preventDefault();
                        input.value = state.matches[state.activeIndex].nome;
                        applyMatch(input, state.matches[state.activeIndex]);
                        input.dispatchEvent(new Event('input', { bubbles: true }));
                        input.dispatchEvent(new Event('change', { bubbles: true }));
                        hideDropdown();
                    }
                    break;
                case 'Escape':
                    hideDropdown();
                    break;
                default:
                    break;
            }
        });
    };

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-istat-comune="true"]').forEach(initInput);
    });
})();
