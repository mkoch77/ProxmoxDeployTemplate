const Toast = {
    _recent: new Map(),  // dedup: "type:message" → timestamp

    show(message, type = 'info') {
        // Deduplicate: suppress identical toasts within 3 seconds
        const dedupeKey = `${type}:${message}`;
        const now = Date.now();
        const lastShown = this._recent.get(dedupeKey);
        if (lastShown && now - lastShown < 3000) return;
        this._recent.set(dedupeKey, now);
        // Clean old entries
        if (this._recent.size > 20) {
            for (const [k, t] of this._recent) {
                if (now - t > 5000) this._recent.delete(k);
            }
        }

        const container = document.getElementById('toast-container');
        const icons = {
            success: 'bi-check-circle-fill',
            danger:  'bi-exclamation-triangle-fill',
            warning: 'bi-exclamation-circle-fill',
            info:    'bi-info-circle-fill',
        };

        const id = 'toast-' + Date.now();
        const html = `
            <div id="${id}" class="toast align-items-center text-bg-${type} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="bi ${icons[type] || icons.info} me-2"></i>
                        ${Utils.escapeHtml(message)}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>`;

        container.insertAdjacentHTML('beforeend', html);
        const toastEl = document.getElementById(id);
        const bsToast = new bootstrap.Toast(toastEl, { delay: 5000 });
        bsToast.show();

        toastEl.addEventListener('hidden.bs.toast', () => toastEl.remove());
    },

    success(msg) { this.show(msg, 'success'); },
    error(msg)   { this.show(msg, 'danger'); },
    warning(msg) { this.show(msg, 'warning'); },
    info(msg)    { this.show(msg, 'info'); },
};
