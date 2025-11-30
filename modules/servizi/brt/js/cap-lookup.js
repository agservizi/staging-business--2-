(function () {
    'use strict';

    const DATA_URL = 'https://raw.githubusercontent.com/matteocontrini/comuni-json/master/comuni.json';
    const ZIP_REGEX = /^\d{5}$/;

    let capIndex = null;
    let loadPromise = null;
    let currentMatches = [];

    function normalize(value) {
        if (value === undefined || value === null) {
            return '';
        }
        let normalized = String(value);
        if (typeof normalized.normalize === 'function') {
            normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return normalized.replace(/'/g, '').replace(/\s+/g, ' ').trim().toUpperCase();
    }

    function buildCapIndex(entries) {
        const index = Object.create(null);
        if (!Array.isArray(entries)) {
            return index;
        }
        entries.forEach(function (entry) {
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

            caps.forEach(function (capValue) {
                const cap = typeof capValue === 'string' ? capValue.trim() : '';
                if (!cap) {
                    return;
                }
                if (!index[cap]) {
                    index[cap] = [];
                }
                const alreadyPresent = index[cap].some(function (item) {
                    return item.city === city && item.province === province;
                });
                if (!alreadyPresent) {
                    index[cap].push({ city: city, province: province });
                }
            });
        });
        return index;
    }

    function loadCapIndex() {
        if (capIndex) {
            return Promise.resolve(capIndex);
        }
        if (loadPromise) {
            return loadPromise;
        }
        loadPromise = fetch(DATA_URL, { cache: 'force-cache' })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('CAP dataset request failed with status ' + response.status);
                }
                return response.json();
            })
            .then(function (payload) {
                capIndex = buildCapIndex(payload);
                return capIndex;
            })
            .catch(function (error) {
                console.warn('CAP lookup: impossibile caricare il dataset', error);
                capIndex = null;
                throw error;
            });
        return loadPromise;
    }

    function setupCapLookup() {
        const zipInput = document.getElementById('consignee_zip');
        const cityInput = document.getElementById('consignee_city');
        const provinceInput = document.getElementById('consignee_province');
        const countryInput = document.getElementById('consignee_country');
        const datalist = document.getElementById('consignee_city_options');

        const originalZipPlaceholder = zipInput ? zipInput.getAttribute('placeholder') || '' : '';
        const irelandZipPlaceholder = 'Eircode (es. D02X285) oppure scrivi EIRE';

        if (!zipInput || !cityInput || !datalist) {
            return;
        }

        function shouldUseLookup() {
            if (!countryInput) {
                return true;
            }
            return (countryInput.value || '').toUpperCase() === 'IT';
        }

        function applyCountrySpecificBehavior() {
            if (!zipInput) {
                return;
            }

            const countryValue = countryInput ? (countryInput.value || '').toUpperCase() : 'IT';
            const isIreland = countryValue === 'IE';
            if (isIreland) {
                if (zipInput.getAttribute('placeholder') !== irelandZipPlaceholder) {
                    zipInput.setAttribute('placeholder', irelandZipPlaceholder);
                }

                if (zipInput.value.trim() === '') {
                    zipInput.value = 'EIRE';
                    zipInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
            } else {
                if (originalZipPlaceholder) {
                    zipInput.setAttribute('placeholder', originalZipPlaceholder);
                } else {
                    zipInput.removeAttribute('placeholder');
                }

                if (zipInput.value.trim().toUpperCase() === 'EIRE') {
                    zipInput.value = '';
                    zipInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
            }
        }

        function clearSuggestions() {
            currentMatches = [];
            while (datalist.firstChild) {
                datalist.removeChild(datalist.firstChild);
            }
        }

        function fillProvinceByCityValue() {
            if (!provinceInput || !currentMatches.length) {
                return;
            }
            const normalizedCity = normalize(cityInput.value);
            if (!normalizedCity) {
                return;
            }
            const match = currentMatches.find(function (item) {
                return normalize(item.city) === normalizedCity;
            });
            if (match && match.province && provinceInput.value !== match.province) {
                provinceInput.value = match.province;
                provinceInput.dispatchEvent(new Event('input', { bubbles: true }));
            }
        }

        function applyMatches(cap, matches) {
            clearSuggestions();
            if (!Array.isArray(matches) || !matches.length) {
                return;
            }
            currentMatches = matches.slice();
            matches.forEach(function (item) {
                const option = document.createElement('option');
                option.value = item.city;
                option.setAttribute('data-province', item.province || '');
                option.setAttribute('data-cap', cap);
                datalist.appendChild(option);
            });

            const normalizedCity = normalize(cityInput.value);
            if (!normalizedCity && currentMatches.length === 1) {
                const match = currentMatches[0];
                if (cityInput.value !== match.city) {
                    cityInput.value = match.city;
                    cityInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
                if (provinceInput && provinceInput.value !== match.province) {
                    provinceInput.value = match.province || '';
                    provinceInput.dispatchEvent(new Event('input', { bubbles: true }));
                }
            } else {
                fillProvinceByCityValue();
            }
        }

        function handleZipChange() {
            if (!shouldUseLookup()) {
                clearSuggestions();
                return;
            }

            const rawValue = zipInput.value.trim();
            if (!ZIP_REGEX.test(rawValue)) {
                clearSuggestions();
                return;
            }

            loadCapIndex()
                .then(function (index) {
                    if (!index) {
                        return;
                    }
                    const matches = index[rawValue] || [];
                    applyMatches(rawValue, matches);
                    fillProvinceByCityValue();
                })
                .catch(function () {
                    clearSuggestions();
                });
        }

        function debounce(fn, delay) {
            let timer = null;
            return function () {
                const args = arguments;
                if (timer) {
                    window.clearTimeout(timer);
                }
                timer = window.setTimeout(function () {
                    fn.apply(null, args);
                }, delay);
            };
        }

        const debouncedZipHandler = debounce(handleZipChange, 180);

        zipInput.addEventListener('input', debouncedZipHandler);
        zipInput.addEventListener('change', handleZipChange);
        zipInput.addEventListener('blur', handleZipChange);
        cityInput.addEventListener('change', fillProvinceByCityValue);
        cityInput.addEventListener('input', fillProvinceByCityValue);

        applyCountrySpecificBehavior();

        if (countryInput) {
            countryInput.addEventListener('change', function () {
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
        } else {
            applyCountrySpecificBehavior();
        }
    }

    document.addEventListener('DOMContentLoaded', setupCapLookup);
})();
