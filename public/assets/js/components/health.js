const Health = {
    refreshInterval: null,
    data: null,
    storageSort: { col: 'storage', dir: 'asc' },
    haSort: { col: 'resource', dir: 'asc' },

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
        this.refreshInterval = setInterval(() => this.loadData(true), 15000);
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
            <div id="health-rightsizing" class="mb-4"></div>
            <div id="health-ha" class="mb-4"></div>
        `;
    },

    async loadData(silent = false) {
        try {
            const data = silent
                ? await API.getSilent('api/cluster-health.php')
                : await API.getClusterHealth();
            this.data = data;
            this.updateView();
            this.loadNodeVersions();
            this.loadRightSizing(silent);
        } catch (err) {
            if (!silent) Toast.error('Failed to load cluster data');
        }
    },

    async loadNodeVersions() {
        if (!this.data?.nodes) return;
        const onlineNodes = this.data.nodes.filter(n => n.status === 'online');
        await Promise.allSettled(onlineNodes.map(async node => {
            try {
                const info = await API.getSilent('api/node-info.php', { node: node.node });
                const verEl = document.getElementById(`node-pve-version-${node.node}`);
                if (verEl && info.pve_version) verEl.textContent = info.pve_version;
                const ipEl = document.getElementById(`node-ip-${node.node}`);
                if (ipEl && info.ip) ipEl.textContent = info.ip;
            } catch (_) {}
        }));
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

        const nodesColor = c.nodes_online < c.total_nodes ? 'color:var(--bs-danger)' : '';

        // In-place update if already rendered
        if (document.getElementById('hcs-nodes')) {
            document.getElementById('hcs-vms').textContent       = `${c.total_qemu_running}/${c.total_qemu}`;
            document.getElementById('hcs-cts').textContent       = `${c.total_lxc_running}/${c.total_lxc}`;
            const nodesEl = document.getElementById('hcs-nodes');
            nodesEl.textContent = `${c.nodes_online}/${c.total_nodes}`;
            nodesEl.style.cssText = nodesColor;
            document.getElementById('hcs-cpu-val').textContent   = `${cpuPct}%`;
            document.getElementById('hcs-mem-val').textContent   = Utils.formatBytes(c.total_mem);
            document.getElementById('hcs-mem-label').textContent = `RAM (${Utils.formatBytes(c.total_maxmem)} total)`;
            const cpuBar = document.getElementById('hcs-cpu-bar');
            cpuBar.className = `progress-bar ${this.levelClass(cpuPct)}`; cpuBar.style.width = `${cpuPct}%`;
            const memBar = document.getElementById('hcs-mem-bar');
            memBar.className = `progress-bar ${this.levelClass(memPct)}`; memBar.style.width = `${memPct}%`;
            return;
        }

        document.getElementById('health-cluster-stats').innerHTML = `
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-display"></i></div>
                    <div class="stat-value" id="hcs-vms">${c.total_qemu_running}/${c.total_qemu}</div>
                    <div class="stat-label">VMs Running</div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-box-fill"></i></div>
                    <div class="stat-value" id="hcs-cts">${c.total_lxc_running}/${c.total_lxc}</div>
                    <div class="stat-label">CTs Running</div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-hdd-rack"></i></div>
                    <div class="stat-value" id="hcs-nodes" style="${nodesColor}">${c.nodes_online}/${c.total_nodes}</div>
                    <div class="stat-label">Nodes Online</div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-cpu"></i></div>
                    <div class="stat-value" id="hcs-cpu-val">${cpuPct}%</div>
                    <div class="stat-label">CPU Usage</div>
                    <div class="resource-bar mt-2"><div class="progress"><div id="hcs-cpu-bar" class="progress-bar ${this.levelClass(cpuPct)}" style="width:${cpuPct}%"></div></div></div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-memory"></i></div>
                    <div class="stat-value" id="hcs-mem-val">${Utils.formatBytes(c.total_mem)}</div>
                    <div class="stat-label" id="hcs-mem-label">RAM (${Utils.formatBytes(c.total_maxmem)} total)</div>
                    <div class="resource-bar mt-2"><div class="progress"><div id="hcs-mem-bar" class="progress-bar ${this.levelClass(memPct)}" style="width:${memPct}%"></div></div></div>
                </div>
            </div>
        `;
    },

    renderNodes() {
        const container = document.getElementById('health-nodes');
        if (!this.data.nodes || this.data.nodes.length === 0) return;
        const nodes = this.data.nodes.slice().sort((a, b) => a.node.localeCompare(b.node));

        // Structural key: node names + online/offline + maintenance status
        const nodesKey = nodes.map(n => `${n.node}:${n.status}:${n.maintenance?.status || ''}`).join('|');
        if (nodesKey === this._lastNodesKey && container.querySelector('.node-card')) {
            for (const node of nodes) {
                if (node.status !== 'online') continue;
                const id = node.node;
                const cpuPct  = Math.round((node.cpu || 0) * 100);
                const memPct  = node.maxmem > 0 ? Math.round((node.mem / node.maxmem) * 100) : 0;
                const diskPct = node.maxdisk > 0 ? Math.round((node.disk / node.maxdisk) * 100) : 0;
                const setBar = (elId, pct) => {
                    const el = document.getElementById(elId);
                    if (el) { el.style.width = `${pct}%`; el.className = `progress-bar ${this.levelClass(pct)}`; }
                };
                const setText = (elId, text) => { const el = document.getElementById(elId); if (el) el.textContent = text; };
                setText(`hn-cpu-val-${id}`, `${cpuPct}% (${node.maxcpu || 0} cores)`);
                setBar(`hn-cpu-bar-${id}`, cpuPct);
                setText(`hn-ram-val-${id}`, `${Utils.formatBytes(node.mem || 0)} / ${Utils.formatBytes(node.maxmem || 0)}`);
                setBar(`hn-ram-bar-${id}`, memPct);
                setText(`hn-disk-val-${id}`, `${Utils.formatBytes(node.disk || 0)} / ${Utils.formatBytes(node.maxdisk || 0)}`);
                setBar(`hn-disk-bar-${id}`, diskPct);
                setText(`hn-uptime-${id}`, `Uptime: ${Utils.formatUptime(node.uptime || 0)}`);
            }
            return;
        }
        this._lastNodesKey = nodesKey;

        container.innerHTML = nodes.map(node => {
            const id = node.node;
            const isOnline = node.status === 'online';
            const maint = node.maintenance;
            const cpuPct  = isOnline ? Math.round((node.cpu || 0) * 100) : 0;
            const memPct  = isOnline && node.maxmem > 0 ? Math.round((node.mem / node.maxmem) * 100) : 0;
            const diskPct = isOnline && node.maxdisk > 0 ? Math.round((node.disk / node.maxdisk) * 100) : 0;

            let statusBadge = isOnline
                ? '<span class="badge badge-online">Online</span>'
                : '<span class="badge badge-offline">Offline</span>';
            if (maint) {
                statusBadge += ` <span class="badge badge-maintenance">${maint.status === 'maintenance' ? 'Maintenance' : 'Migrating...'}</span>`;
            }

            return `
                <div class="col-md-6 col-xl-4">
                    <div class="node-card ${maint ? 'maintenance' : ''} ${!isOnline ? 'offline' : ''}" style="cursor:${isOnline ? 'pointer' : 'default'}" ${isOnline ? `onclick="Health.showNodeInfo('${escapeHtml(id)}')"` : ''}>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="mb-0"><i class="bi bi-hdd-rack me-2"></i>${escapeHtml(id)}</h5>
                                ${isOnline ? `<small class="text-muted" id="node-ip-${escapeHtml(id)}"></small>` : ''}
                            </div>
                            <div>${statusBadge}</div>
                        </div>
                        ${isOnline ? `
                            <div class="resource-item">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">CPU</small>
                                    <small id="hn-cpu-val-${id}">${cpuPct}% (${node.maxcpu || 0} cores)</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div id="hn-cpu-bar-${id}" class="progress-bar ${this.levelClass(cpuPct)}" style="width:${cpuPct}%"></div></div></div>
                            </div>
                            <div class="resource-item mt-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">RAM</small>
                                    <small id="hn-ram-val-${id}">${Utils.formatBytes(node.mem || 0)} / ${Utils.formatBytes(node.maxmem || 0)}</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div id="hn-ram-bar-${id}" class="progress-bar ${this.levelClass(memPct)}" style="width:${memPct}%"></div></div></div>
                            </div>
                            <div class="resource-item mt-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">Disk</small>
                                    <small id="hn-disk-val-${id}">${Utils.formatBytes(node.disk || 0)} / ${Utils.formatBytes(node.maxdisk || 0)}</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div id="hn-disk-bar-${id}" class="progress-bar ${this.levelClass(diskPct)}" style="width:${diskPct}%"></div></div></div>
                            </div>
                            <div class="mt-3 d-flex justify-content-between align-items-center">
                                <span class="text-muted small"><i class="bi bi-clock me-1"></i><span id="hn-uptime-${id}">Uptime: ${Utils.formatUptime(node.uptime || 0)}</span></span>
                                <span class="text-muted small"><i class="bi bi-box me-1"></i><span id="node-pve-version-${escapeHtml(id)}">PVE ...</span></span>
                            </div>
                        ` : `
                            <div class="text-muted text-center py-3">Node unreachable</div>
                        `}
                    </div>
                </div>
            `;
        }).join('');
    },

    async showNodeInfo(nodeName) {
        const modal = new bootstrap.Modal(document.getElementById('nodeInfoModal'));
        document.getElementById('node-info-title').innerHTML = `<i class="bi bi-hdd-rack me-2"></i>${escapeHtml(nodeName)}`;
        document.getElementById('node-info-body').innerHTML = '<div class="text-center py-4"><span class="spinner-border text-secondary"></span></div>';
        modal.show();

        try {
            const info = await API.getNodeInfo(nodeName);
            document.getElementById('node-info-body').innerHTML = this.renderNodeInfoBody(info);
        } catch (e) {
            document.getElementById('node-info-body').innerHTML = '<p class="text-danger">Failed to load node info.</p>';
        }
    },

    renderNodeInfoBody(info) {
        const row = (label, value) => value != null && value !== ''
            ? `<tr><td class="text-muted" style="width:40%">${label}</td><td>${value}</td></tr>`
            : '';

        const cpu = info.cpu || {};
        const mem = info.memory || {};
        const rootfs = info.rootfs || {};
        const loadAvg = info.load_avg || [];

        const cpuTitle = [
            cpu.model,
            cpu.sockets && cpu.cores ? `${cpu.sockets} × ${cpu.cores} cores` : null,
            cpu.threads ? `${cpu.threads} threads` : null,
            cpu.mhz ? `${Math.round(cpu.mhz)} MHz` : null,
        ].filter(Boolean).join(', ');

        return `
            <div class="row g-3">
                <div class="col-12">
                    <h6 class="text-muted mb-2">System</h6>
                    <table class="table table-sm table-dark mb-0">
                        <tbody>
                            ${row('PVE Version', info.pve_version ? `<span class="badge bg-secondary">${escapeHtml(info.pve_version)}</span>` : null)}
                            ${row('IP Address', info.ip ? escapeHtml(info.ip) : null)}
                            ${row('Kernel', info.kernel ? escapeHtml(info.kernel) : null)}
                            ${row('Uptime', Utils.formatUptime(info.uptime || 0))}
                            ${row('Load Average', loadAvg.length ? loadAvg.map(v => parseFloat(v).toFixed(2)).join(' / ') : null)}
                        </tbody>
                    </table>
                </div>
                <div class="col-12">
                    <h6 class="text-muted mb-2">CPU</h6>
                    <table class="table table-sm table-dark mb-0">
                        <tbody>
                            ${row('Model', cpu.model ? escapeHtml(cpu.model) : null)}
                            ${row('Topology', cpu.sockets && cpu.cores ? `${cpu.sockets} socket(s) × ${cpu.cores} cores = ${cpu.threads || cpu.cores * cpu.sockets} threads` : null)}
                            ${row('Speed', cpu.mhz ? `${Math.round(parseFloat(cpu.mhz))} MHz` : null)}
                            ${row('HVM', cpu.hvm ? '<span class="badge bg-success">Enabled</span>' : (cpu.hvm === 0 ? '<span class="badge bg-secondary">Disabled</span>' : null))}
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted mb-2">Memory</h6>
                    <table class="table table-sm table-dark mb-0">
                        <tbody>
                            ${row('Total', mem.total ? Utils.formatBytes(mem.total) : null)}
                            ${row('Used', mem.used ? Utils.formatBytes(mem.used) : null)}
                            ${row('Free', mem.free ? Utils.formatBytes(mem.free) : null)}
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted mb-2">Root Filesystem</h6>
                    <table class="table table-sm table-dark mb-0">
                        <tbody>
                            ${row('Total', rootfs.total ? Utils.formatBytes(rootfs.total) : null)}
                            ${row('Used', rootfs.used ? Utils.formatBytes(rootfs.used) : null)}
                            ${row('Free', rootfs.avail ? Utils.formatBytes(rootfs.avail) : null)}
                        </tbody>
                    </table>
                </div>
            </div>
        `;
    },

    setSortStorage(col) {
        if (this.storageSort.col === col) {
            this.storageSort.dir = this.storageSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            this.storageSort.col = col;
            this.storageSort.dir = 'asc';
        }
        this._lastStorageKey = '';
        this.renderStorage();
    },

    setSortHA(col) {
        if (this.haSort.col === col) {
            this.haSort.dir = this.haSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            this.haSort.col = col;
            this.haSort.dir = 'asc';
        }
        this._lastHaKey = '';
        this.renderHA();
    },

    sortIcon(sort, col) {
        if (sort.col !== col) return '<i class="bi bi-chevron-expand" style="opacity:0.3;font-size:0.7em"></i>';
        return sort.dir === 'asc'
            ? '<i class="bi bi-chevron-up" style="font-size:0.7em"></i>'
            : '<i class="bi bi-chevron-down" style="font-size:0.7em"></i>';
    },

    renderStorage() {
        const container = document.getElementById('health-storage');
        if (!this.data.storage.length) {
            container.innerHTML = '<p class="text-muted">No storage data available</p>';
            this._lastStorageKey = '';
            return;
        }

        const { col, dir } = this.storageSort;
        const mult = dir === 'asc' ? 1 : -1;
        const sorted = this.data.storage.slice().sort((a, b) => {
            switch (col) {
                case 'storage': return a.storage.localeCompare(b.storage) * mult;
                case 'type': return a.type.localeCompare(b.type) * mult;
                case 'used': return (a.used - b.used) * mult;
                case 'total': return (a.total - b.total) * mult;
                case 'pct': {
                    const pa = a.total > 0 ? a.used / a.total : 0;
                    const pb = b.total > 0 ? b.used / b.total : 0;
                    return (pa - pb) * mult;
                }
                default: return 0;
            }
        });

        const th = (key, label) => `<th class="sortable-th" style="cursor:pointer;user-select:none" onclick="Health.setSortStorage('${key}')">${label} ${this.sortIcon(this.storageSort, key)}</th>`;

        // In-place update: same storages, same sort order
        const storageKey = sorted.map(s => s.storage).join('|') + ':' + col + dir;
        if (storageKey === this._lastStorageKey && container.querySelector('tbody')) {
            for (const s of sorted) {
                const pct = s.total > 0 ? Math.round((s.used / s.total) * 100) : 0;
                const id = CSS.escape(s.storage);
                const usedEl = document.getElementById(`hst-used-${s.storage}`);
                const pctEl  = document.getElementById(`hst-pct-${s.storage}`);
                const barEl  = document.getElementById(`hst-bar-${s.storage}`);
                if (usedEl) usedEl.textContent = Utils.formatBytes(s.used);
                if (pctEl)  pctEl.textContent  = `${pct}%`;
                if (barEl)  { barEl.style.width = `${pct}%`; barEl.className = `progress-bar ${this.levelClass(pct)}`; }
            }
            return;
        }
        this._lastStorageKey = storageKey;

        container.innerHTML = `
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle">
                    <thead>
                        <tr>
                            ${th('storage', 'Name')}
                            ${th('type', 'Type')}
                            ${th('used', 'Used')}
                            ${th('total', 'Total')}
                            ${th('pct', 'Usage')}
                            <th>Nodes</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${sorted.map(s => {
                            const pct = s.total > 0 ? Math.round((s.used / s.total) * 100) : 0;
                            return `
                                <tr>
                                    <td><strong>${escapeHtml(s.storage)}</strong></td>
                                    <td><span class="badge bg-secondary">${escapeHtml(s.type)}</span></td>
                                    <td id="hst-used-${escapeHtml(s.storage)}">${Utils.formatBytes(s.used)}</td>
                                    <td>${Utils.formatBytes(s.total)}</td>
                                    <td style="min-width:180px">
                                        <div class="d-flex align-items-center gap-2">
                                            <div class="resource-bar flex-grow-1"><div class="progress"><div id="hst-bar-${escapeHtml(s.storage)}" class="progress-bar ${this.levelClass(pct)}" style="width:${pct}%"></div></div></div>
                                            <small id="hst-pct-${escapeHtml(s.storage)}">${pct}%</small>
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
        if (!this.data.ha && !this.data.guests?.length) {
            container.innerHTML = '';
            return;
        }

        const haResources = this.data.ha?.resources || [];
        const guests = this.data.guests || [];

        // Build HA lookup: sid → ha resource
        const haMap = {};
        for (const r of haResources) {
            if (r.sid) haMap[r.sid] = r;
        }

        // Merge: all guests + HA state
        const rows = guests.map(g => {
            const prefix = g.type === 'lxc' ? 'ct' : 'vm';
            const sid = `${prefix}:${g.vmid}`;
            const ha = haMap[sid] || null;
            return { ...g, sid, ha };
        });

        const { col, dir } = this.haSort;
        const mult = dir === 'asc' ? 1 : -1;
        rows.sort((a, b) => {
            switch (col) {
                case 'resource': return ((a.name || String(a.vmid)).localeCompare(b.name || String(b.vmid))) * mult;
                case 'status': return ((a.ha?.state || '').localeCompare(b.ha?.state || '')) * mult;
                case 'node': return (a.node || '').localeCompare(b.node || '') * mult;
                case 'group': return ((a.ha?.group || '').localeCompare(b.ha?.group || '')) * mult;
                default: return 0;
            }
        });

        const th = (key, label) => `<th class="sortable-th" style="cursor:pointer;user-select:none" onclick="Health.setSortHA('${key}')">${label} ${this.sortIcon(this.haSort, key)}</th>`;
        const canManage = Permissions.has('cluster.ha');

        // Skip full rebuild if data unchanged
        const haKey = rows.map(r => `${r.sid}:${r.ha?.state || ''}:${r.node}`).join('|') + ':' + col + dir;
        if (haKey === this._lastHaKey && container.querySelector('tbody')) return;
        this._lastHaKey = haKey;

        const activeStates = ['started', 'enabled'];

        container.innerHTML = `
            <div class="section-header mt-4">
                <h2><i class="bi bi-shield-check"></i> HA Status</h2>
            </div>
            ${rows.length > 0 ? `
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle">
                        <thead>
                            <tr>
                                ${th('resource', 'Resource')}
                                ${th('node', 'Node')}
                                ${th('status', 'HA State')}
                                ${th('group', 'Group')}
                                ${canManage ? '<th></th>' : ''}
                            </tr>
                        </thead>
                        <tbody>
                            ${rows.map(r => {
                                const label = r.name
                                    ? `${escapeHtml(r.name)} <small class="text-muted">(${r.type === 'lxc' ? 'CT' : 'VM'}:${r.vmid})</small>`
                                    : `<small class="text-muted">${r.type === 'lxc' ? 'CT' : 'VM'}:${r.vmid}</small>`;
                                const haActive = r.ha && activeStates.includes(r.ha.state);
                                const haState = r.ha ? r.ha.state : null;
                                // Use data-sid attribute to avoid quote escaping issues in onclick
                                const sidAttr = escapeHtml(r.sid);
                                return `
                                <tr>
                                    <td>${label}</td>
                                    <td>${escapeHtml(r.node)}</td>
                                    <td>${haState
                                        ? `<span class="badge ${haActive ? 'bg-success' : 'bg-secondary'}">${escapeHtml(haState)}</span>`
                                        : '<span class="text-muted small">—</span>'
                                    }</td>
                                    <td>${escapeHtml(r.ha?.group || (r.ha ? '-' : ''))}</td>
                                    ${canManage ? `
                                    <td class="text-end" style="white-space:nowrap">
                                        ${r.ha
                                            ? (haActive
                                                ? `<button class="btn btn-sm btn-outline-warning me-1" title="Disable HA" data-sid="${sidAttr}" onclick="Health.haToggle(this.dataset.sid, false)">
                                                       <i class="bi bi-pause-fill"></i> Disable
                                                   </button>`
                                                : `<button class="btn btn-sm btn-outline-success me-1" title="Enable HA" data-sid="${sidAttr}" onclick="Health.haToggle(this.dataset.sid, true)">
                                                       <i class="bi bi-play-fill"></i> Enable
                                                   </button>`)
                                            : `<button class="btn btn-sm btn-outline-primary me-1" title="Add to HA" data-sid="${sidAttr}" onclick="Health.haAdd(this.dataset.sid)">
                                                   <i class="bi bi-shield-plus"></i> Add
                                               </button>`
                                        }
                                        ${r.ha ? `<button class="btn btn-sm btn-outline-danger" title="Remove from HA" data-sid="${sidAttr}" onclick="Health.haRemove(this.dataset.sid)">
                                            <i class="bi bi-trash"></i>
                                        </button>` : ''}
                                    </td>` : ''}
                                </tr>
                            `}).join('')}
                        </tbody>
                    </table>
                </div>
            ` : '<p class="text-muted">No VMs or CTs found.</p>'}
        `;
    },

    async haToggle(sid, enable) {
        try {
            if (enable) {
                await API.haEnable(sid);
                Toast.success(`HA enabled for ${sid}`);
            } else {
                await API.haDisable(sid);
                Toast.success(`HA disabled for ${sid}`);
            }
            this._lastHaKey = '';
            await this.loadData(true);
        } catch (_) {}
    },

    async haAdd(sid) {
        try {
            await API.haAdd(sid, '', 'started');
            Toast.success(`${sid} added to HA`);
            this._lastHaKey = '';
            await this.loadData(true);
        } catch (_) {}
    },

    async haRemove(sid) {
        if (!confirm(`Really remove ${sid} from HA? The VM/CT will no longer be protected.`)) return;
        try {
            await API.haRemove(sid);
            Toast.success(`${sid} removed from HA`);
            this._lastHaKey = '';
            await this.loadData(true);
        } catch (_) {}
    },

    showAddHAModal() {
        const modal = new bootstrap.Modal(document.getElementById('addHAModal'));
        modal.show();
    },

    async submitAddHA() {
        const vmidInput = document.getElementById('ha-add-vmid');
        const typeInput = document.getElementById('ha-add-type');
        const groupInput = document.getElementById('ha-add-group');
        const vmid = (vmidInput?.value || '').trim();
        const type = typeInput?.value || 'vm';
        const group = (groupInput?.value || '').trim();

        if (!vmid || !/^\d+$/.test(vmid)) {
            Toast.error('Please enter a valid VMID');
            return;
        }

        const sid = `${type}:${vmid}`;
        try {
            await API.haAdd(sid, group, 'started');
            Toast.success(`${sid} added to HA`);
            bootstrap.Modal.getInstance(document.getElementById('addHAModal'))?.hide();
            vmidInput.value = '';
            groupInput.value = '';
            this._lastHaKey = '';
            await this.loadData(true);
        } catch (_) {}
    },

    async loadRightSizing(silent = false) {
        const container = document.getElementById('health-rightsizing');
        if (!container) return;

        try {
            const data = silent
                ? await API.getSilent('api/monitoring-rightsizing.php', { timerange: '24h' })
                : await API.get('api/monitoring-rightsizing.php', { timerange: '24h' });
            const recs = data.recommendations || [];

            if (!recs.length) {
                container.innerHTML = '';
                return;
            }

            container.innerHTML = `
                <div class="section-header mt-4">
                    <h2><i class="bi bi-speedometer2"></i> Right-Sizing Suggestions</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-dark table-hover align-middle">
                        <thead>
                            <tr>
                                <th>VM</th>
                                <th>Status</th>
                                <th>CPU</th>
                                <th>Memory</th>
                                <th>Suggestions</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${recs.map(r => {
                                const hasRec = r.recommended && (r.recommended.cpu_cores || r.recommended.mem_bytes);
                                return `
                                <tr class="${r.severity === 'critical' ? 'table-danger' : r.severity === 'undersized' ? 'table-warning' : ''}">
                                    <td>
                                        <strong>${r.vmid} — ${escapeHtml(r.name)}</strong>
                                        <br><small class="text-muted">${escapeHtml(r.node)} · ${r.vm_type}</small>
                                    </td>
                                    <td>${this.severityBadge(r.severity)}</td>
                                    <td>
                                        <div class="small">Avg: ${r.usage.avg_cpu}%</div>
                                        <div class="small">P95: ${r.usage.p95_cpu}%</div>
                                        <div class="small text-muted">${r.current.cpu_cores} cores${r.recommended?.cpu_cores ? ` → <strong>${r.recommended.cpu_cores}</strong>` : ''}</div>
                                    </td>
                                    <td>
                                        <div class="small">Avg: ${r.usage.avg_mem_pct}%</div>
                                        <div class="small">P95: ${r.usage.p95_mem_pct}%</div>
                                        <div class="small text-muted">${Utils.formatBytes(r.current.mem_bytes)}${r.recommended?.mem_bytes ? ` → <strong>${Utils.formatBytes(r.recommended.mem_bytes)}</strong>` : ''}</div>
                                    </td>
                                    <td>${r.suggestions.map(s => `<div class="small fw-semibold">${escapeHtml(s)}</div>`).join('')}</td>
                                    <td>
                                        ${hasRec ? `<button class="btn btn-sm btn-outline-success" title="Apply recommended values"
                                            data-rec='${JSON.stringify(r.recommended).replace(/'/g, "&#39;")}'
                                            onclick="Health.applyRightSizing(${r.vmid}, '${escapeHtml(r.node)}', '${r.vm_type}', this)">
                                            <i class="bi bi-check-lg"></i> Apply
                                        </button>` : ''}
                                    </td>
                                </tr>`;
                            }).join('')}
                        </tbody>
                    </table>
                </div>
            `;
        } catch (e) {
            if (!silent) container.innerHTML = '';
        }
    },

    async applyRightSizing(vmid, node, vmType, btn) {
        const rec = JSON.parse(btn.dataset.rec);
        const changes = [];
        if (rec.cpu_cores) changes.push(`CPU: ${rec.cpu_cores} cores`);
        if (rec.mem_bytes) changes.push(`Memory: ${Utils.formatBytes(rec.mem_bytes)}`);

        if (!confirm(`Apply right-sizing to VM ${vmid} and restart?\n\n${changes.join('\n')}\n\nThe VM will be rebooted immediately.`)) return;

        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Applying…';

        try {
            const result = await API.post('api/monitoring-rightsizing.php', {
                vmid, node, vm_type: vmType,
                cpu_cores: rec.cpu_cores || undefined,
                mem_bytes: rec.mem_bytes || undefined,
            });

            // Use the resolved node/type from the backend (VM may have been migrated)
            const actualNode = result.node || node;
            const actualType = result.vm_type || vmType;

            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Restarting…';
            await API.post('api/power.php', { node: actualNode, type: actualType, vmid, action: 'reboot' });

            Toast.success(`VM ${vmid} updated and restarting`);
            const row = btn.closest('tr');
            if (row) row.remove();
            const tbody = document.querySelector('#health-rightsizing tbody');
            if (tbody && !tbody.children.length) {
                document.getElementById('health-rightsizing').innerHTML = '';
            }
        } catch (e) {
            Toast.error(e.message);
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    },

    severityBadge(severity) {
        const map = {
            critical: '<span class="badge bg-danger">Critical</span>',
            undersized: '<span class="badge bg-warning text-dark">Undersized</span>',
            oversized: '<span class="badge bg-info text-dark">Oversized</span>',
            optimal: '<span class="badge bg-success">Optimal</span>',
        };
        return map[severity] || severity;
    },

    levelClass(pct) {
        if (pct >= 90) return 'level-danger';
        if (pct >= 70) return 'level-warn';
        return 'level-ok';
    },

};
