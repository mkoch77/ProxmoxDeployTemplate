const Dashboard = {
    refreshInterval: null,
    currentFilter: { type: '', node: '', search: '', status: '', os: '' },
    currentSort: { col: 'vmid', dir: 'asc' },
    guests: [],
    nodes: [],
    pendingMigrations: {},

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

            // Load pending loadbalancer recommendations (non-blocking)
            this.pendingMigrations = {};
            try {
                const lb = await API.getLoadbalancer();
                if (lb.latest_run?.recommendations) {
                    for (const rec of lb.latest_run.recommendations) {
                        if (rec.status === 'pending') {
                            this.pendingMigrations[rec.vmid] = rec.target_node;
                        }
                    }
                }
            } catch (_) { /* LB not available or no permission */ }

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
                <div class="d-flex gap-2">
                    ${Permissions.has('vm.start') ? `
                        <button class="btn btn-outline-success btn-sm" onclick="Dashboard.startAll()">
                            <i class="bi bi-play-fill"></i> Start All
                        </button>
                    ` : ''}
                    ${Permissions.has('vm.shutdown') ? `
                        <button class="btn btn-outline-danger btn-sm" onclick="Dashboard.shutdownAll()">
                            <i class="bi bi-power"></i> Shutdown All
                        </button>
                    ` : ''}
                    <button class="btn btn-outline-light btn-sm" onclick="Dashboard.loadData()">
                        <i class="bi bi-arrow-clockwise"></i> Refresh
                    </button>
                </div>
            </div>

            <div id="stats-row" class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon blue"><i class="bi bi-hdd-stack-fill"></i></div>
                        <div class="stat-value" id="stat-total">-</div>
                        <div class="stat-label">Total</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon green"><i class="bi bi-play-circle-fill"></i></div>
                        <div class="stat-value" style="color:var(--accent-green)" id="stat-running">-</div>
                        <div class="stat-label">Running</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card">
                        <div class="stat-icon red"><i class="bi bi-stop-circle-fill"></i></div>
                        <div class="stat-value" style="color:var(--accent-red)" id="stat-stopped">-</div>
                        <div class="stat-label">Stopped</div>
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
                    <button class="btn btn-outline-light active" data-filter-type="">All</button>
                    <button class="btn btn-outline-light" data-filter-type="qemu">VMs</button>
                    <button class="btn btn-outline-light" data-filter-type="lxc">CTs</button>
                </div>
                <select id="filter-node" class="form-select form-select-sm" style="width:auto;">
                    <option value="">All Nodes</option>
                </select>
                <select id="filter-status" class="form-select form-select-sm" style="width:auto;">
                    <option value="">All Status</option>
                    <option value="running">Running</option>
                    <option value="stopped">Stopped</option>
                </select>
                <select id="filter-os" class="form-select form-select-sm" style="width:auto;">
                    <option value="">All OS</option>
                </select>
                <input id="filter-search" type="text" class="form-control form-control-sm" placeholder="Search by name or ID..." style="width:220px;">
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

        document.getElementById('filter-status').addEventListener('change', (e) => {
            this.currentFilter.status = e.target.value;
            this.updateView();
        });

        document.getElementById('filter-os').addEventListener('change', (e) => {
            this.currentFilter.os = e.target.value;
            this.updateView();
        });

        document.getElementById('filter-search').addEventListener('input', Utils.debounce((e) => {
            this.currentFilter.search = e.target.value.toLowerCase();
            this.updateView();
        }, 300));
    },

    setSort(col) {
        if (this.currentSort.col === col) {
            this.currentSort.dir = this.currentSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            this.currentSort.col = col;
            this.currentSort.dir = 'asc';
        }
        this.updateView();
    },

    sortGuests(guests) {
        const { col, dir } = this.currentSort;
        const mult = dir === 'asc' ? 1 : -1;

        return guests.sort((a, b) => {
            let va, vb;
            switch (col) {
                case 'vmid':
                    return (a.vmid - b.vmid) * mult;
                case 'name':
                    va = (a.name || '').toLowerCase();
                    vb = (b.name || '').toLowerCase();
                    return va.localeCompare(vb) * mult;
                case 'type':
                    return (a.type || '').localeCompare(b.type || '') * mult;
                case 'node':
                    return (a.node || '').localeCompare(b.node || '') * mult;
                case 'os':
                    va = Dashboard.osLabel(a.ostype, a.type);
                    vb = Dashboard.osLabel(b.ostype, b.type);
                    return va.localeCompare(vb) * mult;
                case 'status':
                    return (a.status || '').localeCompare(b.status || '') * mult;
                case 'cpu':
                    va = a.status === 'running' ? (a.cpu || 0) : -1;
                    vb = b.status === 'running' ? (b.cpu || 0) : -1;
                    return (va - vb) * mult;
                case 'ram':
                    va = a.status === 'running' ? (a.mem || 0) : -1;
                    vb = b.status === 'running' ? (b.mem || 0) : -1;
                    return (va - vb) * mult;
                case 'uptime':
                    va = a.status === 'running' ? (a.uptime || 0) : -1;
                    vb = b.status === 'running' ? (b.uptime || 0) : -1;
                    return (va - vb) * mult;
                default:
                    return 0;
            }
        });
    },

    sortIcon(col) {
        if (this.currentSort.col !== col) return '<i class="bi bi-chevron-expand" style="opacity:0.3;font-size:0.7em"></i>';
        return this.currentSort.dir === 'asc'
            ? '<i class="bi bi-chevron-up" style="font-size:0.7em"></i>'
            : '<i class="bi bi-chevron-down" style="font-size:0.7em"></i>';
    },

    osLabel(ostype, guestType) {
        if (!ostype) return '-';
        if (guestType === 'lxc') {
            const lxcMap = {
                'debian': 'Debian', 'ubuntu': 'Ubuntu', 'centos': 'CentOS',
                'fedora': 'Fedora', 'opensuse': 'openSUSE', 'archlinux': 'Arch',
                'alpine': 'Alpine', 'gentoo': 'Gentoo', 'nixos': 'NixOS',
                'devuan': 'Devuan', 'unmanaged': 'Unmanaged',
            };
            return lxcMap[ostype] || ostype;
        }
        const qemuMap = {
            'l26': 'Linux', 'l24': 'Linux 2.4', 'win11': 'Windows 11',
            'win10': 'Windows 10', 'win8': 'Windows 8', 'win7': 'Windows 7',
            'wvista': 'Vista', 'wxp': 'Windows XP', 'w2k': 'Windows 2000',
            'w2k8': 'Server 2008', 'w2k12': 'Server 2012', 'w2k16': 'Server 2016',
            'w2k19': 'Server 2019', 'w2k22': 'Server 2022', 'w2k25': 'Server 2025',
            'solaris': 'Solaris', 'other': 'Other',
        };
        return qemuMap[ostype] || ostype;
    },

    osIcon(ostype, guestType) {
        if (!ostype) return 'bi-question-circle';
        if (guestType === 'lxc') return 'bi-box';
        if (ostype.startsWith('w') && ostype !== 'wvista') return 'bi-windows';
        if (ostype === 'wvista') return 'bi-windows';
        if (ostype === 'l26' || ostype === 'l24') return 'bi-ubuntu';
        if (ostype === 'solaris') return 'bi-sun';
        return 'bi-pc-display';
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
        const currentNodeVal = nodeSelect.value;
        nodeSelect.innerHTML = '<option value="">All Nodes</option>';
        this.nodes.forEach(n => {
            nodeSelect.innerHTML += `<option value="${n.node}">${n.node}</option>`;
        });
        nodeSelect.value = currentNodeVal;

        // Update OS filter dropdown (dynamically from guest data)
        const osSelect = document.getElementById('filter-os');
        const currentOsVal = osSelect.value;
        const osSet = new Map();
        for (const g of this.guests) {
            const label = this.osLabel(g.ostype, g.type);
            if (label !== '-' && !osSet.has(label)) {
                osSet.set(label, g.ostype);
            }
        }
        osSelect.innerHTML = '<option value="">All OS</option>';
        for (const [label, raw] of [...osSet.entries()].sort((a, b) => a[0].localeCompare(b[0]))) {
            osSelect.innerHTML += `<option value="${Utils.escapeHtml(raw)}">${Utils.escapeHtml(label)}</option>`;
        }
        osSelect.value = currentOsVal;

        // Filter guests
        let filtered = [...this.guests];
        if (this.currentFilter.type) {
            filtered = filtered.filter(g => g.type === this.currentFilter.type);
        }
        if (this.currentFilter.node) {
            filtered = filtered.filter(g => g.node === this.currentFilter.node);
        }
        if (this.currentFilter.status) {
            filtered = filtered.filter(g => g.status === this.currentFilter.status);
        }
        if (this.currentFilter.os) {
            filtered = filtered.filter(g => (g.ostype || '') === this.currentFilter.os);
        }
        if (this.currentFilter.search) {
            filtered = filtered.filter(g =>
                (g.name || '').toLowerCase().includes(this.currentFilter.search) ||
                String(g.vmid).includes(this.currentFilter.search)
            );
        }

        // Sort
        filtered = this.sortGuests(filtered);

        this.renderTable(filtered);
    },

    renderTable(guests) {
        const container = document.getElementById('guest-table-container');

        if (guests.length === 0) {
            container.innerHTML = `
                <div class="text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No VMs/CTs found</p>
                </div>`;
            return;
        }

        const cols = [
            { key: 'vmid', label: 'VMID' },
            { key: 'name', label: 'Name' },
            { key: 'type', label: 'Type' },
            { key: 'node', label: 'Node' },
            { key: 'os', label: 'OS' },
            { key: 'status', label: 'Status' },
            { key: 'cpu', label: 'CPU' },
            { key: 'ram', label: 'RAM' },
            { key: 'uptime', label: 'Uptime' },
        ];

        let html = `<table class="table table-dark table-hover mb-0">
            <thead>
                <tr>`;

        for (const c of cols) {
            html += `<th class="sortable-th" onclick="Dashboard.setSort('${c.key}')" style="cursor:pointer;user-select:none">${c.label} ${this.sortIcon(c.key)}</th>`;
        }
        html += `<th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>`;

        for (const g of guests) {
            const targetNode = this.pendingMigrations[g.vmid];
            const isPending = !!targetNode;
            const migrationHint = isPending
                ? ` <i class="bi bi-shuffle" style="color:var(--accent-purple);font-size:0.75em" title="Pending migration to ${Utils.escapeHtml(targetNode)}"></i>`
                : '';
            const osText = this.osLabel(g.ostype, g.type);

            html += `<tr>
                <td><strong style="color:var(--accent-blue)">${g.vmid}</strong></td>
                <td>${Utils.escapeHtml(g.name || '-')}</td>
                <td><i class="bi ${Utils.typeIcon(g.type)}" style="opacity:0.6"></i> ${Utils.typeLabel(g.type)}</td>
                <td style="color:var(--text-secondary)">${Utils.escapeHtml(g.node)}${migrationHint}</td>
                <td style="color:var(--text-secondary)">${Utils.escapeHtml(osText)}</td>
                <td><span class="badge ${Utils.statusBadgeClass(g.status)}"><i class="bi ${Utils.statusIcon(g.status)}"></i> ${g.status}</span></td>
                <td>${g.status === 'running' ? Utils.cpuPercent(g.cpu) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td>${g.status === 'running' ? Utils.formatBytes(g.mem) + ' / ' + Utils.formatBytes(g.maxmem) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td>${g.status === 'running' ? Utils.formatUptime(g.uptime) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td style="text-align:right">${this.renderActions(g)}</td>
            </tr>`;
        }

        html += '</tbody></table>';
        container.innerHTML = html;
    },

    renderActions(guest) {
        let html = '';

        // Migrate button (dropdown with node selection)
        if (Permissions.has('vm.migrate') && this.nodes.length > 1) {
            const otherNodes = this.nodes.filter(n => n.node !== guest.node && n.status === 'online');
            if (otherNodes.length > 0) {
                const dropId = `migrate-drop-${guest.vmid}`;
                html += `<div class="btn-group me-1">
                    <button class="btn btn-outline-light btn-action dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false" title="Migrate">
                        <i class="bi bi-shuffle"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end" id="${dropId}">
                        <li><span class="dropdown-item-text" style="font-size:0.75rem;color:var(--text-muted)">Migrate to...</span></li>
                        <li><hr class="dropdown-divider"></li>`;
                for (const n of otherNodes) {
                    const online = guest.status === 'running' ? 'true' : 'false';
                    html += `<li><a class="dropdown-item" href="#" onclick="event.preventDefault();Dashboard.migrateGuest('${guest.node}','${guest.type}',${guest.vmid},'${n.node}',${online},'${Utils.escapeHtml(guest.name || '')}')">
                        <i class="bi bi-hdd-rack" style="opacity:0.5"></i> ${Utils.escapeHtml(n.node)}
                    </a></li>`;
                }
                html += `</ul></div>`;
            }
        }

        // Power controls
        html += Controls.renderButtons(guest);
        return html;
    },

    async startAll() {
        const stopped = this.guests.filter(g => g.status === 'stopped');
        if (stopped.length === 0) {
            Toast.info('No stopped VMs/CTs');
            return;
        }
        if (!confirm(`Start all ${stopped.length} stopped VM${stopped.length > 1 ? 's' : ''}/CT${stopped.length > 1 ? 's' : ''}?`)) return;

        Toast.info(`Starting ${stopped.length} guests...`);
        let ok = 0, fail = 0;
        for (const g of stopped) {
            try {
                await API.power(g.node, g.type, g.vmid, 'start');
                ok++;
            } catch (_) {
                fail++;
            }
        }
        const msg = `${ok}/${stopped.length} start initiated` + (fail > 0 ? ` (${fail} failed)` : '');
        fail > 0 ? Toast.error(msg) : Toast.success(msg);
        setTimeout(() => this.loadData(), 3000);
    },

    async shutdownAll() {
        const running = this.guests.filter(g => g.status === 'running');
        if (running.length === 0) {
            Toast.info('No running VMs/CTs');
            return;
        }
        if (!confirm(`Shut down all ${running.length} running VM${running.length > 1 ? 's' : ''}/CT${running.length > 1 ? 's' : ''}?`)) return;

        Toast.info(`Shutting down ${running.length} guests...`);
        let ok = 0, fail = 0;
        for (const g of running) {
            try {
                await API.power(g.node, g.type, g.vmid, 'shutdown');
                ok++;
            } catch (_) {
                fail++;
            }
        }
        const msg = `${ok}/${running.length} shutdown initiated` + (fail > 0 ? ` (${fail} failed)` : '');
        fail > 0 ? Toast.error(msg) : Toast.success(msg);
        setTimeout(() => this.loadData(), 3000);
    },

    async migrateGuest(node, type, vmid, target, online, name) {
        const displayName = name || `${vmid}`;
        if (!confirm(`Migrate ${Utils.typeLabel(type)} "${displayName}" from ${node} to ${target}?`)) return;

        try {
            await API.migrate(node, type, vmid, target, online);
            Toast.success(`Migration of "${displayName}" to ${target} started...`);
            setTimeout(() => this.loadData(), 3000);
        } catch (err) {
            // Error shown by API
        }
    },
};
