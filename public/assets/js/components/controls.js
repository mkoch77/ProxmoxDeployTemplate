const Controls = {
    async performAction(node, type, vmid, action, name = '') {
        const labels = {
            start:    'starten',
            stop:     'stoppen (hart)',
            shutdown: 'herunterfahren',
            reboot:   'neustarten',
            reset:    'resetten',
        };

        const label = labels[action] || action;
        const displayName = name || `${vmid}`;

        if (['stop', 'shutdown', 'reboot', 'reset'].includes(action)) {
            if (!confirm(`${Utils.typeLabel(type)} "${displayName}" wirklich ${label}?`)) {
                return;
            }
        }

        try {
            const result = await API.power(node, type, vmid, action);
            Toast.success(`${Utils.typeLabel(type)} "${displayName}" wird ${label === 'starten' ? 'gestartet' : label}...`);

            // Refresh dashboard after a short delay
            setTimeout(() => {
                if (typeof Dashboard !== 'undefined' && Dashboard.refresh) {
                    Dashboard.refresh();
                }
            }, 2000);

            return result;
        } catch (err) {
            // Error already shown by API
        }
    },

    renderButtons(guest) {
        const { node, type, vmid, name, status } = guest;
        const id = guest.vmid;
        let html = '';

        if (status === 'running') {
            html += `<button class="btn btn-warning btn-action me-1" onclick="Controls.performAction('${node}','${type}',${id},'reboot','${Utils.escapeHtml(name || '')}')">
                        <i class="bi bi-arrow-clockwise"></i>
                     </button>`;
            html += `<button class="btn btn-outline-warning btn-action me-1" onclick="Controls.performAction('${node}','${type}',${id},'shutdown','${Utils.escapeHtml(name || '')}')">
                        <i class="bi bi-power"></i>
                     </button>`;
            html += `<button class="btn btn-danger btn-action" onclick="Controls.performAction('${node}','${type}',${id},'stop','${Utils.escapeHtml(name || '')}')">
                        <i class="bi bi-stop-fill"></i>
                     </button>`;
        } else {
            html += `<button class="btn btn-success btn-action" onclick="Controls.performAction('${node}','${type}',${id},'start','${Utils.escapeHtml(name || '')}')">
                        <i class="bi bi-play-fill"></i> Start
                     </button>`;
        }

        return html;
    }
};
