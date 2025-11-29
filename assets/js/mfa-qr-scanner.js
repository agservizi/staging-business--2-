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
    const challengeLookupEndpoint = scannerRoot.getAttribute('data-challenge-lookup') || '';
    const challengeDecisionEndpoint = scannerRoot.getAttribute('data-challenge-decision') || '';
    const csrfToken = scannerRoot.getAttribute('data-csrf') || '';
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
    const devicePanel = activationBox ? activationBox.querySelector('[data-qr-device-panel]') : null;
    const deviceSummaryLabel = activationBox ? activationBox.querySelector('[data-qr-device-name]') : null;
    const deviceSummaryUuid = activationBox ? activationBox.querySelector('[data-qr-device-id]') : null;
    const deviceSummaryHint = activationBox ? activationBox.querySelector('[data-qr-device-hint]') : null;
    const deviceClearButton = activationBox ? activationBox.querySelector('[data-qr-device-clear]') : null;
    const manualForm = activationBox ? activationBox.querySelector('[data-qr-manual-form]') : null;
    const manualResetButton = activationBox ? activationBox.querySelector('[data-qr-manual-reset]') : null;
    const manualSubmitButton = activationBox ? activationBox.querySelector('[data-qr-manual-submit]') : null;

    const approvalCard = document.querySelector('[data-qr-approval]');
    const approvalAlert = approvalCard ? approvalCard.querySelector('[data-qr-approval-alert]') : null;
    const challengeDetailsList = approvalCard ? approvalCard.querySelector('[data-qr-challenge-details]') : null;
    const challengeTokenEl = approvalCard ? approvalCard.querySelector('[data-qr-challenge-token]') : null;
    const challengeIpEl = approvalCard ? approvalCard.querySelector('[data-qr-challenge-ip]') : null;
    const challengeAgentEl = approvalCard ? approvalCard.querySelector('[data-qr-challenge-agent]') : null;
    const challengeIssuedEl = approvalCard ? approvalCard.querySelector('[data-qr-challenge-issued]') : null;
    const challengeExpiresEl = approvalCard ? approvalCard.querySelector('[data-qr-challenge-expiry]') : null;
    const approvalForm = approvalCard ? approvalCard.querySelector('[data-qr-approval-form]') : null;
    const pinInput = approvalCard ? approvalCard.querySelector('[data-qr-pin-input]') : null;
    const approveButton = approvalCard ? approvalCard.querySelector('[data-qr-approve]') : null;
    const denyButton = approvalCard ? approvalCard.querySelector('[data-qr-deny]') : null;

    let deferredInstallPrompt = null;
    const supportsNativeDetector = 'BarcodeDetector' in window;
    const supportsJsQr = typeof window.jsQR === 'function';
    const fallbackCanvas = supportsJsQr ? document.createElement('canvas') : null;
    const fallbackContext = fallbackCanvas ? fallbackCanvas.getContext('2d') : null;
    let fallbackNoticeShown = false;
    const DEVICE_STORAGE_KEY = 'coresuite.mfaDevice';
    const dateFormatter = new Intl.DateTimeFormat('it-IT', { dateStyle: 'short', timeStyle: 'medium' });

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
    const challengeState = {
        token: '',
        status: 'idle',
        expiresAt: null,
    };
    const parseJsonResponse = async (response) => {
        const payload = await response.json().catch(() => {
            throw new Error('Risposta non valida dal server.');
        });
        if (!response.ok || payload.ok === false) {
            const message = payload.error || 'Operazione non riuscita.';
            throw new Error(message);
        }
        return payload;
    };

    const formatDateTime = (value) => {
        if (!value) {
            return '—';
        }
        const normalized = typeof value === 'string' ? value.replace(' ', 'T') : value;
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return dateFormatter.format(date);
    };

    const loadStoredDevice = () => {
        try {
            const raw = localStorage.getItem(DEVICE_STORAGE_KEY);
            if (!raw) {
                return null;
            }
            const parsed = JSON.parse(raw);
            if (!parsed || typeof parsed.device_uuid !== 'string') {
                return null;
            }
            return parsed;
        } catch (error) {
            console.warn('Impossibile leggere il dispositivo salvato', error);
            return null;
        }
    };

    const saveStoredDevice = (device, user) => {
        if (!device || !device.device_uuid) {
            return;
        }
        const payload = {
            device_uuid: device.device_uuid,
            label: device.label || device.device_label || 'Dispositivo QR',
            user_id: device.user_id || user?.id || null,
            user_display: user?.display || null,
        };
        try {
            localStorage.setItem(DEVICE_STORAGE_KEY, JSON.stringify(payload));
        } catch (error) {
            console.warn('Impossibile salvare il dispositivo locale', error);
        }
        storedDevice = payload;
        renderDeviceSummary();
        return payload;
    };

    const clearStoredDevice = () => {
        try {
            localStorage.removeItem(DEVICE_STORAGE_KEY);
        } catch (error) {
            console.warn('Impossibile rimuovere il dispositivo locale', error);
        }
        storedDevice = null;
        renderDeviceSummary();
    };

    let storedDevice = loadStoredDevice();

    const renderDeviceSummary = () => {
        if (!devicePanel || !deviceSummaryLabel || !deviceSummaryUuid || !deviceSummaryHint) {
            return;
        }
        if (storedDevice) {
            devicePanel.classList.remove('opacity-50');
            deviceSummaryLabel.textContent = storedDevice.label || 'Dispositivo QR';
            deviceSummaryUuid.textContent = storedDevice.device_uuid || '—';
            deviceSummaryHint.textContent = 'Questo dispositivo verrà usato per approvare i login con PIN.';
            deviceClearButton?.classList.remove('d-none');
        } else {
            deviceSummaryLabel.textContent = 'Nessun dispositivo associato a questa web app';
            deviceSummaryUuid.textContent = '—';
            deviceSummaryHint.textContent = 'Attiva un dispositivo dal profilo per collegarlo qui.';
            deviceClearButton?.classList.add('d-none');
        }
    };

    const setApprovalAlert = (type, message, icon = 'circle-info') => {
        if (!approvalAlert) {
            return;
        }
        approvalAlert.className = `alert alert-${type}`;
        approvalAlert.innerHTML = '';
        const iconEl = document.createElement('i');
        const iconClasses = icon
            .split(' ')
            .filter(Boolean)
            .map((cls) => (cls.startsWith('fa-') ? cls : `fa-${cls}`));
        iconEl.className = ['fa-solid', ...iconClasses, 'me-2'].join(' ');
        approvalAlert.appendChild(iconEl);
        approvalAlert.appendChild(document.createTextNode(message));
    };

    const setApprovalBusy = (busy) => {
        if (approveButton) {
            approveButton.disabled = busy;
        }
        if (denyButton) {
            denyButton.disabled = busy;
        }
        if (pinInput) {
            pinInput.disabled = busy;
        }
    };

    const resetApprovalState = () => {
        challengeState.token = '';
        challengeState.status = 'idle';
        challengeState.expiresAt = null;
        challengeDetailsList?.classList.add('d-none');
        approvalForm?.classList.add('d-none');
        if (pinInput) {
            pinInput.value = '';
            pinInput.disabled = false;
        }
        setApprovalAlert('info', 'Inquadra un QR dinamico per iniziare.');
    };

    const updateChallengeDetails = (challenge) => {
        if (!challenge) {
            return;
        }
        challengeDetailsList?.classList.remove('d-none');
        if (challengeTokenEl) {
            challengeTokenEl.textContent = challenge.token || '—';
        }
        if (challengeIpEl) {
            challengeIpEl.textContent = challenge.ip_address || '—';
        }
        if (challengeAgentEl) {
            challengeAgentEl.textContent = challenge.user_agent || '—';
        }
        if (challengeIssuedEl) {
            challengeIssuedEl.textContent = formatDateTime(challenge.created_at);
        }
        if (challengeExpiresEl) {
            challengeExpiresEl.textContent = formatDateTime(challenge.expires_at);
        }
        challengeState.expiresAt = challenge.expires_at || null;
    };

    const ensureDeviceReadyForApproval = () => {
        if (storedDevice) {
            return true;
        }
        setApprovalAlert('warning', 'Questo browser non ha un dispositivo associato. Completa prima l\'attivazione.', 'triangle-exclamation');
        approvalForm?.classList.add('d-none');
        return false;
    };

    const fetchChallengeDetails = async (token) => {
        if (!challengeLookupEndpoint) {
            setApprovalAlert('danger', 'Endpoint dettagli challenge non configurato.', 'triangle-exclamation');
            return;
        }
        setApprovalBusy(true);
        setApprovalAlert('info', 'Richiesta rilevata. Recupero dettagli...', 'circle-notch fa-spin');
        try {
            const response = await fetch(challengeLookupEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ token }),
            });
            const data = await parseJsonResponse(response);
            updateChallengeDetails(data.challenge);
            challengeState.status = data.challenge?.status || 'pending';
            if (ensureDeviceReadyForApproval()) {
                approvalForm?.classList.remove('d-none');
                pinInput?.focus();
                setApprovalAlert('success', 'Inserisci il PIN per approvare questa richiesta.', 'shield-keyhole');
            }
        } catch (error) {
            console.warn('Impossibile recuperare la challenge', error);
            setApprovalAlert('danger', error.message || 'Impossibile ottenere i dettagli della richiesta.', 'triangle-exclamation');
            resetApprovalState();
        } finally {
            setApprovalBusy(false);
        }
    };

    const handleChallengeToken = (token) => {
        if (!token) {
            return;
        }
        challengeState.token = token;
        challengeState.status = 'pending';
        approvalForm?.classList.add('d-none');
        pinInput && (pinInput.value = '');
        fetchChallengeDetails(token);
    };

    const submitChallengeDecision = async (action) => {
        if (!challengeState.token) {
            setApprovalAlert('warning', 'Non c\'è alcuna richiesta attiva da gestire.', 'triangle-exclamation');
            return;
        }
        if (!storedDevice) {
            setApprovalAlert('warning', 'Collega prima un dispositivo a questa web app.', 'triangle-exclamation');
            return;
        }
        const pin = (pinInput?.value || '').trim();
        if (!/^[0-9]{4,8}$/.test(pin)) {
            setApprovalAlert('danger', 'Inserisci il PIN (4-8 cifre).', 'triangle-exclamation');
            pinInput?.focus();
            return;
        }
        if (!challengeDecisionEndpoint) {
            setApprovalAlert('danger', 'Endpoint decisione non configurato.', 'triangle-exclamation');
            return;
        }
        setApprovalBusy(true);
        setApprovalAlert('info', action === 'approve' ? 'Invio approvazione...' : 'Invio annullamento...', 'circle-notch fa-spin');
        try {
            const response = await fetch(challengeDecisionEndpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({
                    token: challengeState.token,
                    device_uuid: storedDevice.device_uuid,
                    pin,
                    action,
                }),
            });
            const data = await parseJsonResponse(response);
            challengeState.status = data.challenge?.status || action;
            if (challengeState.status === 'approved') {
                setApprovalAlert('success', 'Richiesta approvata. Torna alla schermata di login per completare l\'accesso.', 'circle-check');
            } else if (challengeState.status === 'denied') {
                setApprovalAlert('warning', 'Richiesta negata. Aggiorna la schermata di login.', 'triangle-exclamation');
            } else {
                setApprovalAlert('info', `Richiesta aggiornata (${challengeState.status}).`, 'circle-info');
            }
            approvalForm?.classList.add('d-none');
        } catch (error) {
            console.warn('Decisione challenge fallita', error);
            setApprovalAlert('danger', error.message || 'Impossibile aggiornare la richiesta.', 'triangle-exclamation');
        } finally {
            setApprovalBusy(false);
            if (pinInput) {
                pinInput.value = '';
            }
        }
    };

    const handleApprovalSubmit = (event) => {
        event.preventDefault();
        submitChallengeDecision('approve');
    };

    const handleApprovalDeny = () => {
        submitChallengeDecision('deny');
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
    const hasDetectionEngine = () => supportsNativeDetector || supportsJsQr;

    const setStatus = (text) => {
        if (statusEl) {
            statusEl.textContent = text;
        }
    };

    const toggleButtons = () => {
        if (startButton) {
            const detectionReady = hasMediaDevices && hasDetectionEngine();
            startButton.disabled = state.scanning || !detectionReady;
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

    const showFallbackNotice = () => {
        if (supportsJsQr && !fallbackNoticeShown) {
            showSupportMessage('BarcodeDetector non disponibile: uso motore compatibilità jsQR.', 'info');
            fallbackNoticeShown = true;
        }
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
        saveStoredDevice(device, user);
        setApprovalAlert('info', 'Dispositivo associato. Da ora puoi approvare i login con questo browser.', 'shield-keyhole');
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
            const data = await parseJsonResponse(response);
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
        if (!supportsNativeDetector) {
            state.detectorError = 'BarcodeDetector non disponibile in questo browser.';
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

    const detectWithNative = async () => {
        if (!state.detector || !videoEl) {
            return '';
        }
        const results = await state.detector.detect(videoEl);
        if (Array.isArray(results) && results.length > 0) {
            return results[0].rawValue || '';
        }
        return '';
    };

    const detectWithJsQr = () => {
        if (!supportsJsQr || !fallbackCanvas || !fallbackContext || !videoEl) {
            return '';
        }
        const width = videoEl.videoWidth || videoEl.clientWidth || 640;
        const height = videoEl.videoHeight || videoEl.clientHeight || 480;
        if (!width || !height) {
            return '';
        }
        fallbackCanvas.width = width;
        fallbackCanvas.height = height;
        fallbackContext.drawImage(videoEl, 0, 0, width, height);
        try {
            const imageData = fallbackContext.getImageData(0, 0, width, height);
            const jsResult = window.jsQR(imageData.data, width, height, { inversionAttempts: 'attemptBoth' });
            return jsResult && typeof jsResult.data === 'string' ? jsResult.data : '';
        } catch (error) {
            console.warn('jsQR detect error', error);
            return '';
        }
    };

    const scheduleDetection = () => {
        if (!state.scanning) {
            return;
        }
        const delay = state.detector ? 350 : 650;
        state.detectionTimer = window.setTimeout(async () => {
            if (!state.scanning || !videoEl || videoEl.readyState < HTMLMediaElement.HAVE_ENOUGH_DATA) {
                scheduleDetection();
                return;
            }
            try {
                let value = '';
                if (state.detector) {
                    value = await detectWithNative();
                } else if (supportsJsQr) {
                    value = detectWithJsQr();
                }
                if (value && value !== state.lastPayload) {
                    state.lastPayload = value;
                    processPayload(value);
                    return;
                }
            } catch (error) {
                console.warn('QR detect error', error);
            }
            scheduleDetection();
        }, delay);
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
            updateResultBox(token, message, payload);
            return;
        }
        if (parsed && parsed.type === 'coresuite:mfa-login-challenge' && typeof parsed.token === 'string') {
            token = normalizeToken(parsed.token);
            message = 'Richiesta login rilevata. Recupero dettagli...';
            updateResultBox(token, message, payload);
            handleChallengeToken(token);
            return;
        }
        const fallbackToken = normalizeToken(payload);
        if (fallbackToken.length >= 40) {
            message = 'QR dinamico riconosciuto. Recupero dettagli della richiesta...';
            updateResultBox(fallbackToken, message, payload);
            handleChallengeToken(fallbackToken);
            return;
        }
        message = 'QR letto, ma non contiene un token di provisioning o una richiesta di login validi.';
        updateResultBox('', message, payload);
    };

    const startScanning = async () => {
        if (state.scanning) {
            return;
        }
        if (!hasDetectionEngine()) {
            showSupportMessage('Questo browser non supporta la scansione QR. Usa il token manuale.', 'danger');
            toggleButtons();
            return;
        }
        if (supportsNativeDetector) {
            try {
                await ensureDetector();
            } catch (error) {
                console.warn('BarcodeDetector initialization failed', error);
                state.detector = null;
                state.detectorError = error && error.message ? error.message : 'BarcodeDetector non disponibile.';
                if (supportsJsQr) {
                    showFallbackNotice();
                } else {
                    showSupportMessage(state.detectorError, 'danger');
                    toggleButtons();
                    return;
                }
            }
        } else if (supportsJsQr) {
            showFallbackNotice();
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

    if (!hasDetectionEngine()) {
        showSupportMessage('Nessun motore di decodifica QR disponibile. Aggiorna il browser o usa il token manuale.', 'danger');
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
    approvalForm && approvalForm.addEventListener('submit', handleApprovalSubmit);
    denyButton && denyButton.addEventListener('click', handleApprovalDeny);
    deviceClearButton && deviceClearButton.addEventListener('click', () => {
        clearStoredDevice();
        resetApprovalState();
        setApprovalAlert('info', 'Dispositivo rimosso. Attiva nuovamente il pairing per approvare i login.', 'circle-info');
    });
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
    renderDeviceSummary();
    resetApprovalState();
    refreshCameraOptions();
})();
