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

            let schema = {};
            try {
                const payload = select.dataset.certiSchema;
                if (payload) {
                    schema = JSON.parse(payload);
                }
            } catch (error) {
                console.warn('Certi³: impossibile leggere lo schema certificati', error);
            }

            const getCertificates = (category) => (schema[category]?.certificates) || {};

            const toggleSection = (section, visible) => {
                document.querySelectorAll(`[data-section="${section}"]`).forEach((node) => {
                    if (visible) {
                        node.removeAttribute('hidden');
                    } else {
                        node.setAttribute('hidden', 'hidden');
                    }
                });
            };

            const updateSections = (definition) => {
                const requirements = definition?.requirements || {};
                toggleSection('birth', Boolean(requirements.birth_data));
                toggleSection('marriage', Boolean(requirements.marriage_data));
                toggleSection('company', Boolean(requirements.company_data));
                toggleSection('property', Boolean(requirements.property_data));
            };

            const updateIntestatario = (definition) => {
                const allowed = definition?.allowed_intestatario ?? ['persona', 'azienda'];
                const radios = document.querySelectorAll('input[name="intestatario_tipo"]');
                let hasChecked = false;
                radios.forEach((radio) => {
                    radio.disabled = !allowed.includes(radio.value);
                    if (radio.checked) {
                        hasChecked = true;
                        if (radio.disabled) {
                            radio.checked = false;
                            hasChecked = false;
                        }
                    }
                });
                if (!hasChecked) {
                    const fallback = Array.from(radios).find((radio) => !radio.disabled && allowed.includes(radio.value));
                    if (fallback) {
                        fallback.checked = true;
                        fallback.dispatchEvent(new Event('change', { bubbles: true }));
                    }
                }
            };

            const applyDefinition = (category, certificate) => {
                const definition = getCertificates(category)[certificate] || null;
                updateSections(definition);
                updateIntestatario(definition);
            };

            const refreshSelect = (category) => {
                const certificates = getCertificates(category);
                const previousValue = select.value;
                select.innerHTML = '<option value="">Seleziona</option>';
                Object.entries(certificates).forEach(([value, info]) => {
                    const option = document.createElement('option');
                    option.value = value;
                    option.textContent = info.label;
                    select.appendChild(option);
                });
                if (certificates[previousValue]) {
                    select.value = previousValue;
                } else {
                    const first = Object.keys(certificates)[0];
                    if (first) {
                        select.value = first;
                    } else {
                        select.value = '';
                    }
                }
                select.dispatchEvent(new Event('change', { bubbles: true }));
            };

            select.addEventListener('change', () => {
                const category = document.querySelector('input[name="categoria"]:checked')?.value;
                if (!category) {
                    return;
                }
                applyDefinition(category, select.value);
            });

            const updateForCategory = (category) => {
                refreshSelect(category);
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
