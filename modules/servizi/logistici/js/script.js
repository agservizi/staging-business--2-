document.addEventListener('DOMContentLoaded', () => {
    const root = document.querySelector('.pickup-module');
    if (!root) {
        return;
    }

    const resolveFormAction = form => {
        if (!form) {
            return window.location.href;
        }
        const attr = typeof form.getAttribute === 'function' ? form.getAttribute('action') : '';
        return typeof attr === 'string' && attr.trim() !== '' ? attr.trim() : window.location.href;
    };

    const showToast = (message, type = 'info') => {
        if (window.CSToast && typeof window.CSToast.show === 'function') {
            window.CSToast.show(message, type);
            return;
        }
        if (typeof window.alert === 'function') {
            window.alert(message);
        }
    };

    const showModal = (message, type = 'warning') => {
        if (window.CSFlashModal && typeof window.CSFlashModal.show === 'function') {
            window.CSFlashModal.show(message, type);
            return;
        }
        showToast(message, type);
    };

    const confirmAction = async (message, options = {}) => {
        if (window.CSConfirm && typeof window.CSConfirm.confirm === 'function') {
            return window.CSConfirm.confirm(message, options);
        }
        if (typeof window.confirm === 'function') {
            return window.confirm(message);
        }
        return false;
    };

    const statusForms = root.querySelectorAll('[data-pickup-status-form]');
    statusForms.forEach(form => {
        const select = form.querySelector('select[name="status"]');
        if (!select) {
            return;
        }

        select.addEventListener('change', async event => {
            event.preventDefault();
            const submitButton = form.querySelector('button[type="submit"]');
            if (submitButton) {
                submitButton.disabled = true;
            }
            select.disabled = true;

            try {
                const targetUrl = resolveFormAction(form);
                const response = await fetch(targetUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                    },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });

                if (!response.ok) {
                    throw new Error('Aggiornamento stato fallito');
                }

                const raw = await response.text();
                let payload;
                try {
                    payload = raw ? JSON.parse(raw) : {};
                } catch (parseError) {
                    console.error('Pickup status update non-JSON response:', raw);
                    throw new Error('Risposta non valida dal server.');
                }
                if (payload?.success) {
                    const badge = form.closest('tr')?.querySelector('[data-status-badge]');
                    if (badge) {
                        badge.dataset.status = payload.statusKey || '';
                        badge.textContent = payload.statusLabel || '';
                    }
                    const updatedAtCell = form.closest('tr')?.querySelector('[data-updated-at]');
                    if (updatedAtCell) {
                        updatedAtCell.textContent = payload.updatedAt || '';
                    }
                } else {
                    throw new Error(payload?.message || 'Aggiornamento stato non riuscito');
                }
            } catch (error) {
                console.error(error);
                form.classList.add('shake');
                setTimeout(() => form.classList.remove('shake'), 700);
                showToast(error.message || 'Aggiornamento stato non riuscito', 'danger');
            } finally {
                select.disabled = false;
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        });
    });

    const notificationForms = root.querySelectorAll('[data-pickup-notification-form]');
    notificationForms.forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = form.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
            }
            try {
                const targetUrl = resolveFormAction(form);
                const response = await fetch(targetUrl, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                    },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });

                const raw = await response.text();
                let payload;
                try {
                    payload = raw ? JSON.parse(raw) : {};
                } catch (parseError) {
                    console.error('Pickup notification non-JSON response:', raw);
                    throw new Error('Risposta non valida dal server.');
                }

                if (!response.ok || !payload?.success) {
                    throw new Error(payload?.message || 'Invio notifica fallito');
                }

                const logTarget = document.querySelector('[data-notification-log]');
                if (logTarget && payload.entryHtml) {
                    logTarget.insertAdjacentHTML('afterbegin', payload.entryHtml);
                }
                if (payload.fallbackUrl) {
                    const opened = window.open(payload.fallbackUrl, '_blank');
                    if (!opened) {
                        showModal((payload.message || 'Apri WhatsApp per completare l\'invio') + '\n\nLink: ' + payload.fallbackUrl, 'warning');
                        return;
                    }
                }
                showToast(payload.message || 'Notifica inviata', 'success');
            } catch (error) {
                console.error(error);
                showToast(error.message || 'Invio notifica non riuscito', 'danger');
            } finally {
                if (submit) {
                    submit.disabled = false;
                }
            }
        });
    });

    const packageSelects = root.querySelectorAll('[data-pickup-package-select]');
    packageSelects.forEach(select => {
        const form = select.closest('form');
        if (!form) {
            return;
        }
        const channelField = form.querySelector('input[name="channel"]');
        const channel = channelField ? channelField.value : '';
        const recipientField = form.querySelector('input[name="recipient"]');
        const subjectField = form.querySelector('input[name="subject"]');
        const messageField = form.querySelector('textarea[name="message"]');

        const clearFields = () => {
            if (recipientField) {
                recipientField.value = '';
            }
            if (subjectField) {
                subjectField.value = '';
            }
            if (messageField) {
                messageField.value = '';
            }
        };

        const updateFromSelection = () => {
            const option = select.options[select.selectedIndex];
            if (!option) {
                return;
            }

            if (option.value === '') {
                clearFields();
                return;
            }

            if (channel === 'whatsapp') {
                if (recipientField && option.dataset.phone) {
                    recipientField.value = option.dataset.phone;
                }
                if (messageField && option.dataset.message) {
                    messageField.value = option.dataset.message;
                }
                return;
            }

            if (channel === 'email') {
                if (recipientField && typeof option.dataset.email === 'string') {
                    recipientField.value = option.dataset.email;
                }
                if (subjectField && typeof option.dataset.subject === 'string') {
                    subjectField.value = option.dataset.subject;
                }
                if (messageField && typeof option.dataset.message === 'string') {
                    messageField.value = option.dataset.message;
                }
            }
        };

        select.addEventListener('change', updateFromSelection);
        updateFromSelection();
    });

    const checkinForms = root.querySelectorAll('[data-pickup-checkin-qr]');
    checkinForms.forEach(form => {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const submit = form.querySelector('button[type="submit"]');
            if (submit) {
                submit.disabled = true;
            }

            try {
                const response = await fetch(resolveFormAction(form), {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                    },
                    body: new FormData(form),
                    credentials: 'same-origin',
                });

                const raw = await response.text();
                let payload;
                try {
                    payload = raw ? JSON.parse(raw) : {};
                } catch (parseError) {
                    console.error('Pickup check-in QR non-JSON response:', raw);
                    throw new Error('Risposta non valida dal server.');
                }

                if (!response.ok || !payload?.success) {
                    throw new Error(payload?.message || 'Generazione QR non riuscita');
                }

                const container = form.closest('[data-checkin-qr-container]');
                const output = container ? container.querySelector('[data-checkin-qr-output]') : null;
                if (output) {
                    output.innerHTML = '';
                    if (payload.qrUrl) {
                        const img = document.createElement('img');
                        img.src = payload.qrUrl;
                        img.alt = 'QR check-in';
                        img.className = 'img-fluid rounded border mb-2';
                        output.appendChild(img);

                        const link = document.createElement('a');
                        link.href = payload.qrUrl;
                        link.target = '_blank';
                        link.rel = 'noopener';
                        link.className = 'btn btn-sm btn-outline-warning';
                        link.textContent = 'Apri in nuova scheda';
                        output.appendChild(link);
                    } else {
                        output.textContent = payload.message || 'QR generato.';
                    }
                }

                showToast(payload.message || 'QR generato', 'success');
            } catch (error) {
                console.error(error);
                showToast(error.message || 'Generazione QR non riuscita', 'danger');
            } finally {
                if (submit) {
                    submit.disabled = false;
                }
            }
        });
    });

    const archiveButton = document.querySelector('[data-pickup-archive-button]');
    if (archiveButton) {
        archiveButton.addEventListener('click', async event => {
            event.preventDefault();
            const days = archiveButton.dataset.days || '30';
            const confirmed = await confirmAction(`Archiviare i pacchi ritirati da più di ${days} giorni?`, {
                confirmClass: 'btn btn-danger',
                title: 'Archiviazione pacchi',
                confirmLabel: 'Archivia'
            });
            if (!confirmed) {
                return;
            }
            archiveButton.disabled = true;
            try {
                const formData = new FormData();
                formData.append('action', 'archive_packages');
                formData.append('_token', archiveButton.dataset.csrf || '');

                const response = await fetch(archiveButton.dataset.action || window.location.href, {
                    method: 'POST',
                    headers: {
                        'Accept': 'application/json',
                    },
                    body: formData,
                    credentials: 'same-origin',
                });
                const raw = await response.text();
                let payload;
                try {
                    payload = raw ? JSON.parse(raw) : {};
                } catch (parseError) {
                    console.error('Pickup archive non-JSON response:', raw);
                    throw new Error('Risposta non valida dal server.');
                }
                if (!response.ok || !payload?.success) {
                    throw new Error(payload?.message || 'Archiviazione non riuscita');
                }
                showToast(payload.message || 'Archiviazione completata', 'success');
                window.location.reload();
            } catch (error) {
                console.error(error);
                showToast(error.message || 'Errore durante l\'archiviazione', 'danger');
            } finally {
                archiveButton.disabled = false;
            }
        });
    }
});
