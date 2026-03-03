const Dashboard = {
    refreshInterval: null,
    currentFilter: { type: '', node: '', search: '' },
    guests: [],
    nodes: [],

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
        this.refreshInterval = setInterval(() => this.loadData(), 10000);
    },

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    },

    async loadData() {
        try {
            const [guests, nodes] = await Promise.all([
                API.getGuests(),
                API.getNodes(),
            ]);
            this.guests = guests;
            this.nodes = nodes;
            this.updateView();
        } catch (err) {
            // Error shown by API
        }
    },

    async refresh() {
        await this.loadData();
    },

    render() {
        const content = document.getElementById('page-content');
        content.innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-grid-1x2-fill"></i>Dashboard</h2>
                <button class="btn btn-outline-light btn-sm" onclick="Dashboard.loadData()">
                    <i class="bi bi-arrow-clockwise"></i> Aktualisieren
                </button>
            </div>

            <div id="stats-row" class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="bi bi-hdd-stack-fill"></i></div>
                        <div class="stat-value" id="stat-total">-</div>
                        <div class="stat-label">Gesamt</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="bi bi-play-circle-fill"></i></div>
                        <div class="stat-value" style="color:var(--accent-green)" id="stat-running">-</div>
                        <div class="stat-label">Laufend</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="bi bi-stop-circle-fill"></i></div>
                        <div class="stat-value" style="color:var(--accent-red)" id="stat-stopped">-</div>
                        <div class="stat-label">Gestoppt</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon purple"><i class="bi bi-hdd-rack-fill"></i></div>
                        <div class="stat-value" style="color:var(--accent-purple)" id="stat-nodes">-</div>
                        <div class="stat-label">Nodes</div>
                    </div>
                </div>
            </div>

            <div class="filter-bar d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-light active" data-filter-type="">Alle</button>
                    <button class="btn btn-outline-light" data-filter-type="qemu">VMs</button>
                    <button class="btn btn-outline-light" data-filter-type="lxc">CTs</button>
                </div>
                <select id="filter-node" class="form-select form-select-sm" style="width:auto;">
                    <option value="">Alle Nodes</option>
                </select>
                <input id="filter-search" type="text" class="form-control form-control-sm" placeholder="Suche nach Name oder ID..." style="width:220px;">
            </div>

            <div id="guest-table-container" class="guest-table mt-3">
                <div class="loading-spinner"><div class="spinner-border text-primary"></div></div>
            </div>`;

        // Filter events
        content.querySelectorAll('[data-filter-type]').forEach(btn => {
            btn.addEventListener('click', () => {
                content.querySelectorAll('[data-filter-type]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.currentFilter.type = btn.dataset.filterType;
                this.updateView();
            });
        });

        document.getElementById('filter-node').addEventListener('change', (e) => {
            this.currentFilter.node = e.target.value;
            this.updateView();
        });

        document.getElementById('filter-search').addEventListener('input', Utils.debounce((e) => {
            this.currentFilter.search = e.target.value.toLowerCase();
            this.updateView();
        }, 300));
    },

    updateView() {
        // Update stats
        const running = this.guests.filter(g => g.status === 'running').length;
        const stopped = this.guests.filter(g => g.status === 'stopped').length;
        document.getElementById('stat-total').textContent = this.guests.length;
        document.getElementById('stat-running').textContent = running;
        document.getElementById('stat-stopped').textContent = stopped;
        document.getElementById('stat-nodes').textContent = this.nodes.length;

        // Update node filter dropdown
        const nodeSelect = document.getElementById('filter-node');
        const currentVal = nodeSelect.value;
        nodeSelect.innerHTML = '<option value="">Alle Nodes</option>';
        this.nodes.forEach(n => {
            nodeSelect.innerHTML += `<option value="${n.node}">${n.node}</option>`;
        });
        nodeSelect.value = currentVal;

        // Filter guests
        let filtered = [...this.guests];
        if (this.currentFilter.type) {
            filtered = filtered.filter(g => g.type === this.currentFilter.type);
        }
        if (this.currentFilter.node) {
            filtered = filtered.filter(g => g.node === this.currentFilter.node);
        }
        if (this.currentFilter.search) {
            filtered = filtered.filter(g =>
                (g.name || '').toLowerCase().includes(this.currentFilter.search) ||
                String(g.vmid).includes(this.currentFilter.search)
            );
        }

        // Sort: running first, then by VMID
        filtered.sort((a, b) => {
            if (a.status === 'running' && b.status !== 'running') return -1;
            if (a.status !== 'running' && b.status === 'running') return 1;
            return a.vmid - b.vmid;
        });

        this.renderTable(filtered);
    },

    renderTable(guests) {
        const container = document.getElementById('guest-table-container');

        if (guests.length === 0) {
            container.innerHTML = `
                <div class="text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">Keine VMs/CTs gefunden</p>
                </div>`;
            return;
        }

        let html = `<table class="table table-dark table-hover mb-0">
            <thead>
                <tr>
                    <th>VMID</th>
                    <th>Name</th>
                    <th>Typ</th>
                    <th>Node</th>
                    <th>Status</th>
                    <th>CPU</th>
                    <th>RAM</th>
                    <th>Uptime</th>
                    <th style="text-align:right">Aktionen</th>
                </tr>
            </thead>
            <tbody>`;

        for (const g of guests) {
            html += `<tr>
                <td><strong style="color:var(--accent-blue)">${g.vmid}</strong></td>
                <td>${Utils.escapeHtml(g.name || '-')}</td>
                <td><i class="bi ${Utils.typeIcon(g.type)}" style="opacity:0.6"></i> ${Utils.typeLabel(g.type)}</td>
                <td style="color:var(--text-secondary)">${Utils.escapeHtml(g.node)}</td>
                <td><span class="badge ${Utils.statusBadgeClass(g.status)}"><i class="bi ${Utils.statusIcon(g.status)}"></i> ${g.status}</span></td>
                <td>${g.status === 'running' ? Utils.cpuPercent(g.cpu) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td>${g.status === 'running' ? Utils.formatBytes(g.mem) + ' / ' + Utils.formatBytes(g.maxmem) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td>${g.status === 'running' ? Utils.formatUptime(g.uptime) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td style="text-align:right">${Controls.renderButtons(g)}</td>
            </tr>`;
        }

        html += '</tbody></table>';
        container.innerHTML = html;
    }
};
