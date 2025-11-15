(function () {
    'use strict';

    function populateRecipientFields(payload) {
        if (!payload || typeof payload !== 'object') {
            return;
        }

        const mapping = {
            company_name: 'consignee_company_name',
            address: 'consignee_address',
            zip: 'consignee_zip',
            city: 'consignee_city',
            province: 'consignee_province',
            country: 'consignee_country',
            contact_name: 'consignee_contact_name',
            phone: 'consignee_phone',
            mobile: 'consignee_mobile',
            email: 'consignee_email',
        };

        Object.keys(mapping).forEach(function (key) {
            const elementId = mapping[key];
            const field = document.getElementById(elementId);
            if (!field) {
                return;
            }

            const value = payload[key] || '';
            if (field.tagName === 'SELECT') {
                field.value = value;
                field.dispatchEvent(new Event('change', { bubbles: true }));
            } else {
                field.value = value;
                field.dispatchEvent(new Event('input', { bubbles: true }));
            }
        });

        const pudoIdField = document.getElementById('pudo_id');
        const pudoDescriptionField = document.getElementById('pudo_description');
        if (pudoIdField) {
            pudoIdField.value = payload.pudo_id || '';
        }
        if (pudoDescriptionField) {
            pudoDescriptionField.value = payload.pudo_description || '';
        }

        const pudoSelected = document.querySelector('[data-pudo-selected]');
        if (pudoSelected) {
            const description = payload.pudo_description || payload.pudo_id || '';
            pudoSelected.textContent = description ? 'PUDO selezionato: ' + description : '';
        }

        const saveLabelInput = document.querySelector('[data-recipient-label]');
        if (saveLabelInput && !saveLabelInput.value) {
            const companyField = document.getElementById('consignee_company_name');
            if (companyField && companyField.value) {
                saveLabelInput.value = companyField.value;
            }
        }
    }

    function setupRecipientSelect() {
        const select = document.querySelector('[data-recipient-select]');
        if (!select) {
            return;
        }

        select.addEventListener('change', function () {
            const option = select.selectedOptions[0];
            if (!option) {
                return;
            }

            const rawData = option.getAttribute('data-recipient');
            if (!rawData) {
                return;
            }

            try {
                const payload = JSON.parse(rawData);
                populateRecipientFields(payload);
            } catch (error) {
                console.warn('Impossibile applicare il destinatario salvato:', error);
            }
        });

        if (select.value) {
            const option = select.selectedOptions[0];
            if (option) {
                const rawData = option.getAttribute('data-recipient');
                if (rawData) {
                    try {
                        const payload = JSON.parse(rawData);
                        populateRecipientFields(payload);
                    } catch (error) {
                        console.warn('Impossibile applicare il destinatario salvato:', error);
                    }
                }
            }
        }
    }

    function setupSaveRecipientToggle() {
        const toggle = document.querySelector('[data-save-recipient-toggle]');
        const container = document.querySelector('[data-save-recipient-fields]');
        if (!toggle || !container) {
            return;
        }

        const updateVisibility = function () {
            container.style.display = toggle.checked ? '' : 'none';
            if (toggle.checked) {
                const labelInput = document.querySelector('[data-recipient-label]');
                if (labelInput && !labelInput.value) {
                    const companyField = document.getElementById('consignee_company_name');
                    if (companyField && companyField.value) {
                        labelInput.value = companyField.value;
                    }
                }
            }
        };

        toggle.addEventListener('change', updateVisibility);
        updateVisibility();
    }

    document.addEventListener('DOMContentLoaded', function () {
        setupRecipientSelect();
        setupSaveRecipientToggle();
    });
})();
