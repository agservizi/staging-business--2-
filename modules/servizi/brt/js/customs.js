document.addEventListener('DOMContentLoaded', () => {
    initCustomsSections();
    initHsCodeLookup();
});

function initCustomsSections() {
    const countryField = document.getElementById('consignee_country');
    const sections = document.querySelectorAll('[data-customs-section]');
    if (!countryField || sections.length === 0) {
        return;
    }

    const requiredCountryCodes = (value) => {
        if (!value) {
            return [];
        }
        try {
            const parsed = JSON.parse(value);
            if (Array.isArray(parsed)) {
                return parsed.map((item) => String(item).toUpperCase());
            }
        } catch (error) {
            // Ignora contenuti dataset non validi
        }
        return String(value)
            .split(',')
            .map((item) => String(item).trim().toUpperCase())
            .filter(Boolean);
    };

    sections.forEach((section) => {
        const datasetCountries = requiredCountryCodes(section.dataset.customsCountries || 'CH');
        const requiredInputs = section.querySelectorAll('[data-customs-required="true"]');
        const hiddenToggle = section.querySelector('input[data-customs-enabled]');

        const toggleSection = () => {
            const countryValue = String(countryField.value || '').toUpperCase();
            const isRequired = datasetCountries.includes(countryValue);
            section.classList.toggle('d-none', !isRequired);
            section.setAttribute('aria-hidden', isRequired ? 'false' : 'true');

            requiredInputs.forEach((input) => {
                if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement || input instanceof HTMLTextAreaElement)) {
                    return;
                }
                if (isRequired) {
                    input.setAttribute('required', 'required');
                    input.removeAttribute('disabled');
                } else {
                    input.removeAttribute('required');
                }
            });

            if (hiddenToggle instanceof HTMLInputElement) {
                hiddenToggle.value = isRequired ? '1' : '0';
            }
        };

        toggleSection();
        countryField.addEventListener('change', toggleSection);
    });
}

function initHsCodeLookup() {
    const searchRoots = document.querySelectorAll('[data-hs-search]');
    if (searchRoots.length === 0) {
        return;
    }

    searchRoots.forEach((root) => {
        const queryInput = root.querySelector('[data-hs-query]');
        const button = root.querySelector('[data-hs-button]');
        const resultsContainer = root.querySelector('[data-hs-results]');
        const statusMessage = root.querySelector('[data-hs-status]');
        const spinner = root.querySelector('[data-hs-spinner]');
        const targetSelector = root.dataset.hsTarget || '';
        const descriptionSelector = root.dataset.hsDescription || '';
        const limit = parseInt(root.dataset.hsLimit || '10', 10);
        const endpoint = root.dataset.hsUrl || 'hs-code-search.php';
        const autofillMode = root.dataset.hsDescriptionMode || 'if-empty';

        const targetField = targetSelector ? document.querySelector(targetSelector) : null;
        const descriptionField = descriptionSelector ? document.querySelector(descriptionSelector) : null;

        let controller = null;

        const setLoading = (isLoading) => {
            if (spinner) {
                spinner.classList.toggle('d-none', !isLoading);
            }
            if (button instanceof HTMLButtonElement) {
                button.disabled = isLoading;
            }
            if (queryInput instanceof HTMLInputElement) {
                queryInput.disabled = isLoading;
            }
        };

        const clearResults = (message = '') => {
            if (resultsContainer) {
                resultsContainer.innerHTML = '';
                resultsContainer.classList.add('d-none');
            }
            if (statusMessage) {
                statusMessage.textContent = message;
                statusMessage.classList.toggle('d-none', message === '');
            }
        };

        const applySelection = (item) => {
            if (targetField instanceof HTMLInputElement) {
                targetField.value = item.code;
                targetField.dispatchEvent(new Event('change', { bubbles: true }));
            }

            if (descriptionField instanceof HTMLInputElement || descriptionField instanceof HTMLTextAreaElement) {
                const shouldOverwrite = autofillMode === 'always'
                    || (autofillMode === 'if-empty' && String(descriptionField.value || '').trim() === '');
                if (shouldOverwrite) {
                    descriptionField.value = item.description;
                    descriptionField.dispatchEvent(new Event('change', { bubbles: true }));
                }
            }

            clearResults();
        };

        const renderResults = (items) => {
            if (!resultsContainer) {
                return;
            }

            resultsContainer.innerHTML = '';
            if (!Array.isArray(items) || items.length === 0) {
                clearResults('Nessun risultato trovato per la ricerca.');
                return;
            }

            const list = document.createElement('div');
            list.className = 'list-group';

            items.forEach((item) => {
                if (!item || typeof item.code !== 'string') {
                    return;
                }
                const buttonElement = document.createElement('button');
                buttonElement.type = 'button';
                buttonElement.className = 'list-group-item list-group-item-action d-flex flex-column align-items-start';
                buttonElement.dataset.hsCode = item.code;
                buttonElement.innerHTML = `<span class="fw-semibold">${item.code}</span><span class="small text-muted mt-1">${item.description || ''}</span>`;
                buttonElement.addEventListener('click', () => applySelection(item));

                list.appendChild(buttonElement);
            });

            resultsContainer.appendChild(list);
            resultsContainer.classList.remove('d-none');

            if (statusMessage) {
                statusMessage.textContent = `${items.length} risultato${items.length !== 1 ? 'i' : ''} trovati.`;
                statusMessage.classList.remove('d-none');
            }
        };

        const performSearch = async () => {
            if (!(queryInput instanceof HTMLInputElement)) {
                return;
            }

            const term = queryInput.value.trim();
            if (term.length < 3) {
                clearResults('Inserisci almeno 3 caratteri per cercare un codice HS.');
                return;
            }

            if (controller instanceof AbortController) {
                controller.abort();
            }
            controller = new AbortController();

            const url = new URL(endpoint, window.location.href);
            url.searchParams.set('q', term);
            url.searchParams.set('limit', String(Number.isNaN(limit) ? 10 : limit));

            setLoading(true);
            clearResults();

            try {
                const response = await fetch(url.toString(), {
                    headers: {
                        Accept: 'application/json',
                    },
                    signal: controller.signal,
                });

                if (!response.ok) {
                    clearResults(`Ricerca codici HS non riuscita (HTTP ${response.status}).`);
                    return;
                }

                const payload = await response.json();
                if (!payload || payload.success === false) {
                    clearResults(payload && payload.message ? String(payload.message) : 'Ricerca codici HS non riuscita.');
                    return;
                }

                renderResults(Array.isArray(payload.results) ? payload.results : []);
            } catch (error) {
                if (error.name === 'AbortError') {
                    return;
                }
                clearResults('Errore inatteso durante la ricerca del codice HS.');
            } finally {
                setLoading(false);
            }
        };

        if (button instanceof HTMLButtonElement) {
            button.addEventListener('click', (event) => {
                event.preventDefault();
                performSearch();
            });
        }

        if (queryInput instanceof HTMLInputElement) {
            queryInput.addEventListener('keydown', (event) => {
                if (event.key === 'Enter') {
                    event.preventDefault();
                    performSearch();
                }
            });
        }
    });
}
