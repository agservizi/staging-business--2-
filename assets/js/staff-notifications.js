(() => {
    const root = document.getElementById('staffNotificationsRoot');
    if (!root) {
        return;
    }

    const feedUrl = root.dataset.feedUrl || '/api/notifications.php';
    const badge = document.getElementById('staffNotificationsBadge');
    const list = document.getElementById('staffNotificationsList');
    let sinceId = parseInt(root.dataset.sinceId || '0', 10) || 0;
    let audioCtx = null;

    const playPing = () => {
        try {
            if (!audioCtx) {
                audioCtx = new (window.AudioContext || window.webkitAudioContext)();
            }
            const osc = audioCtx.createOscillator();
            const gain = audioCtx.createGain();
            osc.type = 'sine';
            osc.frequency.value = 880;
            gain.gain.value = 0.04;
            osc.connect(gain);
            gain.connect(audioCtx.destination);
            osc.start();
            osc.stop(audioCtx.currentTime + 0.15);
        } catch (error) {
            console.debug('Notification sound skipped', error);
        }
    };

    const renderItems = (items) => {
        if (!list) {
            return;
        }
        if (!items.length) {
            list.innerHTML = '<li class="dropdown-item text-muted small">Nessuna notifica recente.</li>';
            return;
        }
        list.innerHTML = items.map((item) => {
            const severity = item.severity === 'danger' ? 'text-danger' : (item.severity === 'warning' ? 'text-warning' : 'text-info');
            const url = item.url ? ` href="${item.url}"` : '';
            return `<li><a class="dropdown-item"${url}><div class="fw-semibold ${severity}">${item.title || 'Notifica'}</div><div class="small text-muted">${item.message || ''}</div></a></li>`;
        }).join('');
    };

    const poll = async () => {
        try {
            const response = await fetch(`${feedUrl}?since_id=${sinceId}&limit=20`, {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                return;
            }
            const payload = await response.json();
            const items = Array.isArray(payload.items) ? payload.items : [];
            if (items.length && payload.lastId > sinceId) {
                playPing();
            }
            sinceId = Math.max(sinceId, parseInt(payload.lastId || sinceId, 10) || sinceId);
            if (badge) {
                const unread = parseInt(payload.unread || items.length, 10) || 0;
                badge.textContent = unread > 99 ? '99+' : String(unread);
                badge.classList.toggle('d-none', unread <= 0);
            }
            renderItems(items);
        } catch (error) {
            console.debug('Staff notifications poll failed', error);
        }
    };

    poll();
    window.setInterval(poll, 15000);
})();
