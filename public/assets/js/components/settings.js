const Settings = {
    _activeTab: 'logs',
    _monitoringData: null,
    _lbData: null,
    _tasksInterval: null,
    _tasksNode: '',
    _tasksNodes: [],

    init() {
        this.render();
        this.loadAll();
    },

    destroy() {
        this._stopTasksRefresh();
    },

    render() {
        document.getElementById('page-content').innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-gear-fill"></i> Settings</h2>
            </div>

            <ul class="nav nav-tabs settings-tabs mb-4" role="tablist">
                ${Permissions.has('logs.view') ? `<li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="logs" onclick="Settings.switchTab('logs')">
                        <i class="bi bi-journal-text me-1"></i>Logs
                    </button>
                </li>` : ''}
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="monitoring" onclick="Settings.switchTab('monitoring')">
                        <i class="bi bi-graph-up me-1"></i>Monitoring
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="loadbalancer" onclick="Settings.switchTab('loadbalancer')">
                        <i class="bi bi-shuffle me-1"></i>Loadbalancing
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="tasks" onclick="Settings.switchTab('tasks')">
                        <i class="bi bi-terminal-fill me-1"></i>Tasks
                    </button>
                </li>
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="ssh" onclick="Settings.switchTab('ssh')">
                        <i class="bi bi-key-fill me-1"></i>SSH Keys
                    </button>
                </li>
            </ul>

            <div id="settings-tab-content"></div>
        `;
        this.switchTab(this._activeTab);
    },

    switchTab(tab) {
        // Stop tasks refresh when leaving tasks tab
        if (this._activeTab === 'tasks' && tab !== 'tasks') {
            this._stopTasksRefresh();
        }
        this._activeTab = tab;
        document.querySelectorAll('.settings-tabs .nav-link').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });
        const container = document.getElementById('settings-tab-content');
        if (tab === 'monitoring') {
            this.renderMonitoringTab(container);
        } else if (tab === 'loadbalancer') {
            this.renderLoadbalancerTab(container);
        } else if (tab === 'tasks') {
            this.renderTasksTab(container);
            this._loadTasksNodes();
        } else if (tab === 'logs') {
            this.renderLogsTab(container);
            this.loadLogs();
        } else if (tab === 'ssh') {
            this.renderSshTab(container);
            this.loadSshData();
        }
    },

    async loadAll() {
        await Promise.all([
            this.loadMonitoringData(),
            this.loadLoadbalancerData(),
        ]);
    },

    // ── Monitoring Tab ──────────────────────────────────────────────────────

    async loadMonitoringData() {
        try {
            this._monitoringData = await API.get('api/monitoring.php', { action: 'overview' });
            if (this._activeTab === 'monitoring') {
                this.renderMonitoringTab(document.getElementById('settings-tab-content'));
            }
        } catch (_) {}
    },

    renderMonitoringTab(container) {
        if (!container) return;
        const s = this._monitoringData?.settings || {};
        const stats = this._monitoringData?.stats || {};

        container.innerHTML = `
            <div class="settings-section">
                <h5 class="settings-section-title"><i class="bi bi-graph-up me-2"></i>Monitoring Settings</h5>
                <p class="text-muted small mb-4">Configure data collection and retention for node and VM metrics.</p>

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Retention (days)</label>
                        <input type="number" class="form-control" id="set-mon-retention" min="1" max="365" value="${s.retention_days || 30}">
                        <div class="form-text">How long to keep historical metrics data.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Collection interval (seconds)</label>
                        <input type="number" class="form-control" id="set-mon-interval" min="5" max="300" value="${s.collection_interval || 10}">
                        <div class="form-text">How often metrics are collected from Proxmox.</div>
                    </div>
                    <div class="col-md-4 d-flex align-items-start">
                        <div class="settings-stats-card mt-4">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="bi bi-database" style="color:var(--text-muted)"></i>
                                <span class="fw-semibold">Data Statistics</span>
                            </div>
                            <div class="text-muted small">
                                ${stats.node_count || 0} nodes, ${stats.vm_count || 0} VMs tracked<br>
                                ${(stats.total_rows || 0).toLocaleString()} data points
                                ${stats.oldest_data ? `<br>Oldest: ${new Date(stats.oldest_data).toLocaleDateString()}` : ''}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <button class="btn btn-primary" onclick="Settings.saveMonitoring()">
                        <i class="bi bi-floppy me-1"></i>Save Monitoring Settings
                    </button>
                </div>
            </div>
        `;
    },

    async saveMonitoring() {
        const retention = parseInt(document.getElementById('set-mon-retention')?.value) || 30;
        const interval = parseInt(document.getElementById('set-mon-interval')?.value) || 10;
        try {
            await API.post('api/monitoring.php?action=settings', { retention_days: retention, collection_interval: interval });
            Toast.success('Monitoring settings saved');
        } catch (e) {
            Toast.error('Failed to save monitoring settings');
        }
    },

    // ── Loadbalancer Tab ────────────────────────────────────────────────────

    async loadLoadbalancerData() {
        try {
            this._lbData = await API.getLoadbalancer();
            if (this._activeTab === 'loadbalancer') {
                this.renderLoadbalancerTab(document.getElementById('settings-tab-content'));
            }
        } catch (_) {}
    },

    renderLoadbalancerTab(container) {
        if (!container) return;
        const s = this._lbData?.settings || {};
        const thresholdLabels = {
            1: 'Aggressive (10%)',
            2: 'Moderate (15%)',
            3: 'Default (20%)',
            4: 'Conservative (25%)',
            5: 'Very Conservative (30%)',
        };

        container.innerHTML = `
            <div class="settings-section">
                <h5 class="settings-section-title"><i class="bi bi-shuffle me-2"></i>Loadbalancer Settings</h5>
                <p class="text-muted small mb-4">Configure automatic workload distribution across cluster nodes (DRS).</p>

                <div class="row g-3">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="set-lb-enabled" ${s.enabled ? 'checked' : ''}>
                            <label class="form-check-label fw-semibold" for="set-lb-enabled">Loadbalancing enabled</label>
                        </div>
                        <div class="form-text">When enabled, the system periodically evaluates cluster balance and generates migration recommendations.</div>
                    </div>

                    <div class="col-12 mt-3">
                        <label class="form-label">Automation Level</label>
                        <div class="lb-automation-toggle">
                            <button class="btn btn-sm ${s.automation_level === 'manual' ? 'active' : 'btn-outline-light'}" data-level="manual">Manual</button>
                            <button class="btn btn-sm ${s.automation_level === 'partial' ? 'active' : 'btn-outline-light'}" data-level="partial">Semi-Automatic</button>
                            <button class="btn btn-sm ${s.automation_level === 'full' ? 'active' : 'btn-outline-light'}" data-level="full">Fully Automatic</button>
                        </div>
                        <div class="form-text mt-1">
                            <strong>Manual:</strong> View recommendations only.
                            <strong>Semi-Automatic:</strong> Apply migrations individually.
                            <strong>Fully Automatic:</strong> Migrations are applied automatically.
                        </div>
                    </div>

                    <div class="col-md-6 mt-3">
                        <label class="form-label">CPU Weight: <span id="set-lb-cpu-val" class="fw-semibold">${s.cpu_weight || 50}</span>%</label>
                        <input type="range" class="form-range" id="set-lb-cpu-weight" min="0" max="100" value="${s.cpu_weight || 50}">
                    </div>
                    <div class="col-md-6 mt-3">
                        <label class="form-label">RAM Weight: <span id="set-lb-ram-val" class="fw-semibold">${s.ram_weight || 50}</span>%</label>
                        <input type="range" class="form-range" id="set-lb-ram-weight" min="0" max="100" value="${s.ram_weight || 50}">
                    </div>

                    <div class="col-md-4">
                        <label class="form-label">Threshold: <span id="set-lb-threshold-val" class="fw-semibold">${s.threshold || 3} - ${thresholdLabels[s.threshold || 3] || ''}</span></label>
                        <input type="range" class="form-range" id="set-lb-threshold" min="1" max="5" value="${s.threshold || 3}">
                        <div class="form-text">How much deviation from the cluster average triggers a recommendation.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Max. concurrent migrations</label>
                        <input type="number" class="form-control" id="set-lb-max-concurrent" min="1" max="10" value="${s.max_concurrent || 3}">
                        <div class="form-text">Max migrations applied per evaluation run.</div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2">
                    <button class="btn btn-primary" onclick="Settings.saveLoadbalancer()">
                        <i class="bi bi-floppy me-1"></i>Save Loadbalancer Settings
                    </button>
                    <button class="btn btn-outline-secondary" onclick="Settings.resetLoadbalancer()">
                        <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to Default
                    </button>
                </div>
            </div>
        `;

        // Event listeners
        document.getElementById('set-lb-cpu-weight')?.addEventListener('input', (e) => {
            document.getElementById('set-lb-cpu-val').textContent = e.target.value;
        });
        document.getElementById('set-lb-ram-weight')?.addEventListener('input', (e) => {
            document.getElementById('set-lb-ram-val').textContent = e.target.value;
        });
        document.getElementById('set-lb-threshold')?.addEventListener('input', (e) => {
            const val = parseInt(e.target.value);
            const labels = { 1: 'Aggressive (10%)', 2: 'Moderate (15%)', 3: 'Default (20%)', 4: 'Conservative (25%)', 5: 'Very Conservative (30%)' };
            document.getElementById('set-lb-threshold-val').textContent = `${val} - ${labels[val] || ''}`;
        });
        document.querySelectorAll('.lb-automation-toggle .btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.lb-automation-toggle .btn').forEach(b => {
                    b.classList.remove('active');
                    b.classList.add('btn-outline-light');
                });
                btn.classList.add('active');
                btn.classList.remove('btn-outline-light');
            });
        });
    },

    async saveLoadbalancer() {
        const activeBtn = document.querySelector('.lb-automation-toggle .btn.active');
        const settings = {
            enabled: document.getElementById('set-lb-enabled')?.checked ? 1 : 0,
            automation_level: activeBtn?.dataset.level || 'manual',
            cpu_weight: parseInt(document.getElementById('set-lb-cpu-weight')?.value || '50'),
            ram_weight: parseInt(document.getElementById('set-lb-ram-weight')?.value || '50'),
            threshold: parseInt(document.getElementById('set-lb-threshold')?.value || '3'),
            max_concurrent: parseInt(document.getElementById('set-lb-max-concurrent')?.value || '3'),
        };
        try {
            await API.updateLoadbalancerSettings(settings);
            Toast.success('Loadbalancer settings saved');
        } catch (err) {
            Toast.error('Failed to save loadbalancer settings');
        }
    },

    async resetLoadbalancer() {
        if (!confirm('Reset all loadbalancer settings to default values?')) return;
        const defaults = {
            enabled: 0,
            automation_level: 'manual',
            cpu_weight: 50,
            ram_weight: 50,
            threshold: 3,
            max_concurrent: 3,
        };
        try {
            await API.updateLoadbalancerSettings(defaults);
            Toast.success('Loadbalancer settings reset to defaults');
            await this.loadLoadbalancerData();
        } catch (err) {
            Toast.error('Failed to reset settings');
        }
    },

    // ── Tasks Tab ────────────────────────────────────────────────────────

    renderTasksTab(container) {
        if (!container) return;
        container.innerHTML = `
            <div class="settings-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <h5 class="settings-section-title mb-0"><i class="bi bi-terminal-fill me-2"></i>Proxmox Tasks</h5>
                        <select id="tasks-node-select" class="form-select form-select-sm" style="width:auto;" onchange="Settings._selectTasksNode(this.value)">
                            <option value="">Select node...</option>
                        </select>
                    </div>
                    <button class="btn btn-sm btn-outline-light" onclick="Settings._loadTasks()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
                <div id="tasks-table-container">
                    <div class="text-center p-5" style="color:var(--text-muted)">
                        <i class="bi bi-terminal" style="font-size:2.5rem;opacity:0.3"></i>
                        <p class="mt-2 mb-0">Select a node to view tasks</p>
                    </div>
                </div>
            </div>
        `;
    },

    async _loadTasksNodes() {
        try {
            this._tasksNodes = await API.getNodes();
            const select = document.getElementById('tasks-node-select');
            if (!select) return;
            select.innerHTML = '<option value="">Select node...</option>';
            for (const n of this._tasksNodes) {
                select.innerHTML += `<option value="${n.node}">${n.node}</option>`;
            }
            if (this._tasksNode) {
                select.value = this._tasksNode;
                this._loadTasks();
                this._startTasksRefresh();
            } else if (this._tasksNodes.length > 0) {
                select.value = this._tasksNodes[0].node;
                this._selectTasksNode(this._tasksNodes[0].node);
            }
        } catch (_) {}
    },

    _selectTasksNode(node) {
        this._tasksNode = node;
        if (node) {
            this._loadTasks();
            this._startTasksRefresh();
        }
    },

    _startTasksRefresh() {
        this._stopTasksRefresh();
        this._tasksInterval = setInterval(() => this._loadTasks(), 10000);
    },

    _stopTasksRefresh() {
        if (this._tasksInterval) {
            clearInterval(this._tasksInterval);
            this._tasksInterval = null;
        }
    },

    async _loadTasks() {
        if (!this._tasksNode) return;
        const container = document.getElementById('tasks-table-container');
        if (!container) return;
        try {
            const tasks = await API.getTasks(this._tasksNode);
            if (tasks.length === 0) {
                container.innerHTML = `<div class="text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-check-circle" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No tasks found</p>
                </div>`;
                return;
            }
            let html = `<div class="guest-table"><table class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>Time</th><th>Type</th><th>VMID</th><th>User</th><th>Status</th><th style="text-align:right">Action</th>
                </tr></thead><tbody>`;
            for (const t of tasks) {
                const statusColor = t.status === 'OK' ? 'var(--accent-green)' :
                    (t.status && t.status !== 'running') ? 'var(--accent-red)' : 'var(--accent-amber)';
                const statusIcon = t.status === 'OK' ? 'bi-check-circle-fill' :
                    (t.status && t.status !== 'running') ? 'bi-x-circle-fill' : 'bi-hourglass-split';
                html += `<tr>
                    <td>${Utils.formatDate(t.starttime)}</td>
                    <td>${Utils.escapeHtml(t.type || '-')}</td>
                    <td><strong style="color:var(--accent-blue)">${t.id || '-'}</strong></td>
                    <td style="color:var(--text-secondary)">${Utils.escapeHtml(t.user || '-')}</td>
                    <td style="color:${statusColor}"><i class="bi ${statusIcon}"></i> ${Utils.escapeHtml(t.status || 'running')}</td>
                    <td style="text-align:right">
                        <button class="btn btn-outline-light btn-action" onclick="Settings._showTaskLog('${this._tasksNode}', '${Utils.escapeHtml(t.upid)}')">
                            <i class="bi bi-terminal-fill"></i> Log
                        </button>
                    </td>
                </tr>`;
            }
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger" style="border-radius:var(--radius-md)">Error: ${Utils.escapeHtml(err.message)}</div>`;
        }
    },

    async _showTaskLog(node, upid) {
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
    },

    // ── Logs Tab ─────────────────────────────────────────────────────────

    renderLogsTab(container) {
        if (!container) return;
        container.innerHTML = `
            <div class="settings-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="settings-section-title mb-0"><i class="bi bi-journal-text me-2"></i>Application Logs</h5>
                    <div class="d-flex gap-2 align-items-center">
                        <select id="logs-level-filter" class="form-select form-select-sm" style="width:auto;" onchange="Settings.loadLogs()">
                            <option value="">All Levels</option>
                            <option value="debug">Debug</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                        </select>
                        <select id="logs-category-filter" class="form-select form-select-sm" style="width:auto;" onchange="Settings.loadLogs()">
                            <option value="">All Categories</option>
                        </select>
                        <button class="btn btn-sm btn-outline-light" onclick="Settings.loadLogs()">
                            <i class="bi bi-arrow-clockwise"></i>
                        </button>
                    </div>
                </div>
                <div id="logs-table-container">
                    <div class="text-center py-4"><div class="spinner-border spinner-border-sm"></div></div>
                </div>
            </div>
        `;
    },

    async loadLogs() {
        const container = document.getElementById('logs-table-container');
        if (!container) return;
        const level = document.getElementById('logs-level-filter')?.value || '';
        const category = document.getElementById('logs-category-filter')?.value || '';
        try {
            const res = await API.get('api/logs.php', { limit: 200, level, category });
            const logs = res.logs || [];
            const categories = res.categories || [];

            // Populate category filter (preserve selection)
            const catSelect = document.getElementById('logs-category-filter');
            if (catSelect) {
                const current = catSelect.value;
                catSelect.innerHTML = '<option value="">All Categories</option>';
                categories.forEach(c => {
                    catSelect.innerHTML += `<option value="${escapeHtml(c)}" ${c === current ? 'selected' : ''}>${escapeHtml(c)}</option>`;
                });
            }

            if (logs.length === 0) {
                container.innerHTML = `<div class="text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-journal" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No log entries found</p>
                </div>`;
                return;
            }

            const levelBadge = (lvl) => {
                const cls = lvl === 'error' ? 'bg-danger' : lvl === 'warning' ? 'bg-warning text-dark' : 'bg-info text-dark';
                return `<span class="badge ${cls}">${escapeHtml(lvl)}</span>`;
            };

            let html = `<div class="guest-table"><table class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>Time</th><th>Level</th><th>Category</th><th>Message</th><th>User</th>
                </tr></thead><tbody>`;
            for (const l of logs) {
                const time = new Date(l.created_at).toLocaleString();
                html += `<tr>
                    <td class="text-nowrap small">${escapeHtml(time)}</td>
                    <td>${levelBadge(l.level)}</td>
                    <td><span class="badge bg-secondary">${escapeHtml(l.category)}</span></td>
                    <td class="small">${escapeHtml(l.message)}${l.context ? `<br><code class="small text-muted">${escapeHtml(l.context)}</code>` : ''}</td>
                    <td class="small text-muted">${escapeHtml(l.username || '-')}</td>
                </tr>`;
            }
            html += '</tbody></table></div>';
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Error: ${escapeHtml(err.message)}</div>`;
        }
    },

    // ── SSH Keys Tab ─────────────────────────────────────────────────────

    renderSshTab(container) {
        if (!container) return;
        container.innerHTML = `
            <div class="settings-section">
                <h5 class="settings-section-title"><i class="bi bi-key-fill me-2"></i>SSH Key Setup</h5>
                <p class="text-muted small mb-4">
                    This is the auto-generated SSH public key of this container.
                    Run the command for each Proxmox node to authorize it, or use the deploy button.
                </p>

                <label class="form-label fw-semibold">Public Key</label>
                <div class="input-group mb-3">
                    <textarea class="form-control font-monospace" id="ssh-setup-pubkey" rows="3" readonly style="font-size:0.75rem;resize:none">Loading...</textarea>
                    <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(document.getElementById('ssh-setup-pubkey').value).then(()=>Toast.success('Copied!'))">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label fw-semibold mb-0">Copy to Nodes</label>
                    <button class="btn btn-sm btn-primary" onclick="Settings.deployKeyToNodes(this)">
                        <i class="bi bi-cloud-upload me-1"></i>Deploy to All Nodes
                    </button>
                </div>
                <div id="ssh-deploy-results" class="mb-3"></div>
                <div id="ssh-setup-commands"><div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div></div>
            </div>
        `;
    },

    async loadSshData() {
        try {
            const [keyRes, healthRes] = await Promise.all([
                API.get('api/ssh-pubkey.php'),
                API.getClusterHealth(),
            ]);
            const pubKey = keyRes.public_key;
            const keyEl = document.getElementById('ssh-setup-pubkey');
            const cmdsEl = document.getElementById('ssh-setup-commands');
            if (!keyEl || !cmdsEl) return;

            keyEl.textContent = pubKey;
            const nodes = (healthRes.nodes || []).sort((a, b) => (a.node || '').localeCompare(b.node || ''));

            // Fetch IPs for each node
            const nodeInfos = await Promise.allSettled(
                nodes.filter(n => (n.status || '') === 'online').map(n => API.getSilent('api/node-info.php', { node: n.node }))
            );
            const ipMap = {};
            nodeInfos.forEach(r => {
                if (r.status === 'fulfilled' && r.value?.node) {
                    ipMap[r.value.node] = r.value.ip || r.value.node;
                }
            });

            if (nodes.length > 0) {
                cmdsEl.innerHTML = nodes.filter(n => (n.status || '') === 'online').map(n => {
                    const ip = ipMap[n.node] || n.node;
                    return `<div class="mb-2">
                        <small class="text-muted">${escapeHtml(n.node)} (${escapeHtml(ip)})</small>
                        <div class="input-group input-group-sm mt-1">
                            <input type="text" class="form-control font-monospace" readonly
                                value="ssh-copy-id -i /dev/stdin root@${escapeHtml(ip)} <<< ${escapeHtml("'" + pubKey + "'")}">
                            <button class="btn btn-outline-secondary" onclick="navigator.clipboard.writeText(this.previousElementSibling.value).then(()=>Toast.success('Copied!'))">
                                <i class="bi bi-clipboard"></i>
                            </button>
                        </div>
                    </div>`;
                }).join('');
            } else {
                cmdsEl.innerHTML = '<p class="text-muted small">No nodes found. Copy the key manually.</p>';
            }
        } catch (e) {
            const keyEl = document.getElementById('ssh-setup-pubkey');
            if (keyEl) keyEl.textContent = 'Error: ' + (e.message || 'Could not load public key');
        }
    },

    async deployKeyToNodes(btn) {
        const resultsEl = document.getElementById('ssh-deploy-results');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deploying...';
        resultsEl.innerHTML = '';
        try {
            const res = await API.post('api/ssh-deploy-key.php', {});
            resultsEl.innerHTML = res.results.map(r => `
                <div class="d-flex align-items-center gap-2 mb-1">
                    <i class="bi ${r.success ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'}"></i>
                    <span class="small">${escapeHtml(r.node)} (${escapeHtml(r.ip)})${r.error ? ' — ' + escapeHtml(r.error) : ''}</span>
                </div>`).join('');
            const allOk = res.results.every(r => r.success);
            if (allOk) Toast.success('SSH key deployed to all nodes');
            else Toast.warning('Some nodes failed — see details above');
        } catch (e) {
            resultsEl.innerHTML = `<p class="text-danger small">${escapeHtml(e.message || 'Deploy failed')}</p>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Deploy to All Nodes';
        }
    },
};
