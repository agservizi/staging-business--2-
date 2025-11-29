(function () {
    'use strict';

    const pollIntervalMs = 4000;
    const challengeTtlMs = 180000;

    class MfaChoiceController {
        constructor(root) {
            this.root = root;
            this.createUrl = root.dataset.challengeCreate;
            this.statusUrl = root.dataset.challengeStatus;
            this.initialToken = root.dataset.challengeToken || '';
            this.csrf = root.dataset.csrf;

            this.startButton = document.querySelector('[data-mfa-qr-start]');
            this.cancelButton = document.querySelector('[data-mfa-qr-cancel]');
            this.progressBox = document.querySelector('[data-mfa-qr-progress]');
            this.statusText = document.querySelector('[data-mfa-qr-status-text]');
            this.progressBar = document.querySelector('[data-mfa-qr-progressbar]');
            this.tokenDisplay = document.querySelector('[data-mfa-qr-token]');
            this.copyTokenBtn = document.querySelector('[data-mfa-qr-copy-token]');
            this.timerLabel = document.querySelector('[data-mfa-qr-timer]');
            this.alertBox = document.querySelector('[data-mfa-alert]');

            this.activeToken = this.initialToken;
            this.pollTimer = null;
            this.expireTimer = null;
            this.challengeStart = null;

            this.bindEvents();
            if (this.activeToken) {
                this.resumeChallenge();
            }
        }

        bindEvents() {
            this.startButton?.addEventListener('click', () => this.startChallenge());
            this.cancelButton?.addEventListener('click', () => this.resetChallenge());
            this.copyTokenBtn?.addEventListener('click', () => this.copyToken());
        }

        async startChallenge() {
            if (!this.createUrl) {
                return;
            }
            this.toggleButtons(true);
            this.showProgress('Generazione token in corso...', 15);
            this.clearAlert();

            try {
                const response = await fetch(this.createUrl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-Token': this.csrf
                    }
                });
                const payload = await this.parseResponse(response);
                this.handleChallengePayload(payload.challenge);
            } catch (error) {
                this.showAlert('danger', error.message || 'Impossibile creare la richiesta.');
                this.toggleButtons(false);
                this.hideProgress();
            }
        }

        resumeChallenge() {
            if (!this.activeToken) {
                return;
            }
            this.toggleButtons(true);
            this.showProgress('Richiesta attiva. Inquadra il QR e conferma dal dispositivo.', 50);
            this.schedulePolling();
            this.startExpiryCountdown();
        }

        handleChallengePayload(challenge) {
            if (!challenge || !challenge.token) {
                throw new Error('Challenge non valida.');
            }
            this.activeToken = challenge.token;
            this.challengeStart = Date.now();
            this.showProgress('Richiesta creata. Apri l\'app mobile e conferma.', 70, challenge.token);
            this.schedulePolling();
            this.startExpiryCountdown(challenge.expires_at);
        }

        schedulePolling() {
            clearTimeout(this.pollTimer);
            this.pollTimer = setTimeout(() => this.pollStatus(), pollIntervalMs);
        }

        async pollStatus() {
            if (!this.statusUrl || !this.activeToken) {
                return;
            }
            try {
                const url = new URL(this.statusUrl, window.location.origin);
                url.searchParams.set('token', this.activeToken);
                const response = await fetch(url.toString(), {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                });
                const payload = await this.parseResponse(response);
                this.handleStatus(payload.challenge);
            } catch (error) {
                this.showAlert('warning', error.message || 'Errore durante il controllo stato. Riprovo...');
                this.schedulePolling();
            }
        }

        handleStatus(challenge) {
            if (!challenge) {
                return;
            }
            const { status } = challenge;
            if (status === 'approved') {
                this.showProgress('Accesso approvato! Reindirizzamento in corso...', 100);
                this.finalizeLogin();
                return;
            }
            if (status === 'denied') {
                this.showAlert('danger', 'La richiesta è stata rifiutata dal dispositivo.');
                this.resetChallenge();
                return;
            }
            if (status === 'expired') {
                this.showAlert('warning', 'La richiesta è scaduta. Generane una nuova.');
                this.resetChallenge();
                return;
            }
            this.showProgress('In attesa di conferma dal dispositivo...', 70);
            this.schedulePolling();
        }

        async finalizeLogin() {
            clearTimeout(this.pollTimer);
            clearTimeout(this.expireTimer);
            this.toggleButtons(false);

            try {
                const response = await fetch('mfa-qrsuccess.php', {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-Token': this.csrf
                    },
                    body: JSON.stringify({ token: this.activeToken })
                });
                const payload = await this.parseResponse(response);
                window.location.href = payload.redirect || 'dashboard.php';
            } catch (error) {
                this.showAlert('danger', error.message || 'Errore durante il completamento login.');
                this.resetChallenge();
            }
        }

        startExpiryCountdown(expiresAt) {
            clearTimeout(this.expireTimer);
            if (!expiresAt) {
                expiresAt = new Date(Date.now() + challengeTtlMs).toISOString();
            }
            const expiry = new Date(expiresAt).getTime();
            if (Number.isNaN(expiry)) {
                return;
            }

            const update = () => {
                const remaining = expiry - Date.now();
                if (remaining <= 0) {
                    this.timerLabel.textContent = 'Richiesta scaduta.';
                    clearTimeout(this.expireTimer);
                    this.handleStatus({ status: 'expired' });
                    return;
                }
                const seconds = Math.ceil(remaining / 1000);
                this.timerLabel.textContent = `La richiesta scade tra ${seconds} secondi.`;
                this.expireTimer = setTimeout(update, 1000);
            };

            update();
        }

        copyToken() {
            if (!navigator.clipboard || !this.activeToken) {
                return;
            }
            navigator.clipboard.writeText(this.activeToken).then(() => {
                this.copyTokenBtn?.classList.add('btn-success');
                this.copyTokenBtn.textContent = 'Copiato';
                setTimeout(() => {
                    this.copyTokenBtn?.classList.remove('btn-success');
                    this.copyTokenBtn.textContent = 'Copia token';
                }, 2000);
            }).catch(() => {
                this.showAlert('warning', 'Impossibile copiare il token. Copialo manualmente.');
            });
        }

        resetChallenge() {
            clearTimeout(this.pollTimer);
            clearTimeout(this.expireTimer);
            this.activeToken = '';
            this.challengeStart = null;
            this.hideProgress();
            this.toggleButtons(false);
        }

        showProgress(message, progressValue, token) {
            if (!this.progressBox) {
                return;
            }
            this.progressBox.classList.remove('d-none');
            if (this.statusText) {
                this.statusText.textContent = message;
            }
            if (this.progressBar) {
                this.progressBar.style.width = `${progressValue}%`;
            }
            if (token && this.tokenDisplay) {
                this.tokenDisplay.textContent = token;
            }
            this.cancelButton?.classList.remove('d-none');
        }

        hideProgress() {
            this.progressBox?.classList.add('d-none');
            this.tokenDisplay.textContent = '';
            this.timerLabel.textContent = '';
            this.cancelButton?.classList.add('d-none');
        }

        toggleButtons(active) {
            if (this.startButton) {
                this.startButton.disabled = active;
            }
            if (this.cancelButton) {
                this.cancelButton.disabled = !active;
            }
        }

        parseResponse(response) {
            return response.json().catch(() => {
                throw new Error('Risposta non valida dal server.');
            }).then((payload) => {
                if (!response.ok || payload.ok === false) {
                    throw new Error(payload.error || 'Operazione non riuscita.');
                }
                return payload;
            });
        }

        showAlert(type, message) {
            if (!this.alertBox) {
                return;
            }
            this.alertBox.className = `alert alert-${type}`;
            this.alertBox.textContent = message;
            this.alertBox.classList.remove('d-none');
        }

        clearAlert() {
            this.alertBox?.classList.add('d-none');
            this.alertBox.textContent = '';
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const root = document.querySelector('[data-mfa-choice]');
        if (root) {
            new MfaChoiceController(root);
        }
    });
})();
