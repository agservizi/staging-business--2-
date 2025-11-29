(function () {
    const container = document.querySelector('[data-ticket-base]');
    if (!container) {
        return;
    }

    const moduleBase = container.getAttribute('data-ticket-base') || '/modules/ticket';
    const csrfToken = container.getAttribute('data-ticket-csrf') || '';

    const buildUrl = (endpoint) => `${moduleBase.replace(/\/$/, '')}/ajax/${endpoint}`;

    const showNotice = (message, type = 'success') => {
        const wrapper = document.createElement('div');
        wrapper.className = `toast align-items-center text-bg-${type === 'error' ? 'danger' : type} border-0 show position-fixed top-0 end-0 m-3`;
        wrapper.setAttribute('role', 'alert');
        wrapper.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>`;
        document.body.appendChild(wrapper);
        setTimeout(() => wrapper.remove(), 4000);
    };

    const handleResponse = async (response) => {
        const data = await response.json();
        if (!response.ok || data.success === false) {
            throw new Error(data.message || 'Operazione non completata');
        }
        return data;
    };

    const postForm = async (endpoint, formData) => {
        if (!(formData instanceof FormData)) {
            formData = new FormData();
        }
        if (!formData.has('_token') && csrfToken) {
            formData.append('_token', csrfToken);
        }

        const response = await fetch(buildUrl(endpoint), {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': csrfToken,
            },
            body: formData,
        });

        return handleResponse(response);
    };

    // Assign modal (index)
    const assignModalEl = document.getElementById('ticketAssignModal');
    if (assignModalEl) {
        const assignForm = document.getElementById('ticket-assign-form');
        const ticketInput = document.getElementById('assign-ticket-id');
        const modal = window.bootstrap && window.bootstrap.Modal ? new window.bootstrap.Modal(assignModalEl) : null;

        document.querySelectorAll('[data-ticket-assign]').forEach((button) => {
            button.addEventListener('click', () => {
                if (ticketInput) {
                    ticketInput.value = button.getAttribute('data-ticket-assign');
                }
                if (modal) {
                    modal.show();
                }
            });
        });

        assignForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(assignForm);
            try {
                await postForm('assign.php', formData);
                modal?.hide();
                showNotice('Ticket assegnato.');
                window.location.reload();
            } catch (error) {
                showNotice(error.message, 'error');
            }
        });
    }

    // Archive modal (index + view button)
    const archiveModalEl = document.getElementById('ticketArchiveModal');
    if (archiveModalEl) {
        const archiveForm = document.getElementById('ticket-archive-form');
        const archiveInput = document.getElementById('archive-ticket-id');
        const modal = window.bootstrap && window.bootstrap.Modal ? new window.bootstrap.Modal(archiveModalEl) : null;

        document.querySelectorAll('[data-ticket-archive]').forEach((button) => {
            button.addEventListener('click', () => {
                if (archiveInput) {
                    archiveInput.value = button.getAttribute('data-ticket-archive');
                }
                modal?.show();
            });
        });

        archiveForm?.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(archiveForm);
            try {
                await postForm('archive.php', formData);
                modal?.hide();
                showNotice('Ticket archiviato.');
                window.location.reload();
            } catch (error) {
                showNotice(error.message, 'error');
            }
        });
    }

    const archiveButton = document.querySelector('[data-ticket-action="archive"]');
    if (archiveButton && !archiveModalEl) {
        archiveButton.addEventListener('click', async () => {
            const ticketId = container.getAttribute('data-ticket-id');
            if (!ticketId) {
                return;
            }
            const formData = new FormData();
            formData.append('ticket_id', ticketId);
            try {
                await postForm('archive.php', formData);
                showNotice('Ticket archiviato.');
                window.location.href = `${moduleBase}/index.php`;
            } catch (error) {
                showNotice(error.message, 'error');
            }
        });
    }

    // Status form (view)
    const statusForm = document.getElementById('ticket-status-form');
    if (statusForm) {
        statusForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(statusForm);
            try {
                const result = await postForm('update_status.php', formData);
                const badge = statusForm.closest('.card')?.querySelector('.badge');
                if (badge && result.status_badge) {
                    badge.className = `badge ${result.status_badge} text-uppercase`;
                    badge.textContent = result.status;
                }
                showNotice('Stato aggiornato.');
            } catch (error) {
                showNotice(error.message, 'error');
            }
        });
    }

    // Message form (view)
    const messageForm = document.getElementById('ticket-message-form');
    const thread = document.getElementById('ticket-thread');
    if (messageForm && thread) {
        messageForm.addEventListener('submit', async (event) => {
            event.preventDefault();
            const formData = new FormData(messageForm);
            try {
                const result = await postForm('add_message.php', formData);
                if (result.html) {
                    const temp = document.createElement('div');
                    temp.innerHTML = result.html.trim();
                    const newNode = temp.firstElementChild;
                    if (newNode) {
                        thread.appendChild(newNode);
                    }
                    messageForm.reset();
                }
                showNotice('Aggiornamento salvato.');
            } catch (error) {
                showNotice(error.message, 'error');
            }
        });
    }

    // Tags editing
    document.querySelectorAll('[data-ticket-action="edit-tags"]').forEach((button) => {
        button.addEventListener('click', async () => {
            const currentTags = Array.from(document.querySelectorAll('#ticket-tags .badge')).map((badge) => badge.textContent?.trim() ?? '');
            const next = window.prompt('Inserisci i tag separati da virgola', currentTags.join(', '));
            if (next === null) {
                return;
            }
            const ticketId = container.getAttribute('data-ticket-id');
            if (!ticketId) {
                return;
            }
            const formData = new FormData();
            formData.append('ticket_id', ticketId);
            formData.append('tags', next);
            try {
                const result = await postForm('update_tags.php', formData);
                const tagsWrapper = document.getElementById('ticket-tags');
                const emptyState = document.getElementById('ticket-tags-empty');
                if (result.tags && result.tags.length > 0) {
                    if (!tagsWrapper) {
                        return;
                    }
                    tagsWrapper.innerHTML = '';
                    result.tags.forEach((tag) => {
                        const span = document.createElement('span');
                        span.className = 'badge bg-dark text-uppercase';
                        span.textContent = tag;
                        tagsWrapper.appendChild(span);
                    });
                    emptyState?.classList.add('d-none');
                } else {
                    if (tagsWrapper) {
                        tagsWrapper.innerHTML = '';
                    }
                    emptyState?.classList.remove('d-none');
                }
                showNotice('Tag aggiornati.');
            } catch (error) {
                showNotice(error.message, 'error');
            }
        });
    });

    // Copy link (view)
    document.querySelectorAll('[data-ticket-action="copy-link"]').forEach((button) => {
        button.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(window.location.href);
                showNotice('Link copiato.');
            } catch (error) {
                showNotice('Impossibile copiare il link', 'error');
            }
        });
    });
})();
