'use strict';

(function () {
    const module = {
        init() {
            this.bindStatusCards();
            this.bindCategorySelect();
            this.bindIntestatarioToggle();
        },
        bindStatusCards() {
            const cards = document.querySelectorAll('.certi-metric');
            if (!cards.length) {
                return;
            }
            cards.forEach((card) => {
                card.addEventListener('click', () => {
                    const statuses = (card.dataset.certiStatus || '').split(',').filter(Boolean);
                    if (!statuses.length) {
                        return;
                    }
                    const url = new URL(window.location.href);
                    const current = url.searchParams.get('stato') || '';
                    if (statuses.includes(current)) {
                        url.searchParams.delete('stato');
                    } else {
                        url.searchParams.set('stato', statuses[0]);
                    }
                    url.searchParams.delete('page');
                    window.location.assign(url.pathname + url.search);
                });
            });
        },
        bindCategorySelect() {
            const select = document.querySelector('#tipo_certificato');
            const categoryRadios = document.querySelectorAll('input[name="categoria"]');
            if (!select || !categoryRadios.length) {
                return;
            }
            let optionsData = {};
            try {
                const payload = select.dataset.certiOptions;
                if (payload) {
                    optionsData = JSON.parse(payload);
                }
            } catch (error) {
                console.warn('Certi³: impossibile leggere il catalogo certificati', error);
            }

            const refreshSelect = (category) => {
                if (!optionsData[category]) {
                    return;
                }
                const previousValue = select.value;
                select.innerHTML = '<option value="">Seleziona</option>';
                Object.entries(optionsData[category]).forEach(([value, label]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = label;
                    select.appendChild(option);
                });
                if (optionsData[category][previousValue]) {
                    select.value = previousValue;
                }
                select.dispatchEvent(new Event('change', { bubbles: true }));
            };

            const toggleCatFields = (category) => {
                document.querySelectorAll('[data-cat-only]').forEach((node) => {
                    const target = node.getAttribute('data-cat-only');
                    if (target === category) {
                        node.removeAttribute('hidden');
                    } else {
                        node.setAttribute('hidden', 'hidden');
                    }
                });
            };

            const updateForCategory = (category) => {
                refreshSelect(category);
                toggleCatFields(category);
            };

            categoryRadios.forEach((radio) => {
                radio.addEventListener('change', () => {
                    if (radio.checked) {
                        updateForCategory(radio.value);
                    }
                });
                if (radio.checked) {
                    updateForCategory(radio.value);
                }
            });
        },
        bindIntestatarioToggle() {
            const personaFields = document.querySelectorAll('[data-intestatario="persona"]');
            const aziendaFields = document.querySelectorAll('[data-intestatario="azienda"]');
            const radios = document.querySelectorAll('input[name="intestatario_tipo"]');
            if (!radios.length) {
                return;
            }
            const toggle = (type) => {
                personaFields.forEach((node) => {
                    node.hidden = type !== 'persona';
                });
                aziendaFields.forEach((node) => {
                    node.hidden = type !== 'azienda';
                });
            };
            radios.forEach((radio) => {
                radio.addEventListener('change', () => {
                    if (radio.checked) {
                        toggle(radio.value);
                    }
                });
                if (radio.checked) {
                    toggle(radio.value);
                }
            });
        },
    };

    document.addEventListener('DOMContentLoaded', () => module.init());
})();
