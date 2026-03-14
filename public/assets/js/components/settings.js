const Settings = {
    _activeTab: 'stats',
    _monitoringData: null,
    _lbData: null,
    _statsData: null,
    _logsPage: 1,
    _logsPerPage: 50,
    _logsSource: 'all',

    init() {
        this.render();
        this.loadAll();
    },

    destroy() {
    },

    render() {
        document.getElementById('page-content').innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-gear-fill"></i> Settings</h2>
            </div>

            <ul class="nav nav-tabs settings-tabs mb-4" role="tablist">
                <li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="stats" onclick="Settings.switchTab('stats')">
                        <i class="bi bi-bar-chart-fill me-1"></i>Statistics
                    </button>
                </li>
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
                ${Permissions.has('cluster.affinity') ? `<li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="affinity" onclick="Settings.switchTab('affinity')">
                        <i class="bi bi-diagram-2 me-1"></i>Affinity Rules
                    </button>
                </li>` : ''}
                ${Utils.sshEnabled() ? `<li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="ssh" onclick="Settings.switchTab('ssh')">
                        <i class="bi bi-key-fill me-1"></i>SSH Keys
                    </button>
                </li>` : ''}
                ${Permissions.has('users.manage') ? `<li class="nav-item" role="presentation">
                    <button class="nav-link" data-tab="vault" onclick="Settings.switchTab('vault')">
                        <i class="bi bi-shield-lock-fill me-1"></i>Vault
                    </button>
                </li>` : ''}
            </ul>

            <div id="settings-tab-content"></div>
        `;
        this.switchTab(this._activeTab);
    },

    switchTab(tab) {
        this._activeTab = tab;
        document.querySelectorAll('.settings-tabs .nav-link').forEach(btn => {
            btn.classList.toggle('active', btn.dataset.tab === tab);
        });
        const container = document.getElementById('settings-tab-content');
        if (tab === 'stats') {
            this.renderStatsTab(container);
            this.loadStatsData();
        } else if (tab === 'monitoring') {
            this.renderMonitoringTab(container);
        } else if (tab === 'loadbalancer') {
            this.renderLoadbalancerTab(container);
        } else if (tab === 'logs') {
            this.renderLogsTab(container);
            this.loadLogs();
        } else if (tab === 'affinity') {
            this.renderAffinityTab(container);
            this.loadAffinityData();
        } else if (tab === 'ssh') {
            this.renderSshTab(container);
            this.loadSshData();
        } else if (tab === 'vault') {
            this.renderVaultTab(container);
            this.loadVaultData();
        }
    },

    async loadAll() {
        await Promise.all([
            this.loadMonitoringData(),
            this.loadLoadbalancerData(),
        ]);
    },

    // ── Statistics Tab ─────────────────────────────────────────────────────

    async loadStatsData() {
        try {
            this._statsData = await API.getClusterStats();
            if (this._activeTab === 'stats') {
                this.renderStatsTab(document.getElementById('settings-tab-content'));
            }
        } catch (_) {}
    },

    renderStatsTab(container) {
        if (!container) return;
        const d = this._statsData;
        if (!d) {
            container.innerHTML = '<div class="loading-spinner"><div class="spinner-border text-primary"></div></div>';
            return;
        }

        const fmtBytes = (bytes) => {
            if (bytes >= 1099511627776) return (bytes / 1099511627776).toFixed(1) + ' TB';
            if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(0) + ' MB';
            return (bytes / 1024).toFixed(0) + ' KB';
        };

        const fmtUptime = (seconds) => {
            const days = Math.floor(seconds / 86400);
            const hours = Math.floor((seconds % 86400) / 3600);
            if (days > 0) return `${days}d ${hours}h`;
            const mins = Math.floor((seconds % 3600) / 60);
            return `${hours}h ${mins}m`;
        };

        const pctBar = (pct, color = 'var(--accent-green)') => {
            const c = pct > 85 ? 'var(--accent-red)' : pct > 65 ? 'var(--accent-amber)' : color;
            return `<div class="stats-pct-bar"><div class="stats-pct-fill" style="width:${Math.min(pct, 100)}%;background:${c}"></div></div>`;
        };

        const statCard = (icon, label, value, sub = '') =>
            `<div class="stats-card">
                <div class="stats-card-icon"><i class="bi bi-${icon}"></i></div>
                <div class="stats-card-body">
                    <div class="stats-card-value">${value}</div>
                    <div class="stats-card-label">${label}</div>
                    ${sub ? `<div class="stats-card-sub">${sub}</div>` : ''}
                </div>
            </div>`;

        // Task activity breakdown
        const taskTypes = d.tasks.types_24h || {};
        const topTypes = Object.entries(taskTypes)
            .sort((a, b) => b[1] - a[1])
            .slice(0, 8);

        const maxCount = topTypes.length > 0 ? topTypes[0][1] : 1;

        container.innerHTML = `
            <div class="stats-section">
                <h5 class="stats-section-title"><i class="bi bi-speedometer2 me-2"></i>Cluster Resources</h5>
                <div class="stats-grid">
                    ${statCard('cpu', 'CPU Usage', d.cluster.cpu_pct + '%', d.cluster.total_cores + ' Cores' )}
                    ${statCard('memory', 'RAM Usage', d.cluster.mem_pct + '%', fmtBytes(d.cluster.mem_used) + ' / ' + fmtBytes(d.cluster.mem_total))}
                    ${statCard('device-hdd', 'Storage', d.cluster.storage_pct + '%', fmtBytes(d.cluster.storage_used) + ' / ' + fmtBytes(d.cluster.storage_total))}
                    ${statCard('hdd-rack', 'Nodes', d.nodes.online + ' / ' + d.nodes.total, d.nodes.max_uptime > 0 ? 'Max uptime: ' + fmtUptime(d.nodes.max_uptime) : '')}
                </div>
                <div class="stats-bars mt-3">
                    <div class="stats-bar-row">
                        <span class="stats-bar-label">CPU</span>
                        ${pctBar(d.cluster.cpu_pct)}
                        <span class="stats-bar-value">${d.cluster.cpu_pct}%</span>
                    </div>
                    <div class="stats-bar-row">
                        <span class="stats-bar-label">RAM</span>
                        ${pctBar(d.cluster.mem_pct)}
                        <span class="stats-bar-value">${d.cluster.mem_pct}%</span>
                    </div>
                    <div class="stats-bar-row">
                        <span class="stats-bar-label">Storage</span>
                        ${pctBar(d.cluster.storage_pct, 'var(--accent-blue)')}
                        <span class="stats-bar-value">${d.cluster.storage_pct}%</span>
                    </div>
                </div>
            </div>

            <div class="stats-section">
                <h5 class="stats-section-title"><i class="bi bi-pc-display me-2"></i>Guests</h5>
                <div class="stats-grid">
                    ${statCard('play-circle', 'Running', d.guests.running, d.guests.total + ' total')}
                    ${statCard('stop-circle', 'Stopped', d.guests.stopped, '')}
                    ${statCard('hdd', 'VMs (QEMU)', d.guests.qemu, '')}
                    ${statCard('box', 'Containers (LXC)', d.guests.lxc, '')}
                    ${d.guests.ha_managed > 0 ? statCard('shield-check', 'HA Managed', d.guests.ha_managed, '') : ''}
                </div>
            </div>

            <div class="stats-section">
                <h5 class="stats-section-title"><i class="bi bi-activity me-2"></i>Activity (Last 24h)</h5>
                <div class="stats-grid">
                    ${statCard('arrow-left-right', 'Migrations', d.tasks.migrations_24h, d.tasks.migrations_failed > 0 ? '<span style="color:var(--accent-red)">' + d.tasks.migrations_failed + ' failed</span>' : d.tasks.migrations_7d + ' in 7d')}
                    ${statCard('list-task', 'Total Tasks', d.tasks.total_24h, d.tasks.failed_24h > 0 ? '<span style="color:var(--accent-red)">' + d.tasks.failed_24h + ' failed</span>' : d.tasks.total_7d + ' in 7d')}
                    ${statCard('camera', 'Snapshots', d.tasks.snapshots_24h, '')}
                    ${statCard('archive', 'Backups', d.tasks.backups_24h, '')}
                    ${statCard('power', 'VM Starts', d.tasks.vm_starts_24h, d.tasks.vm_stops_24h + ' stops')}
                    ${statCard('copy', 'Clones', d.tasks.clones_24h, '')}
                    ${statCard('rocket-takeoff', 'Deploys (App)', d.deploys.count_24h, d.deploys.count_7d + ' in 7d')}
                </div>
            </div>

            ${topTypes.length > 0 ? `
            <div class="stats-section">
                <h5 class="stats-section-title"><i class="bi bi-bar-chart me-2"></i>Task Breakdown (24h)</h5>
                <div class="stats-task-breakdown">
                    ${topTypes.map(([type, count]) => `
                        <div class="stats-breakdown-row">
                            <span class="stats-breakdown-label">${escapeHtml(type)}</span>
                            <div class="stats-breakdown-bar">
                                <div class="stats-breakdown-fill" style="width:${Math.round(count / maxCount * 100)}%"></div>
                            </div>
                            <span class="stats-breakdown-count">${count}</span>
                        </div>
                    `).join('')}
                </div>
            </div>
            ` : ''}

            <div class="stats-section">
                <h5 class="stats-section-title"><i class="bi bi-hdd-rack me-2"></i>Node Performance</h5>
                <div class="stats-node-grid">
                    ${d.nodes.stats.map(n => `
                        <div class="stats-node-card">
                            <div class="stats-node-header">
                                <strong>${escapeHtml(n.node)}</strong>
                                <span class="text-muted small">${fmtUptime(n.uptime)}</span>
                            </div>
                            <div class="stats-bar-row">
                                <span class="stats-bar-label">CPU</span>
                                ${pctBar(n.cpu_pct)}
                                <span class="stats-bar-value">${n.cpu_pct}%</span>
                            </div>
                            <div class="stats-bar-row">
                                <span class="stats-bar-label">RAM</span>
                                ${pctBar(n.mem_pct)}
                                <span class="stats-bar-value">${n.mem_pct}%</span>
                            </div>
                            <div class="stats-node-footer text-muted small">
                                ${n.maxcpu} Cores · ${fmtBytes(n.maxmem)} RAM
                            </div>
                        </div>
                    `).join('')}
                </div>
            </div>

            ${d.database ? `
            <div class="stats-section">
                <h5 class="stats-section-title"><i class="bi bi-database me-2"></i>Database</h5>
                <div class="stats-grid">
                    ${statCard('database', 'DB Size', d.database.size ? fmtBytes(d.database.size) : 'N/A', '')}
                    ${statCard('table', 'Tables', d.database.tables != null ? d.database.tables : 'N/A', '')}
                </div>
            </div>
            ` : ''}

            <div class="text-muted small mt-3 text-end">
                <button class="btn btn-sm btn-outline-secondary" onclick="Settings._statsData=null;Settings.loadStatsData()">
                    <i class="bi bi-arrow-clockwise me-1"></i>Refresh
                </button>
            </div>
        `;
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

    // ── Logs Tab ─────────────────────────────────────────────────────────

    renderLogsTab(container) {
        if (!container) return;
        container.innerHTML = `
            <div class="settings-section">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="settings-section-title mb-0"><i class="bi bi-journal-text me-2"></i>Logs</h5>
                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <div class="btn-group btn-group-sm" role="group">
                            <button class="btn btn-outline-light ${this._logsSource === 'all' ? 'active' : ''}" onclick="Settings._logsSource='all';Settings._logsPage=1;Settings.loadLogs()">All</button>
                            <button class="btn btn-outline-light ${this._logsSource === 'app' ? 'active' : ''}" onclick="Settings._logsSource='app';Settings._logsPage=1;Settings.loadLogs()">App</button>
                            <button class="btn btn-outline-light ${this._logsSource === 'proxmox' ? 'active' : ''}" onclick="Settings._logsSource='proxmox';Settings._logsPage=1;Settings.loadLogs()">Proxmox</button>
                        </div>
                        <select id="logs-level-filter" class="form-select form-select-sm" style="width:auto;" onchange="Settings._logsPage=1;Settings.loadLogs()">
                            <option value="no-debug" selected>All (excl. Debug)</option>
                            <option value="">All Levels</option>
                            <option value="info">Info</option>
                            <option value="warning">Warning</option>
                            <option value="error">Error</option>
                        </select>
                        <select id="logs-category-filter" class="form-select form-select-sm" style="width:auto;" onchange="Settings._logsPage=1;Settings.loadLogs()">
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

    setLogsPage(page) {
        this._logsPage = page;
        this.loadLogs();
    },

    setLogsPerPage(perPage) {
        this._logsPerPage = perPage;
        this._logsPage = 1;
        this.loadLogs();
    },

    async loadLogs() {
        const container = document.getElementById('logs-table-container');
        if (!container) return;
        const level = document.getElementById('logs-level-filter')?.value || '';
        const category = document.getElementById('logs-category-filter')?.value || '';
        const source = this._logsSource || 'all';
        try {
            const res = await API.get('api/logs.php', { limit: 500, level, category, source });
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

            const pag = Utils.paginate(logs, this._logsPage, this._logsPerPage);
            this._logsPage = pag.page;

            const levelBadge = (lvl) => {
                const cls = lvl === 'error' ? 'bg-danger' : lvl === 'warning' ? 'bg-warning text-dark' : 'bg-info text-dark';
                return `<span class="badge ${cls}">${escapeHtml(lvl)}</span>`;
            };

            const sourceBadge = (src) => {
                if (src === 'proxmox') return '<span class="badge" style="background:#e65100;font-size:0.65rem">PVE</span>';
                return '<span class="badge" style="background:var(--accent-green);color:#000;font-size:0.65rem">App</span>';
            };

            const fmtContext = (ctx) => {
                if (!ctx) return '';
                try {
                    const obj = typeof ctx === 'string' ? JSON.parse(ctx) : ctx;
                    if (typeof obj === 'object' && obj !== null) {
                        const parts = [];
                        for (const [k, v] of Object.entries(obj)) {
                            if (v !== '' && v !== null && v !== undefined) parts.push(`${k}=${v}`);
                        }
                        return parts.length ? parts.join(' · ') : '';
                    }
                } catch (_) {}
                return String(ctx);
            };

            let html = `<div class="guest-table"><table class="table table-dark table-hover mb-0">
                <thead><tr>
                    <th>Time</th><th>Source</th><th>Level</th><th>Category</th><th>Message</th><th>User</th>
                </tr></thead><tbody>`;
            for (const l of pag.items) {
                const time = new Date(l.created_at).toLocaleString();
                const ctxStr = fmtContext(l.context);
                html += `<tr${l.source === 'proxmox' ? ' style="opacity:0.85"' : ''}>
                    <td class="text-nowrap small">${escapeHtml(time)}</td>
                    <td>${sourceBadge(l.source || 'app')}</td>
                    <td>${levelBadge(l.level)}</td>
                    <td><span class="badge bg-secondary">${escapeHtml(l.category)}</span></td>
                    <td class="small">${escapeHtml(l.message)}${ctxStr ? `<br><code class="small text-muted">${escapeHtml(ctxStr)}</code>` : ''}</td>
                    <td class="small text-muted">${escapeHtml(l.username || '-')}</td>
                </tr>`;
            }
            html += '</tbody></table></div>';
            html += Utils.paginationHtml(pag, 'Settings.setLogsPage', 'Settings.setLogsPerPage');
            container.innerHTML = html;
        } catch (err) {
            container.innerHTML = `<div class="alert alert-danger">Error: ${escapeHtml(err.message)}</div>`;
        }
    },

    // ── Affinity Rules Tab ────────────────────────────────────────────────

    _affinityData: null,
    _allVms: null,

    renderAffinityTab(container) {
        if (!container) return;
        container.innerHTML = `
            <div class="settings-section">
                <h5 class="settings-section-title"><i class="bi bi-diagram-2 me-2"></i>Affinity &amp; Anti-Affinity Rules</h5>
                <p class="text-muted small mb-4">
                    Define hardware placement rules for VMs across datacenter zones.
                    <strong>Anti-Affinity:</strong> VMs must run in <em>different</em> zones (HA separation).
                    <strong>Affinity:</strong> VMs must run in the <em>same</em> zone (co-location).
                </p>

                <div id="affinity-violations"></div>

                <h6 class="mt-4 mb-3"><i class="bi bi-geo-alt me-1"></i>Zone Groups</h6>
                <p class="text-muted small">
                    Zone groups let you define multiple independent groupings for nodes (e.g. "location", "rack", "network").
                    Each rule uses one zone group for enforcement.
                </p>
                <div class="d-flex align-items-center gap-2 mb-3" id="affinity-zone-group-bar">
                    <div class="btn-group btn-group-sm" id="affinity-zone-group-tabs"></div>
                    <button class="btn btn-sm btn-outline-primary" onclick="Settings._addZoneGroup()">
                        <i class="bi bi-plus-lg me-1"></i>New Group
                    </button>
                </div>
                <div id="affinity-zones-list">
                    <div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>
                </div>

                <h6 class="mt-4 mb-3"><i class="bi bi-link-45deg me-1"></i>Rules</h6>
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <p class="text-muted small mb-0">Define which VMs must be separated or co-located.</p>
                    <button class="btn btn-sm btn-primary" onclick="Settings._showRuleModal()">
                        <i class="bi bi-plus-lg me-1"></i>Add Rule
                    </button>
                </div>
                <div id="affinity-rules-list">
                    <div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div>
                </div>
            </div>

            <!-- Rule Modal -->
            <div class="modal fade" id="affinityRuleModal" tabindex="-1">
                <div class="modal-dialog">
                    <div class="modal-content bg-dark text-light">
                        <div class="modal-header border-secondary">
                            <h5 class="modal-title" id="affinityRuleModalTitle">Add Rule</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="affinityRuleModalBody"></div>
                        <div class="modal-footer border-secondary">
                            <button class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button class="btn btn-primary" onclick="Settings._saveRule()">Save Rule</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },

    async loadAffinityData() {
        try {
            const [overview, nodesRes] = await Promise.all([
                API.get('api/affinity.php', { action: 'overview' }),
                API.get('api/nodes.php'),
            ]);
            this._affinityData = overview;
            this._affinityNodes = nodesRes || [];

            // Ensure zone_groups always has 'default'
            const groups = overview?.zone_groups || [];
            if (!groups.includes('default')) groups.unshift('default');
            this._affinityData.zone_groups = groups;

            // Set active group if not yet set
            if (!this._activeZoneGroup || !groups.includes(this._activeZoneGroup)) {
                this._activeZoneGroup = 'default';
            }

            // Load all VMs for rule creation
            try {
                const res = await API.getSilent('api/guests.php');
                this._allVms = res || [];
            } catch (_) { this._allVms = []; }

            this._renderZoneGroupTabs();
            this._renderZones(this._affinityNodes);
            this._renderRules();
            this._renderViolations();
        } catch (e) {
            const el = document.getElementById('affinity-zones-list');
            if (el) el.innerHTML = `<div class="alert alert-danger">Error: ${Utils.escapeHtml(e.message)}</div>`;
        }
    },

    _renderViolations() {
        const el = document.getElementById('affinity-violations');
        if (!el) return;
        const violations = this._affinityData?.violations || [];
        if (violations.length === 0) {
            el.innerHTML = '';
            return;
        }
        el.innerHTML = `
            <div class="alert alert-warning" style="border-radius:var(--radius-md)">
                <div class="d-flex justify-content-between align-items-start">
                    <h6 class="alert-heading mb-0"><i class="bi bi-exclamation-triangle-fill me-1"></i>Active Violations</h6>
                    ${Permissions.has('cluster.affinity') ? `<button class="btn btn-sm btn-warning" id="btn-resolve-violations" onclick="Settings._resolveViolations()">
                        <i class="bi bi-wrench me-1"></i>Auto-Fix
                    </button>` : ''}
                </div>
                ${violations.map(v => `
                    <div class="small mb-1 mt-2">
                        <span class="badge ${v.type === 'anti-affinity' ? 'bg-danger' : 'bg-warning text-dark'} me-1">${Utils.escapeHtml(v.type)}</span>
                        <span class="badge bg-secondary me-1">${Utils.escapeHtml(v.zone_group || 'default')}</span>
                        <strong>${Utils.escapeHtml(v.rule)}:</strong> ${Utils.escapeHtml(v.message)}
                    </div>
                `).join('')}
            </div>
        `;
    },

    async _resolveViolations() {
        const btn = document.getElementById('btn-resolve-violations');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Fixing...'; }
        try {
            const res = await API.post('api/affinity.php?action=resolve', {});
            const migrations = res?.migrations || [];
            if (migrations.length === 0) {
                Toast.success('No violations found');
            } else {
                const ok = migrations.filter(m => m.status === 'running').length;
                const fail = migrations.filter(m => m.status === 'error').length;
                const details = migrations.map(m =>
                    `${m.vm_name} (${m.vmid}): ${m.source} → ${m.target} — ${m.status === 'error' ? m.error : 'migrating'}`
                ).join('\n');
                if (fail > 0) {
                    Toast.warning(`${ok} migrations started, ${fail} failed`);
                } else {
                    Toast.success(`${ok} migration${ok !== 1 ? 's' : ''} started`);
                }
                console.log('Affinity resolve results:', details);
            }
            // Reload after a short delay to show updated state
            setTimeout(() => this.loadAffinityData(), 3000);
        } catch (e) {
            Toast.error(e.message || 'Failed to resolve violations');
        } finally {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-wrench me-1"></i>Auto-Fix'; }
        }
    },

    _renderZoneGroupTabs() {
        const el = document.getElementById('affinity-zone-group-tabs');
        if (!el) return;
        const groups = this._affinityData?.zone_groups || ['default'];
        el.innerHTML = groups.map(g => `
            <button class="btn btn-sm ${g === this._activeZoneGroup ? 'btn-primary' : 'btn-outline-secondary'}"
                onclick="Settings._switchZoneGroup('${Utils.escapeHtml(g)}')">${Utils.escapeHtml(g)}</button>
        `).join('');
    },

    _switchZoneGroup(group) {
        this._activeZoneGroup = group;
        this._renderZoneGroupTabs();
        this._renderZones(this._affinityNodes || []);
    },

    _addZoneGroup() {
        const name = prompt('Zone group name (e.g. "location", "rack", "network"):');
        if (!name || !name.trim()) return;
        const clean = name.trim().toLowerCase().replace(/[^a-z0-9\-_]/g, '');
        if (!clean) { Toast.error('Invalid name — use alphanumeric, dashes, underscores'); return; }
        const groups = this._affinityData?.zone_groups || ['default'];
        if (groups.includes(clean)) {
            this._activeZoneGroup = clean;
            this._renderZoneGroupTabs();
            this._renderZones(this._affinityNodes || []);
            return;
        }
        groups.push(clean);
        this._affinityData.zone_groups = groups;
        if (!this._affinityData.zones) this._affinityData.zones = {};
        if (!this._affinityData.zones[clean]) this._affinityData.zones[clean] = {};
        this._activeZoneGroup = clean;
        this._renderZoneGroupTabs();
        this._renderZones(this._affinityNodes || []);
    },

    _renderZones(nodes) {
        const el = document.getElementById('affinity-zones-list');
        if (!el) return;
        const group = this._activeZoneGroup || 'default';
        const allZones = this._affinityData?.zones || {};
        const zones = allZones[group] || {};

        // Collect all zone names across all groups for suggestions
        const allZoneNames = new Set();
        for (const g of Object.values(allZones)) {
            for (const z of Object.values(g)) allZoneNames.add(z);
        }
        const existingZones = [...allZoneNames].sort();

        let html = `<datalist id="zone-suggestions">
            ${existingZones.map(z => `<option value="${Utils.escapeHtml(z)}">`).join('')}
        </datalist>`;
        html += `<div class="guest-table"><table class="table table-dark table-hover mb-0">
            <thead><tr><th>Node</th><th>Status</th><th>Zone <span class="text-muted small">(${Utils.escapeHtml(group)})</span></th><th style="text-align:right">Action</th></tr></thead>
            <tbody>`;

        for (const n of nodes) {
            const name = n.node || n.name || '';
            const currentZone = zones[name] || '';
            const statusColor = n.status === 'online' ? 'var(--accent-green)' : 'var(--accent-red)';
            html += `<tr>
                <td><strong>${Utils.escapeHtml(name)}</strong></td>
                <td><span style="color:${statusColor}"><i class="bi bi-circle-fill" style="font-size:0.5rem;vertical-align:middle"></i> ${Utils.escapeHtml(n.status || 'unknown')}</span></td>
                <td>
                    <input type="text" class="form-control form-control-sm" style="max-width:200px;display:inline-block"
                        id="zone-input-${Utils.escapeHtml(name)}" value="${Utils.escapeHtml(currentZone)}"
                        placeholder="e.g. dc1" list="zone-suggestions">
                </td>
                <td style="text-align:right">
                    <button class="btn btn-sm btn-outline-primary" onclick="Settings._saveZone('${Utils.escapeHtml(name)}')">
                        <i class="bi bi-check-lg"></i>
                    </button>
                    ${currentZone ? `<button class="btn btn-sm btn-outline-danger ms-1" onclick="Settings._removeZone('${Utils.escapeHtml(name)}')">
                        <i class="bi bi-x-lg"></i>
                    </button>` : ''}
                </td>
            </tr>`;
        }
        html += '</tbody></table></div>';

        // Show delete group button for non-default groups
        if (group !== 'default') {
            html += `<div class="mt-2">
                <button class="btn btn-sm btn-outline-danger" onclick="Settings._deleteZoneGroup('${Utils.escapeHtml(group)}')">
                    <i class="bi bi-trash me-1"></i>Delete Group "${Utils.escapeHtml(group)}"
                </button>
            </div>`;
        }

        el.innerHTML = html;
    },

    async _saveZone(nodeName) {
        const input = document.getElementById(`zone-input-${nodeName}`);
        const zone = input?.value?.trim();
        if (!zone) { Toast.error('Zone name required'); return; }
        const group = this._activeZoneGroup || 'default';
        const btn = input?.closest('tr')?.querySelector('.btn-outline-primary');
        if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
        try {
            await API.post('api/affinity.php?action=zone', { node: nodeName, zone, zone_group: group });
            Toast.success(`Node ${nodeName} → Zone ${zone} (${group})`);
            // Update local state
            if (!this._affinityData) this._affinityData = { zones: {}, rules: [], violations: [] };
            if (!this._affinityData.zones[group]) this._affinityData.zones[group] = {};
            this._affinityData.zones[group][nodeName] = zone;
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i>'; }
            const td = btn?.closest('td');
            if (td && !td.querySelector('.btn-outline-danger')) {
                const rmBtn = document.createElement('button');
                rmBtn.className = 'btn btn-sm btn-outline-danger ms-1';
                rmBtn.innerHTML = '<i class="bi bi-x-lg"></i>';
                rmBtn.onclick = () => Settings._removeZone(nodeName);
                td.appendChild(rmBtn);
            }
        } catch (e) {
            Toast.error(e.message);
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg"></i>'; }
        }
    },

    async _removeZone(nodeName) {
        const group = this._activeZoneGroup || 'default';
        try {
            await API.post('api/affinity.php?action=zone-remove', { node: nodeName, zone_group: group });
            Toast.success(`Zone removed from ${nodeName}`);
            if (this._affinityData?.zones?.[group]) delete this._affinityData.zones[group][nodeName];
            const input = document.getElementById(`zone-input-${nodeName}`);
            if (input) input.value = '';
            const rmBtn = input?.closest('tr')?.querySelector('.btn-outline-danger');
            if (rmBtn) rmBtn.remove();
        } catch (e) { Toast.error(e.message); }
    },

    async _deleteZoneGroup(group) {
        if (!confirm(`Delete zone group "${group}" and all its node assignments?`)) return;
        try {
            await API.post('api/affinity.php?action=zone-group-delete', { zone_group: group });
            Toast.success(`Zone group "${group}" deleted`);
            this._activeZoneGroup = 'default';
            await this.loadAffinityData();
        } catch (e) { Toast.error(e.message); }
    },

    _renderRules() {
        const el = document.getElementById('affinity-rules-list');
        if (!el) return;
        const rules = this._affinityData?.rules || [];

        if (rules.length === 0) {
            el.innerHTML = `<div class="text-center p-4" style="color:var(--text-muted)">
                <i class="bi bi-diagram-2" style="font-size:2rem;opacity:0.3"></i>
                <p class="mt-2 mb-0">No affinity rules defined</p>
            </div>`;
            return;
        }

        let html = `<div class="guest-table"><table class="table table-dark table-hover mb-0">
            <thead><tr><th>Rule</th><th>Type</th><th>Zone Group</th><th>VMs</th><th>Status</th><th style="text-align:right">Actions</th></tr></thead>
            <tbody>`;

        const allZones = this._affinityData?.zones || {};
        const violations = this._affinityData?.violations || [];

        for (const rule of rules) {
            const typeBadge = rule.type === 'anti-affinity'
                ? '<span class="badge bg-danger">Anti-Affinity</span>'
                : '<span class="badge bg-info text-dark">Affinity</span>';

            const ruleZoneGroup = rule.zone_group || 'default';
            const zones = allZones[ruleZoneGroup] || {};

            const vmHtml = (rule.vm_details || []).map(vm => {
                const zone = vm.zone || zones[vm.node] || '—';
                return `<span class="badge bg-secondary me-1 mb-1" title="Node: ${Utils.escapeHtml(vm.node || '?')}, Zone: ${Utils.escapeHtml(zone)}">${vm.vmid} (${Utils.escapeHtml(vm.name || '?')})</span>`;
            }).join('');

            const hasViolation = violations.some(v => v.rule === rule.name);
            const statusHtml = hasViolation
                ? '<span class="badge bg-warning text-dark"><i class="bi bi-exclamation-triangle-fill"></i> Violated</span>'
                : '<span class="badge bg-success"><i class="bi bi-check-circle-fill"></i> OK</span>';

            html += `<tr>
                <td><strong>${Utils.escapeHtml(rule.name)}</strong></td>
                <td>${typeBadge}</td>
                <td><span class="badge bg-secondary">${Utils.escapeHtml(ruleZoneGroup)}</span></td>
                <td>${vmHtml}</td>
                <td>${statusHtml}</td>
                <td style="text-align:right">
                    <button class="btn btn-sm btn-outline-light" onclick="Settings._showRuleModal(${rule.id})">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger ms-1" onclick="Settings._deleteRule(${rule.id})">
                        <i class="bi bi-trash"></i>
                    </button>
                </td>
            </tr>`;
        }
        html += '</tbody></table></div>';
        el.innerHTML = html;
    },

    _showRuleModal(editId) {
        const rules = this._affinityData?.rules || [];
        const rule = editId ? rules.find(r => r.id === editId) : null;
        const title = document.getElementById('affinityRuleModalTitle');
        const body = document.getElementById('affinityRuleModalBody');

        title.textContent = rule ? 'Edit Rule' : 'Add Rule';

        const vms = this._allVms || [];
        const selectedVmids = rule ? (rule.vmids || []) : [];
        const groups = this._affinityData?.zone_groups || ['default'];
        const selectedGroup = rule?.zone_group || 'default';

        body.innerHTML = `
            <input type="hidden" id="affinity-rule-id" value="${rule?.id || 0}">
            <div class="mb-3">
                <label class="form-label">Rule Name</label>
                <input type="text" class="form-control" id="affinity-rule-name" value="${Utils.escapeHtml(rule?.name || '')}" placeholder="e.g. Web Cluster HA">
            </div>
            <div class="mb-3">
                <label class="form-label">Type</label>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm ${(!rule || rule.type === 'anti-affinity') ? 'btn-danger' : 'btn-outline-danger'} affinity-type-btn" data-type="anti-affinity" onclick="Settings._selectRuleType(this)">
                        <i class="bi bi-arrows-expand me-1"></i>Anti-Affinity (separate)
                    </button>
                    <button class="btn btn-sm ${rule?.type === 'affinity' ? 'btn-info' : 'btn-outline-info'} affinity-type-btn" data-type="affinity" onclick="Settings._selectRuleType(this)">
                        <i class="bi bi-arrows-collapse me-1"></i>Affinity (co-locate)
                    </button>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label">Zone Group</label>
                <select class="form-select" id="affinity-rule-zone-group">
                    ${groups.map(g => `<option value="${Utils.escapeHtml(g)}" ${g === selectedGroup ? 'selected' : ''}>${Utils.escapeHtml(g)}</option>`).join('')}
                </select>
                <div class="form-text">Which zone grouping this rule applies to.</div>
            </div>
            <div class="mb-3">
                <label class="form-label">VMs (select at least 2)</label>
                <div style="max-height:250px;overflow-y:auto;border:1px solid var(--border-color);border-radius:var(--radius-sm);padding:0.5rem">
                    ${vms.length > 0 ? vms.map(vm => `
                        <div class="form-check">
                            <input class="form-check-input affinity-vm-check" type="checkbox" value="${vm.vmid}" id="aff-vm-${vm.vmid}"
                                ${selectedVmids.includes(vm.vmid) ? 'checked' : ''}>
                            <label class="form-check-label small" for="aff-vm-${vm.vmid}">
                                <strong>${vm.vmid}</strong> — ${Utils.escapeHtml(vm.name || '?')}
                                <span class="text-muted">(${Utils.escapeHtml(vm.node || '?')})</span>
                            </label>
                        </div>
                    `).join('') : '<p class="text-muted small mb-0">No VMs found</p>'}
                </div>
            </div>
        `;

        new bootstrap.Modal(document.getElementById('affinityRuleModal')).show();
    },

    _selectRuleType(btn) {
        document.querySelectorAll('.affinity-type-btn').forEach(b => {
            b.classList.remove('btn-danger', 'btn-info');
            b.classList.add(b.dataset.type === 'anti-affinity' ? 'btn-outline-danger' : 'btn-outline-info');
        });
        btn.classList.remove('btn-outline-danger', 'btn-outline-info');
        btn.classList.add(btn.dataset.type === 'anti-affinity' ? 'btn-danger' : 'btn-info');
    },

    async _saveRule() {
        const id = parseInt(document.getElementById('affinity-rule-id')?.value || '0');
        const name = document.getElementById('affinity-rule-name')?.value?.trim();
        const activeTypeBtn = document.querySelector('.affinity-type-btn.btn-danger, .affinity-type-btn.btn-info:not(.btn-outline-info)');
        const type = activeTypeBtn?.dataset?.type || 'anti-affinity';
        const vmids = [...document.querySelectorAll('.affinity-vm-check:checked')].map(c => parseInt(c.value));
        const zone_group = document.getElementById('affinity-rule-zone-group')?.value || 'default';

        if (!name) { Toast.error('Rule name required'); return; }
        if (vmids.length < 2) { Toast.error('Select at least 2 VMs'); return; }

        try {
            await API.post('api/affinity.php?action=rule', { id, name, type, vmids, zone_group });
            Toast.success(id ? 'Rule updated' : 'Rule created');
            bootstrap.Modal.getInstance(document.getElementById('affinityRuleModal'))?.hide();
            await this.loadAffinityData();
        } catch (e) { Toast.error(e.message); }
    },

    async _deleteRule(id) {
        if (!confirm('Delete this affinity rule?')) return;
        try {
            await API.post('api/affinity.php?action=rule-delete', { id });
            Toast.success('Rule deleted');
            await this.loadAffinityData();
        } catch (e) { Toast.error(e.message); }
    },

    // ── SSH Keys Tab ─────────────────────────────────────────────────────

    renderSshTab(container) {
        if (!container) return;
        if (!Utils.sshEnabled()) {
            container.innerHTML = Utils.sshDisabledHint();
            return;
        }
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
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-warning" onclick="Settings.rotateKey(this)" title="Generate a new key pair, deploy to all nodes, and remove the old key">
                            <i class="bi bi-arrow-repeat me-1"></i>Rotate Key
                        </button>
                        <button class="btn btn-sm btn-primary" onclick="Settings.deployKeyToNodes(this)">
                            <i class="bi bi-cloud-upload me-1"></i>Deploy to All Nodes
                        </button>
                    </div>
                </div>
                <div id="ssh-deploy-results" class="mb-3"></div>
                <p class="text-muted small mb-3">
                    <i class="bi bi-info-circle me-1"></i>Keys are automatically rotated every 4 hours and on container restart.
                </p>
                <div id="ssh-setup-commands"><div class="text-center py-3"><div class="spinner-border spinner-border-sm"></div></div></div>
            </div>

            <div class="settings-section mt-4">
                <h5 class="settings-section-title"><i class="bi bi-cloud-check me-2"></i>Cloud-Init VM Key Rotation</h5>
                <p class="text-muted small mb-3">
                    Rotate the SSH key used for Cloud-Init VMs. This generates a new keypair, updates the
                    cloud-init config on all VMs that use your current key, and attempts to update running VMs
                    via the QEMU guest agent.
                </p>

                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label fw-semibold mb-0">Affected VMs</label>
                    <div class="d-flex gap-2">
                        <button class="btn btn-sm btn-outline-info" onclick="Settings.previewCiRotation(this)">
                            <i class="bi bi-search me-1"></i>Scan VMs
                        </button>
                        <button class="btn btn-sm btn-warning" id="ci-rotate-btn" onclick="Settings.rotateCiKey(this)" disabled>
                            <i class="bi bi-arrow-repeat me-1"></i>Rotate Cloud-Init Key
                        </button>
                    </div>
                </div>
                <div id="ci-rotate-preview" class="mb-3"></div>
                <div id="ci-rotate-results" class="mb-3"></div>
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

    _promptSshPassword(action) {
        return new Promise((resolve) => {
            const modalId = 'ssh-password-modal';
            let modal = document.getElementById(modalId);
            if (modal) modal.remove();

            modal = document.createElement('div');
            modal.id = modalId;
            modal.className = 'modal fade';
            modal.tabIndex = -1;
            modal.innerHTML = `
                <div class="modal-dialog modal-sm modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header py-2">
                            <h6 class="modal-title"><i class="bi bi-shield-lock me-2"></i>SSH Password</h6>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="small text-muted mb-2">
                                Key-based authentication failed. Enter the SSH root password to ${action === 'rotate' ? 'rotate the key' : 'deploy the key'}.
                                The password is only used for this operation and will not be stored.
                            </p>
                            <input type="password" class="form-control form-control-sm" id="ssh-onetime-pw" placeholder="SSH root password" autocomplete="off">
                        </div>
                        <div class="modal-footer py-2">
                            <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-sm btn-primary" id="ssh-pw-confirm">
                                <i class="bi bi-key me-1"></i>Continue
                            </button>
                        </div>
                    </div>
                </div>`;
            document.body.appendChild(modal);

            const bsModal = new bootstrap.Modal(modal);
            let resolved = false;

            const submit = () => {
                const pw = document.getElementById('ssh-onetime-pw')?.value?.trim();
                resolved = true;
                bsModal.hide();
                resolve(pw || null);
            };

            modal.querySelector('#ssh-pw-confirm').addEventListener('click', submit);
            modal.querySelector('#ssh-onetime-pw').addEventListener('keydown', e => {
                if (e.key === 'Enter') submit();
            });
            modal.addEventListener('hidden.bs.modal', () => {
                if (!resolved) resolve(null);
                setTimeout(() => modal.remove(), 300);
            });

            bsModal.show();
            setTimeout(() => modal.querySelector('#ssh-onetime-pw')?.focus(), 300);
        });
    },

    _renderSshResults(resultsEl, results) {
        resultsEl.innerHTML = results.map(r => `
            <div class="d-flex align-items-center gap-2 mb-1">
                <i class="bi ${r.success ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'}"></i>
                <span class="small">${escapeHtml(r.node)} (${escapeHtml(r.ip)})${r.error ? ' — ' + escapeHtml(r.error) : ''}</span>
            </div>`).join('');
    },

    async rotateKey(btn, password) {
        const resultsEl = document.getElementById('ssh-deploy-results');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Rotating...';
        resultsEl.innerHTML = '';
        try {
            const payload = password ? { password } : {};
            const res = await API.post('api/ssh-rotate-key.php', payload);
            this._renderSshResults(resultsEl, res.results);
            const allOk = res.results.every(r => r.success);
            if (allOk) {
                Toast.success('SSH key rotated successfully');
                if (res.new_public_key) {
                    const keyEl = document.getElementById('ssh-setup-pubkey');
                    if (keyEl) keyEl.textContent = res.new_public_key;
                }
            } else if (res.needs_password && !password) {
                // Auth failed — prompt for password and retry
                const pw = await this._promptSshPassword('rotate');
                if (pw) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Rotate Key';
                    return this.rotateKey(btn, pw);
                }
                Toast.warning('Rotation cancelled');
            } else {
                Toast.warning('Rotation failed on some nodes — old key kept');
            }
        } catch (e) {
            const errData = e.details || {};
            if (errData.needs_password && !password) {
                const pw = await this._promptSshPassword('rotate');
                if (pw) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Rotate Key';
                    return this.rotateKey(btn, pw);
                }
                resultsEl.innerHTML = '';
            } else {
                resultsEl.innerHTML = `<p class="text-danger small">${escapeHtml(e.message || 'Rotation failed')}</p>`;
            }
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Rotate Key';
        }
    },

    // ── Cloud-Init Key Rotation ────────────────────────────────────────

    async previewCiRotation(btn) {
        const previewEl = document.getElementById('ci-rotate-preview');
        const rotateBtn = document.getElementById('ci-rotate-btn');
        if (!previewEl) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Scanning...';
        previewEl.innerHTML = '';

        try {
            const res = await API.previewCiKeyRotation();

            if (!res.current_key) {
                previewEl.innerHTML = '<p class="text-warning small"><i class="bi bi-exclamation-triangle me-1"></i>No SSH key found. Generate one via the Deploy form first.</p>';
                return;
            }

            if (!res.vms || res.vms.length === 0) {
                previewEl.innerHTML = '<p class="text-muted small"><i class="bi bi-info-circle me-1"></i>No VMs found with your current SSH key.</p>';
                return;
            }

            previewEl.innerHTML = `
                <div class="small text-muted mb-2">${res.vms.length} VM(s) found with your current key:</div>
                <div class="ci-rotate-vm-list">
                    ${res.vms.map(vm => `
                        <div class="d-flex align-items-center gap-2 mb-1">
                            <span class="badge ${vm.status === 'running' ? 'bg-success' : 'bg-secondary'}" style="min-width:60px">${escapeHtml(vm.status)}</span>
                            <span class="small fw-semibold">${escapeHtml(String(vm.vmid))}</span>
                            <span class="small text-muted">${escapeHtml(vm.name)}</span>
                            <span class="small text-muted ms-auto">${escapeHtml(vm.node)}</span>
                        </div>
                    `).join('')}
                </div>
            `;
            if (rotateBtn) rotateBtn.disabled = false;
        } catch (e) {
            previewEl.innerHTML = `<p class="text-danger small">${escapeHtml(e.message || 'Scan failed')}</p>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-search me-1"></i>Scan VMs';
        }
    },

    async rotateCiKey(btn) {
        const resultsEl = document.getElementById('ci-rotate-results');
        const previewEl = document.getElementById('ci-rotate-preview');
        if (!resultsEl) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Rotating...';
        resultsEl.innerHTML = '';

        try {
            const res = await API.rotateCiKey();

            // Show results per VM
            if (res.results && res.results.length > 0) {
                resultsEl.innerHTML = res.results.map(r => {
                    let icon, text;
                    if (r.error) {
                        icon = 'bi-x-circle-fill text-danger';
                        text = `${r.name} (${r.vmid}) — ${r.error}`;
                    } else if (r.agent_updated) {
                        icon = 'bi-check-circle-fill text-success';
                        text = `${r.name} (${r.vmid}) — config + live updated`;
                    } else if (r.config_updated && r.needs_restart) {
                        icon = 'bi-exclamation-circle-fill text-warning';
                        text = `${r.name} (${r.vmid}) — config updated, restart needed`;
                    } else if (r.config_updated) {
                        icon = 'bi-check-circle-fill text-success';
                        text = `${r.name} (${r.vmid}) — config updated`;
                    } else {
                        icon = 'bi-dash-circle text-muted';
                        text = `${r.name} (${r.vmid}) — skipped`;
                    }
                    return `<div class="d-flex align-items-center gap-2 mb-1">
                        <i class="bi ${icon}"></i>
                        <span class="small">${escapeHtml(text)}</span>
                    </div>`;
                }).join('');
            }

            // Offer private key download
            if (res.private_key) {
                const blob = new Blob([res.private_key], { type: 'application/x-pem-file' });
                const url = URL.createObjectURL(blob);
                resultsEl.innerHTML += `
                    <div class="alert alert-info mt-3 py-2 px-3 small">
                        <i class="bi bi-download me-1"></i>
                        <strong>Save your new private key now!</strong> It cannot be retrieved later.
                        <a href="${url}" download="id_ed25519_cloud_init" class="btn btn-sm btn-info ms-2">
                            <i class="bi bi-download me-1"></i>Download Private Key
                        </a>
                    </div>
                `;
            }

            // Update the user's key in the deploy form if open
            if (res.public_key && window.APP_USER) {
                window.APP_USER.ssh_public_keys = res.public_key;
            }

            Toast.success(`Cloud-Init key rotated — ${res.updated} VM(s) updated`);
            if (previewEl) previewEl.innerHTML = '';
        } catch (e) {
            resultsEl.innerHTML = `<p class="text-danger small">${escapeHtml(e.message || 'Rotation failed')}</p>`;
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i>Rotate Cloud-Init Key';
        }
    },

    async deployKeyToNodes(btn, password) {
        const resultsEl = document.getElementById('ssh-deploy-results');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Deploying...';
        resultsEl.innerHTML = '';
        try {
            const payload = password ? { password } : {};
            const res = await API.post('api/ssh-deploy-key.php', payload);
            this._renderSshResults(resultsEl, res.results);
            const allOk = res.results.every(r => r.success);
            if (allOk) Toast.success('SSH key deployed to all nodes');
            else Toast.warning('Some nodes failed — see details above');
        } catch (e) {
            const errData = e.details || {};
            if ((errData.needs_password || (e.message && e.message.includes('SSH password required'))) && !password) {
                const pw = await this._promptSshPassword('deploy');
                if (pw) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Deploy to All Nodes';
                    return this.deployKeyToNodes(btn, pw);
                }
                resultsEl.innerHTML = '';
            } else {
                resultsEl.innerHTML = `<p class="text-danger small">${escapeHtml(e.message || 'Deploy failed')}</p>`;
            }
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-cloud-upload me-1"></i>Deploy to All Nodes';
        }
    },

    // ── Vault Tab ────────────────────────────────────────────────────────────

    _vaultData: null,

    renderVaultTab(container) {
        container.innerHTML = `
            <div class="stat-card mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0"><i class="bi bi-shield-lock me-2"></i>Secret Vault</h5>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm" onclick="Settings.vaultMigrate()" id="vault-migrate-btn">
                            <i class="bi bi-box-arrow-in-down me-1"></i>Import from .env
                        </button>
                    </div>
                </div>
                <p class="text-muted small mb-3">
                    Secrets are encrypted with AES-256-GCM using the ENCRYPTION_KEY (Docker Secret).
                    Only database credentials remain in .env — the master key is stored as a Docker Secret in <code>secrets/encryption_key.txt</code>.
                </p>
                <div id="vault-status" class="mb-3"></div>
                <div id="vault-table"></div>
            </div>
        `;
    },

    async loadVaultData() {
        const statusEl = document.getElementById('vault-status');
        const tableEl = document.getElementById('vault-table');
        if (!statusEl) return;

        try {
            const data = await API.get('api/vault.php');
            this._vaultData = data;
            const esc = Utils.escapeHtml;

            if (!data.available) {
                statusEl.innerHTML = `
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong>Vault not active.</strong> The encryption key is missing. Create the Docker Secret file:
                        <br><small class="text-muted"><code>mkdir -p secrets &amp;&amp; openssl rand -hex 32 &gt; secrets/encryption_key.txt</code></small>
                        <br><small class="text-muted">Then restart: <code>docker compose up -d</code></small>
                    </div>`;
                tableEl.innerHTML = '';
                return;
            }

            statusEl.innerHTML = `
                <div class="d-flex gap-3">
                    <span class="badge bg-success"><i class="bi bi-shield-check me-1"></i>Vault active</span>
                    <span class="text-muted small">${data.total_in_vault} / ${data.entries.length} secrets in vault</span>
                </div>`;

            // Group keys
            const groups = {
                'Proxmox API': ['PROXMOX_HOST', 'PROXMOX_PORT', 'PROXMOX_VERIFY_SSL', 'PROXMOX_FALLBACK_HOSTS', 'PROXMOX_TOKEN_ID', 'PROXMOX_TOKEN_SECRET'],
                'Application': ['APP_SECRET'],
                'SSH': ['SSH_ENABLED', 'SSH_PORT', 'SSH_USER', 'SSH_PRIVATE_KEY', 'SSH_KEY_PATH', 'SSH_PASSWORD'],
                'Entra ID / Azure AD': ['ENTRAID_TENANT_ID', 'ENTRAID_CLIENT_ID', 'ENTRAID_CLIENT_SECRET', 'ENTRAID_REDIRECT_URI'],
                'Other': ['CLOUD_DISTROS', 'DOMAIN', 'LETSENCRYPT_EMAIL'],
            };

            const entryMap = {};
            for (const e of data.entries) entryMap[e.key] = e;

            let html = '';
            for (const [group, keys] of Object.entries(groups)) {
                html += `<h6 class="mt-3 mb-2 text-muted small text-uppercase">${esc(group)}</h6>`;
                html += `<div class="guest-table mb-2"><table class="table table-dark table-hover table-sm mb-0"><tbody>`;
                for (const key of keys) {
                    const e = entryMap[key];
                    if (!e) continue;
                    const sensitive = key.includes('SECRET') || key.includes('PASSWORD') || key === 'APP_SECRET' || key === 'SSH_PRIVATE_KEY';
                    const statusBadge = e.in_vault
                        ? '<span class="badge bg-success"><i class="bi bi-shield-lock-fill"></i> Vault</span>'
                        : e.in_env
                            ? '<span class="badge bg-warning text-dark"><i class="bi bi-file-earmark-text"></i> .env</span>'
                            : '<span class="badge bg-secondary">Not set</span>';
                    const updated = e.updated_at ? `<small class="text-muted">${new Date(e.updated_at).toLocaleString()}</small>` : '';

                    html += `<tr>
                        <td style="width:250px"><code class="small">${esc(key)}</code></td>
                        <td style="width:100px">${statusBadge}</td>
                        <td style="width:180px">${updated}</td>
                        <td>
                            <div class="d-flex gap-1 justify-content-end">
                                <button class="btn btn-outline-light btn-sm py-0 px-2" onclick="Settings.vaultEdit('${esc(key)}', ${sensitive})" title="Edit">
                                    <i class="bi bi-pencil-fill" style="font-size:0.75rem"></i>
                                </button>
                                ${e.in_vault ? `<button class="btn btn-outline-danger btn-sm py-0 px-2" onclick="Settings.vaultDelete('${esc(key)}')" title="Remove from vault">
                                    <i class="bi bi-trash-fill" style="font-size:0.75rem"></i>
                                </button>` : ''}
                            </div>
                        </td>
                    </tr>`;
                }
                html += `</tbody></table></div>`;
            }

            tableEl.innerHTML = html;
        } catch (err) {
            statusEl.innerHTML = `<div class="alert alert-danger">${Utils.escapeHtml(err.message)}</div>`;
        }
    },

    vaultEdit(key, sensitive = false) {
        const currentVal = '';
        const inputType = sensitive ? 'password' : 'text';
        const isTextarea = key === 'SSH_PRIVATE_KEY';
        const modal = document.createElement('div');
        modal.className = 'modal fade show d-block';
        modal.style.background = 'rgba(0,0,0,0.6)';
        const inputHtml = isTextarea
            ? `<textarea class="form-control bg-dark text-light border-secondary font-monospace" id="vault-edit-value"
                    rows="12" placeholder="Paste SSH private key here..." autocomplete="off" style="font-size:0.8rem"></textarea>
               <small class="text-muted mt-1 d-block">Paste the full private key including BEGIN/END lines. Stored encrypted in vault — no file on disk needed.</small>`
            : `<input type="${inputType}" class="form-control bg-dark text-light border-secondary" id="vault-edit-value"
                    placeholder="Enter new value..." autocomplete="off">
               <small class="text-muted mt-1 d-block">Leave empty and save to keep current value unchanged.</small>`;
        modal.innerHTML = `
            <div class="modal-dialog modal-dialog-centered${isTextarea ? ' modal-lg' : ''}">
                <div class="modal-content bg-dark text-light border-secondary">
                    <div class="modal-header border-secondary">
                        <h5 class="modal-title"><i class="bi bi-shield-lock me-2"></i>Edit Secret</h5>
                        <button type="button" class="btn-close btn-close-white" onclick="this.closest('.modal').remove()"></button>
                    </div>
                    <div class="modal-body">
                        <label class="form-label"><code>${Utils.escapeHtml(key)}</code></label>
                        ${inputHtml}
                    </div>
                    <div class="modal-footer border-secondary">
                        <button class="btn btn-secondary btn-sm" onclick="this.closest('.modal').remove()">Cancel</button>
                        <button class="btn btn-primary btn-sm" onclick="Settings.vaultSave('${Utils.escapeHtml(key)}', this.closest('.modal'))">
                            <i class="bi bi-shield-lock me-1"></i>Save to Vault
                        </button>
                    </div>
                </div>
            </div>`;
        document.body.appendChild(modal);
        modal.querySelector('#vault-edit-value').focus();
    },

    async vaultSave(key, modal) {
        const input = modal.querySelector('#vault-edit-value');
        const value = input.value.trim();
        if (!value) {
            modal.remove();
            return;
        }

        try {
            await API.post('api/vault.php', {
                action: 'save',
                secrets: { [key]: value },
            });
            modal.remove();
            Toast.success(`${key} saved to vault`);
            this.loadVaultData();
        } catch (err) {
            Toast.error(err.message);
        }
    },

    async vaultDelete(key) {
        if (!confirm(`Remove "${key}" from the vault? The value in .env (if any) will be used as fallback.`)) return;

        try {
            await API.post('api/vault.php', { action: 'delete', key });
            Toast.success(`${key} removed from vault`);
            this.loadVaultData();
        } catch (err) {
            Toast.error(err.message);
        }
    },

    async vaultMigrate() {
        const btn = document.getElementById('vault-migrate-btn');
        if (!btn) return;

        if (!confirm('Import all secrets from .env into the encrypted vault? Existing vault entries will not be overwritten.')) return;

        btn.disabled = true;
        try {
            const data = await API.post('api/vault.php', { action: 'migrate' });
            Toast.success(data.message || 'Migration complete');
            this.loadVaultData();
        } catch (err) {
            Toast.error(err.message);
        } finally {
            btn.disabled = false;
        }
    },
};
