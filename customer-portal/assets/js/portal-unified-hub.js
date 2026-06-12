(function () {
    const config = window.portalConfig || {};
    const apiBase = config.apiBaseUrl || 'api/';
    const container = document.querySelector('[data-unified-hub]');
    if (!container) {
        return;
    }

    const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
    })[ch]);

    fetch(apiBase + 'hub', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
        .then((response) => response.json())
        .then((payload) => {
            if (!payload || payload.error) {
                container.innerHTML = '<p class="text-muted mb-0">Area unica temporaneamente non disponibile.</p>';
                return;
            }
            const hub = payload.data || payload.hub || payload;
            const packages = hub.packages || {};
            const brt = hub.brt_shipments || {};
            const practices = Array.isArray(hub.caf_practices) ? hub.caf_practices : [];
            const loyalty = Number(hub.loyalty_points || 0);
            const appointment = hub.next_appointment;

            let html = '<div class="row g-3">';
            html += `<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="small text-muted">Pacchi</div><div class="fs-4 fw-semibold">${packages.ready || 0} pronti</div><div class="small">${packages.total || 0} totali</div></div></div>`;
            html += `<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="small text-muted">Spedizioni BRT</div><div class="fs-4 fw-semibold">${brt.total || 0}</div></div></div>`;
            html += `<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="small text-muted">Punti fedeltà</div><div class="fs-4 fw-semibold">${loyalty}</div></div></div>`;
            html += `<div class="col-md-3"><div class="border rounded-3 p-3 h-100"><div class="small text-muted">Prossimo appuntamento</div><div class="fw-semibold">${appointment ? escapeHtml(appointment.titolo || 'Appuntamento') : 'Nessuno'}</div>${appointment && appointment.data ? `<div class="small text-muted">${escapeHtml(appointment.data)}</div>` : ''}</div></div>`;
            html += '</div>';

            if (practices.length) {
                html += '<div class="mt-3"><div class="small text-muted mb-2">Pratiche CAF & Patronato</div><ul class="list-group list-group-flush">';
                practices.forEach((practice) => {
                    const code = practice.tracking_code ? ` <span class="badge bg-light text-dark">${escapeHtml(practice.tracking_code)}</span>` : '';
                    html += `<li class="list-group-item px-0 d-flex justify-content-between align-items-center"><span>${escapeHtml(practice.titolo || 'Pratica')}${code}</span><span class="badge text-bg-secondary">${escapeHtml(practice.stato || '')}</span></li>`;
                });
                html += '</ul></div>';
            }

            container.innerHTML = html;
        })
        .catch(() => {
            container.innerHTML = '<p class="text-muted mb-0">Impossibile caricare l\'area unica.</p>';
        });
})();
