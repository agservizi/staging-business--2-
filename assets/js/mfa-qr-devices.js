(function () {
    'use strict';

    const DATE_FORMATTER = new Intl.DateTimeFormat('it-IT', {
        dateStyle: 'medium',
        timeStyle: 'short'
    });

    const STATUS_MAP = {
        pending: { label: 'In attesa', className: 'text-bg-warning' },
        active: { label: 'Attivo', className: 'text-bg-success' },
        revoked: { label: 'Revocato', className: 'text-bg-secondary' }
    };

    class MfaQrDeviceManager {
        constructor(root) {
            this.root = root;
            this.listEndpoint = root.dataset.endpointList;
            this.createEndpoint = root.dataset.endpointCreate;
            this.revokeEndpoint = root.dataset.endpointRevoke;
            this.csrfToken = root.dataset.csrf;

            this.alertEl = root.querySelector('[data-mfa-qr-alert]');
            this.loadingEl = root.querySelector('[data-mfa-qr-loading]');
            this.listEl = root.querySelector('[data-mfa-qr-devices-list]');
            this.emptyEl = root.querySelector('[data-mfa-qr-empty]');
            this.formWrapper = root.querySelector('[data-mfa-qr-form-wrapper]');
            this.formEl = root.querySelector('[data-mfa-qr-form]');
            this.submitBtn = root.querySelector('[data-mfa-qr-submit]');
            this.provisioningBox = root.querySelector('[data-mfa-qr-provisioning]');
            this.provisioningTokenEl = root.querySelector('[data-mfa-qr-provisioning-token]');
            this.provisioningExpiryEl = root.querySelector('[data-mfa-qr-provisioning-expiry]');
            this.provisioningPayloadEl = root.querySelector('[data-mfa-qr-provisioning-payload]');
            this.copyBtn = root.querySelector('[data-mfa-qr-copy]');
            this.refreshAfterBtn = root.querySelector('[data-mfa-qr-refresh-after]');
            this.policyHintEl = root.querySelector('[data-mfa-qr-pin-policy]');

            this.toggleButtons = Array.from(root.querySelectorAll('[data-mfa-qr-toggle-form]'));
            this.refreshButtons = Array.from(root.querySelectorAll('[data-mfa-qr-refresh]'));

            this.pinPolicy = {
                attemptLimit: null,
                lockSeconds: null
            };

            this.init();
        }

        init() {
            this.bindEvents();
            this.loadDevices();
        }

        bindEvents() {
            this.toggleButtons.forEach((btn) => {
                btn.addEventListener('click', () => this.toggleForm());
            });

            this.refreshButtons.forEach((btn) => {
                btn.addEventListener('click', () => this.loadDevices(true));
            });

            this.root.querySelector('[data-mfa-qr-dismiss]')?.addEventListener('click', () => this.hideProvisioning());

            if (this.copyBtn) {
                this.copyBtn.addEventListener('click', () => this.copyProvisioningPayload());
            }

            if (this.refreshAfterBtn) {
                this.refreshAfterBtn.addEventListener('click', () => {
                    this.hideProvisioning();
                    this.loadDevices(true);
                });
            }

            if (this.formEl) {
                this.formEl.addEventListener('submit', (event) => {
                    event.preventDefault();
                    this.createDevice(new FormData(this.formEl));
                });
            }

            this.listEl?.addEventListener('click', (event) => {
                const button = event.target.closest('[data-mfa-qr-revoke]');
                if (!button) {
                    return;
                }
                const uuid = button.dataset.deviceUuid;
                if (!uuid) {
                    return;
                }
                const label = button.dataset.deviceLabel || 'questo dispositivo';
                if (!window.confirm(`Vuoi davvero disattivare ${label}?`)) {
                    return;
                }
                button.disabled = true;
                this.revokeDevice(uuid).finally(() => {
                    button.disabled = false;
                });
            });
        }

        async loadDevices(force = false) {
            if (!this.listEndpoint) {
                return;
            }

            this.clearAlert();
            if (!force && this.loadingPromise) {
                return this.loadingPromise;
            }

            this.setLoading(true);
            this.loadingPromise = fetch(this.listEndpoint, {
                method: 'GET',
                credentials: 'same-origin',
                headers: {
                    'Accept': 'application/json'
                }
            })
                .then((response) => this.parseResponse(response))
                .then((payload) => {
                    this.updatePolicy(payload.pin_policy || null);
                    const devices = Array.isArray(payload.devices) ? payload.devices : [];
                    this.renderDevices(devices);
                })
                .catch((error) => {
                    this.showAlert('danger', error.message || 'Impossibile caricare i dispositivi.');
                })
                .finally(() => {
                    this.setLoading(false);
                    this.loadingPromise = null;
                });

            return this.loadingPromise;
        }

        renderDevices(devices) {
            if (!this.listEl) {
                return;
            }
            this.listEl.innerHTML = '';

            if (!devices.length) {
                this.emptyEl?.classList.remove('d-none');
                return;
            }

            this.emptyEl?.classList.add('d-none');
            const fragment = document.createDocumentFragment();

            devices.forEach((device) => {
                const item = document.createElement('div');
                item.className = 'list-group-item d-flex flex-column flex-md-row gap-3 align-items-md-center justify-content-between';

                const statusData = STATUS_MAP[device.status] || STATUS_MAP.pending;
                const lastUsed = this.formatDate(device.last_used_at) || 'Mai utilizzato';
                const createdAt = this.formatDate(device.created_at);
                const revoked = device.status === 'revoked';
                const hint = this.buildPinHint(device);
                const hintHtml = hint ? `<div class="small mt-1 text-${hint.tone}">${escapeHtml(hint.text)}</div>` : '';

                item.innerHTML = `
                    <div>
                        <div class="fw-semibold">${escapeHtml(device.label || device.device_label || 'Dispositivo')}</div>
                        <div class="text-muted small">${revoked ? 'Revocato' : `Ultimo utilizzo: ${lastUsed}`} • Creato il ${createdAt || '—'}</div>
                        ${hintHtml}
                    </div>
                    <div class="d-flex flex-column flex-sm-row gap-2 align-items-sm-center">
                        <span class="badge ${statusData.className}">${statusData.label}</span>
                        ${revoked ? '' : `<button type="button" class="btn btn-outline-danger btn-sm" data-mfa-qr-revoke data-device-uuid="${escapeHtml(device.device_uuid)}" data-device-label="${escapeHtml(device.label || device.device_label || 'questo dispositivo')}"><i class="fa-solid fa-ban me-1"></i>Revoca</button>`}
                    </div>
                `;

                fragment.appendChild(item);
            });

            this.listEl.appendChild(fragment);
        }

        updatePolicy(policy) {
            const fallbackLimit = 5;
            const fallbackLock = 300;
            if (policy && typeof policy === 'object') {
                const attemptLimit = Number(policy.attempt_limit);
                const lockSeconds = Number(policy.lock_seconds);
                if (Number.isFinite(attemptLimit) && attemptLimit > 0) {
                    this.pinPolicy.attemptLimit = attemptLimit;
                } else if (this.pinPolicy.attemptLimit === null) {
                    this.pinPolicy.attemptLimit = fallbackLimit;
                }
                if (Number.isFinite(lockSeconds) && lockSeconds > 0) {
                    this.pinPolicy.lockSeconds = lockSeconds;
                } else if (this.pinPolicy.lockSeconds === null) {
                    this.pinPolicy.lockSeconds = fallbackLock;
                }
            } else {
                if (this.pinPolicy.attemptLimit === null) {
                    this.pinPolicy.attemptLimit = fallbackLimit;
                }
                if (this.pinPolicy.lockSeconds === null) {
                    this.pinPolicy.lockSeconds = fallbackLock;
                }
            }
            this.renderPolicyHint();
        }

        renderPolicyHint() {
            if (!this.policyHintEl) {
                return;
            }
            const { attemptLimit, lockSeconds } = this.pinPolicy;
            if (!attemptLimit || !lockSeconds) {
                this.policyHintEl.classList.add('d-none');
                this.policyHintEl.textContent = '';
                return;
            }
            const lockLabel = this.formatMinutes(lockSeconds);
            this.policyHintEl.innerHTML = `<i class="fa-solid fa-shield-halved me-2"></i>Hai ${attemptLimit} tentativi disponibili per ogni PIN. Dopo ${lockLabel} di blocco gli accessi vengono riabilitati automaticamente.`;
            this.policyHintEl.classList.remove('d-none');
        }

        buildPinHint(device) {
            const limit = Number(device.pin_attempt_limit ?? this.pinPolicy.attemptLimit ?? 0);
            const failed = Number(device.failed_attempts ?? 0);
            const attemptsLeftValue = Number(device.attempts_left);
            const attemptsLeft = Number.isFinite(attemptsLeftValue)
                ? attemptsLeftValue
                : (limit > 0 ? Math.max(0, limit - failed) : 0);

            if (device.pin_locked) {
                const unlockLabel = device.pin_locked_until ? this.formatDate(device.pin_locked_until) : '';
                const waitSeconds = Number(device.pin_lock_eta_seconds ?? this.pinPolicy.lockSeconds ?? 0);
                const waitLabel = this.formatMinutes(waitSeconds) || 'qualche minuto';
                const text = unlockLabel
                    ? `PIN bloccato fino a ${unlockLabel}.`
                    : `PIN bloccato per circa ${waitLabel}.`;
                return { tone: 'danger', text };
            }

            if (limit > 0 && attemptsLeft <= 2) {
                if (attemptsLeft <= 0) {
                    return {
                        tone: 'danger',
                        text: 'Nessun tentativo residuo: aggiorna il PIN dal dispositivo mobile per sicurezza.'
                    };
                }
                return {
                    tone: attemptsLeft === 1 ? 'danger' : 'warning',
                    text: attemptsLeft === 1
                        ? 'Ultimo tentativo disponibile prima del blocco del PIN.'
                        : `Attenzione: restano ${attemptsLeft} tentativi prima del blocco.`
                };
            }

            return null;
        }

        toggleForm(forceState) {
            if (!this.formWrapper) {
                return;
            }
            const shouldShow = typeof forceState === 'boolean'
                ? forceState
                : this.formWrapper.classList.contains('d-none');

            this.formWrapper.classList.toggle('d-none', !shouldShow);
            this.toggleButtons.forEach((btn) => {
                btn.setAttribute('aria-expanded', shouldShow ? 'true' : 'false');
                btn.classList.toggle('btn-outline-light', shouldShow);
            });

            if (!shouldShow) {
                this.formEl?.reset();
                this.setFormDisabled(false);
            }
        }

        async createDevice(formData) {
            if (!this.createEndpoint) {
                return;
            }
            this.clearAlert();
            this.setFormDisabled(true);

            const payload = {
                label: String(formData.get('label') || ''),
                pin: String(formData.get('pin') || ''),
                pin_confirmation: String(formData.get('pin_confirmation') || '')
            };

            try {
                const response = await fetch(this.createEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: JSON.stringify(payload)
                });
                const data = await this.parseResponse(response);
                this.toggleForm(false);
                this.showProvisioning(data.provisioning || {}, data.device || {});
                await this.loadDevices(true);
            } catch (error) {
                this.showAlert('danger', error.message || 'Impossibile creare il dispositivo.');
            } finally {
                this.setFormDisabled(false);
            }
        }

        async revokeDevice(deviceUuid) {
            if (!this.revokeEndpoint) {
                return Promise.resolve();
            }
            this.clearAlert();
            try {
                const response = await fetch(this.revokeEndpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-Token': this.csrfToken
                    },
                    body: JSON.stringify({ device_uuid: deviceUuid })
                });
                await this.parseResponse(response);
                await this.loadDevices(true);
                this.showAlert('success', 'Dispositivo revocato correttamente.', 4000);
            } catch (error) {
                this.showAlert('danger', error.message || 'Impossibile revocare il dispositivo.');
            }
        }

        showProvisioning(provisioning, device) {
            if (!this.provisioningBox) {
                return;
            }
            this.provisioningBox.classList.remove('d-none');
            if (this.provisioningTokenEl) {
                this.provisioningTokenEl.textContent = provisioning.token || '—';
            }
            if (this.provisioningExpiryEl) {
                this.provisioningExpiryEl.textContent = provisioning.expires_at
                    ? this.formatDate(provisioning.expires_at)
                    : '—';
            }
            if (this.provisioningPayloadEl) {
                this.provisioningPayloadEl.textContent = provisioning.qr_payload || JSON.stringify({ token: provisioning.token }, null, 2);
            }
            if (device && device.label) {
                this.showAlert('success', `Dispositivo "${device.label}" creato. Completa l'abbinamento entro pochi minuti.`, 6000);
            }
        }

        hideProvisioning() {
            this.provisioningBox?.classList.add('d-none');
        }

        async copyProvisioningPayload() {
            if (!this.provisioningPayloadEl || !navigator.clipboard) {
                return;
            }
            const text = this.provisioningPayloadEl.textContent || '';
            try {
                await navigator.clipboard.writeText(text.trim());
                this.copyBtn?.classList.add('btn-success');
                if (this.copyBtn) {
                    this.copyBtn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Copiato';
                    setTimeout(() => {
                        this.copyBtn.classList.remove('btn-success');
                        this.copyBtn.innerHTML = '<i class="fa-solid fa-copy me-1"></i>Copia payload';
                    }, 2000);
                }
            } catch (_) {
                this.showAlert('warning', 'Impossibile copiare negli appunti. Copia manualmente il testo.');
            }
        }

        setLoading(state) {
            if (this.loadingEl) {
                this.loadingEl.classList.toggle('d-none', !state);
            }
            if (state) {
                this.listEl?.classList.add('opacity-50');
            } else {
                this.listEl?.classList.remove('opacity-50');
            }
        }

        setFormDisabled(disabled) {
            if (!this.formEl) {
                return;
            }
            Array.from(this.formEl.elements).forEach((element) => {
                if ('disabled' in element) {
                    element.disabled = disabled;
                }
            });
            if (this.submitBtn) {
                this.submitBtn.disabled = disabled;
                this.submitBtn.innerHTML = disabled
                    ? '<span class="spinner-border spinner-border-sm me-2"></span>Generazione in corso...'
                    : '<i class="fa-solid fa-link me-2"></i>Genera QR di pairing';
            }
        }

        parseResponse(response) {
            return response
                .json()
                .catch(() => {
                    throw new Error('Risposta non valida dal server.');
                })
                .then((payload) => {
                    if (!response.ok || payload.ok === false) {
                        const message = payload.error || 'Operazione non riuscita.';
                        throw new Error(message);
                    }
                    return payload;
                });
        }

        showAlert(type, message, timeout) {
            if (!this.alertEl) {
                return;
            }
            this.alertEl.className = `alert alert-${type}`;
            this.alertEl.textContent = message;
            this.alertEl.classList.remove('d-none');
            if (timeout) {
                clearTimeout(this.alertTimeout);
                this.alertTimeout = setTimeout(() => this.clearAlert(), timeout);
            }
        }

        clearAlert() {
            if (this.alertEl) {
                this.alertEl.classList.add('d-none');
                this.alertEl.textContent = '';
            }
        }

        formatDate(value) {
            if (!value) {
                return '';
            }
            const normalized = typeof value === 'string' ? value.replace(' ', 'T') : value;
            const date = new Date(normalized);
            if (Number.isNaN(date.getTime())) {
                return value;
            }
            return DATE_FORMATTER.format(date);
        }

        formatMinutes(seconds) {
            const value = Number(seconds);
            if (!Number.isFinite(value) || value <= 0) {
                return '';
            }
            const minutes = Math.max(1, Math.ceil(value / 60));
            return minutes === 1 ? '1 minuto' : `${minutes} minuti`;
        }
    }

    function escapeHtml(value) {
        if (value === undefined || value === null) {
            return '';
        }
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    document.addEventListener('DOMContentLoaded', () => {
        document.querySelectorAll('[data-mfa-qr-root]').forEach((root) => {
            if (!root.dataset.endpointList) {
                return;
            }
            new MfaQrDeviceManager(root);
        });
    });
})();
