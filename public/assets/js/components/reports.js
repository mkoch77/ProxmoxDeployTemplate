const Reports = {
    _data: null,
    _loading: false,

    init() {
        this.render();
    },

    destroy() {},

    render() {
        const content = document.getElementById('page-content');
        content.innerHTML = `
            <div class="content-header d-flex justify-content-between align-items-center">
                <h1><i class="bi bi-file-earmark-spreadsheet me-2"></i>Reports</h1>
            </div>

            <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-list-ul me-1"></i>Inventory</h6>
            <div class="row g-3 mb-4">
                ${this._reportCard(
                    'vm-inventory',
                    'bi-hdd-rack',
                    'VM / CT Inventory',
                    'Overview of all VMs and containers including CPU, RAM, disk, OS, and IP addresses.'
                )}
            </div>

            <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-device-hdd me-1"></i>Storage & Snapshots</h6>
            <div class="row g-3 mb-4">
                ${this._reportCard(
                    'storage-usage',
                    'bi-device-hdd-fill',
                    'Storage Usage',
                    'Disk utilization per VM/CT sorted by usage percentage.'
                )}
                ${this._reportCard(
                    'snapshots',
                    'bi-camera-fill',
                    'Snapshot Report',
                    'All VMs/CTs with snapshots. Shows count, oldest snapshot age, and snapshot names.'
                )}
            </div>

            <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-cpu me-1"></i>Capacity & Resources</h6>
            <div class="row g-3 mb-4">
                ${this._reportCard(
                    'resource-overcommit',
                    'bi-exclamation-triangle-fill',
                    'Resource Overcommit',
                    'Node-level CPU and RAM allocation ratios to identify overprovisioning.'
                )}
                ${this._reportCard(
                    'stopped-vms',
                    'bi-stop-circle-fill',
                    'Stopped VMs/CTs',
                    'Stopped guests still consuming disk space and allocated resources.'
                )}
            </div>

            <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-graph-up me-1"></i>Forecasting</h6>
            <div class="row g-3 mb-4">
                ${this._reportCard(
                    'resource-forecast',
                    'bi-graph-up-arrow',
                    'Resource Forecast',
                    'Trend analysis and capacity projections for CPU, RAM, and storage based on 30-day history.'
                )}
            </div>

            <h6 class="text-muted text-uppercase small mb-2"><i class="bi bi-speedometer2 me-1"></i>Right-Sizing</h6>
            <div class="row g-3 mb-4">
                ${this._reportCard(
                    'oversized-vms',
                    'bi-arrow-down-circle-fill',
                    'Oversized VMs',
                    'VMs with low CPU/RAM utilization that could be downsized to free resources.'
                )}
                ${this._reportCard(
                    'undersized-vms',
                    'bi-arrow-up-circle-fill',
                    'Undersized VMs',
                    'VMs with consistently high CPU/RAM usage that need more resources.'
                )}
            </div>

            <div id="report-output"></div>
        `;
    },

    _reportCard(id, icon, title, description) {
        return `
            <div class="col-md-6 col-lg-4">
                <div class="stat-card h-100" style="cursor:pointer" onclick="Reports.generate('${id}')">
                    <div class="d-flex align-items-start gap-3">
                        <div style="font-size:1.8rem;color:var(--text-muted)"><i class="bi ${icon}"></i></div>
                        <div>
                            <h6 class="mb-1">${title}</h6>
                            <p class="text-muted small mb-0">${description}</p>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },

    async generate(reportId) {
        if (this._loading) return;
        this._loading = true;

        const output = document.getElementById('report-output');
        output.innerHTML = `
            <div class="text-center py-5">
                <div class="spinner-border" role="status"></div>
                <p class="text-muted mt-2">Generating report...</p>
            </div>
        `;

        try {
            switch (reportId) {
                case 'vm-inventory': await this._generateVmInventory(output); break;
                case 'snapshots': await this._generateSnapshots(output); break;
                case 'stopped-vms': await this._generateStoppedVms(output); break;
                case 'storage-usage': await this._generateStorageUsage(output); break;
                case 'resource-overcommit': await this._generateOvercommit(output); break;
                case 'resource-forecast': await this._generateForecast(output); break;
                case 'oversized-vms': await this._generateSizing(output, 'oversized'); break;
                case 'undersized-vms': await this._generateSizing(output, 'undersized'); break;
            }
        } catch (err) {
            output.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${Utils.escapeHtml(err.message)}</div>`;
        } finally {
            this._loading = false;
        }
    },

    _osLabel(ostype) {
        const map = {
            'l26': 'Linux', 'l24': 'Linux 2.4', 'win11': 'Windows 11', 'win10': 'Windows 10',
            'win8': 'Windows 8', 'win7': 'Windows 7', 'wvista': 'Vista', 'wxp': 'Windows XP',
            'w2k': 'Windows 2000', 'w2k8': 'Server 2008', 'w2k12': 'Server 2012',
            'w2k16': 'Server 2016', 'w2k19': 'Server 2019', 'w2k22': 'Server 2022',
            'w2k25': 'Server 2025', 'solaris': 'Solaris', 'other': 'Other',
        };
        return map[ostype] || ostype || '-';
    },

    // ── VM Inventory ─────────────────────────────────────────────────────────
    async _generateVmInventory(output) {
        const data = await API.get('api/reports.php', { report: 'vm-inventory' });
        const rows = data.rows || [];
        this._data = { id: 'vm-inventory', rows };

        if (rows.length === 0) { output.innerHTML = this._emptyState('No VMs or CTs found'); return; }

        const fmtBytes = Utils.formatBytes;
        const esc = Utils.escapeHtml;

        let html = `
            ${this._reportHeader('bi-hdd-rack', 'VM / CT Inventory', rows.length)}
            <div class="guest-table">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>VMID</th><th>Name</th><th>Type</th><th>Node</th><th>Status</th>
                            <th>CPUs</th><th>RAM</th><th>Disk (max)</th><th>Disk (used)</th>
                            <th>OS</th><th>IP</th><th>Tags</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        for (const r of rows) {
            const typeLabel = r.type === 'qemu' ? 'VM' : 'CT';
            const statusCls = Utils.statusBadgeClass(r.status);
            html += `<tr>
                <td><strong style="color:var(--accent-blue)">${r.vmid}</strong></td>
                <td>${esc(r.name || '-')}</td>
                <td>${typeLabel}</td>
                <td style="color:var(--text-secondary)">${esc(r.node)}</td>
                <td><span class="badge ${statusCls}">${r.status}</span></td>
                <td>${r.cpus}</td>
                <td>${fmtBytes(r.ram_bytes)}</td>
                <td>${fmtBytes(r.disk_max_bytes)}</td>
                <td>${fmtBytes(r.disk_used_bytes)}</td>
                <td style="color:var(--text-secondary)">${esc(this._osLabel(r.ostype))}</td>
                <td><code class="small">${esc(r.primary_ip || '-')}</code></td>
                <td>${r.tags ? r.tags.split(';').filter(Boolean).map(t => `<span class="badge vm-tag me-1">${esc(t.trim())}</span>`).join('') : '<span style="color:var(--text-muted)">-</span>'}</td>
            </tr>`;
        }

        html += '</tbody></table></div>';
        output.innerHTML = html;
    },

    // ── Snapshot Report ──────────────────────────────────────────────────────
    async _generateSnapshots(output) {
        const data = await API.get('api/reports.php', { report: 'snapshots' });
        const rows = data.rows || [];
        this._data = { id: 'snapshots', rows };

        if (rows.length === 0) { output.innerHTML = this._emptyState('No VMs/CTs with snapshots found'); return; }

        const esc = Utils.escapeHtml;
        const fmtBytes = Utils.formatBytes;
        const totalSnaps = rows.reduce((s, r) => s + r.snapshot_count, 0);

        let html = `
            ${this._reportHeader('bi-camera-fill', 'Snapshot Report', rows.length)}
            <div class="row g-3 mb-3">
                <div class="col-auto"><div class="stat-card py-2 px-3"><small class="text-muted d-block">VMs with Snapshots</small><strong>${rows.length}</strong></div></div>
                <div class="col-auto"><div class="stat-card py-2 px-3"><small class="text-muted d-block">Total Snapshots</small><strong>${totalSnaps}</strong></div></div>
            </div>
            <div class="guest-table">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>VMID</th><th>Name</th><th>Type</th><th>Node</th><th>Status</th>
                            <th>Snapshots</th><th>Oldest</th><th>Age</th><th>Newest</th>
                            <th>Disk</th><th>Snapshot Names</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        for (const r of rows) {
            const typeLabel = r.type === 'qemu' ? 'VM' : 'CT';
            const statusCls = Utils.statusBadgeClass(r.status);
            const ageDays = r.oldest_age_days || 0;
            const ageBadge = ageDays > 90
                ? `<span class="badge bg-danger">${ageDays}d</span>`
                : ageDays > 30
                    ? `<span class="badge bg-warning text-dark">${ageDays}d</span>`
                    : `<span class="badge bg-secondary">${ageDays}d</span>`;

            html += `<tr>
                <td><strong style="color:var(--accent-blue)">${r.vmid}</strong></td>
                <td>${esc(r.name || '-')}</td>
                <td>${typeLabel}</td>
                <td style="color:var(--text-secondary)">${esc(r.node)}</td>
                <td><span class="badge ${statusCls}">${r.status}</span></td>
                <td><span class="badge bg-info text-dark">${r.snapshot_count}</span></td>
                <td class="small">${esc(r.oldest_snapshot)}</td>
                <td>${ageBadge}</td>
                <td class="small">${esc(r.newest_snapshot)}</td>
                <td>${fmtBytes(r.disk_max_bytes)}</td>
                <td class="small">${r.snapshot_names.map(n => `<span class="badge bg-secondary me-1">${esc(n)}</span>`).join('')}</td>
            </tr>`;
        }

        html += '</tbody></table></div>';
        output.innerHTML = html;
    },

    // ── Stopped VMs ──────────────────────────────────────────────────────────
    async _generateStoppedVms(output) {
        const data = await API.get('api/reports.php', { report: 'stopped-vms' });
        const rows = data.rows || [];
        const totals = data.totals || {};
        this._data = { id: 'stopped-vms', rows, totals };

        if (rows.length === 0) { output.innerHTML = this._emptyState('No stopped VMs/CTs found'); return; }

        const fmtBytes = Utils.formatBytes;
        const esc = Utils.escapeHtml;

        let html = `
            ${this._reportHeader('bi-stop-circle-fill', 'Stopped VMs/CTs', rows.length)}
            <div class="row g-3 mb-3">
                <div class="col-auto"><div class="stat-card py-2 px-3"><small class="text-muted d-block">Stopped Guests</small><strong>${totals.count || 0}</strong></div></div>
                <div class="col-auto"><div class="stat-card py-2 px-3"><small class="text-muted d-block">Disk Allocated</small><strong>${fmtBytes(totals.disk_bytes || 0)}</strong></div></div>
                <div class="col-auto"><div class="stat-card py-2 px-3"><small class="text-muted d-block">RAM Allocated</small><strong>${fmtBytes(totals.ram_bytes || 0)}</strong></div></div>
                <div class="col-auto"><div class="stat-card py-2 px-3"><small class="text-muted d-block">vCPUs Allocated</small><strong>${totals.cpus || 0}</strong></div></div>
            </div>
            <div class="guest-table">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>VMID</th><th>Name</th><th>Type</th><th>Node</th>
                            <th>CPUs</th><th>RAM</th><th>Disk (max)</th><th>Disk (used)</th><th>Tags</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        for (const r of rows) {
            const typeLabel = r.type === 'qemu' ? 'VM' : 'CT';
            html += `<tr>
                <td><strong style="color:var(--accent-blue)">${r.vmid}</strong></td>
                <td>${esc(r.name || '-')}</td>
                <td>${typeLabel}</td>
                <td style="color:var(--text-secondary)">${esc(r.node)}</td>
                <td>${r.cpus}</td>
                <td>${fmtBytes(r.ram_bytes)}</td>
                <td>${fmtBytes(r.disk_max_bytes)}</td>
                <td>${fmtBytes(r.disk_used_bytes)}</td>
                <td>${r.tags ? r.tags.split(';').filter(Boolean).map(t => `<span class="badge vm-tag me-1">${esc(t.trim())}</span>`).join('') : '<span style="color:var(--text-muted)">-</span>'}</td>
            </tr>`;
        }

        html += '</tbody></table></div>';
        output.innerHTML = html;
    },

    // ── Storage Usage ────────────────────────────────────────────────────────
    async _generateStorageUsage(output) {
        const data = await API.get('api/reports.php', { report: 'storage-usage' });
        const rows = data.rows || [];
        this._data = { id: 'storage-usage', rows };

        if (rows.length === 0) { output.innerHTML = this._emptyState('No VMs/CTs found'); return; }

        const fmtBytes = Utils.formatBytes;
        const esc = Utils.escapeHtml;

        let html = `
            ${this._reportHeader('bi-device-hdd-fill', 'Storage Usage per VM/CT', rows.length)}
            <div class="guest-table">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>VMID</th><th>Name</th><th>Type</th><th>Node</th><th>Status</th>
                            <th>Disk Max</th><th>Disk Used</th><th style="min-width:160px">Usage</th><th>RAM</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        for (const r of rows) {
            const typeLabel = r.type === 'qemu' ? 'VM' : 'CT';
            const statusCls = Utils.statusBadgeClass(r.status);
            const pct = r.disk_pct || 0;
            const barClass = pct >= 90 ? 'level-danger' : pct >= 70 ? 'level-warn' : 'level-ok';

            html += `<tr>
                <td><strong style="color:var(--accent-blue)">${r.vmid}</strong></td>
                <td>${esc(r.name || '-')}</td>
                <td>${typeLabel}</td>
                <td style="color:var(--text-secondary)">${esc(r.node)}</td>
                <td><span class="badge ${statusCls}">${r.status}</span></td>
                <td>${fmtBytes(r.disk_max_bytes)}</td>
                <td>${fmtBytes(r.disk_used_bytes)}</td>
                <td>
                    <div class="d-flex align-items-center gap-2">
                        <div class="resource-bar flex-grow-1"><div class="progress"><div class="progress-bar ${barClass}" style="width:${pct}%"></div></div></div>
                        <small>${pct}%</small>
                    </div>
                </td>
                <td>${fmtBytes(r.ram_bytes)}</td>
            </tr>`;
        }

        html += '</tbody></table></div>';
        output.innerHTML = html;
    },

    // ── Resource Overcommit ──────────────────────────────────────────────────
    async _generateOvercommit(output) {
        const data = await API.get('api/reports.php', { report: 'resource-overcommit' });
        const rows = data.rows || [];
        this._data = { id: 'resource-overcommit', rows };

        if (rows.length === 0) { output.innerHTML = this._emptyState('No nodes found'); return; }

        const fmtBytes = Utils.formatBytes;
        const esc = Utils.escapeHtml;

        let html = `
            ${this._reportHeader('bi-exclamation-triangle-fill', 'Resource Overcommit per Node', rows.length)}
            <div class="guest-table">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Node</th><th>Status</th><th>VMs/CTs</th>
                            <th>pCores</th><th>Alloc vCPUs</th><th>vCPU:pCPU</th><th>CPU Used</th>
                            <th>RAM Total</th><th>RAM Allocated</th><th>RAM Alloc %</th><th>RAM Used</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        for (const r of rows) {
            const statusCls = r.status === 'online' ? 'badge-online' : 'badge-offline';
            const cpuRatioBadge = r.cpu_ratio <= 2
                ? `<span class="badge bg-success">${r.cpu_ratio}:1</span>`
                : r.cpu_ratio <= 4
                    ? `<span class="badge bg-warning text-dark">${r.cpu_ratio}:1</span>`
                    : `<span class="badge bg-danger">${r.cpu_ratio}:1</span>`;
            const ramBadge = r.ram_alloc_pct <= 80
                ? `<span class="badge bg-success">${r.ram_alloc_pct}%</span>`
                : r.ram_alloc_pct <= 100
                    ? `<span class="badge bg-warning text-dark">${r.ram_alloc_pct}%</span>`
                    : `<span class="badge bg-danger">${r.ram_alloc_pct}%</span>`;

            html += `<tr>
                <td><strong>${esc(r.node)}</strong></td>
                <td><span class="badge ${statusCls}">${r.status}</span></td>
                <td>${r.vm_count}</td>
                <td>${r.physical_cores}</td>
                <td>${r.allocated_vcpus}</td>
                <td>${cpuRatioBadge}</td>
                <td>${r.used_cpu_pct}%</td>
                <td>${fmtBytes(r.total_ram_bytes)}</td>
                <td>${fmtBytes(r.allocated_ram_bytes)}</td>
                <td>${ramBadge}</td>
                <td>${fmtBytes(r.used_ram_bytes)}</td>
            </tr>`;
        }

        html += '</tbody></table></div>';
        output.innerHTML = html;
    },

    // ── Resource Forecast ──────────────────────────────────────────────────
    async _generateForecast(output) {
        const data = await API.get('api/reports.php', { report: 'resource-forecast' });
        const nodes = data.nodes || [];
        const storage = data.storage;
        const pools = data.storage_pools || [];
        const vmGrowth = data.vm_growth;
        this._data = { id: 'resource-forecast', rows: nodes, storage, pools, vmGrowth };

        const fmtBytes = Utils.formatBytes;
        const esc = Utils.escapeHtml;

        const trendIcon = (t) => t === 'rising' ? '<i class="bi bi-arrow-up text-danger"></i>'
            : t === 'falling' ? '<i class="bi bi-arrow-down text-success"></i>'
            : t === 'no_data' ? '<i class="bi bi-dash text-muted" title="Not enough data"></i>'
            : '<i class="bi bi-arrow-right text-muted"></i>';

        const daysBadge = (d) => {
            if (d === null || d === undefined) return '<span class="text-muted">-</span>';
            if (d === 0) return '<span class="badge bg-danger">Now</span>';
            if (d <= 30) return `<span class="badge bg-danger">${d}d</span>`;
            if (d <= 90) return `<span class="badge bg-warning text-dark">${d}d</span>`;
            if (d <= 365) return `<span class="badge bg-success">${d}d</span>`;
            return `<span class="badge bg-secondary">${d > 999 ? '999+' : d}d</span>`;
        };

        const trendNodes = nodes.filter(n => n.has_trend).length;
        let html = this._reportHeader('bi-graph-up-arrow', `Resource Forecast (${nodes.length} nodes, ${trendNodes} with trend)`, nodes.length);

        // ── Summary cards ──
        html += '<div class="row g-3 mb-4">';
        if (vmGrowth) {
            const sign = vmGrowth.monthly_change >= 0 ? '+' : '';
            html += `<div class="col-auto"><div class="stat-card py-2 px-3">
                <small class="text-muted d-block">VMs</small>
                <strong>${vmGrowth.current_count}</strong>
                <small class="ms-1" style="color:var(--text-secondary)">${sign}${vmGrowth.monthly_change}/mo</small>
                ${trendIcon(vmGrowth.trend)}
            </div></div>`;
        }
        if (storage) {
            html += `<div class="col-auto"><div class="stat-card py-2 px-3">
                <small class="text-muted d-block">VM Disk Used</small>
                <strong>${fmtBytes(storage.current_used_bytes)}</strong>
                <small class="ms-1" style="color:var(--text-secondary)">/ ${fmtBytes(storage.total_capacity_bytes)}</small>
            </div></div>
            <div class="col-auto"><div class="stat-card py-2 px-3">
                <small class="text-muted d-block">Disk Growth</small>
                <strong>${storage.daily_change_bytes > 0 ? '+' : ''}${fmtBytes(Math.abs(storage.daily_change_bytes))}/d</strong>
                ${trendIcon(storage.trend)}
            </div></div>`;
        }
        html += '</div>';

        // ── Node CPU & RAM forecast table ──
        if (nodes.length > 0) {
            html += `<h6 class="mb-2">Node CPU & RAM Capacity</h6>
            <div class="guest-table mb-4">
                <table class="table table-dark table-hover mb-0">
                    <thead><tr>
                        <th>Node</th><th>Days</th>
                        <th>CPU Now</th><th>CPU Trend</th><th>CPU /day</th><th>CPU 80%</th><th>CPU 90%</th>
                        <th>RAM Now</th><th>RAM Trend</th><th>RAM /day</th><th>RAM 80%</th><th>RAM 90%</th><th>RAM 100%</th>
                    </tr></thead><tbody>`;

            for (const n of nodes) {
                const noTrend = !n.has_trend;
                const na = '<span class="text-muted">-</span>';
                html += `<tr${noTrend ? ' style="opacity:0.6"' : ''}>
                    <td><strong>${esc(n.node)}</strong></td>
                    <td class="text-muted small">${n.data_days || 'live'}</td>
                    <td>${n.cpu.current_pct}%</td>
                    <td>${trendIcon(n.cpu.trend)}</td>
                    <td class="small">${noTrend ? na : `${n.cpu.daily_change_pct > 0 ? '+' : ''}${n.cpu.daily_change_pct}%`}</td>
                    <td>${noTrend ? na : daysBadge(n.cpu.days_to_80)}</td>
                    <td>${noTrend ? na : daysBadge(n.cpu.days_to_90)}</td>
                    <td>${n.ram.current_pct}%</td>
                    <td>${trendIcon(n.ram.trend)}</td>
                    <td class="small">${noTrend ? na : `${n.ram.daily_change_bytes > 0 ? '+' : ''}${fmtBytes(Math.abs(n.ram.daily_change_bytes))}/d`}</td>
                    <td>${noTrend ? na : daysBadge(n.ram.days_to_80)}</td>
                    <td>${noTrend ? na : daysBadge(n.ram.days_to_90)}</td>
                    <td>${noTrend ? na : daysBadge(n.ram.days_to_100)}</td>
                </tr>`;
            }
            html += '</tbody></table></div>';
        }

        // ── Storage Pools (current) ──
        if (pools.length > 0) {
            html += `<h6 class="mb-2">Storage Pools (current)</h6>
            <div class="guest-table mb-4">
                <table class="table table-dark table-hover mb-0">
                    <thead><tr><th>Pool</th><th>Type</th><th>Used</th><th>Total</th><th>Free</th><th style="min-width:140px">Usage</th></tr></thead>
                    <tbody>`;
            for (const p of pools) {
                const barClass = p.pct >= 90 ? 'level-danger' : p.pct >= 70 ? 'level-warn' : 'level-ok';
                html += `<tr>
                    <td><strong>${esc(p.storage)}</strong></td>
                    <td class="text-muted small">${esc(p.type)}</td>
                    <td>${fmtBytes(p.used)}</td>
                    <td>${fmtBytes(p.total)}</td>
                    <td>${fmtBytes(p.free_bytes)}</td>
                    <td><div class="d-flex align-items-center gap-2">
                        <div class="resource-bar flex-grow-1"><div class="progress"><div class="progress-bar ${barClass}" style="width:${p.pct}%"></div></div></div>
                        <small>${p.pct}%</small>
                    </div></td>
                </tr>`;
            }
            html += '</tbody></table></div>';
        }

        // ── VM Disk Growth forecast ──
        if (storage) {
            html += `<h6 class="mb-2">VM Disk Growth Forecast</h6>
            <div class="guest-table mb-4">
                <table class="table table-dark table-hover mb-0">
                    <thead><tr><th>Metric</th><th>Value</th></tr></thead>
                    <tbody>
                        <tr><td>Current VM disk usage</td><td>${fmtBytes(storage.current_used_bytes)} / ${fmtBytes(storage.total_capacity_bytes)} (${storage.current_pct}%)</td></tr>
                        <tr><td>Daily growth</td><td>${storage.daily_change_bytes > 0 ? '+' : ''}${fmtBytes(Math.abs(storage.daily_change_bytes))}/day ${trendIcon(storage.trend)}</td></tr>
                        <tr><td>Days to 80%</td><td>${daysBadge(storage.days_to_80)}</td></tr>
                        <tr><td>Days to 90%</td><td>${daysBadge(storage.days_to_90)}</td></tr>
                        <tr><td>Days to 100%</td><td>${daysBadge(storage.days_to_100)}</td></tr>
                        <tr><td>Confidence (R²)</td><td>${storage.r_squared} <small class="text-muted">(${storage.data_days} data points)</small></td></tr>
                    </tbody>
                </table></div>`;
        }

        if (nodes.length === 0 && !storage && pools.length === 0) {
            html += this._emptyState('No data available — cluster may be unreachable');
        } else if (!nodes.some(n => n.has_trend) && !storage) {
            html += `<div class="alert alert-info mt-3"><i class="bi bi-info-circle me-2"></i>Trend projections require at least 2 days of monitoring data. Currently showing live values only.</div>`;
        }

        output.innerHTML = html;
    },

    // ── Right-Sizing (Oversized / Undersized) ─────────────────────────────
    async _generateSizing(output, mode) {
        const data = await API.get('api/monitoring-rightsizing.php', { timerange: '24h' });
        const all = data.recommendations || [];
        const isOver = mode === 'oversized';
        const rows = isOver
            ? all.filter(r => r.severity === 'oversized')
            : all.filter(r => r.severity === 'undersized' || r.severity === 'critical');
        this._data = { id: isOver ? 'oversized-vms' : 'undersized-vms', rows };

        const icon = isOver ? 'bi-arrow-down-circle-fill' : 'bi-arrow-up-circle-fill';
        const title = isOver ? 'Oversized VMs' : 'Undersized VMs';

        if (rows.length === 0) {
            output.innerHTML = this._emptyState(`No ${isOver ? 'oversized' : 'undersized'} VMs found (based on last 24h)`);
            return;
        }

        const fmtBytes = Utils.formatBytes;
        const esc = Utils.escapeHtml;

        let html = `
            ${this._reportHeader(icon, title, rows.length)}
            <div class="guest-table">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>VMID</th><th>Name</th><th>Type</th><th>Node</th>
                            <th>CPUs</th><th>RAM</th>
                            <th>Avg CPU</th><th>p95 CPU</th><th>Avg RAM</th><th>p95 RAM</th>
                            <th>Recommendation</th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        for (const r of rows) {
            const typeLabel = r.vm_type === 'qemu' ? 'VM' : 'CT';
            const sevBadge = r.severity === 'critical'
                ? '<span class="badge bg-danger">Critical</span>'
                : isOver
                    ? '<span class="badge bg-info text-dark">Oversized</span>'
                    : '<span class="badge bg-warning text-dark">Undersized</span>';

            const recParts = [];
            if (r.recommended.cpu_cores !== undefined) {
                const arrow = r.recommended.cpu_cores > r.current.cpu_cores ? '&uarr;' : '&darr;';
                recParts.push(`CPU ${r.current.cpu_cores} ${arrow} ${r.recommended.cpu_cores}`);
            }
            if (r.recommended.mem_bytes !== undefined) {
                const arrow = r.recommended.mem_bytes > r.current.mem_bytes ? '&uarr;' : '&darr;';
                recParts.push(`RAM ${fmtBytes(r.current.mem_bytes)} ${arrow} ${fmtBytes(r.recommended.mem_bytes)}`);
            }

            html += `<tr>
                <td><strong style="color:var(--accent-blue)">${r.vmid}</strong></td>
                <td>${esc(r.name || '-')}</td>
                <td>${typeLabel}</td>
                <td style="color:var(--text-secondary)">${esc(r.node)}</td>
                <td>${r.current.cpu_cores}</td>
                <td>${fmtBytes(r.current.mem_bytes)}</td>
                <td>${r.usage.avg_cpu}%</td>
                <td>${r.usage.p95_cpu}%</td>
                <td>${r.usage.avg_mem_pct}%</td>
                <td>${r.usage.p95_mem_pct}%</td>
                <td>${sevBadge} ${recParts.join(', ')}</td>
            </tr>`;
        }

        html += '</tbody></table></div>';
        output.innerHTML = html;
    },

    // ── Helpers ──────────────────────────────────────────────────────────────
    _reportHeader(icon, title, count) {
        return `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi ${icon} me-2"></i>${title} <span class="badge bg-secondary">${count}</span></h5>
                <button class="btn btn-success btn-sm" onclick="Reports.exportExcel()">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i>Export Excel
                </button>
            </div>
        `;
    },

    _emptyState(message) {
        return `
            <div class="text-center py-5" style="color:var(--text-muted)">
                <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3"></i>
                <p class="mt-2 mb-0">${message}</p>
            </div>
        `;
    },

    exportExcel() {
        if (!this._data || (!this._data.rows.length && this._data.id !== 'resource-forecast')) {
            Toast.warning('No data to export');
            return;
        }

        if (typeof XLSX === 'undefined') {
            Toast.error('Excel library not loaded');
            return;
        }

        const { id, rows } = this._data;
        const fmtMB = (b) => Math.round(b / 1048576);
        const fmtGB = (b) => +(b / 1073741824).toFixed(1);
        const date = new Date().toISOString().slice(0, 10);

        let wsData, sheetName, fileName, cols;

        switch (id) {
            case 'vm-inventory':
                sheetName = 'VM Inventory';
                fileName = `VM_Inventory_${date}.xlsx`;
                cols = [
                    { wch: 6 }, { wch: 25 }, { wch: 5 }, { wch: 12 }, { wch: 9 },
                    { wch: 5 }, { wch: 10 }, { wch: 13 }, { wch: 13 }, { wch: 12 },
                    { wch: 16 }, { wch: 20 },
                ];
                wsData = [['VMID', 'Name', 'Type', 'Node', 'Status', 'CPUs', 'RAM (MB)', 'Disk Max (GB)', 'Disk Used (GB)', 'OS', 'IP', 'Tags']];
                for (const r of rows) {
                    wsData.push([r.vmid, r.name || '', r.type === 'qemu' ? 'VM' : 'CT', r.node, r.status, r.cpus, fmtMB(r.ram_bytes), fmtGB(r.disk_max_bytes), fmtGB(r.disk_used_bytes), this._osLabel(r.ostype), r.primary_ip || '', r.tags ? r.tags.replace(/;/g, ', ') : '']);
                }
                break;

            case 'snapshots':
                sheetName = 'Snapshots';
                fileName = `Snapshot_Report_${date}.xlsx`;
                cols = [
                    { wch: 6 }, { wch: 25 }, { wch: 5 }, { wch: 12 }, { wch: 9 },
                    { wch: 10 }, { wch: 18 }, { wch: 8 }, { wch: 18 }, { wch: 12 }, { wch: 40 },
                ];
                wsData = [['VMID', 'Name', 'Type', 'Node', 'Status', 'Snapshots', 'Oldest', 'Age (days)', 'Newest', 'Disk (GB)', 'Snapshot Names']];
                for (const r of rows) {
                    wsData.push([r.vmid, r.name || '', r.type === 'qemu' ? 'VM' : 'CT', r.node, r.status, r.snapshot_count, r.oldest_snapshot, r.oldest_age_days, r.newest_snapshot, fmtGB(r.disk_max_bytes), r.snapshot_names.join(', ')]);
                }
                break;

            case 'stopped-vms':
                sheetName = 'Stopped VMs';
                fileName = `Stopped_VMs_${date}.xlsx`;
                cols = [
                    { wch: 6 }, { wch: 25 }, { wch: 5 }, { wch: 12 },
                    { wch: 5 }, { wch: 10 }, { wch: 13 }, { wch: 13 }, { wch: 20 },
                ];
                wsData = [['VMID', 'Name', 'Type', 'Node', 'CPUs', 'RAM (MB)', 'Disk Max (GB)', 'Disk Used (GB)', 'Tags']];
                for (const r of rows) {
                    wsData.push([r.vmid, r.name || '', r.type === 'qemu' ? 'VM' : 'CT', r.node, r.cpus, fmtMB(r.ram_bytes), fmtGB(r.disk_max_bytes), fmtGB(r.disk_used_bytes), r.tags ? r.tags.replace(/;/g, ', ') : '']);
                }
                break;

            case 'storage-usage':
                sheetName = 'Storage Usage';
                fileName = `Storage_Usage_${date}.xlsx`;
                cols = [
                    { wch: 6 }, { wch: 25 }, { wch: 5 }, { wch: 12 }, { wch: 9 },
                    { wch: 13 }, { wch: 13 }, { wch: 8 }, { wch: 10 },
                ];
                wsData = [['VMID', 'Name', 'Type', 'Node', 'Status', 'Disk Max (GB)', 'Disk Used (GB)', 'Usage %', 'RAM (MB)']];
                for (const r of rows) {
                    wsData.push([r.vmid, r.name || '', r.type === 'qemu' ? 'VM' : 'CT', r.node, r.status, fmtGB(r.disk_max_bytes), fmtGB(r.disk_used_bytes), r.disk_pct, fmtMB(r.ram_bytes)]);
                }
                break;

            case 'resource-overcommit':
                sheetName = 'Resource Overcommit';
                fileName = `Resource_Overcommit_${date}.xlsx`;
                cols = [
                    { wch: 15 }, { wch: 9 }, { wch: 8 }, { wch: 8 }, { wch: 12 },
                    { wch: 10 }, { wch: 9 }, { wch: 12 }, { wch: 14 }, { wch: 12 }, { wch: 12 },
                ];
                wsData = [['Node', 'Status', 'VMs/CTs', 'pCores', 'Alloc vCPUs', 'vCPU:pCPU', 'CPU Used %', 'RAM Total (GB)', 'RAM Alloc (GB)', 'RAM Alloc %', 'RAM Used (GB)']];
                for (const r of rows) {
                    wsData.push([r.node, r.status, r.vm_count, r.physical_cores, r.allocated_vcpus, r.cpu_ratio, r.used_cpu_pct, fmtGB(r.total_ram_bytes), fmtGB(r.allocated_ram_bytes), r.ram_alloc_pct, fmtGB(r.used_ram_bytes)]);
                }
                break;

            case 'resource-forecast': {
                const fmtB = (b) => +(b / 1073741824).toFixed(2);
                const wb = XLSX.utils.book_new();

                // Sheet 1: Node forecasts
                const nodeData = [['Node', 'Data Days', 'CPU Now %', 'CPU Trend', 'CPU Change/day %', 'Days to CPU 80%', 'Days to CPU 90%',
                    'RAM Now %', 'RAM Trend', 'RAM Change/day (GB)', 'Days to RAM 80%', 'Days to RAM 90%', 'Days to RAM 100%']];
                for (const n of rows) {
                    nodeData.push([n.node, n.data_days,
                        n.cpu.current_pct, n.cpu.trend, n.cpu.daily_change_pct, n.cpu.days_to_80, n.cpu.days_to_90,
                        n.ram.current_pct, n.ram.trend, fmtB(n.ram.daily_change_bytes), n.ram.days_to_80, n.ram.days_to_90, n.ram.days_to_100]);
                }
                const ws1 = XLSX.utils.aoa_to_sheet(nodeData);
                ws1['!cols'] = [{ wch: 15 }, { wch: 8 }, { wch: 9 }, { wch: 8 }, { wch: 14 }, { wch: 12 }, { wch: 12 },
                    { wch: 9 }, { wch: 8 }, { wch: 16 }, { wch: 12 }, { wch: 12 }, { wch: 14 }];
                XLSX.utils.book_append_sheet(wb, ws1, 'Node Forecast');

                // Sheet 2: Storage pools
                if (this._data.pools?.length) {
                    const poolData = [['Pool', 'Type', 'Used (GB)', 'Total (GB)', 'Free (GB)', 'Usage %']];
                    for (const p of this._data.pools) {
                        poolData.push([p.storage, p.type, fmtB(p.used), fmtB(p.total), fmtB(p.free_bytes), p.pct]);
                    }
                    const ws2 = XLSX.utils.aoa_to_sheet(poolData);
                    ws2['!cols'] = [{ wch: 20 }, { wch: 10 }, { wch: 12 }, { wch: 12 }, { wch: 12 }, { wch: 8 }];
                    XLSX.utils.book_append_sheet(wb, ws2, 'Storage Pools');
                }

                XLSX.writeFile(wb, `Resource_Forecast_${date}.xlsx`);
                Toast.success('Exported forecast data');
                return;
            }

            case 'oversized-vms':
            case 'undersized-vms':
                sheetName = id === 'oversized-vms' ? 'Oversized VMs' : 'Undersized VMs';
                fileName = `${sheetName.replace(/ /g, '_')}_${date}.xlsx`;
                cols = [
                    { wch: 6 }, { wch: 25 }, { wch: 5 }, { wch: 12 },
                    { wch: 5 }, { wch: 10 }, { wch: 9 }, { wch: 9 }, { wch: 9 }, { wch: 9 },
                    { wch: 10 }, { wch: 12 }, { wch: 35 },
                ];
                wsData = [['VMID', 'Name', 'Type', 'Node', 'CPUs', 'RAM (MB)', 'Avg CPU %', 'p95 CPU %', 'Avg RAM %', 'p95 RAM %', 'Rec. CPUs', 'Rec. RAM (MB)', 'Issues']];
                for (const r of rows) {
                    wsData.push([
                        r.vmid, r.name || '', r.vm_type === 'qemu' ? 'VM' : 'CT', r.node,
                        r.current.cpu_cores, Math.round(r.current.mem_bytes / 1048576),
                        r.usage.avg_cpu, r.usage.p95_cpu, r.usage.avg_mem_pct, r.usage.p95_mem_pct,
                        r.recommended.cpu_cores ?? r.current.cpu_cores,
                        r.recommended.mem_bytes ? Math.round(r.recommended.mem_bytes / 1048576) : Math.round(r.current.mem_bytes / 1048576),
                        r.issues.join('; '),
                    ]);
                }
                break;

            default:
                Toast.warning('Unknown report type');
                return;
        }

        const wb = XLSX.utils.book_new();
        const ws = XLSX.utils.aoa_to_sheet(wsData);
        ws['!cols'] = cols;
        XLSX.utils.book_append_sheet(wb, ws, sheetName);
        XLSX.writeFile(wb, fileName);
        Toast.success(`Exported ${rows.length} entries`);
    },
};
