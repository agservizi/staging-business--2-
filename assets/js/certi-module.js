'use strict';

(function () {
    const module = {
        init() {
            this.bindStatusCards();
            this.setupAnprForm();
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
        setupAnprForm() {
            const form = document.querySelector('#certi-create-form');
            if (!form) {
                return;
            }

            const schema = this.safeParse(form.dataset.anprSchema, null);
            const values = this.safeParse(form.dataset.anprValues, {});
            const categorySelect = form.querySelector('[data-role="anpr-category"]');
            const subcategorySelect = form.querySelector('[data-role="anpr-subcategory"]');
            const certificateSelect = form.querySelector('[data-role="anpr-certificate"]');
            const tooltip = form.querySelector('[data-role="anpr-tooltip-text"]');
            const dynamicContainer = form.querySelector('[data-role="anpr-fieldsets"]');
            const payloadPreview = document.querySelector('#payload-preview');
            const summaryPreview = document.querySelector('#summary-preview');

            if (!schema || !categorySelect || !subcategorySelect || !certificateSelect || !dynamicContainer) {
                return;
            }

            if (form.dataset.selectedCategory) {
                categorySelect.value = form.dataset.selectedCategory;
            }
            if (form.dataset.selectedSubcategory) {
                subcategorySelect.value = form.dataset.selectedSubcategory;
            }
            if (form.dataset.selectedCertificate) {
                certificateSelect.value = form.dataset.selectedCertificate;
            }

            this.anprContext = {
                form,
                schema,
                fieldsets: schema.fieldsets || {},
                values: values || {},
                categorySelect,
                subcategorySelect,
                certificateSelect,
                tooltip,
                dynamicContainer,
                payloadPreview,
                summaryPreview,
                activeFieldNames: [],
            };

            const hiddenCategory = form.querySelector('input[name="categoria"]');
            if (hiddenCategory) {
                hiddenCategory.value = categorySelect.value;
            }

            this.populateSubcategories(categorySelect.value);
            this.populateCertificates(categorySelect.value, subcategorySelect.value);
            this.bindSelects();
            this.bindUtilityButtons();
            this.updateCertificateView();
        },
        safeParse(payload, fallback) {
            if (!payload) {
                return fallback;
            }
            try {
                return JSON.parse(payload);
            } catch (error) {
                console.warn('Certi³: JSON non valido', error);
                return fallback;
            }
        },
        bindSelects() {
            const ctx = this.anprContext;
            if (!ctx) {
                return;
            }
            const hiddenCategory = ctx.form.querySelector('input[name="categoria"]');

            ctx.categorySelect.addEventListener('change', () => {
                if (hiddenCategory) {
                    hiddenCategory.value = ctx.categorySelect.value;
                }
                this.populateSubcategories(ctx.categorySelect.value);
                this.populateCertificates(ctx.categorySelect.value, ctx.subcategorySelect.value);
                this.updateCertificateView();
            });

            ctx.subcategorySelect.addEventListener('change', () => {
                this.populateCertificates(ctx.categorySelect.value, ctx.subcategorySelect.value);
                this.updateCertificateView();
            });

            ctx.certificateSelect.addEventListener('change', () => this.updateCertificateView());
        },
        populateSubcategories(categoryKey) {
            const ctx = this.anprContext;
            if (!ctx) {
                return;
            }
            const categories = ctx.schema.categories || {};
            const subcategories = categories[categoryKey]?.subcategories || {};
            const previous = ctx.subcategorySelect.value;
            ctx.subcategorySelect.innerHTML = '';
            Object.entries(subcategories).forEach(([key, definition]) => {
                const option = document.createElement('option');
                option.value = key;
                option.textContent = definition.label;
                ctx.subcategorySelect.appendChild(option);
            });
            if (!Object.keys(subcategories).length) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Nessuna sottocategoria disponibile';
                ctx.subcategorySelect.appendChild(placeholder);
            }
            if (previous && subcategories[previous]) {
                ctx.subcategorySelect.value = previous;
            }
            if (!ctx.subcategorySelect.value) {
                const first = Object.keys(subcategories)[0] || '';
                ctx.subcategorySelect.value = first;
            }
        },
        populateCertificates(categoryKey, subcategoryKey) {
            const ctx = this.anprContext;
            if (!ctx) {
                return;
            }
            const categories = ctx.schema.categories || {};
            const certificates = categories[categoryKey]?.subcategories?.[subcategoryKey]?.certificates || {};
            const previous = ctx.certificateSelect.value;
            ctx.certificateSelect.innerHTML = '';
            Object.entries(certificates).forEach(([key, definition]) => {
                const option = document.createElement('option');
                option.value = key;
                option.textContent = definition.label;
                ctx.certificateSelect.appendChild(option);
            });
            if (!Object.keys(certificates).length) {
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Nessun certificato disponibile';
                ctx.certificateSelect.appendChild(placeholder);
            }
            if (previous && certificates[previous]) {
                ctx.certificateSelect.value = previous;
            }
            if (!ctx.certificateSelect.value) {
                const first = Object.keys(certificates)[0] || '';
                ctx.certificateSelect.value = first;
            }
        },
        updateCertificateView() {
            const ctx = this.anprContext;
            if (!ctx) {
                return;
            }
            const category = ctx.categorySelect.value;
            const subcategory = ctx.subcategorySelect.value;
            const certificateId = ctx.certificateSelect.value;
            const certificate = this.resolveCertificate(category, subcategory, certificateId);
            ctx.currentCertificate = certificate;
            if (ctx.tooltip) {
                ctx.tooltip.textContent = certificate?.tooltip || 'Seleziona un certificato per visualizzare i dettagli.';
            }
            this.renderFieldsets(category, subcategory, certificateId);
            this.updateSummaryPreview();
        },
        resolveCertificate(category, subcategory, certificate) {
            const ctx = this.anprContext;
            if (!ctx) {
                return null;
            }
            return ctx.schema.categories?.[category]?.subcategories?.[subcategory]?.certificates?.[certificate] || null;
        },
        renderFieldsets(category, subcategory, certificateId) {
            const ctx = this.anprContext;
            if (!ctx) {
                return;
            }
            const certificate = this.resolveCertificate(category, subcategory, certificateId);
            const container = ctx.dynamicContainer;
            container.innerHTML = '';
            ctx.activeFieldNames = [];

            if (!certificate || !(certificate.fieldsets || []).length) {
                container.innerHTML = '<p class="text-muted mb-0">Nessun campo aggiuntivo richiesto per questo certificato.</p>';
                return;
            }

            const fragment = document.createDocumentFragment();
            certificate.fieldsets.forEach((entry) => {
                const normalized = this.normalizeFieldset(entry);
                const baseDefinition = ctx.fieldsets[normalized.key];
                if (!baseDefinition) {
                    return;
                }
                const definition = Object.assign({}, baseDefinition, normalized.options || {});
                const box = document.createElement('div');
                box.className = 'bg-white border rounded-3 p-3 mb-3 shadow-sm';

                const title = document.createElement('h3');
                title.className = 'h6 mb-1';
                title.textContent = definition.title || baseDefinition.title || 'Campo dinamico';
                box.appendChild(title);

                if (definition.description) {
                    const description = document.createElement('p');
                    description.className = 'text-muted small mb-2';
                    description.textContent = definition.description;
                    box.appendChild(description);
                }

                const row = document.createElement('div');
                row.className = 'row g-3 mt-1';

                (definition.fields || []).forEach((field) => {
                    ctx.activeFieldNames.push(field.name);
                    const rawValue = ctx.values[field.name];
                    row.appendChild(this.renderField(field, rawValue, certificate));
                });

                box.appendChild(row);
                fragment.appendChild(box);
            });

            container.appendChild(fragment);
            this.bindDynamicFieldListeners();
        },
        normalizeFieldset(entry) {
            if (typeof entry === 'string') {
                return { key: entry, options: {} };
            }
            return {
                key: entry?.key || '',
                options: entry?.options || {},
            };
        },
        renderField(field, rawValue, certificate) {
            const col = document.createElement('div');
            col.className = 'col-md-6';
            const type = field.type || 'text';
            const value = typeof rawValue === 'undefined' ? '' : rawValue;
            const requiredFields = Array.isArray(certificate?.required_fields) ? certificate.required_fields : [];
            const isRequired = Boolean(field.required) || requiredFields.includes(field.name);

            if (type === 'checkbox') {
                const wrapper = document.createElement('div');
                wrapper.className = 'form-check mt-4';
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.className = 'form-check-input';
                input.id = field.name;
                input.name = field.name;
                input.checked = value === '1';
                input.dataset.anprField = field.name;
                if (isRequired) {
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
                textarea.value = value;
                textarea.placeholder = field.placeholder || '';
                textarea.required = isRequired;
                textarea.dataset.anprField = field.name;
                col.appendChild(textarea);
                return col;
            }

            if (type === 'select') {
                const select = document.createElement('select');
                select.className = 'form-select';
                select.id = field.name;
                select.name = field.name;
                select.required = isRequired;
                select.dataset.anprField = field.name;
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
            input.value = value;
            input.placeholder = field.placeholder || '';
            input.required = isRequired;
            input.dataset.anprField = field.name;
            col.appendChild(input);
            return col;
        },
        bindDynamicFieldListeners() {
            const ctx = this.anprContext;
            if (!ctx) {
                return;
            }
            const inputs = ctx.dynamicContainer.querySelectorAll('[data-anpr-field]');
            inputs.forEach((input) => {
                const handler = () => this.captureDynamicValue(input);
                input.addEventListener('input', handler);
                input.addEventListener('change', handler);
                this.captureDynamicValue(input);
            });
        },
        captureDynamicValue(input) {
            const ctx = this.anprContext;
            if (!ctx) {
                return;
            }
            const fieldName = input.dataset.anprField;
            if (!fieldName) {
                return;
            }
            if (input.type === 'checkbox') {
                ctx.values[fieldName] = input.checked ? '1' : '0';
            } else {
                ctx.values[fieldName] = input.value;
            }
        },
        bindUtilityButtons() {
            const generateButton = document.querySelector('#generate-payload');
            const downloadButton = document.querySelector('#download-summary');

            if (generateButton) {
                generateButton.addEventListener('click', async () => {
                    const payload = this.buildPayload();
                    const serialized = JSON.stringify(payload, null, 2);
                    this.updatePayloadPreview(serialized);
                    try {
                        await navigator.clipboard.writeText(serialized);
                        this.flashButton(generateButton, 'Copiato!');
                    } catch (error) {
                        console.warn('Clipboard non disponibile', error);
                        this.flashButton(generateButton, 'Generato');
                    }
                });
            }

            if (downloadButton) {
                downloadButton.addEventListener('click', () => {
                    const summary = this.buildSummaryText();
                    if (!summary.trim()) {
                        this.flashButton(downloadButton, 'Nessun dato');
                        return;
                    }
                    this.downloadText('riepilogo-certificato.txt', summary);
                });
            }
        },
        updatePayloadPreview(serialized) {
            const ctx = this.anprContext;
            if (ctx?.payloadPreview) {
                ctx.payloadPreview.textContent = serialized;
            }
        },
        updateSummaryPreview() {
            const ctx = this.anprContext;
            if (!ctx?.summaryPreview) {
                return;
            }
            const summary = this.buildSummaryText();
            ctx.summaryPreview.textContent = summary || 'Seleziona un certificato per vedere il riepilogo.';
        },
        buildSummaryText() {
            const ctx = this.anprContext;
            if (!ctx) {
                return '';
            }
            const certificate = ctx.currentCertificate;
            if (!certificate) {
                return '';
            }
            const nome = ctx.form.querySelector('#nome')?.value || '';
            const cognome = ctx.form.querySelector('#cognome')?.value || '';
            const comune = ctx.form.querySelector('#comune')?.value || '';
            const lines = [
                `Richiedente: ${(nome + ' ' + cognome).trim()}`,
                comune ? `Comune di riferimento: ${comune}` : '',
                certificate.category_label ? `Categoria: ${certificate.category_label}` : '',
                certificate.subcategory_label ? `Sottocategoria: ${certificate.subcategory_label}` : '',
                certificate.label ? `Certificato: ${certificate.label}` : '',
            ];
            return lines.filter(Boolean).join('\n');
        },
        downloadText(filename, content) {
            const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(link.href);
        },
        flashButton(button, text) {
            const original = button.innerHTML;
            button.innerHTML = text;
            button.disabled = true;
            window.setTimeout(() => {
                button.innerHTML = original;
                button.disabled = false;
            }, 1500);
        },
        buildPayload() {
            const ctx = this.anprContext;
            if (!ctx) {
                return {};
            }
            const formData = new FormData(ctx.form);
            const base = {};
            formData.forEach((value, key) => {
                base[key] = value;
            });

            const dynamicValues = {};
            (ctx.activeFieldNames || []).forEach((name) => {
                const stored = ctx.values[name];
                if (typeof stored === 'undefined') {
                    return;
                }
                if (stored === '1' || stored === '0') {
                    dynamicValues[name] = stored === '1';
                } else {
                    dynamicValues[name] = stored;
                }
            });

            return {
                categoria: base.categoria || 'comunale',
                macro_categoria: ctx.categorySelect.value,
                sottocategoria: ctx.subcategorySelect.value,
                tipo_certificato: ctx.certificateSelect.value,
                urgenza: base.urgenza,
                note_interne: base.note_interne || null,
                dati_intestatario: {
                    nome: base.nome || '',
                    cognome: base.cognome || '',
                    cf_piva: base.cf_piva || '',
                    data_nascita: base.data_nascita || '',
                    comune: base.comune || '',
                    provincia: base.provincia || '',
                    indirizzo: base.indirizzo || '',
                    cap: base.cap || '',
                    istat: base.istat || '',
                    email: base.email || '',
                    telefono: base.telefono || '',
                    comune_nascita: base.comune_nascita || '',
                    provincia_nascita: base.provincia_nascita || '',
                },
                anpr: {
                    certificate: {
                        id: ctx.certificateSelect.value,
                        label: ctx.currentCertificate?.label || '',
                        category_label: ctx.currentCertificate?.category_label || '',
                        subcategory_label: ctx.currentCertificate?.subcategory_label || '',
                    },
                    fieldsets: ctx.currentCertificate?.fieldsets || [],
                    values: dynamicValues,
                    schema_version: 'anpr_v1',
                },
            };
        },
    };

    document.addEventListener('DOMContentLoaded', () => {
        module.init();
        window.getPayload = () => module.buildPayload();
    });
})();
