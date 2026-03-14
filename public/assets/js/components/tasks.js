const Tasks = {
    refreshInterval: null,
    nodes: [],
    currentNode: '',

    async init() {
        this.render();
        await this.loadNodes();
    },

    destroy() {
        this.stopAutoRefresh();
    },

    startAutoRefresh() {
        this.stopAutoRefresh();
        this.refreshInterval = setInterval(() => this.loadTasks(), 10000);
    },

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    },

    render() {
        const content = document.getElementById('page-content');
        content.innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-terminal-fill"></i>Tasks</h2>
                <button class="btn btn-outline-light btn-sm" onclick="Tasks.loadTasks()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
            <div class="filter-bar d-flex gap-2 mb-3">
                <select id="tasks-node-select" class="form-select form-select-sm" style="width:auto;" onchange="Tasks.selectNode(this.value)">
                    <option value="">Select node...</option>
                </select>
            </div>
            <div id="tasks-table-container">
                <div class="text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-terminal" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">Please select a node</p>
                </div>
            </div>`;
    },

    async loadNodes() {
        try {
            this.nodes = await API.getNodes();
            const select = document.getElementById('tasks-node-select');
            for (const n of this.nodes) {
                select.innerHTML += `<option value="${n.node}">${n.node}</option>`;
            }
            if (this.nodes.length > 0) {
                select.value = this.nodes[0].node;
                this.selectNode(this.nodes[0].node);
            }
        } catch (err) {
            // Error shown by API
        }
    },

    async selectNode(node) {
        this.currentNode = node;
        if (node) {
            await this.loadTasks();
            this.startAutoRefresh();
        }
    },

    async loadTasks() {
        if (!this.currentNode || this._loading) return;
        this._loading = true;

        const container = document.getElementById('tasks-table-container');

        try {
            const tasks = await API.getSilentAbortable('tasks', 'api/tasks.php', { node: this.currentNode, limit: 50 });
            this.renderTasks(tasks);
        } catch (err) {
            if (err?.name !== 'AbortError') {
                container.innerHTML = `<div class="alert alert-danger" style="border-radius:var(--radius-md)">Error: ${Utils.escapeHtml(err.message)}</div>`;
            }
        } finally {
            this._loading = false;
        }
    },

    renderTasks(tasks) {
        const container = document.getElementById('tasks-table-container');

        if (tasks.length === 0) {
            container.innerHTML = `
                <div class="text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-check-circle" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No tasks found</p>
                </div>`;
            return;
        }

        let html = `<div class="guest-table">
            <table class="table table-dark table-hover mb-0">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>VMID</th>
                        <th>User</th>
                        <th>Status</th>
                        <th style="text-align:right">Action</th>
                    </tr>
                </thead>
                <tbody>`;

        for (const t of tasks) {
            const statusColor = t.status === 'OK' ? 'var(--accent-green)' :
                               (t.status && t.status !== 'running') ? 'var(--accent-red)' : 'var(--accent-amber)';
            const statusIcon = t.status === 'OK' ? 'bi-check-circle-fill' :
                              (t.status && t.status !== 'running') ? 'bi-x-circle-fill' : 'bi-hourglass-split';
            const displayStatus = t.status || 'running';

            html += `<tr>
                <td>${Utils.formatDate(t.starttime)}</td>
                <td>${Utils.escapeHtml(t.type || '-')}</td>
                <td><strong style="color:var(--accent-blue)">${t.id || '-'}</strong></td>
                <td style="color:var(--text-secondary)">${Utils.escapeHtml(t.user || '-')}</td>
                <td style="color:${statusColor}"><i class="bi ${statusIcon}"></i> ${Utils.escapeHtml(displayStatus)}</td>
                <td style="text-align:right">
                    <button class="btn btn-outline-light btn-action" onclick="Tasks.showLog('${this.currentNode}', '${Utils.escapeHtml(t.upid)}')">
                        <i class="bi bi-terminal-fill"></i> Log
                    </button>
                </td>
            </tr>`;
        }

        html += '</tbody></table></div>';
        container.innerHTML = html;
    },

    async showLog(node, upid) {
        const logContent = document.getElementById('task-log-content');
        logContent.textContent = 'Loading...';

        const modal = new bootstrap.Modal(document.getElementById('taskLogModal'));
        modal.show();

        try {
            const logLines = await API.getTaskLog(node, upid);
            if (Array.isArray(logLines) && logLines.length > 0) {
                logContent.textContent = logLines.map(l => l.t || l.d || '').join('\n');
            } else {
                logContent.textContent = '(No log entries)';
            }
        } catch (err) {
            logContent.textContent = 'Error: ' + err.message;
        }
    }
};
