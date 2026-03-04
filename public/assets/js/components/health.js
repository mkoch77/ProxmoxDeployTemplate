const Health = {
    refreshInterval: null,
    data: null,

    async init() {
        this.render();
        await this.loadData();
        this.startAutoRefresh();
    },

    destroy() {
        this.stopAutoRefresh();
    },

    startAutoRefresh() {
        this.stopAutoRefresh();
        this.refreshInterval = setInterval(() => this.loadData(), 15000);
    },

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    },

    render() {
        const main = document.getElementById('page-content');
        main.innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-heart-pulse-fill"></i> Cluster Health</h2>
            </div>
            <div id="health-cluster-stats" class="row g-3 mb-4"></div>
            <div class="section-header mt-4">
                <h2><i class="bi bi-hdd-rack-fill"></i> Nodes</h2>
            </div>
            <div id="health-nodes" class="row g-3 mb-4"></div>
            <div class="section-header mt-4">
                <h2><i class="bi bi-device-hdd-fill"></i> Storage Pools</h2>
            </div>
            <div id="health-storage" class="mb-4"></div>
            <div id="health-ha" class="mb-4"></div>
        `;
    },

    async loadData() {
        try {
            const data = await API.getClusterHealth();
            this.data = data;
            this.updateView();
        } catch (err) {
            Toast.error('Failed to load cluster data');
        }
    },

    updateView() {
        if (!this.data) return;
        this.renderClusterStats();
        this.renderNodes();
        this.renderStorage();
        this.renderHA();
    },

    renderClusterStats() {
        const c = this.data.cluster;
        const cpuPct = Math.round(c.total_cpu * 100);
        const memPct = c.total_maxmem > 0 ? Math.round((c.total_mem / c.total_maxmem) * 100) : 0;
        const diskPct = c.total_maxdisk > 0 ? Math.round((c.total_disk / c.total_maxdisk) * 100) : 0;

        document.getElementById('health-cluster-stats').innerHTML = `
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-hdd-rack"></i></div>
                    <div class="stat-value">${c.nodes_online}/${c.total_nodes}</div>
                    <div class="stat-label">Nodes Online</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-cpu"></i></div>
                    <div class="stat-value">${cpuPct}%</div>
                    <div class="stat-label">CPU Usage</div>
                    <div class="resource-bar mt-2"><div class="progress"><div class="progress-bar ${this.levelClass(cpuPct)}" style="width:${cpuPct}%"></div></div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-memory"></i></div>
                    <div class="stat-value">${Utils.formatBytes(c.total_mem)}</div>
                    <div class="stat-label">RAM (${Utils.formatBytes(c.total_maxmem)} total)</div>
                    <div class="resource-bar mt-2"><div class="progress"><div class="progress-bar ${this.levelClass(memPct)}" style="width:${memPct}%"></div></div></div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-display"></i></div>
                    <div class="stat-value">${c.total_running}/${c.total_vms}</div>
                    <div class="stat-label">VMs Running</div>
                </div>
            </div>
        `;
    },

    renderNodes() {
        const container = document.getElementById('health-nodes');
        if (!this.data.nodes || this.data.nodes.length === 0) return;
        const nodes = this.data.nodes.slice().sort((a, b) => a.node.localeCompare(b.node));
        container.innerHTML = nodes.map(node => {
            const isOnline = node.status === 'online';
            const maint = node.maintenance;
            const cpuPct = isOnline ? Math.round((node.cpu || 0) * 100) : 0;
            const memPct = isOnline && node.maxmem > 0 ? Math.round((node.mem / node.maxmem) * 100) : 0;
            const diskPct = isOnline && node.maxdisk > 0 ? Math.round((node.disk / node.maxdisk) * 100) : 0;

            let statusBadge = isOnline
                ? '<span class="badge badge-online">Online</span>'
                : '<span class="badge badge-offline">Offline</span>';

            if (maint) {
                const maintStatus = maint.status === 'maintenance' ? 'Maintenance' : 'Migrating...';
                statusBadge += ` <span class="badge badge-maintenance">${maintStatus}</span>`;
            }

            return `
                <div class="col-md-6 col-xl-4">
                    <div class="node-card ${maint ? 'maintenance' : ''} ${!isOnline ? 'offline' : ''}">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="bi bi-hdd-rack me-2"></i>${escapeHtml(node.node)}</h5>
                            <div>${statusBadge}</div>
                        </div>
                        ${isOnline ? `
                            <div class="resource-item">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">CPU</small>
                                    <small>${cpuPct}% (${node.maxcpu || 0} cores)</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div class="progress-bar ${this.levelClass(cpuPct)}" style="width:${cpuPct}%"></div></div></div>
                            </div>
                            <div class="resource-item mt-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">RAM</small>
                                    <small>${Utils.formatBytes(node.mem || 0)} / ${Utils.formatBytes(node.maxmem || 0)}</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div class="progress-bar ${this.levelClass(memPct)}" style="width:${memPct}%"></div></div></div>
                            </div>
                            <div class="resource-item mt-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">Disk</small>
                                    <small>${Utils.formatBytes(node.disk || 0)} / ${Utils.formatBytes(node.maxdisk || 0)}</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div class="progress-bar ${this.levelClass(diskPct)}" style="width:${diskPct}%"></div></div></div>
                            </div>
                            <div class="mt-3 text-muted small">
                                <i class="bi bi-clock me-1"></i>Uptime: ${Utils.formatUptime(node.uptime || 0)}
                            </div>
                        ` : `
                            <div class="text-muted text-center py-3">Node unreachable</div>
                        `}
                    </div>
                </div>
            `;
        }).join('');
    },

    renderStorage() {
        const container = document.getElementById('health-storage');
        if (!this.data.storage.length) {
            container.innerHTML = '<p class="text-muted">No storage data available</p>';
            return;
        }

        container.innerHTML = `
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Used</th>
                            <th>Total</th>
                            <th style="width:200px">Usage</th>
                            <th>Nodes</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${this.data.storage.map(s => {
                            const pct = s.total > 0 ? Math.round((s.used / s.total) * 100) : 0;
                            return `
                                <tr>
                                    <td><strong>${escapeHtml(s.storage)}</strong></td>
                                    <td><span class="badge bg-secondary">${escapeHtml(s.type)}</span></td>
                                    <td>${Utils.formatBytes(s.used)}</td>
                                    <td>${Utils.formatBytes(s.total)}</td>
                                    <td>
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="resource-bar flex-grow-1"><div class="progress"><div class="progress-bar ${this.levelClass(pct)}" style="width:${pct}%"></div></div></div>
                                            <small>${pct}%</small>
                                        </div>
                                    </td>
                                    <td class="text-muted small">${s.nodes.slice().sort().join(', ')}</td>
                                </tr>
                            `;
                        }).join('')}
                    </tbody>
                </table>
            </div>
        `;
    },

    renderHA() {
        const container = document.getElementById('health-ha');
        if (!this.data.ha) {
            container.innerHTML = '';
            return;
        }

        const resources = this.data.ha.resources || [];
        const statusList = this.data.ha.status || [];

        // Build node lookup from HA status entries (they contain the current node)
        const nodeMap = {};
        for (const s of statusList) {
            if (s.sid) nodeMap[s.sid] = s.node || '';
        }

        container.innerHTML = `
            <div class="section-header mt-4">
                <h2><i class="bi bi-shield-check"></i> HA Status</h2>
            </div>
            ${resources.length > 0 ? `
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle">
                        <thead>
                            <tr><th>Resource</th><th>Status</th><th>Node</th><th>Group</th></tr>
                        </thead>
                        <tbody>
                            ${resources.map(r => {
                                const node = r.node || nodeMap[r.sid] || '';
                                const name = r.name ? `${escapeHtml(r.name)} <small class="text-muted">(${escapeHtml(r.sid || '')})</small>` : escapeHtml(r.sid || '');
                                return `
                                <tr>
                                    <td>${name}</td>
                                    <td><span class="badge ${r.state === 'started' ? 'bg-success' : 'bg-secondary'}">${escapeHtml(r.state || '')}</span></td>
                                    <td>${escapeHtml(node)}</td>
                                    <td>${escapeHtml(r.group || '-')}</td>
                                </tr>
                            `}).join('')}
                        </tbody>
                    </table>
                </div>
            ` : '<p class="text-muted">No HA resources configured</p>'}
        `;
    },

    levelClass(pct) {
        if (pct >= 90) return 'level-danger';
        if (pct >= 70) return 'level-warn';
        return 'level-ok';
    },
};
