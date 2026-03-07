const Controls = {
    async deleteGuest(node, type, vmid, name = '') {
        const label = type === 'lxc' ? 'CT' : 'VM';
        const displayName = name || `${vmid}`;
        if (!confirm(`Really delete ${label} "${displayName}"? This action cannot be undone.`)) {
            return;
        }
        try {
            await API.deleteGuest(node, type, vmid);
            Toast.success(`${label} "${displayName}" is being deleted...`);
            setTimeout(() => {
                if (typeof Dashboard !== 'undefined' && Dashboard.refresh) {
                    Dashboard.refresh();
                }
            }, 2000);
        } catch (err) {
            // Error already shown by API
        }
    },

    async performAction(node, type, vmid, action, name = '') {
        const labels = {
            start:    'start',
            stop:     'force stop (hard poweroff)',
            shutdown: 'shut down',
            reboot:   'reboot',
            reset:    'hard reboot (reset)',
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
        const running = status === 'running';
        const e = Utils.escapeHtml(name || '');
        const dimOff = running ? ' disabled style="opacity:0.3;pointer-events:none"' : '';
        const dimOn  = running ? '' : ' disabled style="opacity:0.3;pointer-events:none"';
        let html = '';

        // Start
        if (Permissions.has('vm.start')) {
            html += `<button class="btn btn-success btn-action me-1" title="Start"${dimOff}
                onclick="Controls.performAction('${node}','${type}',${vmid},'start','${e}')">
                <i class="bi bi-play-fill"></i></button>`;
        }

        // Reboot dropdown (Graceful Reboot / Hard Reboot)
        if (Permissions.has('vm.reboot')) {
            html += `<div class="btn-group btn-group-sm me-1">
                <button class="btn btn-warning btn-action" title="Graceful Reboot"${dimOn}
                    onclick="Controls.performAction('${node}','${type}',${vmid},'reboot','${e}')">
                    <i class="bi bi-arrow-clockwise"></i></button>
                <button class="btn btn-warning dropdown-toggle dropdown-toggle-split"${dimOn}
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">Toggle Dropdown</span></button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#"
                        onclick="event.preventDefault();Controls.performAction('${node}','${type}',${vmid},'reboot','${e}')">
                        <i class="bi bi-arrow-clockwise me-2"></i>Graceful Reboot</a></li>
                    <li><a class="dropdown-item" href="#"
                        onclick="event.preventDefault();Controls.performAction('${node}','${type}',${vmid},'reset','${e}')">
                        <i class="bi bi-arrow-repeat me-2"></i>Hard Reboot (Reset)</a></li>
                </ul>
            </div>`;
        }

        // Power Off dropdown (Graceful Shutdown / Hard Poweroff)
        const hasShutdown = Permissions.has('vm.shutdown');
        const hasStop     = Permissions.has('vm.stop');
        if (hasShutdown || hasStop) {
            const primaryAction   = hasShutdown ? 'shutdown' : 'stop';
            const primaryTitle    = hasShutdown ? 'Graceful Shutdown' : 'Hard Poweroff';
            const primaryIcon     = hasShutdown ? 'bi-power' : 'bi-stop-fill';
            html += `<div class="btn-group btn-group-sm me-1">
                <button class="btn btn-outline-warning btn-action" title="${primaryTitle}"${dimOn}
                    onclick="Controls.performAction('${node}','${type}',${vmid},'${primaryAction}','${e}')">
                    <i class="bi ${primaryIcon}"></i></button>`;
            if (hasShutdown && hasStop) {
                html += `<button class="btn btn-outline-warning dropdown-toggle dropdown-toggle-split"${dimOn}
                    data-bs-toggle="dropdown" aria-expanded="false">
                    <span class="visually-hidden">Toggle Dropdown</span></button>
                <ul class="dropdown-menu">
                    <li><a class="dropdown-item" href="#"
                        onclick="event.preventDefault();Controls.performAction('${node}','${type}',${vmid},'shutdown','${e}')">
                        <i class="bi bi-power me-2"></i>Graceful Shutdown</a></li>
                    <li><a class="dropdown-item" href="#"
                        onclick="event.preventDefault();Controls.performAction('${node}','${type}',${vmid},'stop','${e}')">
                        <i class="bi bi-stop-fill me-2"></i>Hard Poweroff (Force Stop)</a></li>
                </ul>`;
            }
            html += `</div>`;
        }

        // Delete (only when stopped)
        if (Permissions.has('vm.delete')) {
            const delTitle = running ? 'Delete (stop VM first)' : 'Delete VM/CT';
            html += `<button class="btn btn-danger btn-action" title="${delTitle}"${dimOff}
                onclick="Controls.deleteGuest('${node}','${type}',${vmid},'${e}')">
                <i class="bi bi-trash-fill"></i></button>`;
        }

        return html;
    }
};
