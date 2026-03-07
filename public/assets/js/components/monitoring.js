const Monitoring = {
    _interval: null,
    _charts: {},
    _overview: null,
    _currentView: 'overview', // 'overview', 'node', 'vm'
    _currentTarget: null,
    _timerange: '1h',
    _smoothing: 0,

    init() {
        this.render();
        this.loadOverview();
    },

    destroy() {
        if (this._interval) clearInterval(this._interval);
        this._interval = null;
        Object.values(this._charts).forEach(c => c.destroy());
        this._charts = {};
    },

    render() {
        document.getElementById('page-content').innerHTML = `
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h4 class="mb-0"><i class="bi bi-graph-up me-2"></i>Monitoring</h4>
                <div class="d-flex gap-2 align-items-center">
                    <select id="mon-timerange" class="form-select form-select-sm" style="width:auto" onchange="Monitoring.onTimerangeChange(this.value)">
                        <option value="1h">1 Hour</option>
                        <option value="3h">3 Hours</option>
                        <option value="6h">6 Hours</option>
                        <option value="12h">12 Hours</option>
                        <option value="24h">24 Hours</option>
                        <option value="48h">48 Hours</option>
                        <option value="7d">7 Days</option>
                        <option value="30d">30 Days</option>
                    </select>
                    <button class="btn btn-sm btn-outline-secondary" onclick="Monitoring.refresh()" title="Refresh">
                        <i class="bi bi-arrow-clockwise"></i>
                    </button>
                </div>
            </div>

            <div id="mon-nav" class="mb-3"></div>
            <div id="mon-content"></div>
        `;
    },

    async loadOverview() {
        try {
            this._overview = await API.get('api/monitoring.php', { action: 'overview' });
            this.renderNav();
            this.showOverview();
        } catch (e) {
            document.getElementById('mon-content').innerHTML = `<div class="alert alert-danger">${Utils.escapeHtml(e.message)}</div>`;
        }
    },

    renderNav() {
        if (!this._overview) return;
        const nodes = this._overview.nodes || [];
        const vms = this._overview.vms || [];
        const running = vms.filter(v => v.status === 'running');

        const sortedNodes = [...nodes].sort((a, b) => a.localeCompare(b, undefined, { numeric: true }));
        const sortedRunning = [...running].sort((a, b) => a.vmid - b.vmid);

        const nodeLabel = this._currentView === 'node' ? Utils.escapeHtml(this._currentTarget) : 'Select Node...';
        const vmLabel = this._currentView === 'vm'
            ? (() => { const v = vms.find(v => v.vmid === this._currentTarget); return v ? `${v.vmid} — ${Utils.escapeHtml(v.name)}` : `VM ${this._currentTarget}`; })()
            : 'Select VM/CT...';

        document.getElementById('mon-nav').innerHTML = `
            <div class="d-flex gap-2 flex-wrap align-items-center">
                <button class="btn btn-sm ${this._currentView === 'overview' ? 'btn-primary' : 'btn-outline-secondary'}"
                    onclick="Monitoring.showOverview()">
                    <i class="bi bi-house me-1"></i>Overview
                </button>
                <div class="dropdown">
                    <button class="btn btn-sm ${this._currentView === 'node' ? 'btn-success' : 'btn-outline-secondary'} dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-hdd-rack me-1"></i>${nodeLabel}
                    </button>
                    <ul class="dropdown-menu">
                        ${sortedNodes.map(n => `
                            <li><a class="dropdown-item ${this._currentView === 'node' && this._currentTarget === n ? 'active' : ''}"
                                href="#" onclick="Monitoring.showNode('${Utils.escapeHtml(n)}'); return false;">
                                <i class="bi bi-hdd-rack me-1"></i>${Utils.escapeHtml(n)}
                            </a></li>
                        `).join('')}
                    </ul>
                </div>
                <div class="dropdown">
                    <button class="btn btn-sm ${this._currentView === 'vm' ? 'btn-info' : 'btn-outline-secondary'} dropdown-toggle" data-bs-toggle="dropdown">
                        <i class="bi bi-pc-display me-1"></i>${vmLabel}
                    </button>
                    <ul class="dropdown-menu" style="max-height:300px;overflow-y:auto">
                        ${sortedRunning.map(v => `
                            <li><a class="dropdown-item ${this._currentView === 'vm' && this._currentTarget === v.vmid ? 'active' : ''}"
                                href="#" onclick="Monitoring.showVm(${v.vmid}); return false;">
                                ${v.vmid} — ${Utils.escapeHtml(v.name)} <small class="text-muted">(${Utils.escapeHtml(v.node)})</small>
                            </a></li>
                        `).join('')}
                    </ul>
                </div>
            </div>
        `;
    },

    showOverview() {
        this._currentView = 'overview';
        this._currentTarget = null;
        this.renderNav();
        this.clearCharts();
        if (this._interval) clearInterval(this._interval);

        const nodes = this._overview?.nodes || [];
        if (!nodes.length) {
            document.getElementById('mon-content').innerHTML = '<p class="text-muted">No data collected yet. Metrics will appear after the collector runs.</p>';
            return;
        }

        // Show a small chart per node
        document.getElementById('mon-content').innerHTML = `
            <div class="row g-3">
                ${nodes.map(n => `
                    <div class="col-md-6">
                        <div class="card" style="background:var(--card-bg);border:1px solid var(--border-color);cursor:pointer"
                            onclick="Monitoring.showNode('${Utils.escapeHtml(n)}')">
                            <div class="card-body p-2">
                                <h6 class="mb-1"><i class="bi bi-hdd-rack me-1"></i>${Utils.escapeHtml(n)}</h6>
                                <div style="position:relative;height:120px"><canvas id="chart-overview-${Utils.escapeHtml(n)}"></canvas></div>
                            </div>
                        </div>
                    </div>
                `).join('')}
            </div>
        `;

        nodes.forEach(n => this.loadNodeOverviewChart(n));
    },

    async loadNodeOverviewChart(node) {
        try {
            const data = await API.getSilent('api/monitoring.php', {
                action: 'node', node, timerange: this._timerange, smoothing: this._smoothing
            });
            const metrics = data.metrics || [];
            if (!metrics.length) return;

            const labels = metrics.map(m => this.formatTime(m.ts));
            const cpu = metrics.map(m => (m.cpu_pct * 100).toFixed(1));
            const mem = metrics.map(m => m.mem_total > 0 ? ((m.mem_used / m.mem_total) * 100).toFixed(1) : 0);

            this.createChart(`chart-overview-${node}`, labels, [
                { label: 'CPU %', data: cpu, borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true },
                { label: 'RAM %', data: mem, borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', fill: true },
            ], { max: 100 });
        } catch (e) {}
    },

    async showNode(node) {
        this._currentView = 'node';
        this._currentTarget = node;
        this.renderNav();
        this.clearCharts();

        document.getElementById('mon-content').innerHTML = `
            <h5 class="mb-3"><i class="bi bi-hdd-rack me-2"></i>${Utils.escapeHtml(node)}</h5>
            <div class="row g-3">
                <div class="col-md-6"><div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)"><div class="card-body p-2">
                    <h6 class="mb-1">CPU Usage</h6><div style="position:relative;height:200px"><canvas id="chart-node-cpu"></canvas></div>
                </div></div></div>
                <div class="col-md-6"><div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)"><div class="card-body p-2">
                    <h6 class="mb-1">Memory Usage</h6><div style="position:relative;height:200px"><canvas id="chart-node-mem"></canvas></div>
                </div></div></div>
                <div class="col-md-6"><div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)"><div class="card-body p-2">
                    <h6 class="mb-1">Network Throughput</h6><div style="position:relative;height:200px"><canvas id="chart-node-net"></canvas></div>
                </div></div></div>
                <div class="col-md-6"><div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)"><div class="card-body p-2">
                    <h6 class="mb-1">Disk Throughput</h6><div style="position:relative;height:200px"><canvas id="chart-node-disk"></canvas></div>
                </div></div></div>
            </div>
        `;

        await this.loadNodeCharts(node);
        this.startAutoRefresh(() => this.loadNodeCharts(node));
    },

    async loadNodeCharts(node) {
        try {
            const data = await API.getSilent('api/monitoring.php', {
                action: 'node', node, timerange: this._timerange, smoothing: this._smoothing
            });
            const m = data.metrics || [];
            if (!m.length) return;

            const labels = m.map(r => this.formatTime(r.ts));

            this.createChart('chart-node-cpu', labels, [
                { label: 'CPU %', data: m.map(r => (r.cpu_pct * 100).toFixed(1)), borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true },
            ], { max: 100 });

            this.createChart('chart-node-mem', labels, [
                { label: 'Used', data: m.map(r => (r.mem_used / 1073741824).toFixed(2)), borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', fill: true },
                { label: 'Total', data: m.map(r => (r.mem_total / 1073741824).toFixed(2)), borderColor: '#6c757d', borderDash: [5, 5] },
            ], { label: 'GB' });

            this.createChart('chart-node-net', labels, [
                { label: 'In', data: m.map(r => (r.net_in_bytes * 8 / 1048576).toFixed(2)), borderColor: '#0dcaf0' },
                { label: 'Out', data: m.map(r => (r.net_out_bytes * 8 / 1048576).toFixed(2)), borderColor: '#ffc107' },
            ], { label: 'Mbit/s' });

            this.createChart('chart-node-disk', labels, [
                { label: 'Read', data: m.map(r => (r.disk_read_bytes / 1048576).toFixed(2)), borderColor: '#0d6efd' },
                { label: 'Write', data: m.map(r => (r.disk_write_bytes / 1048576).toFixed(2)), borderColor: '#dc3545' },
            ], { label: 'MB/s' });
        } catch (e) {}
    },

    async showVm(vmid) {
        this._currentView = 'vm';
        this._currentTarget = vmid;
        this.renderNav();
        this.clearCharts();

        const vm = (this._overview?.vms || []).find(v => v.vmid === vmid);
        const vmName = vm ? `${vm.vmid} — ${vm.name}` : `VM ${vmid}`;

        document.getElementById('mon-content').innerHTML = `
            <h5 class="mb-3"><i class="bi bi-pc-display me-2"></i>${Utils.escapeHtml(vmName)}</h5>
            <div id="mon-vm-summary" class="mb-3"></div>
            <div class="row g-3">
                <div class="col-md-6"><div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)"><div class="card-body p-2">
                    <h6 class="mb-1">CPU Usage</h6><div style="position:relative;height:200px"><canvas id="chart-vm-cpu"></canvas></div>
                </div></div></div>
                <div class="col-md-6"><div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)"><div class="card-body p-2">
                    <h6 class="mb-1">Memory</h6><div style="position:relative;height:200px"><canvas id="chart-vm-mem"></canvas></div>
                </div></div></div>
                <div class="col-md-6"><div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)"><div class="card-body p-2">
                    <h6 class="mb-1">Network Throughput</h6><div style="position:relative;height:200px"><canvas id="chart-vm-net"></canvas></div>
                </div></div></div>
                <div class="col-md-6"><div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)"><div class="card-body p-2">
                    <h6 class="mb-1">Disk Throughput</h6><div style="position:relative;height:200px"><canvas id="chart-vm-disk"></canvas></div>
                </div></div></div>
            </div>
        `;

        await this.loadVmCharts(vmid);
        this.loadVmSummary(vmid);
        this.startAutoRefresh(() => this.loadVmCharts(vmid));
    },

    async loadVmCharts(vmid) {
        try {
            const data = await API.getSilent('api/monitoring.php', {
                action: 'vm', vmid, timerange: this._timerange, smoothing: this._smoothing
            });
            const m = data.metrics || [];
            if (!m.length) return;

            const labels = m.map(r => this.formatTime(r.ts));

            this.createChart('chart-vm-cpu', labels, [
                { label: 'CPU %', data: m.map(r => (r.cpu_pct * 100).toFixed(1)), borderColor: '#0d6efd', backgroundColor: 'rgba(13,110,253,0.1)', fill: true },
            ], { max: 100 });

            this.createChart('chart-vm-mem', labels, [
                { label: 'Used', data: m.map(r => (r.mem_used / 1073741824).toFixed(3)), borderColor: '#198754', backgroundColor: 'rgba(25,135,84,0.1)', fill: true },
                { label: 'Allocated', data: m.map(r => (r.mem_total / 1073741824).toFixed(3)), borderColor: '#6c757d', borderDash: [5, 5] },
            ], { label: 'GB' });

            this.createChart('chart-vm-net', labels, [
                { label: 'In', data: m.map(r => (r.net_in_bytes * 8 / 1048576).toFixed(2)), borderColor: '#0dcaf0' },
                { label: 'Out', data: m.map(r => (r.net_out_bytes * 8 / 1048576).toFixed(2)), borderColor: '#ffc107' },
            ], { label: 'Mbit/s' });

            this.createChart('chart-vm-disk', labels, [
                { label: 'Read', data: m.map(r => (r.disk_read_bytes / 1048576).toFixed(2)), borderColor: '#0d6efd' },
                { label: 'Write', data: m.map(r => (r.disk_write_bytes / 1048576).toFixed(2)), borderColor: '#dc3545' },
            ], { label: 'MB/s' });
        } catch (e) {}
    },

    async loadVmSummary(vmid) {
        try {
            const data = await API.getSilent('api/monitoring.php', {
                action: 'vm-summary', vmid, timerange: this._timerange
            });
            const s = data.summary;
            if (!s || !s.samples) {
                document.getElementById('mon-vm-summary').innerHTML = '<p class="text-muted small">Not enough data for summary.</p>';
                return;
            }

            const memTotal = s.mem_total || 1;
            document.getElementById('mon-vm-summary').innerHTML = `
                <div class="row g-2">
                    <div class="col-md-3">
                        <div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)">
                            <div class="card-body p-2 text-center">
                                <div class="text-muted small">CPU Avg / P95 / Max</div>
                                <div class="fw-bold">${(s.avg_cpu * 100).toFixed(1)}% / ${(s.p95_cpu * 100).toFixed(1)}% / ${(s.max_cpu * 100).toFixed(1)}%</div>
                                <div class="text-muted small">${s.cpu_count} cores</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)">
                            <div class="card-body p-2 text-center">
                                <div class="text-muted small">RAM Avg / P95</div>
                                <div class="fw-bold">${((s.avg_mem / memTotal) * 100).toFixed(1)}% / ${((s.p95_mem / memTotal) * 100).toFixed(1)}%</div>
                                <div class="text-muted small">${this.formatBytes(memTotal)} allocated</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)">
                            <div class="card-body p-2 text-center">
                                <div class="text-muted small">Net In / Out (avg)</div>
                                <div class="fw-bold">${((s.avg_net_in || 0) * 8 / 1048576).toFixed(2)} Mbit/s / ${((s.avg_net_out || 0) * 8 / 1048576).toFixed(2)} Mbit/s</div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card" style="background:var(--card-bg);border:1px solid var(--border-color)">
                            <div class="card-body p-2 text-center">
                                <div class="text-muted small">Samples</div>
                                <div class="fw-bold">${parseInt(s.samples).toLocaleString()}</div>
                                <div class="text-muted small">${this.formatTimeAgo(s.first_sample)} — ${this.formatTimeAgo(s.last_sample)}</div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        } catch (e) {}
    },

    // --- Chart helpers ---

    createChart(canvasId, labels, datasets, opts = {}) {
        if (this._charts[canvasId]) {
            this._charts[canvasId].destroy();
            delete this._charts[canvasId];
        }
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        const isDark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
        const gridColor = isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.08)';
        const textColor = isDark ? '#adb5bd' : '#495057';

        const finalDatasets = datasets.map(ds => ({
            borderWidth: 1.5,
            pointRadius: 0,
            tension: 0.3,
            ...ds,
        }));

        // Downsample for performance if more than 500 points
        const maxPoints = 500;
        if (labels.length > maxPoints) {
            const step = Math.ceil(labels.length / maxPoints);
            const newLabels = [];
            const newData = finalDatasets.map(() => []);
            for (let i = 0; i < labels.length; i += step) {
                newLabels.push(labels[i]);
                finalDatasets.forEach((ds, di) => newData[di].push(ds.data[i]));
            }
            labels = newLabels;
            finalDatasets.forEach((ds, di) => { ds.data = newData[di]; });
        }

        this._charts[canvasId] = new Chart(canvas, {
            type: 'line',
            data: { labels, datasets: finalDatasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: datasets.length > 1, position: 'top', labels: { color: textColor, boxWidth: 12, font: { size: 11 } } },
                    tooltip: { mode: 'index', intersect: false },
                },
                scales: {
                    x: { display: true, grid: { color: gridColor }, ticks: { color: textColor, font: { size: 10 }, maxTicksLimit: 8 } },
                    y: {
                        display: true,
                        grid: { color: gridColor },
                        ticks: { color: textColor, font: { size: 10 } },
                        min: 0,
                        ...(opts.max ? { max: opts.max } : {}),
                        ...(opts.label ? { title: { display: true, text: opts.label, color: textColor, font: { size: 11 } } } : {}),
                    },
                },
            },
        });
    },

    clearCharts() {
        Object.values(this._charts).forEach(c => c.destroy());
        this._charts = {};
    },

    startAutoRefresh(fn) {
        if (this._interval) clearInterval(this._interval);
        this._interval = setInterval(fn, 30000);
    },

    onTimerangeChange(val) {
        this._timerange = val;
        this.refresh();
    },

    onSmoothingChange(val) {
        this._smoothing = parseInt(val) || 0;
        this.refresh();
    },

    refresh() {
        if (this._currentView === 'overview') this.showOverview();
        else if (this._currentView === 'node') this.showNode(this._currentTarget);
        else if (this._currentView === 'vm') this.showVm(this._currentTarget);
    },

    // --- Utility ---

    formatTime(ts) {
        if (!ts) return '';
        const d = new Date(ts.replace(' ', 'T') + 'Z');
        const now = new Date();
        if (d.toDateString() === now.toDateString()) {
            return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
        }
        return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' +
            d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },

    formatBytes(bytes) {
        bytes = parseInt(bytes) || 0;
        if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(1) + ' GB';
        if (bytes >= 1048576) return (bytes / 1048576).toFixed(0) + ' MB';
        if (bytes >= 1024) return (bytes / 1024).toFixed(0) + ' KB';
        return bytes + ' B';
    },

    formatTimeAgo(ts) {
        if (!ts) return '';
        const d = new Date(ts.replace(' ', 'T') + 'Z');
        return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    },
};
