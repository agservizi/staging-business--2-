(() => {
    const board = document.getElementById('pickupLiveBoard');
    if (!board) {
        return;
    }

    const feedUrl = board.dataset.feedUrl || '/api/pickup-report-feed.php';
    const list = document.getElementById('pickupLiveEvents');
    const updated = document.getElementById('pickupLiveUpdated');
    let sinceId = 0;

    const render = (events) => {
        if (!list) {
            return;
        }
        if (!events.length) {
            list.innerHTML = '<div class="text-muted small py-3">Nessuna nuova segnalazione.</div>';
            return;
        }
        list.innerHTML = events.map((event) => `
            <a class="list-group-item list-group-item-action" href="${event.url || '#'}">
                <div class="d-flex justify-content-between gap-2">
                    <strong>${event.customerName || 'Cliente'}</strong>
                    <small class="text-muted">${event.createdAt || ''}</small>
                </div>
                <div class="small text-muted">${event.message || ''}</div>
            </a>
        `).join('');
    };

    const poll = async () => {
        try {
            const response = await fetch(`${feedUrl}?since_id=${sinceId}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            const events = Array.isArray(payload.events) ? payload.events : [];
            if (events.length) {
                try {
                    const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmwhBSuBzvLZiTYIGWi77+efTRAMUKfj8LZjHAY4kdfyzHksBSR3x/DdkEAKFF606euoVRQKRp/g8r5sIQUrgc7y2Yk2CBlou+/nn00QDFCn4/C2YxwGOJHX8sx5LAUkd8fw3ZBAC');
                    audio.volume = 0.2;
                    audio.play();
                } catch (error) {
                    console.debug('Live board sound skipped', error);
                }
            }
            sinceId = Math.max(sinceId, parseInt(payload.lastId || sinceId, 10) || sinceId);
            render(events);
            if (updated) {
                updated.textContent = 'Aggiornato alle ' + new Date().toLocaleTimeString('it-IT');
            }
        } catch (error) {
            console.debug('Live board poll failed', error);
        }
    };

    poll();
    window.setInterval(poll, 8000);
})();
