"use strict";

(function () {
    const module = {
        init() {
            const form = document.querySelector('#cciaa-form');
            if (!form) {
                return;
            }

            this.form = form;
            this.schema = this.safeParse(form.dataset.cameraliSchema, {});
            this.fieldsets = this.schema.fieldsets || {};
            this.values = this.safeParse(form.dataset.cameraliValues, {});
            this.categoryInput = form.querySelector('#categoria_macro');
            this.certificateSelect = form.querySelector('[data-role="cciaa-certificate-select"]');
            this.tooltip = form.querySelector('[data-role="cciaa-certificate-tooltip"]');
            this.dynamicContainer = form.querySelector('[data-role="cciaa-dynamic-fields"]');
            this.elencoWarning = form.querySelector('[data-role="elenco-warning"]');
            this.formaGiuridica = form.querySelector('[data-role="forma-giuridica"]');
            this.categoryCards = Array.from(form.querySelectorAll('[data-category-option]'));
            this.activeFieldNames = [];

            this.bindCategoryCards();
            this.syncCategoryButtons();
            this.populateCertificates(this.categoryInput.value);
            this.bindCertificateSelect();
            this.updateCertificateView();
        },
        safeParse(payload, fallback) {
            if (!payload) {
                return fallback;
            }
            try {
                return JSON.parse(payload);
            } catch (error) {
                console.warn('Certi³ Camerale: JSON non valido', error);
                return fallback;
            }
        },
        bindCategoryCards() {
            this.categoryCards.forEach((button) => {
                button.addEventListener('click', () => {
                    const value = button.dataset.categoryOption;
                    if (!value || !this.schema.categories?.[value]) {
                        return;
                    }
                    this.categoryCards.forEach((card) => card.classList.toggle('active', card === button));
                    this.categoryInput.value = value;
                    this.populateCertificates(value);
                    this.updateCertificateView();
                });
            });
        },
        syncCategoryButtons() {
            this.categoryCards.forEach((card) => {
                const isActive = card.dataset.categoryOption === this.categoryInput.value;
                card.classList.toggle('active', isActive);
            });
        },
        populateCertificates(categoryKey) {
            const certificates = this.schema.categories?.[categoryKey]?.certificates || {};
            const previous = this.certificateSelect.value;
            this.certificateSelect.innerHTML = '';
            Object.entries(certificates).forEach(([key, definition]) => {
                const option = document.createElement('option');
                option.value = key;
                option.textContent = definition.label;
                this.certificateSelect.appendChild(option);
            });
            if (!Object.keys(certificates).length) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Nessun certificato disponibile';
                this.certificateSelect.appendChild(placeholder);
            }
            if (previous && certificates[previous]) {
                this.certificateSelect.value = previous;
            }
            if (!this.certificateSelect.value) {
                this.certificateSelect.value = Object.keys(certificates)[0] || '';
            }
            this.toggleElencoSociAvailability();
        },
        bindCertificateSelect() {
            this.certificateSelect.addEventListener('change', () => this.updateCertificateView());
            if (this.formaGiuridica) {
                this.formaGiuridica.addEventListener('change', () => {
                    this.toggleElencoSociAvailability();
                });
            }
        },
        toggleElencoSociAvailability() {
            if (!this.certificateSelect) {
                return;
            }
            const disable = (this.formaGiuridica?.value || '') === 'ditta_individuale';
            Array.from(this.certificateSelect.options).forEach((option) => {
                if (option.value === 'elenco_soci') {
                    option.disabled = disable;
                    if (disable && option.selected) {
                        this.certificateSelect.selectedIndex = 0;
                    }
                }
            });
            if (this.elencoWarning) {
                if (disable) {
                    this.elencoWarning.removeAttribute('hidden');
                } else {
                    this.elencoWarning.setAttribute('hidden', 'hidden');
                }
            }
        },
        updateCertificateView() {
            const category = this.categoryInput.value;
            const certificateId = this.certificateSelect.value;
            const certificate = this.schema.categories?.[category]?.certificates?.[certificateId] || null;
            this.currentCertificate = certificate;
            if (this.tooltip) {
                this.tooltip.textContent = certificate?.tooltip || 'Seleziona un certificato per leggere la descrizione.';
            }
            this.renderDynamicFieldsets(certificate);
        },
        renderDynamicFieldsets(certificate) {
            if (!this.dynamicContainer) {
                return;
            }
            this.dynamicContainer.innerHTML = '';
            this.activeFieldNames = [];
            if (!certificate || !(certificate.fieldsets || []).length) {
                this.dynamicContainer.innerHTML = '<p class="text-muted mb-0">Questo certificato non richiede parametri aggiuntivi.</p>';
                return;
            }
            const fragment = document.createDocumentFragment();
            certificate.fieldsets.forEach((entry) => {
                const fieldsetKey = typeof entry === 'string' ? entry : entry.key;
                const baseDefinition = this.fieldsets[fieldsetKey];
                if (!baseDefinition) {
                    return;
                }
                const box = document.createElement('div');
                box.className = 'bg-white rounded-3 shadow-sm p-3 mb-3';
                const title = document.createElement('h3');
                title.className = 'h6 mb-1';
                title.textContent = entry?.options?.title || baseDefinition.title || 'Parametri';
                box.appendChild(title);
                if (baseDefinition.description) {
                    const description = document.createElement('p');
                    description.className = 'text-muted small mb-2';
                    description.textContent = baseDefinition.description;
                    box.appendChild(description);
                }
                const row = document.createElement('div');
                row.className = 'row g-3';
                (baseDefinition.fields || []).forEach((field) => {
                    this.activeFieldNames.push(field.name);
                    row.appendChild(this.renderField(field));
                });
                box.appendChild(row);
                fragment.appendChild(box);
            });
            this.dynamicContainer.appendChild(fragment);
            this.bindDynamicFieldListeners();
        },
        renderField(field) {
            const col = document.createElement('div');
            col.className = 'col-md-6';
            const type = field.type || 'text';
            const value = this.values[field.name] ?? '';
            if (type === 'checkbox') {
                const wrapper = document.createElement('div');
                wrapper.className = 'form-check mt-4';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.id = field.name;
                input.name = field.name;
                input.checked = value === '1';
                input.dataset.cciaaField = field.name;
                if (field.required) {
                    input.required = true;
                }
                const label = document.createElement('label');
                label.className = 'form-check-label';
                label.setAttribute('for', field.name);
                label.textContent = field.label;
                wrapper.appendChild(input);
                wrapper.appendChild(label);
                col.appendChild(wrapper);
                return col;
            }
            const label = document.createElement('label');
            label.className = 'form-label';
            label.setAttribute('for', field.name);
            label.textContent = field.label;
            col.appendChild(label);
            if (type === 'textarea') {
                const textarea = document.createElement('textarea');
                textarea.className = 'form-control';
                textarea.id = field.name;
                textarea.name = field.name;
                textarea.rows = field.rows || 3;
                textarea.placeholder = field.placeholder || '';
                textarea.value = value;
                textarea.required = Boolean(field.required);
                textarea.dataset.cciaaField = field.name;
                col.appendChild(textarea);
                return col;
            }
            if (type === 'select') {
                const select = document.createElement('select');
                select.className = 'form-select';
                select.id = field.name;
                select.name = field.name;
                select.required = Boolean(field.required);
                select.dataset.cciaaField = field.name;
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Seleziona...';
                select.appendChild(placeholder);
                (field.options || []).forEach((option) => {
                    const opt = document.createElement('option');
                    opt.value = option.value;
                    opt.textContent = option.label;
                    if (option.value === value) {
                        opt.selected = true;
                    }
                    select.appendChild(opt);
                });
                if (value && value !== '') {
                    select.value = value;
                }
                col.appendChild(select);
                return col;
            }
            const input = document.createElement('input');
            input.type = type;
            input.className = 'form-control';
            input.id = field.name;
            input.name = field.name;
            input.placeholder = field.placeholder || '';
            input.value = value;
            input.required = Boolean(field.required);
            input.dataset.cciaaField = field.name;
            col.appendChild(input);
            return col;
        },
        bindDynamicFieldListeners() {
            const inputs = this.dynamicContainer.querySelectorAll('[data-cciaa-field]');
            inputs.forEach((input) => {
                const handler = () => this.captureDynamicValue(input);
                input.addEventListener('input', handler);
                input.addEventListener('change', handler);
                this.captureDynamicValue(input);
            });
        },
        captureDynamicValue(input) {
            const fieldName = input.dataset.cciaaField;
            if (!fieldName) {
                return;
            }
            if (input.type === 'checkbox') {
                this.values[fieldName] = input.checked ? '1' : '0';
            } else {
                this.values[fieldName] = input.value;
            }
        },
    };

    document.addEventListener('DOMContentLoaded', () => module.init());
})();
