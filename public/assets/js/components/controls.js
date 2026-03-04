const Controls = {
    async performAction(node, type, vmid, action, name = '') {
        const labels = {
            start:    'start',
            stop:     'force stop',
            shutdown: 'shut down',
            reboot:   'reboot',
            reset:    'reset',
        };

        const label = labels[action] || action;
        const displayName = name || `${vmid}`;

        if (['stop', 'shutdown', 'reboot', 'reset'].includes(action)) {
            if (!confirm(`Really ${label} ${Utils.typeLabel(type)} "${displayName}"?`)) {
                return;
            }
        }

        try {
            const result = await API.power(node, type, vmid, action);
            Toast.success(`${Utils.typeLabel(type)} "${displayName}" is being ${action === 'start' ? 'started' : action === 'stop' ? 'stopped' : action === 'shutdown' ? 'shut down' : action === 'reboot' ? 'rebooted' : 'reset'}...`);

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
            if (Permissions.has('vm.reboot')) {
                html += `<button class="btn btn-warning btn-action me-1" onclick="Controls.performAction('${node}','${type}',${id},'reboot','${Utils.escapeHtml(name || '')}')">
                            <i class="bi bi-arrow-clockwise"></i>
                         </button>`;
            }
            if (Permissions.has('vm.shutdown')) {
                html += `<button class="btn btn-outline-warning btn-action me-1" onclick="Controls.performAction('${node}','${type}',${id},'shutdown','${Utils.escapeHtml(name || '')}')">
                            <i class="bi bi-power"></i>
                         </button>`;
            }
            if (Permissions.has('vm.stop')) {
                html += `<button class="btn btn-danger btn-action" onclick="Controls.performAction('${node}','${type}',${id},'stop','${Utils.escapeHtml(name || '')}')">
                            <i class="bi bi-stop-fill"></i>
                         </button>`;
            }
        } else {
            if (Permissions.has('vm.start')) {
                html += `<button class="btn btn-success btn-action" onclick="Controls.performAction('${node}','${type}',${id},'start','${Utils.escapeHtml(name || '')}')">
                            <i class="bi bi-play-fill"></i> Start
                         </button>`;
            }
        }

        return html;
    }
};
