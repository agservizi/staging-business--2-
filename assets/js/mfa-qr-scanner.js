(function () {
    'use strict';

    const scannerRoot = document.querySelector('[data-qr-scanner]');
    if (!scannerRoot) {
        return;
    }

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('/mfa-qr-sw.js').catch((error) => {
                console.warn('Service worker registration failed', error);
            });
        });
    }

    const completeEndpoint = scannerRoot.getAttribute('data-complete-endpoint') || '';
    const videoEl = scannerRoot.querySelector('[data-qr-video]');
    const placeholderEl = scannerRoot.querySelector('[data-qr-placeholder]');
    const statusEl = scannerRoot.querySelector('[data-qr-status]');
    const resultBox = scannerRoot.querySelector('[data-qr-result]');
    const tokenEl = scannerRoot.querySelector('[data-qr-token]');
    const messageEl = scannerRoot.querySelector('[data-qr-message]');
    const rawEl = scannerRoot.querySelector('[data-qr-raw]');
    const supportAlert = scannerRoot.querySelector('[data-qr-support]');
    const copyButton = scannerRoot.querySelector('[data-qr-copy]');
    const startButton = scannerRoot.querySelector('[data-qr-start]');
    const stopButton = scannerRoot.querySelector('[data-qr-stop]');
    const resetButton = scannerRoot.querySelector('[data-qr-reset]');
    const cameraSelect = scannerRoot.querySelector('[data-qr-camera-select]');
    const cameraHint = scannerRoot.querySelector('[data-qr-camera-hint]');
    const cameraRefreshButton = scannerRoot.querySelector('[data-qr-camera-refresh]');

    const installationButton = document.querySelector('[data-qr-install]');
    const activationBox = document.querySelector('[data-qr-activation]');
    const activationStatus = activationBox ? activationBox.querySelector('[data-qr-activation-status]') : null;
    const activationDetails = activationBox ? activationBox.querySelector('[data-qr-activation-details]') : null;
    const deviceLabelEl = activationBox ? activationBox.querySelector('[data-qr-device-label]') : null;
    const deviceUuidEl = activationBox ? activationBox.querySelector('[data-qr-device-uuid]') : null;
    const userDisplayEl = activationBox ? activationBox.querySelector('[data-qr-user-display]') : null;
    const manualForm = activationBox ? activationBox.querySelector('[data-qr-manual-form]') : null;
    const manualResetButton = activationBox ? activationBox.querySelector('[data-qr-manual-reset]') : null;
    const manualSubmitButton = activationBox ? activationBox.querySelector('[data-qr-manual-submit]') : null;

    let deferredInstallPrompt = null;

    const state = {
        detector: null,
        detectorError: '',
        scanning: false,
        detectionTimer: null,
        lastPayload: '',
        stream: null,
        activationBusy: false,
        cameraOptions: [],
        selectedDeviceId: '',
    };

    window.addEventListener('beforeinstallprompt', (event) => {
        event.preventDefault();
        deferredInstallPrompt = event;
        if (installationButton) {
            installationButton.disabled = false;
        }
    });

    installationButton && installationButton.addEventListener('click', async () => {
        if (!deferredInstallPrompt) {
            return;
        }
        installationButton.disabled = true;
        deferredInstallPrompt.prompt();
        try {
            const outcome = await deferredInstallPrompt.userChoice;
            if (outcome && outcome.outcome === 'accepted') {
                installationButton.innerHTML = '<i class="fa-solid fa-circle-check me-2"></i>App installata';
                installationButton.classList.remove('btn-outline-warning');
                installationButton.classList.add('btn-success', 'text-white');
            }
        } catch (error) {
            console.warn('Install prompt failed', error);
        }
        deferredInstallPrompt = null;
    });

    const hasMediaDevices = !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);

    const setStatus = (text) => {
        if (statusEl) {
            statusEl.textContent = text;
        }
    };

    const toggleButtons = () => {
        if (startButton) {
            startButton.disabled = state.scanning || !!state.detectorError;
        }
        if (stopButton) {
            stopButton.disabled = !state.scanning;
        }
        if (resetButton) {
            resetButton.disabled = !state.lastPayload;
        }
    };

    const showSupportMessage = (text, type = 'warning') => {
        if (!supportAlert) {
            return;
        }
        supportAlert.textContent = text;
        supportAlert.classList.remove('d-none', 'alert-warning', 'alert-danger', 'alert-info');
        supportAlert.classList.add(`alert-${type}`);
    };

    const updateCameraHint = (text) => {
        if (cameraHint) {
            cameraHint.textContent = text;
        }
    };

    const populateCameraSelect = () => {
        if (!cameraSelect) {
            return;
        }
        const select = cameraSelect;
        select.innerHTML = '';
        if (!state.cameraOptions.length) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'Nessuna fotocamera rilevata';
            select.append(option);
            select.disabled = true;
            return;
        }
        state.cameraOptions.forEach((device, index) => {
            const option = document.createElement('option');
            option.value = device.deviceId || '';
            option.textContent = device.label || `Fotocamera ${index + 1}`;
            if (state.selectedDeviceId && device.deviceId === state.selectedDeviceId) {
                option.selected = true;
            }
            select.append(option);
        });
        select.disabled = false;
    };

    const refreshCameraOptions = async () => {
        if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
            updateCameraHint('Il browser non supporta la selezione della fotocamera.');
            return;
        }
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            const cameras = devices.filter((device) => device.kind === 'videoinput');
            state.cameraOptions = cameras;
            if (!state.selectedDeviceId && cameras.length) {
                state.selectedDeviceId = cameras[0].deviceId || '';
            }
            populateCameraSelect();
            if (cameras.length) {
                updateCameraHint('Seleziona la fotocamera da utilizzare (supporta webcam esterne).');
            } else {
                updateCameraHint('Nessuna fotocamera rilevata. Collega un dispositivo e premi "Rileva camere".');
            }
        } catch (error) {
            console.warn('Camera enumeration failed', error);
            updateCameraHint('Impossibile elencare le fotocamere. Concedi i permessi e riprova.');
        }
    };

    const setActivationAlert = (type, message, icon = 'circle-info') => {
        if (!activationStatus) {
            return;
        }
        activationStatus.className = `alert alert-${type}`;
        activationStatus.innerHTML = `<i class="fa-solid fa-${icon} me-2"></i>${message}`;
    };

    const clearActivationDetails = () => {
        if (activationDetails) {
            activationDetails.classList.add('d-none');
        }
        if (deviceLabelEl) {
            deviceLabelEl.textContent = '—';
        }
        if (deviceUuidEl) {
            deviceUuidEl.textContent = '—';
        }
        if (userDisplayEl) {
            userDisplayEl.textContent = '—';
        }
    };

    const stopScanning = (reason) => {
        if (state.detectionTimer) {
            window.clearTimeout(state.detectionTimer);
            state.detectionTimer = null;
        }
        if (state.stream) {
            state.stream.getTracks().forEach((track) => track.stop());
            state.stream = null;
        }
        if (videoEl) {
            videoEl.srcObject = null;
        }
        state.scanning = false;
        toggleButtons();
        if (reason && reason !== '') {
            setStatus(reason);
        }
        if (placeholderEl && !state.lastPayload) {
            placeholderEl.classList.remove('d-none');
        }
    };

    const resetScanner = () => {
        stopScanning('In attesa di avvio.');
        state.lastPayload = '';
        if (resultBox) {
            resultBox.classList.add('d-none');
        }
        if (tokenEl) {
            tokenEl.textContent = '—';
        }
        if (rawEl) {
            rawEl.textContent = '—';
        }
        if (messageEl) {
            messageEl.textContent = '—';
        }
        if (copyButton) {
            copyButton.disabled = true;
            copyButton.dataset.copyValue = '';
        }
    };

    const normalizeToken = (value) => (value || '')
        .toString()
        .trim()
        .toLowerCase()
        .replace(/[^a-f0-9]/g, '');

    const updateResultBox = (token, message, rawPayload) => {
        if (!resultBox) {
            return;
        }
        resultBox.classList.remove('d-none');
        if (tokenEl) {
            tokenEl.textContent = token || '—';
        }
        if (messageEl) {
            messageEl.textContent = message;
        }
        if (rawEl) {
            rawEl.textContent = rawPayload || '—';
        }
        if (copyButton) {
            copyButton.disabled = !(token || rawPayload);
            copyButton.dataset.copyValue = token || rawPayload || '';
        }
    };

    const handleActivationSuccess = (payload) => {
        if (!payload || !payload.ok) {
            return;
        }
        const device = payload.device || {};
        const user = payload.user || {};
        setActivationAlert('success', 'Dispositivo attivato correttamente.', 'circle-check');
        if (activationDetails) {
            activationDetails.classList.remove('d-none');
        }
        if (deviceLabelEl) {
            deviceLabelEl.textContent = device.label || '—';
        }
        if (deviceUuidEl) {
            deviceUuidEl.textContent = device.device_uuid || '—';
        }
        if (userDisplayEl) {
            userDisplayEl.textContent = user.display || user.username || '—';
        }
    };

    const handleActivationError = (errorMessage) => {
        clearActivationDetails();
        setActivationAlert('danger', errorMessage || 'Attivazione non riuscita.', 'triangle-exclamation');
    };

    const activateToken = async (token, sourceLabel) => {
        if (!token) {
            handleActivationError('Token non valido.');
            return;
        }
        if (!completeEndpoint) {
            handleActivationError('Endpoint attivazione non configurato.');
            return;
        }
        if (state.activationBusy) {
            return;
        }
        state.activationBusy = true;
        clearActivationDetails();
        setActivationAlert('warning', `${sourceLabel} in corso...`, 'circle-notch fa-spin');
        try {
            const response = await fetch(completeEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ token }),
            });
            const data = await response.json().catch(() => ({ ok: false, error: 'Risposta non valida dal server.' }));
            if (!response.ok || !data.ok) {
                throw new Error(data.error || 'Impossibile completare l\'attivazione.');
            }
            handleActivationSuccess(data);
        } catch (error) {
            handleActivationError(error.message || 'Errore imprevisto durante l\'attivazione.');
        } finally {
            state.activationBusy = false;
        }
    };

    const ensureDetector = async () => {
        if (state.detector) {
            return state.detector;
        }
        if (state.detectorError) {
            throw new Error(state.detectorError);
        }
        if (!('BarcodeDetector' in window)) {
            state.detectorError = 'Il browser non supporta BarcodeDetector. Usa l\'inserimento manuale.';
            throw new Error(state.detectorError);
        }
        try {
            if (typeof window.BarcodeDetector.getSupportedFormats === 'function') {
                const supported = await window.BarcodeDetector.getSupportedFormats();
                if (Array.isArray(supported) && supported.length > 0 && !supported.includes('qr_code')) {
                    throw new Error('Il browser non espone il supporto ai QR code nelle API native.');
                }
            }
            state.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
            return state.detector;
        } catch (error) {
            state.detectorError = error && error.message ? error.message : 'Impossibile inizializzare lo scanner QR.';
            throw error;
        }
    };

    const scheduleDetection = () => {
        state.detectionTimer = window.setTimeout(async () => {
            if (!state.scanning || !state.detector || !videoEl || videoEl.readyState < HTMLMediaElement.HAVE_ENOUGH_DATA) {
                scheduleDetection();
                return;
            }
            try {
                const results = await state.detector.detect(videoEl);
                if (Array.isArray(results) && results.length > 0) {
                    const value = results[0].rawValue || '';
                    if (value && value !== state.lastPayload) {
                        state.lastPayload = value;
                        processPayload(value);
                        return;
                    }
                }
            } catch (error) {
                console.warn('QR detect error', error);
            }
            scheduleDetection();
        }, 450);
    };

    const processPayload = (payload) => {
        stopScanning('QR rilevato, analisi in corso...');
        if (placeholderEl) {
            placeholderEl.classList.add('d-none');
        }
        let parsed;
        let message = 'Formato QR non riconosciuto.';
        let token = '';
        try {
            parsed = JSON.parse(payload);
        } catch (error) {
            parsed = null;
        }
        if (parsed && parsed.type === 'coresuite:mfa-device-provision' && typeof parsed.token === 'string') {
            token = normalizeToken(parsed.token);
            message = 'Provisioning token riconosciuto. Avvio attivazione...';
            void activateToken(token, 'Attivazione da scansione');
        } else {
            message = 'QR letto, ma non contiene un provisioning token valido.';
        }
        updateResultBox(token, message, payload);
    };

    const startScanning = async () => {
        if (state.scanning) {
            return;
        }
        try {
            await ensureDetector();
        } catch (error) {
            showSupportMessage(error.message || 'Funzionalità non supportata sul dispositivo.', 'danger');
            toggleButtons();
            return;
        }
        if (!hasMediaDevices) {
            showSupportMessage('Il dispositivo non espone l\'accesso alla fotocamera nel browser corrente.', 'danger');
            return;
        }
        const videoConstraints = {
            width: { ideal: 1280 },
            height: { ideal: 720 },
        };
        if (state.selectedDeviceId) {
            videoConstraints.deviceId = { exact: state.selectedDeviceId };
        } else {
            videoConstraints.facingMode = { ideal: 'environment' };
        }
        try {
            const stream = await navigator.mediaDevices.getUserMedia({
                video: videoConstraints,
                audio: false,
            });
            state.stream = stream;
            const [track] = stream.getVideoTracks();
            if (track && typeof track.getSettings === 'function') {
                const settings = track.getSettings();
                if (settings.deviceId) {
                    state.selectedDeviceId = settings.deviceId;
                }
            }
            refreshCameraOptions();
            if (videoEl) {
                videoEl.srcObject = stream;
                await videoEl.play();
            }
            state.scanning = true;
            state.lastPayload = '';
            setStatus('Scanner attivo. Inquadra il QR.');
            if (placeholderEl) {
                placeholderEl.classList.add('d-none');
            }
            toggleButtons();
            scheduleDetection();
        } catch (error) {
            console.error('Impossibile avviare la fotocamera', error);
            if (error && error.name === 'OverconstrainedError' && state.selectedDeviceId) {
                showSupportMessage('Fotocamera selezionata non disponibile, ritorno a quella di default.', 'warning');
                state.selectedDeviceId = '';
                await refreshCameraOptions();
                startScanning();
                return;
            }
            showSupportMessage('Accesso alla fotocamera negato o non disponibile.', 'danger');
            stopScanning('Accesso alla fotocamera negato.');
        }
    };

    const handleCopy = async () => {
        if (!copyButton || copyButton.disabled) {
            return;
        }
        const value = copyButton.dataset.copyValue || '';
        if (!value) {
            return;
        }
        try {
            await navigator.clipboard.writeText(value);
            copyButton.classList.add('btn-success');
            copyButton.classList.remove('btn-outline-secondary');
            setTimeout(() => {
                copyButton.classList.remove('btn-success');
                copyButton.classList.add('btn-outline-secondary');
            }, 1200);
        } catch (error) {
            console.warn('Clipboard copy failed', error);
        }
    };

    const handleManualSubmit = (event) => {
        event.preventDefault();
        if (!manualForm) {
            return;
        }
        const formData = new FormData(manualForm);
        const token = normalizeToken(formData.get('token'));
        if (token.length < 32) {
            handleActivationError('Inserisci un token valido (>=32 caratteri esadecimali).');
            return;
        }
        manualSubmitButton && (manualSubmitButton.disabled = true);
        activateToken(token, 'Attivazione manuale').finally(() => {
            manualSubmitButton && (manualSubmitButton.disabled = false);
        });
    };

    const handleManualReset = () => {
        if (!manualForm) {
            return;
        }
        manualForm.reset();
        clearActivationDetails();
        setActivationAlert('info', 'Nessuna scansione ancora rilevata.', 'circle-info');
    };

    if (!completeEndpoint) {
        setActivationAlert('danger', 'Endpoint di attivazione mancante. Contatta un amministratore.', 'triangle-exclamation');
    }

    if (!hasMediaDevices) {
        showSupportMessage('Il browser non espone la fotocamera. Usa l\'inserimento manuale.', 'danger');
        if (startButton) {
            startButton.disabled = true;
        }
    }

    startButton && startButton.addEventListener('click', startScanning);
    stopButton && stopButton.addEventListener('click', () => stopScanning('Scansione interrotta.'));
    resetButton && resetButton.addEventListener('click', resetScanner);
    copyButton && copyButton.addEventListener('click', handleCopy);
    manualForm && manualForm.addEventListener('submit', handleManualSubmit);
    manualResetButton && manualResetButton.addEventListener('click', handleManualReset);
    cameraSelect && cameraSelect.addEventListener('change', () => {
        state.selectedDeviceId = cameraSelect.value || '';
        if (state.scanning) {
            stopScanning('Cambio fotocamera rilevato. Riavvio in corso...');
            startScanning();
        }
    });
    cameraRefreshButton && cameraRefreshButton.addEventListener('click', async () => {
        cameraRefreshButton.disabled = true;
        await refreshCameraOptions();
        cameraRefreshButton.disabled = false;
    });

    document.addEventListener('visibilitychange', () => {
        if (document.hidden) {
            stopScanning('Scanner in pausa.');
        }
    });

    window.addEventListener('beforeunload', () => {
        stopScanning();
    });

    resetScanner();
    toggleButtons();
    setActivationAlert('info', 'Nessuna scansione ancora rilevata.', 'circle-info');
    refreshCameraOptions();
})();
