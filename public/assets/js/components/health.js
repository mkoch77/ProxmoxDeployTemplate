const Health = {
    refreshInterval: null,
    data: null,
    storageSort: { col: 'storage', dir: 'asc' },
    haSort: { col: 'resource', dir: 'asc' },
    _haPage: 1,
    _haPerPage: 50,

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
        this.refreshInterval = setInterval(() => this.loadData(true), 30000);
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
            <div id="health-ceph" class="mb-4"></div>
            <div class="section-header mt-4">
                <h2><i class="bi bi-device-hdd-fill"></i> Storage Pools</h2>
            </div>
            <div id="health-storage" class="mb-4"></div>
            <div id="health-rightsizing" class="mb-4"></div>
            <div id="health-ha" class="mb-4"></div>
        `;
    },

    async loadData(silent = false) {
        if (this._loading) return;
        this._loading = true;
        try {
            const data = silent
                ? await API.getSilentAbortable('cluster-health', 'api/cluster-health.php')
                : await API.getClusterHealth();
            this.data = data;
            this.updateView();
            this.loadNodeVersions();
            this.loadRightSizing(silent);
        } catch (err) {
            if (!silent && err?.name !== 'AbortError') Toast.error('Failed to load cluster data');
        } finally {
            this._loading = false;
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
        this.renderCeph();
        this.renderNodes();
        this.renderStorage();
        this.renderHA();
    },

    vcpuRatioLevel(ratio) {
        if (ratio <= 2) return 'level-ok';
        if (ratio <= 4) return 'level-warn';
        return 'level-danger';
    },

    vcpuRatioLabel(ratio) {
        if (ratio <= 2) return 'Good';
        if (ratio <= 3) return 'Moderate';
        if (ratio <= 4) return 'High';
        return 'Overcommitted';
    },

    memAllocLevel(ratio) {
        if (ratio <= 0.8) return 'level-ok';
        if (ratio <= 1.0) return 'level-warn';
        return 'level-danger';
    },

    memAllocLabel(ratio) {
        if (ratio <= 0.8) return 'Good';
        if (ratio <= 1.0) return 'Near Limit';
        return 'Overcommitted';
    },

    iowaitLevel(pct) {
        if (pct <= 5) return 'level-ok';
        if (pct <= 15) return 'level-warn';
        return 'level-danger';
    },

    iowaitLabel(pct) {
        if (pct <= 5) return 'Good';
        if (pct <= 15) return 'Moderate';
        return 'High';
    },

    renderClusterStats() {
        const c = this.data.cluster;
        const cpuPct = Math.round(c.total_cpu * 100);
        const memPct = c.total_maxmem > 0 ? Math.round((c.total_mem / c.total_maxmem) * 100) : 0;
        const vcpuRatio = c.total_physical_cores > 0 ? (c.total_vcpus / c.total_physical_cores) : 0;
        const vcpuRatioStr = vcpuRatio.toFixed(1);
        const vcpuRatioPct = Math.min(Math.round((vcpuRatio / 6) * 100), 100); // 6:1 = 100%
        const memAllocRatio = c.total_maxmem > 0 ? (c.total_mem_allocated / c.total_maxmem) : 0;
        const memAllocPct = Math.min(Math.round(memAllocRatio * 100), 100);

        // Average I/O wait across online nodes
        const onlineNodes = (this.data.nodes || []).filter(n => n.status === 'online');
        const avgIowait = onlineNodes.length > 0
            ? onlineNodes.reduce((sum, n) => sum + (n.iowait || 0), 0) / onlineNodes.length
            : 0;
        const iowaitDisplay = avgIowait.toFixed(1);

        const nodesColor = c.nodes_online < c.total_nodes ? 'color:var(--bs-danger)' : '';

        // In-place update if already rendered
        if (document.getElementById('hcs-nodes')) {
            document.getElementById('hcs-vms').textContent       = `${c.total_qemu_running}/${c.total_qemu}`;
            document.getElementById('hcs-cts').textContent       = `${c.total_lxc_running}/${c.total_lxc}`;
            const nodesEl = document.getElementById('hcs-nodes');
            nodesEl.textContent = `${c.nodes_online}/${c.total_nodes}`;
            nodesEl.style.cssText = nodesColor;
            document.getElementById('hcs-cpu-val').textContent   = `${cpuPct}%`;
            document.getElementById('hcs-vcpu-val').textContent  = `${vcpuRatioStr}:1`;
            document.getElementById('hcs-vcpu-label').textContent = `vCPU:pCPU (${c.total_vcpus} / ${c.total_physical_cores})`;
            const vcpuBar = document.getElementById('hcs-vcpu-bar');
            vcpuBar.className = `progress-bar ${this.vcpuRatioLevel(vcpuRatio)}`; vcpuBar.style.width = `${vcpuRatioPct}%`;
            document.getElementById('hcs-mem-val').textContent   = Utils.formatBytes(c.total_mem);
            document.getElementById('hcs-mem-label').textContent = `RAM Usage (${Utils.formatBytes(c.total_maxmem)} total)`;
            const cpuBar = document.getElementById('hcs-cpu-bar');
            cpuBar.className = `progress-bar ${this.levelClass(cpuPct)}`; cpuBar.style.width = `${cpuPct}%`;
            const memBar = document.getElementById('hcs-mem-bar');
            memBar.className = `progress-bar ${this.levelClass(memPct)}`; memBar.style.width = `${memPct}%`;
            document.getElementById('hcs-memalloc-val').textContent  = `${memAllocPct}%`;
            document.getElementById('hcs-memalloc-label').textContent = `RAM Allocated (${Utils.formatBytes(c.total_mem_allocated)} / ${Utils.formatBytes(c.total_maxmem)})`;
            const memAllocBar = document.getElementById('hcs-memalloc-bar');
            memAllocBar.className = `progress-bar ${this.memAllocLevel(memAllocRatio)}`; memAllocBar.style.width = `${memAllocPct}%`;
            document.getElementById('hcs-iowait-val').textContent = `${iowaitDisplay}%`;
            const iowaitBar = document.getElementById('hcs-iowait-bar');
            iowaitBar.className = `progress-bar ${this.iowaitLevel(avgIowait)}`; iowaitBar.style.width = `${Math.min(avgIowait * 2, 100)}%`;
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
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-cpu-fill"></i></div>
                    <div class="stat-value" id="hcs-vcpu-val">${vcpuRatioStr}:1</div>
                    <div class="stat-label" id="hcs-vcpu-label">vCPU:pCPU (${c.total_vcpus} / ${c.total_physical_cores})</div>
                    <div class="resource-bar mt-2"><div class="progress"><div id="hcs-vcpu-bar" class="progress-bar ${this.vcpuRatioLevel(vcpuRatio)}" style="width:${vcpuRatioPct}%"></div></div></div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-memory"></i></div>
                    <div class="stat-value" id="hcs-mem-val">${Utils.formatBytes(c.total_mem)}</div>
                    <div class="stat-label" id="hcs-mem-label">RAM Usage (${Utils.formatBytes(c.total_maxmem)} total)</div>
                    <div class="resource-bar mt-2"><div class="progress"><div id="hcs-mem-bar" class="progress-bar ${this.levelClass(memPct)}" style="width:${memPct}%"></div></div></div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-memory"></i></div>
                    <div class="stat-value" id="hcs-memalloc-val">${memAllocPct}%</div>
                    <div class="stat-label" id="hcs-memalloc-label">RAM Allocated (${Utils.formatBytes(c.total_mem_allocated)} / ${Utils.formatBytes(c.total_maxmem)})</div>
                    <div class="resource-bar mt-2"><div class="progress"><div id="hcs-memalloc-bar" class="progress-bar ${this.memAllocLevel(memAllocRatio)}" style="width:${memAllocPct}%"></div></div></div>
                </div>
            </div>
            <div class="col-6 col-lg">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-hdd"></i></div>
                    <div class="stat-value" id="hcs-iowait-val">${iowaitDisplay}%</div>
                    <div class="stat-label">I/O Wait (avg)</div>
                    <div class="resource-bar mt-2"><div class="progress"><div id="hcs-iowait-bar" class="progress-bar ${this.iowaitLevel(avgIowait)}" style="width:${Math.min(avgIowait * 2, 100)}%"></div></div></div>
                </div>
            </div>
        `;
    },

    cephHealthBadge(health) {
        const map = {
            'HEALTH_OK': ['bg-success', 'Healthy'],
            'HEALTH_WARN': ['bg-warning text-dark', 'Warning'],
            'HEALTH_ERR': ['bg-danger', 'Error'],
        };
        const [cls, label] = map[health] || ['bg-secondary', health || 'Unknown'];
        return `<span class="badge ${cls}">${label}</span>`;
    },

    renderCeph() {
        const container = document.getElementById('health-ceph');
        if (!container) return;
        const ceph = this.data.ceph;
        if (!ceph || !ceph.available) {
            container.innerHTML = '';
            return;
        }

        const o = ceph.osds || {};
        const cap = ceph.capacity || {};
        const perf = ceph.performance || {};
        const usedPct = cap.total > 0 ? Math.round((cap.used / cap.total) * 100) : 0;

        // PG state summary
        const pgStates = (ceph.pgs?.states || []).map(s =>
            `<span class="badge ${s.state.includes('active+clean') ? 'bg-success' : s.state.includes('active') ? 'bg-info' : 'bg-warning text-dark'} me-1 mb-1">${s.count} ${escapeHtml(s.state)}</span>`
        ).join('');

        // Warnings
        const warnHtml = (ceph.warnings || []).map(w =>
            `<div class="small ${w.severity === 'HEALTH_ERR' ? 'text-danger' : 'text-warning'}"><i class="bi bi-exclamation-triangle me-1"></i>${escapeHtml(w.message)}</div>`
        ).join('');

        container.innerHTML = `
            <div class="section-header mt-4">
                <h2><i class="bi bi-device-ssd-fill"></i> CEPH Storage</h2>
            </div>
            <div class="row g-3">
                <div class="col-md-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-heart-pulse"></i></div>
                        <div class="stat-value">${this.cephHealthBadge(ceph.health)}</div>
                        <div class="stat-label">CEPH Health</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-hdd-fill"></i></div>
                        <div class="stat-value">${o.up}/${o.total} <small class="text-muted" style="font-size:0.6em">up</small></div>
                        <div class="stat-label">OSDs (${o.in} in)</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-speedometer2"></i></div>
                        <div class="stat-value">${Utils.formatNumber(perf.read_ops + perf.write_ops)}</div>
                        <div class="stat-label">IOPS (R: ${Utils.formatNumber(perf.read_ops)} / W: ${Utils.formatNumber(perf.write_ops)})</div>
                    </div>
                </div>
                <div class="col-md-6 col-xl-3">
                    <div class="stat-card">
                        <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-arrow-left-right"></i></div>
                        <div class="stat-value">${Utils.formatRate(perf.read_bytes + perf.write_bytes)}</div>
                        <div class="stat-label">Throughput (R: ${Utils.formatRate(perf.read_bytes)} / W: ${Utils.formatRate(perf.write_bytes)})</div>
                    </div>
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-6">
                    <div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)">
                        <div class="card-body">
                            <h6 class="text-muted mb-2">Capacity</h6>
                            <div class="d-flex justify-content-between mb-1">
                                <small>Used: ${Utils.formatBytes(cap.used)}</small>
                                <small>Total: ${Utils.formatBytes(cap.total)}</small>
                            </div>
                            <div class="resource-bar"><div class="progress"><div class="progress-bar ${this.levelClass(usedPct)}" style="width:${usedPct}%"></div></div></div>
                            <div class="text-end"><small class="text-muted">${usedPct}% used — ${Utils.formatBytes(cap.available)} free</small></div>
                            <div class="mt-2">
                                <small class="text-muted">Objects: ${Utils.formatNumber(ceph.objects || 0)}</small>
                                <small class="text-muted ms-3">Monitors: ${ceph.monitors}</small>
                                <small class="text-muted ms-3">PGs: ${ceph.pgs?.total || 0}</small>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)">
                        <div class="card-body">
                            <h6 class="text-muted mb-2">Placement Groups</h6>
                            <div class="mb-2">${pgStates || '<span class="text-muted small">No PG data</span>'}</div>
                            ${warnHtml ? `<h6 class="text-muted mb-1 mt-3">Warnings</h6>${warnHtml}` : ''}
                        </div>
                    </div>
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
                const setHtml = (elId, html) => { const el = document.getElementById(elId); if (el) el.innerHTML = html; };
                setText(`hn-cpu-val-${id}`, `${cpuPct}% (${node.maxcpu || 0} threads)`);
                setBar(`hn-cpu-bar-${id}`, cpuPct);
                // vCPU ratio
                const nRatio = node.physical_cores > 0 ? (node.vcpus_allocated / node.physical_cores) : 0;
                const nRatioPct = Math.min(Math.round((nRatio / 6) * 100), 100);
                setText(`hn-vcpu-val-${id}`, this._nodeVcpuText(node));
                setBar(`hn-vcpu-bar-${id}`, nRatioPct);
                const vcpuBarEl = document.getElementById(`hn-vcpu-bar-${id}`);
                if (vcpuBarEl) vcpuBarEl.className = `progress-bar ${this.vcpuRatioLevel(nRatio)}`;
                setText(`hn-ram-val-${id}`, `${Utils.formatBytes(node.mem || 0)} / ${Utils.formatBytes(node.maxmem || 0)}`);
                setBar(`hn-ram-bar-${id}`, memPct);
                // RAM allocation
                const nMemAllocRatio = node.maxmem > 0 ? ((node.mem_allocated || 0) / node.maxmem) : 0;
                const nMemAllocPct = Math.min(Math.round(nMemAllocRatio * 100), 100);
                setText(`hn-memalloc-val-${id}`, this._nodeMemAllocText(node));
                const memAllocBarEl = document.getElementById(`hn-memalloc-bar-${id}`);
                if (memAllocBarEl) { memAllocBarEl.style.width = `${nMemAllocPct}%`; memAllocBarEl.className = `progress-bar ${this.memAllocLevel(nMemAllocRatio)}`; }
                setText(`hn-disk-val-${id}`, `${Utils.formatBytes(node.disk || 0)} / ${Utils.formatBytes(node.maxdisk || 0)}`);
                setBar(`hn-disk-bar-${id}`, diskPct);
                // I/O wait
                const nIowait = node.iowait || 0;
                setText(`hn-iowait-val-${id}`, `${nIowait.toFixed(1)}%`);
                const iowaitBarEl = document.getElementById(`hn-iowait-bar-${id}`);
                if (iowaitBarEl) { iowaitBarEl.style.width = `${Math.min(nIowait * 2, 100)}%`; iowaitBarEl.className = `progress-bar ${this.iowaitLevel(nIowait)}`; }
                setText(`hn-uptime-${id}`, `Uptime: ${Utils.formatUptime(node.uptime || 0)}`);
                // Swap
                const swapUsed = node.swapused || 0;
                const swapTotal = node.swaptotal || 0;
                const swapPct = swapTotal > 0 ? Math.round(swapUsed / swapTotal * 100) : 0;
                setText(`hn-swap-val-${id}`, `${Utils.formatBytes(swapUsed)} / ${Utils.formatBytes(swapTotal)}`);
                setBar(`hn-swap-bar-${id}`, swapPct);
                // I/O metrics
                setText(`hn-load-${id}`, (node.loadavg || 0).toFixed(2));
                setText(`hn-net-${id}`, `${Utils.formatRate(node.netin_rate || 0)} / ${Utils.formatRate(node.netout_rate || 0)}`);
                setText(`hn-disk-io-${id}`, `${Utils.formatRate(node.diskread_rate || 0)} / ${Utils.formatRate(node.diskwrite_rate || 0)}`);
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
                    <div class="node-card ${maint ? 'maintenance' : ''} ${!isOnline ? 'offline' : ''}" style="cursor:${isOnline ? 'pointer' : 'default'}" ${isOnline ? `onclick="Health.showNodeInfo('${escapeHtml(id)}')"` : ''}
                        ${isOnline ? `onmouseenter="ResourceTooltip.showNode(this,'${escapeHtml(id)}')" onmouseleave="ResourceTooltip.hide()"` : ''}>
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <div>
                                <h5 class="mb-0"><i class="bi bi-hdd-rack me-2"></i>${node.ip
                                    ? `<a href="https://${escapeHtml(node.ip)}:8006" target="_blank" rel="noopener" onclick="event.stopPropagation()" class="text-decoration-none" style="color:inherit" title="Open Proxmox UI">${escapeHtml(id)}<i class="bi bi-box-arrow-up-right ms-1" style="font-size:0.7em;opacity:0.5"></i></a>`
                                    : escapeHtml(id)}</h5>
                                ${isOnline ? `<small class="text-muted" id="node-ip-${escapeHtml(id)}">${node.ip ? escapeHtml(node.ip) : ''}</small>` : ''}
                            </div>
                            <div>${statusBadge}</div>
                        </div>
                        ${isOnline ? (() => {
                            const vcpus = node.vcpus_allocated || 0;
                            const pCores = node.physical_cores || 0;
                            const nRatio = pCores > 0 ? vcpus / pCores : 0;
                            const nRatioPct = Math.min(Math.round((nRatio / 6) * 100), 100);
                            const numaInfo = this._nodeNumaTooltip(node);
                            const nMemAlloc = node.mem_allocated || 0;
                            const nMemAllocRatio = node.maxmem > 0 ? nMemAlloc / node.maxmem : 0;
                            const nMemAllocPct = Math.min(Math.round(nMemAllocRatio * 100), 100);
                            return `
                            <div class="resource-item">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">CPU</small>
                                    <small id="hn-cpu-val-${id}">${cpuPct}% (${node.maxcpu || 0} threads)</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div id="hn-cpu-bar-${id}" class="progress-bar ${this.levelClass(cpuPct)}" style="width:${cpuPct}%"></div></div></div>
                            </div>
                            <div class="resource-item mt-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted" ${numaInfo ? `title="${numaInfo}" style="cursor:help;border-bottom:1px dotted var(--text-muted)"` : ''}>vCPU:pCPU</small>
                                    <small id="hn-vcpu-val-${id}">${this._nodeVcpuText(node)}</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div id="hn-vcpu-bar-${id}" class="progress-bar ${this.vcpuRatioLevel(nRatio)}" style="width:${nRatioPct}%"></div></div></div>
                            </div>
                            <div class="resource-item mt-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">RAM Usage</small>
                                    <small id="hn-ram-val-${id}">${Utils.formatBytes(node.mem || 0)} / ${Utils.formatBytes(node.maxmem || 0)}</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div id="hn-ram-bar-${id}" class="progress-bar ${this.levelClass(memPct)}" style="width:${memPct}%"></div></div></div>
                            </div>
                            <div class="resource-item mt-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">RAM Allocated</small>
                                    <small id="hn-memalloc-val-${id}">${this._nodeMemAllocText(node)}</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div id="hn-memalloc-bar-${id}" class="progress-bar ${this.memAllocLevel(nMemAllocRatio)}" style="width:${nMemAllocPct}%"></div></div></div>
                            </div>
                            <div class="resource-item mt-2">
                                <div class="d-flex justify-content-between mb-1">
                                    <small class="text-muted">Disk</small>
                                    <small id="hn-disk-val-${id}">${Utils.formatBytes(node.disk || 0)} / ${Utils.formatBytes(node.maxdisk || 0)}</small>
                                </div>
                                <div class="resource-bar"><div class="progress"><div id="hn-disk-bar-${id}" class="progress-bar ${this.levelClass(diskPct)}" style="width:${diskPct}%"></div></div></div>
                            </div>
                            ${(() => {
                                const nIow = node.iowait || 0;
                                return `<div class="resource-item mt-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="text-muted">I/O Wait</small>
                                        <small id="hn-iowait-val-${id}">${nIow.toFixed(1)}%</small>
                                    </div>
                                    <div class="resource-bar"><div class="progress"><div id="hn-iowait-bar-${id}" class="progress-bar ${this.iowaitLevel(nIow)}" style="width:${Math.min(nIow * 2, 100)}%"></div></div></div>
                                </div>`;
                            })()}
                            ${(() => {
                                const swapUsed = node.swapused || 0;
                                const swapTotal = node.swaptotal || 0;
                                const swapPct = swapTotal > 0 ? Math.round(swapUsed / swapTotal * 100) : 0;
                                return swapTotal > 0 ? `<div class="resource-item mt-2">
                                    <div class="d-flex justify-content-between mb-1">
                                        <small class="text-muted">Swap</small>
                                        <small id="hn-swap-val-${id}">${Utils.formatBytes(swapUsed)} / ${Utils.formatBytes(swapTotal)}</small>
                                    </div>
                                    <div class="resource-bar"><div class="progress"><div id="hn-swap-bar-${id}" class="progress-bar ${this.levelClass(swapPct)}" style="width:${swapPct}%"></div></div></div>
                                </div>` : '';
                            })()}
                            <div class="mt-2 d-flex justify-content-between small text-muted" id="hn-io-${id}">
                                <span title="Load Average"><i class="bi bi-speedometer2 me-1"></i><span id="hn-load-${id}">${(node.loadavg || 0).toFixed(2)}</span></span>
                                <span title="Network I/O"><i class="bi bi-ethernet me-1"></i><span id="hn-net-${id}">${Utils.formatRate(node.netin_rate || 0)} / ${Utils.formatRate(node.netout_rate || 0)}</span></span>
                                <span title="Disk I/O"><i class="bi bi-hdd me-1"></i><span id="hn-disk-io-${id}">${Utils.formatRate(node.diskread_rate || 0)} / ${Utils.formatRate(node.diskwrite_rate || 0)}</span></span>
                            </div>
                            <div class="mt-2 d-flex justify-content-between align-items-center">
                                <span class="text-muted small"><i class="bi bi-clock me-1"></i><span id="hn-uptime-${id}">Uptime: ${Utils.formatUptime(node.uptime || 0)}</span></span>
                                <span class="text-muted small"><i class="bi bi-box me-1"></i><span id="node-pve-version-${escapeHtml(id)}">PVE ...</span></span>
                            </div>`;
                        })() : `
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
            // Enrich with vCPU/RAM allocation data from cluster health
            const nodeData = this.data?.nodes?.find(n => n.node === nodeName);
            if (nodeData) {
                info._vcpus_allocated = nodeData.vcpus_allocated || 0;
                info._physical_cores = nodeData.physical_cores || 0;
                info._numa_nodes = nodeData.numa_nodes || (info.cpu?.sockets || 1);
                info._mem_allocated = nodeData.mem_allocated || 0;
                info._mem_total = nodeData.maxmem || 0;
            }
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

        // NUMA & vCPU ratio
        const sockets = cpu.sockets || 1;
        const coresPerSocket = cpu.cores || 0;
        const threads = cpu.threads || 0;
        const physicalCores = info._physical_cores || (sockets * coresPerSocket);
        const numaNodes = info._numa_nodes || sockets;
        const coresPerNuma = coresPerSocket;
        const vcpus = info._vcpus_allocated || 0;
        const ratio = physicalCores > 0 ? (vcpus / physicalCores) : 0;
        const ratioStr = ratio.toFixed(1);

        const ratioBadge = ratio <= 2
            ? `<span class="badge bg-success">${ratioStr}:1 — ${this.vcpuRatioLabel(ratio)}</span>`
            : ratio <= 4
                ? `<span class="badge bg-warning text-dark">${ratioStr}:1 — ${this.vcpuRatioLabel(ratio)}</span>`
                : `<span class="badge bg-danger">${ratioStr}:1 — ${this.vcpuRatioLabel(ratio)}</span>`;

        // RAM allocation
        const memAllocated = info._mem_allocated || 0;
        const memTotal = info._mem_total || (mem.total || 0);
        const memAllocRatio = memTotal > 0 ? memAllocated / memTotal : 0;
        const memAllocPct = Math.round(memAllocRatio * 100);
        const memAllocBadge = memAllocRatio <= 0.8
            ? `<span class="badge bg-success">${memAllocPct}% — ${this.memAllocLabel(memAllocRatio)}</span>`
            : memAllocRatio <= 1.0
                ? `<span class="badge bg-warning text-dark">${memAllocPct}% — ${this.memAllocLabel(memAllocRatio)}</span>`
                : `<span class="badge bg-danger">${memAllocPct}% — ${this.memAllocLabel(memAllocRatio)}</span>`;

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
                    <h6 class="text-muted mb-2">CPU & NUMA Topology</h6>
                    <table class="table table-sm table-dark mb-0">
                        <tbody>
                            ${row('Model', cpu.model ? escapeHtml(cpu.model) : null)}
                            ${row('Topology', cpu.sockets && cpu.cores ? `${cpu.sockets} socket(s) × ${cpu.cores} cores = ${threads} threads` : null)}
                            ${row('Physical Cores', physicalCores ? `${physicalCores} cores` : null)}
                            ${row('NUMA Nodes', `${numaNodes} (${coresPerNuma} cores / ${Math.floor(threads / numaNodes)} threads per node)`)}
                            ${row('Speed', cpu.mhz ? `${Math.round(parseFloat(cpu.mhz))} MHz` : null)}
                            ${row('HVM', cpu.hvm ? '<span class="badge bg-success">Enabled</span>' : (cpu.hvm === 0 ? '<span class="badge bg-secondary">Disabled</span>' : null))}
                        </tbody>
                    </table>
                </div>
                <div class="col-12">
                    <h6 class="text-muted mb-2">vCPU Allocation</h6>
                    <table class="table table-sm table-dark mb-0">
                        <tbody>
                            ${row('Allocated vCPUs', `${vcpus}`)}
                            ${row('Physical Cores', `${physicalCores}`)}
                            ${row('vCPU:pCPU Ratio', ratioBadge)}
                            ${vcpus > 0 && coresPerNuma > 0 ? row('NUMA Assessment',
                                vcpus <= coresPerNuma
                                    ? '<span class="badge bg-success">Fits single NUMA node</span>'
                                    : vcpus <= physicalCores
                                        ? `<span class="badge bg-info text-dark">Spans ${Math.ceil(vcpus / coresPerNuma)} of ${numaNodes} NUMA nodes</span>`
                                        : `<span class="badge bg-warning text-dark">Oversubscribed — ${vcpus} vCPUs across ${numaNodes} NUMA nodes (${physicalCores} physical cores)</span>`
                            ) : ''}
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
                            ${row('Allocated to VMs/CTs', memAllocated ? Utils.formatBytes(memAllocated) : null)}
                            ${row('Allocation Ratio', memAllocated ? memAllocBadge : null)}
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted mb-2">Storage I/O</h6>
                    <table class="table table-sm table-dark mb-0">
                        <tbody>
                            ${(() => {
                                const nodeData = this.data?.nodes?.find(n => n.node === (info.node || ''));
                                const iow = nodeData?.iowait || 0;
                                const iowBadge = iow <= 5
                                    ? `<span class="badge bg-success">${iow.toFixed(1)}% — ${this.iowaitLabel(iow)}</span>`
                                    : iow <= 15
                                        ? `<span class="badge bg-warning text-dark">${iow.toFixed(1)}% — ${this.iowaitLabel(iow)}</span>`
                                        : `<span class="badge bg-danger">${iow.toFixed(1)}% — ${this.iowaitLabel(iow)}</span>`;
                                const readRate = nodeData?.diskread_rate || 0;
                                const writeRate = nodeData?.diskwrite_rate || 0;
                                return row('I/O Wait', iowBadge)
                                    + row('Disk Read', Utils.formatRate(readRate))
                                    + row('Disk Write', Utils.formatRate(writeRate));
                            })()}
                        </tbody>
                    </table>
                </div>
                <div class="col-md-6">
                    <h6 class="text-muted mb-2">Network</h6>
                    <table class="table table-sm table-dark mb-0">
                        <tbody>
                            ${(() => {
                                const nodeData = this.data?.nodes?.find(n => n.node === (info.node || ''));
                                return row('Inbound', Utils.formatRate(nodeData?.netin_rate || 0))
                                    + row('Outbound', Utils.formatRate(nodeData?.netout_rate || 0));
                            })()}
                        </tbody>
                    </table>
                </div>
                ${(() => {
                    const nodeData = this.data?.nodes?.find(n => n.node === (info.node || ''));
                    const swapUsed = nodeData?.swapused || 0;
                    const swapTotal = nodeData?.swaptotal || 0;
                    if (!swapTotal) return '';
                    const swapPct = Math.round(swapUsed / swapTotal * 100);
                    return `<div class="col-md-6">
                        <h6 class="text-muted mb-2">Swap</h6>
                        <table class="table table-sm table-dark mb-0">
                            <tbody>
                                ${row('Used', Utils.formatBytes(swapUsed) + ' / ' + Utils.formatBytes(swapTotal) + ' (' + swapPct + '%)')}
                            </tbody>
                        </table>
                    </div>`;
                })()}
                <div class="col-md-6">
                    <h6 class="text-muted mb-2">Load Average</h6>
                    <table class="table table-sm table-dark mb-0">
                        <tbody>
                            ${row('Current', (() => {
                                const nodeData = this.data?.nodes?.find(n => n.node === (info.node || ''));
                                const load = nodeData?.loadavg || 0;
                                const cores = cpu.threads || cpu.cores || 1;
                                const loadBadge = load <= cores * 0.7
                                    ? `<span class="badge bg-success">${load.toFixed(2)}</span>`
                                    : load <= cores
                                        ? `<span class="badge bg-warning text-dark">${load.toFixed(2)}</span>`
                                        : `<span class="badge bg-danger">${load.toFixed(2)}</span>`;
                                return loadBadge + ` <small class="text-muted">(${cores} threads)</small>`;
                            })())}
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

    setHaPage(page) {
        this._haPage = page;
        this._lastHaKey = '';
        this.renderHA();
    },

    setHaPerPage(perPage) {
        this._haPerPage = perPage;
        this._haPage = 1;
        this._lastHaKey = '';
        this.renderHA();
    },

    setSortHA(col) {
        if (this.haSort.col === col) {
            this.haSort.dir = this.haSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            this.haSort.col = col;
            this.haSort.dir = 'asc';
        }
        this._haPage = 1;
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
        const haKey = rows.map(r => `${r.sid}:${r.ha?.state || ''}:${r.node}`).join('|') + ':' + col + dir + ':' + this._haPage + ':' + this._haPerPage;
        if (haKey === this._lastHaKey && container.querySelector('tbody')) return;
        this._lastHaKey = haKey;

        const activeStates = ['started', 'enabled'];
        const pag = Utils.paginate(rows, this._haPage, this._haPerPage);
        this._haPage = pag.page;
        const pageRows = pag.items;

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
                            ${pageRows.map(r => {
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
                    ${Utils.paginationHtml(pag, 'Health.setHaPage', 'Health.setHaPerPage')}
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

    _dismissedVmids: new Set(), // dismissed right-sizing VMIDs (session only)

    async loadRightSizing(silent = false) {
        const container = document.getElementById('health-rightsizing');
        if (!container) return;

        try {
            const data = silent
                ? await API.getSilent('api/monitoring-rightsizing.php', { timerange: '24h' })
                : await API.get('api/monitoring-rightsizing.php', { timerange: '24h' });
            const recs = data.recommendations || [];

            // Store total count for top bar, filter dismissed for display
            this._rightSizingTotal = recs.length;
            const visible = recs.filter(r => !this._dismissedVmids.has(r.vmid));

            if (!visible.length) {
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
                                <th>Node</th>
                                <th>Suggestions</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            ${visible.map(r => {
                                const hasRec = r.recommended && (r.recommended.cpu_cores || r.recommended.mem_bytes);
                                const nc = r.node_context || {};
                                const vcpuR = nc.vcpu_ratio || 0;
                                const iow = nc.iowait || 0;
                                return `
                                <tr class="${r.severity === 'critical' ? 'table-danger' : r.severity === 'undersized' ? 'table-warning' : ''}" data-rs-vmid="${r.vmid}">
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
                                    <td>
                                        <div class="small"><span class="badge ${vcpuR <= 2 ? 'bg-success' : vcpuR <= 4 ? 'bg-warning text-dark' : 'bg-danger'}" title="vCPU:pCPU">${vcpuR.toFixed(1)}:1</span></div>
                                        <div class="small mt-1"><span class="badge ${iow <= 5 ? 'bg-success' : iow <= 15 ? 'bg-warning text-dark' : 'bg-danger'}" title="I/O Wait">IO ${iow.toFixed(1)}%</span></div>
                                    </td>
                                    <td>${r.suggestions.map(s => `<div class="small fw-semibold">${escapeHtml(s)}</div>`).join('')}</td>
                                    <td class="text-nowrap">
                                        ${hasRec ? `<button class="btn btn-sm btn-outline-success me-1" title="Apply recommended values"
                                            data-rec='${JSON.stringify(r.recommended).replace(/'/g, "&#39;")}'
                                            onclick="Health.applyRightSizing(${r.vmid}, '${escapeHtml(r.node)}', '${r.vm_type}', this)">
                                            <i class="bi bi-check-lg"></i> Apply
                                        </button>` : ''}<button class="btn btn-sm btn-outline-secondary" title="Dismiss"
                                            onclick="Health.dismissRightSizingVm(${r.vmid})">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
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

        if (!confirm(`Apply right-sizing to VM ${vmid}?\n\n${changes.join('\n')}\n\nIf the VM is running, it will be rebooted.`)) return;

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

            // Only reboot if VM is running
            if (result.restart_required) {
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Restarting…';
                await API.post('api/power.php', { node: actualNode, type: actualType, vmid, action: 'reboot' });
                Toast.success(`VM ${vmid} updated and restarting`);
            } else {
                Toast.success(`VM ${vmid} updated — changes apply on next start`);
            }
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

    dismissRightSizingVm(vmid) {
        this._dismissedVmids.add(vmid);

        // Remove the row
        const row = document.querySelector(`tr[data-rs-vmid="${vmid}"]`);
        if (row) row.remove();

        // If table is now empty, hide the whole section
        const tbody = document.querySelector('#health-rightsizing tbody');
        if (tbody && !tbody.children.length) {
            document.getElementById('health-rightsizing').innerHTML = '';
        }

        // Update top bar info icon — if all suggestions are dismissed, hide it
        this._updateTopBarInfo();
    },

    _updateTopBarInfo() {
        if (typeof App === 'undefined' || !App._infos) return;
        const totalRecs = this._rightSizingTotal || 0;
        const allDismissed = this._dismissedVmids.size >= totalRecs;

        if (allDismissed) {
            // Dismiss rightsizing in top bar
            for (const info of App._infos) {
                if (info.cat === 'rightsizing') {
                    App._dismissedInfos.add(info.cat + ':' + info.msg);
                }
            }
        }

        const activeInfos = App._infos.filter(i => !App._dismissedInfos.has(i.cat + ':' + i.msg));
        const infoBtn = document.getElementById('cluster-info-btn');
        const infoCnt = document.getElementById('cluster-info-count');
        if (infoBtn) {
            if (activeInfos.length === 0) {
                infoBtn.classList.add('d-none');
            } else {
                infoCnt.textContent = activeInfos.length;
            }
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

    _nodeVcpuText(node) {
        const vcpus = node.vcpus_allocated || 0;
        const pCores = node.physical_cores || 0;
        const ratio = pCores > 0 ? (vcpus / pCores).toFixed(1) : '–';
        return `${ratio}:1 (${vcpus} vCPU / ${pCores} pCPU)`;
    },

    _nodeMemAllocText(node) {
        const allocated = node.mem_allocated || 0;
        const total = node.maxmem || 0;
        const pct = total > 0 ? Math.round((allocated / total) * 100) : 0;
        return `${pct}% (${Utils.formatBytes(allocated)} / ${Utils.formatBytes(total)})`;
    },

    _nodeNumaTooltip(node) {
        const topo = node.cpu_topology;
        if (!topo) return '';
        const numaNodes = node.numa_nodes || topo.sockets || 1;
        const coresPerNuma = topo.cores || 0;
        const threadsPerNuma = Math.floor(topo.threads / numaNodes);
        return `${numaNodes} NUMA node${numaNodes > 1 ? 's' : ''} · ${coresPerNuma} cores/socket · ${topo.threads} threads`;
    },

};
