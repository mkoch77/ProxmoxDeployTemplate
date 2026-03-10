const Dashboard = {
    refreshInterval: null,
    currentFilter: { type: '', node: '', search: '', status: '', os: '' },
    currentSort: { col: 'name', dir: 'asc' },
    groupBy: '',
    guests: [],
    nodes: [],
    pendingMigrations: {},
    selectedGuests: new Set(),  // Set of "vmid:node" keys
    _ipRefreshInterval: null,
    _ipLoading: false,
    _page: 1,
    _perPage: 50,

    async init() {
        this.render();
        await this.loadData();
        this.startAutoRefresh();
        this._startIpRefresh();
    },

    destroy() {
        this.stopAutoRefresh();
        this._stopIpRefresh();
    },

    startAutoRefresh() {
        this.stopAutoRefresh();
        this.refreshInterval = setInterval(() => this.loadData(true), 10000);
    },

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    },

    _startIpRefresh() {
        this._stopIpRefresh();
        this._refreshIps();
        this._ipRefreshInterval = setInterval(() => this._refreshIps(), 120000);
    },

    _stopIpRefresh() {
        if (this._ipRefreshInterval) {
            clearInterval(this._ipRefreshInterval);
            this._ipRefreshInterval = null;
        }
    },

    async loadData(silent = false) {
        try {
            const fetch = silent ? API.getSilent.bind(API) : API.get.bind(API);
            const [guests, nodes] = await Promise.all([
                fetch('api/guests.php'),
                fetch('api/nodes.php'),
            ]);
            this.guests = guests;
            this.nodes = nodes;

            // Clean stale selections (VMs that no longer exist)
            if (this.selectedGuests.size > 0) {
                const validKeys = new Set(guests.map(g => `${g.vmid}:${g.node}`));
                for (const key of [...this.selectedGuests]) {
                    if (!validKeys.has(key)) this.selectedGuests.delete(key);
                }
            }

            // Load pending loadbalancer recommendations (non-blocking)
            this.pendingMigrations = {};
            try {
                const lb = silent ? await API.getSilent('api/loadbalancer.php') : await API.getLoadbalancer();
                if (lb.latest_run?.recommendations) {
                    for (const rec of lb.latest_run.recommendations) {
                        if (rec.status === 'pending') {
                            this.pendingMigrations[rec.vmid] = rec.target_node;
                        }
                    }
                }
            } catch (_) { /* LB not available or no permission */ }

            // Load maintenance nodes so migrate dropdown excludes them
            this.maintenanceNodeNames = new Set();
            try {
                const maint = await API.getSilent('api/maintenance.php');
                if (Array.isArray(maint)) {
                    for (const row of maint) {
                        if (row.node_name) this.maintenanceNodeNames.add(row.node_name);
                    }
                }
            } catch (_) { /* no permission or not available */ }

            this.updateView();
        } catch (err) {
            // Error shown by API (only when silent=false)
        }
    },

    async refresh() {
        await this.loadData(false);
    },

    render() {
        const content = document.getElementById('page-content');
        content.innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-grid-1x2-fill"></i>Dashboard</h2>
                <div class="d-flex gap-2 align-items-center">
                    ${Permissions.isAdmin() ? `
                        <button class="btn btn-outline-success btn-sm" onclick="Dashboard.startAll()"><i class="bi bi-play-fill"></i> Start All</button>
                        <button class="btn btn-outline-danger btn-sm" onclick="Dashboard.shutdownAll()"><i class="bi bi-power"></i> Shutdown All</button>
                    ` : ''}
                    <button class="btn btn-outline-light btn-sm" onclick="Dashboard.loadData()"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                </div>
            </div>

            <div id="stats-row" class="row g-3 mb-4">
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-centered">
                        <div class="stat-icon blue"><i class="bi bi-hdd-stack-fill"></i></div>
                        <div class="stat-value" id="stat-total">-</div>
                        <div class="stat-label">VMs/CTs Total</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-centered">
                        <div class="stat-icon blue"><i class="bi bi-play-circle-fill"></i></div>
                        <div class="stat-value" id="stat-running">-</div>
                        <div class="stat-label">VMs Running</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-centered">
                        <div class="stat-icon blue"><i class="bi bi-box-fill"></i></div>
                        <div class="stat-value" id="stat-stopped">-</div>
                        <div class="stat-label">CTs Running</div>
                    </div>
                </div>
                <div class="col-6 col-lg-3">
                    <div class="stat-card stat-card-centered">
                        <div class="stat-icon purple"><i class="bi bi-hdd-rack-fill"></i></div>
                        <div class="stat-value" id="stat-nodes">-</div>
                        <div class="stat-label">Cluster Nodes</div>
                    </div>
                </div>
            </div>

            <div class="filter-bar d-flex flex-wrap gap-2 align-items-center">
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
                <select id="group-by" class="form-select form-select-sm" style="width:auto;" title="Group by">
                    <option value="">No grouping</option>
                    <option value="tags">Group by Tags</option>
                    <option value="os">Group by OS</option>
                </select>
                <div class="btn-group btn-group-sm ms-auto" role="group">
                    <button class="btn btn-outline-light active" data-filter-type="">All</button>
                    <button class="btn btn-outline-light" data-filter-type="qemu">VMs</button>
                    <button class="btn btn-outline-light" data-filter-type="lxc">CTs</button>
                </div>
                <input id="filter-search" type="text" class="form-control form-control-sm" placeholder="Search by name or ID..." style="width:220px;">
            </div>

            <div id="bulk-action-bar" class="bulk-action-bar mt-3" style="display:none">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <span class="text-muted small"><strong id="bulk-count">0</strong> selected</span>
                    <div class="vr" style="border-color:var(--border-color);opacity:0.3"></div>
                    ${Permissions.has('vm.start') ? `<button class="btn btn-success btn-sm" onclick="Dashboard.bulkAction('start')"><i class="bi bi-play-fill me-1"></i>Start</button>` : ''}
                    ${Permissions.has('vm.shutdown') ? `<button class="btn btn-outline-warning btn-sm" onclick="Dashboard.bulkAction('shutdown')"><i class="bi bi-power me-1"></i>Shutdown</button>` : ''}
                    ${Permissions.has('vm.stop') ? `<button class="btn btn-warning btn-sm" onclick="Dashboard.bulkAction('stop')"><i class="bi bi-stop-fill me-1"></i>Force Stop</button>` : ''}
                    ${Permissions.has('vm.delete') ? `<button class="btn btn-danger btn-sm" onclick="Dashboard.bulkAction('delete')"><i class="bi bi-trash-fill me-1"></i>Delete</button>` : ''}
                    <button class="btn btn-outline-secondary btn-sm ms-auto" onclick="Dashboard.clearSelection()"><i class="bi bi-x-lg me-1"></i>Clear</button>
                </div>
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
                this._page = 1;
                this.updateView();
            });
        });

        document.getElementById('filter-node').addEventListener('change', (e) => {
            this.currentFilter.node = e.target.value;
            this._page = 1;
            this.updateView();
        });

        document.getElementById('filter-status').addEventListener('change', (e) => {
            this.currentFilter.status = e.target.value;
            this._page = 1;
            this.updateView();
        });

        document.getElementById('filter-os').addEventListener('change', (e) => {
            this.currentFilter.os = e.target.value;
            this._page = 1;
            this.updateView();
        });

        document.getElementById('filter-search').addEventListener('input', Utils.debounce((e) => {
            this.currentFilter.search = e.target.value.toLowerCase();
            this._page = 1;
            this.updateView();
        }, 300));

        document.getElementById('group-by').addEventListener('change', (e) => {
            this.groupBy = e.target.value;
            this._lastTableKey = '';
            this.updateView();
        });
    },

    setPage(page) {
        this._page = page;
        this._lastTableKey = '';
        this.updateView();
    },

    setPerPage(perPage) {
        this._perPage = perPage;
        this._page = 1;
        this._lastTableKey = '';
        this.updateView();
    },

    setSort(col) {
        if (this.currentSort.col === col) {
            this.currentSort.dir = this.currentSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            this.currentSort.col = col;
            this.currentSort.dir = 'asc';
        }
        this._page = 1;
        this._lastTableKey = '';
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
        const total = this.guests.length;
        const vmsTotal   = this.guests.filter(g => g.type === 'qemu').length;
        const vmsRunning = this.guests.filter(g => g.type === 'qemu' && g.status === 'running').length;
        const ctsRunning = this.guests.filter(g => g.type === 'lxc' && g.status === 'running').length;
        const ctsTotal   = this.guests.filter(g => g.type === 'lxc').length;
        document.getElementById('stat-total').textContent = total;
        document.getElementById('stat-running').textContent = `${vmsRunning}/${vmsTotal}`;
        document.getElementById('stat-stopped').textContent = `${ctsRunning}/${ctsTotal}`;
        const nodesOnline = this.nodes.filter(n => n.status === 'online').length;
        document.getElementById('stat-nodes').textContent = `${nodesOnline}/${this.nodes.length}`;

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
            this._lastTableKey = '';
            container.innerHTML = `
                <div class="text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No VMs/CTs found</p>
                </div>`;
            return;
        }

        // If the set of VMs and their statuses/nodes hasn't changed, update only
        // the frequently-changing cells in-place to avoid flickering.
        const tableKey = guests.map(g => `${g.vmid}:${g.node}:${g.status}`).join('|') + ':' + this.groupBy + ':' + this._page + ':' + this._perPage;

        // Close any open migrate menus before re-rendering to prevent orphaned popups
        if (tableKey !== this._lastTableKey) {
            this._closeMigrateMenus();
        }

        if (tableKey === this._lastTableKey && container.querySelector('tbody')) {
            for (const g of guests) {
                const id = `${g.vmid}-${g.node}`;
                const cpuEl    = document.getElementById(`cell-cpu-${id}`);
                const ramEl    = document.getElementById(`cell-ram-${id}`);
                const uptimeEl = document.getElementById(`cell-uptime-${id}`);
                if (cpuEl)    cpuEl.innerHTML    = g.status === 'running' ? Utils.cpuPercent(g.cpu) : '<span style="color:var(--text-muted)">-</span>';
                if (ramEl)    ramEl.innerHTML    = g.status === 'running' ? Utils.formatBytes(g.mem) + ' / ' + Utils.formatBytes(g.maxmem) : '<span style="color:var(--text-muted)">-</span>';
                if (uptimeEl) uptimeEl.innerHTML = g.status === 'running' ? Utils.formatUptime(g.uptime) : '<span style="color:var(--text-muted)">-</span>';
            }
            return;
        }
        this._lastTableKey = tableKey;

        const sortableCols = [
            { key: 'vmid', label: 'VMID' },
            { key: 'name', label: 'Name' },
            { key: 'type', label: 'Type' },
            { key: 'node', label: 'Node' },
            { key: 'os', label: 'OS' },
            { key: 'status', label: 'Status' },
            { key: 'cpu', label: 'CPU' },
            { key: 'ram', label: 'RAM' },
            { key: 'uptime', label: 'Uptime' },
            { key: 'tags', label: 'Tags' },
        ];

        let html = `<table class="table table-dark table-hover mb-0">
            <thead>
                <tr>
                    <th style="width:40px;text-align:center"><input type="checkbox" class="form-check-input" id="select-all-cb" onclick="Dashboard.toggleSelectAll(this)" title="Select all"></th>`;

        for (const c of sortableCols) {
            html += `<th class="sortable-th" onclick="Dashboard.setSort('${c.key}')" style="cursor:pointer;user-select:none">${c.label} ${this.sortIcon(c.key)}</th>`;
        }
        html += `<th>IP</th>`;
        html += `<th style="text-align:right">Actions</th>
                </tr>
            </thead>
            <tbody>`;

        const guestRowHtml = (g) => {
            const id = `${g.vmid}-${g.node}`;
            const targetNode = this.pendingMigrations[g.vmid];
            const migrationHint = targetNode
                ? ` <i class="bi bi-shuffle" style="color:var(--accent-purple);font-size:0.75em" title="Pending migration to ${Utils.escapeHtml(targetNode)}"></i>`
                : '';
            const osText = this.osLabel(g.ostype, g.type);
            const tagsHtml = g.tags
                ? g.tags.split(';').filter(Boolean).map(t =>
                    `<span class="badge vm-tag me-1">${Utils.escapeHtml(t.trim())}</span>`).join('')
                : '<span style="color:var(--text-muted)">-</span>';
            const selKey = `${g.vmid}:${g.node}`;
            const checked = this.selectedGuests.has(selKey) ? 'checked' : '';
            return `<tr class="vm-row ${checked ? 'row-selected' : ''}" style="cursor:pointer" onclick="Dashboard.openVmDetail(event,${g.vmid},'${Utils.escapeHtml(g.node)}','${Utils.escapeHtml(g.type)}')"
                onmouseenter="ResourceTooltip.showVm(this,${g.vmid},'${g.status}')" onmouseleave="ResourceTooltip.hide()">
                <td style="text-align:center" onclick="event.stopPropagation()"><input type="checkbox" class="form-check-input guest-cb" data-key="${selKey}" ${checked} onchange="Dashboard.toggleSelect(this)"></td>
                <td><strong style="color:var(--accent-blue)">${g.vmid}</strong></td>
                <td>${Utils.escapeHtml(g.name || '-')}</td>
                <td><i class="bi ${Utils.typeIcon(g.type)}" style="opacity:0.6"></i> ${Utils.typeLabel(g.type)}</td>
                <td style="color:var(--text-secondary)">${Utils.escapeHtml(g.node)}${migrationHint}</td>
                <td style="color:var(--text-secondary)">${Utils.escapeHtml(osText)}</td>
                <td><span class="badge ${Utils.statusBadgeClass(g.status)}"><i class="bi ${Utils.statusIcon(g.status)}"></i> ${g.status}</span></td>
                <td id="cell-cpu-${id}">${g.status === 'running' ? Utils.cpuPercent(g.cpu) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td id="cell-ram-${id}">${g.status === 'running' ? Utils.formatBytes(g.mem) + ' / ' + Utils.formatBytes(g.maxmem) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td id="cell-uptime-${id}">${g.status === 'running' ? Utils.formatUptime(g.uptime) : '<span style="color:var(--text-muted)">-</span>'}</td>
                <td>${tagsHtml}</td>
                <td><span id="guest-ip-${id}" class="text-muted small">${g.status === 'running' ? this._ipBadgeHtml(g.ips || [], id) : '-'}</span></td>
                <td style="text-align:right" onclick="event.stopPropagation()">${this.renderActions(g)}</td>
            </tr>`;
        };

        // Paginate
        const pag = Utils.paginate(guests, this._page, this._perPage);
        this._page = pag.page;
        const pageGuests = pag.items;

        if (this.groupBy === 'tags' || this.groupBy === 'os') {
            const groups = new Map();
            for (const g of pageGuests) {
                let keys;
                if (this.groupBy === 'tags') {
                    const tags = g.tags ? g.tags.split(';').map(t => t.trim()).filter(Boolean) : [];
                    keys = tags.length > 0 ? tags : ['__none__'];
                } else {
                    const label = this.osLabel(g.ostype, g.type);
                    keys = [label !== '-' ? label : '__none__'];
                }
                for (const key of keys) {
                    if (!groups.has(key)) groups.set(key, []);
                    groups.get(key).push(g);
                }
            }
            const sortedKeys = [...groups.keys()].sort((a, b) => {
                if (a === '__none__') return 1;
                if (b === '__none__') return -1;
                return a.localeCompare(b);
            });
            const noneLabel = this.groupBy === 'tags' ? 'Untagged' : 'Unknown';
            for (const key of sortedKeys) {
                const label = key === '__none__' ? noneLabel : key;
                const groupGuests = groups.get(key);
                html += `<tr style="background:var(--bg-secondary)">
                    <td colspan="13" style="padding:.35rem .75rem;border-bottom:1px solid var(--border-color)">
                        <span class="badge vm-tag me-2">${Utils.escapeHtml(label)}</span>
                        <span class="text-muted small">${groupGuests.length} guest${groupGuests.length !== 1 ? 's' : ''}</span>
                    </td>
                </tr>`;
                for (const g of groupGuests) {
                    html += guestRowHtml(g);
                }
            }
        } else {
            for (const g of pageGuests) {
                html += guestRowHtml(g);
            }
        }

        html += '</tbody></table>';
        html += Utils.paginationHtml(pag, 'Dashboard.setPage', 'Dashboard.setPerPage');
        container.innerHTML = html;

        // Restore bulk bar state after full re-render
        this._updateBulkBar();
    },

    _refreshIps() {
        if (this._ipLoading || !this.guests.length) return;
        const running = this.guests.filter(g => g.status === 'running');
        if (running.length === 0) return;
        this._ipLoading = true;
        const payload = running.map(g => ({ node: g.node, type: g.type, vmid: g.vmid }));
        API.postSilent('api/guest-ips-bulk.php', { guests: payload }).then(data => {
            for (const [key, ips] of Object.entries(data || {})) {
                this._renderIpCell(key, ips);
            }
        }).catch(() => {}).finally(() => {
            this._ipLoading = false;
        });
    },

    _ipBadgeHtml(ips, id) {
        if (ips.length === 0) return '-';
        const primary = ips[0];
        const copyBadge = (ip) => `<span class="badge ip-badge" title="Click to copy"
            onclick="event.stopPropagation();navigator.clipboard.writeText('${escapeHtml(ip)}').then(()=>Toast.success('${escapeHtml(ip)} copied'))"
        >${escapeHtml(ip)}</span>`;
        if (ips.length === 1) return copyBadge(primary);
        return `<div class="dropdown d-inline-block" onclick="event.stopPropagation()">
            <span class="badge ip-badge dropdown-toggle" data-bs-toggle="dropdown" role="button">${escapeHtml(primary)} <span class="text-muted" style="font-size:0.7em">+${ips.length - 1}</span></span>
            <ul class="dropdown-menu dropdown-menu-dark" style="min-width:auto">
                ${ips.map(ip => `<li><a class="dropdown-item small font-monospace" href="#" onclick="event.preventDefault();navigator.clipboard.writeText('${escapeHtml(ip)}').then(()=>Toast.success('${escapeHtml(ip)} copied'))">
                    <i class="bi bi-clipboard me-2" style="opacity:0.5"></i>${escapeHtml(ip)}
                </a></li>`).join('')}
            </ul>
        </div>`;
    },

    _renderIpCell(id, ips) {
        const el = document.getElementById(`guest-ip-${id}`);
        if (!el) return;
        el.innerHTML = this._ipBadgeHtml(ips, id);
    },

    async showInstallAgent(vmid, node, ostype) {
        const manualCmd = 'apt-get install -y qemu-guest-agent && systemctl enable --now qemu-guest-agent';
        const body = document.getElementById('install-agent-body');

        const renderIdle = (detectedIp) => {
            body.innerHTML = `
                <p class="text-muted small mb-3">Install the QEMU Guest Agent inside the VM to enable IP address detection and other features.</p>
                <div class="position-relative mb-3">
                    <pre class="log-viewer p-3" id="agent-cmd-pre" style="font-size:0.85rem;border-radius:var(--radius-sm)">${Utils.escapeHtml(manualCmd)}</pre>
                    <button id="agent-copy-btn" class="btn btn-sm btn-outline-light position-absolute top-0 end-0 m-2" title="Copy to clipboard">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </div>
                <div class="sidebar-divider mb-3"></div>
                <p class="small mb-2"><i class="bi bi-lightning-fill me-1" style="color:var(--accent-green)"></i><strong>Auto-Install</strong> — SSHs from the Proxmox node into the VM and runs the command automatically.</p>
                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text" style="background:var(--bg-secondary);border-color:var(--border-color);color:var(--text-muted)">VM IP</span>
                    <input type="text" id="agent-ip-input" class="form-control" placeholder="e.g. 192.168.1.100"
                        value="${Utils.escapeHtml(detectedIp || '')}"
                        style="background:var(--bg-secondary);border-color:var(--border-color);color:var(--text-primary)">
                    <button id="agent-auto-btn" class="btn btn-success">
                        <i class="bi bi-lightning-fill me-1"></i>Install
                    </button>
                </div>
                <p class="text-muted small mb-0"><i class="bi bi-info-circle me-1"></i>Requires key-based SSH access from the Proxmox node to the VM (root@IP).</p>
            `;
            body.querySelector('#agent-copy-btn').addEventListener('click', () => {
                navigator.clipboard.writeText(manualCmd).then(() => Toast.success('Copied!'));
            });
            body.querySelector('#agent-auto-btn').addEventListener('click', () => {
                const ip = body.querySelector('#agent-ip-input').value.trim();
                if (!ip) { Toast.error('Please enter the VM IP address'); return; }
                this._runAgentInstall(node, ip, body);
            });
        };

        const bsModal = new bootstrap.Modal(document.getElementById('installAgentModal'));
        bsModal.show();

        // Try to detect IP — works for static cloud-init IPs even without the agent
        renderIdle(null);
        try {
            const result = await API.getGuestIPs(node, 'qemu', vmid);
            const ips = (result.ips || []).filter(ip => ip);
            renderIdle(ips[0] || null);
        } catch (_) { /* leave field empty */ }
    },

    async _runAgentInstall(node, vmIp, body) {
        body.innerHTML = `
            <div class="mb-2 small text-muted"><i class="bi bi-terminal me-1"></i>Connecting to <strong>${Utils.escapeHtml(vmIp)}</strong> via ${Utils.escapeHtml(node)}...</div>
            <pre id="agent-log" class="log-viewer p-3" style="font-size:0.8rem;max-height:300px;overflow-y:auto;border-radius:var(--radius-sm)"></pre>
            <div id="agent-status" class="mt-2 small text-muted">Running...</div>
        `;

        const log = body.querySelector('#agent-log');
        const statusEl = body.querySelector('#agent-status');

        let token;
        try {
            const res = await API.startAgentInstall(node, vmIp);
            token = res.token;
        } catch (err) {
            statusEl.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Failed to start session: ${Utils.escapeHtml(err.message || '')}</span>`;
            return;
        }

        const es = new EventSource('api/terminal-output.php?token=' + encodeURIComponent(token));

        es.addEventListener('data', (e) => {
            const text = new TextDecoder().decode(Uint8Array.from(atob(e.data), c => c.charCodeAt(0)));
            log.textContent += text;
            log.scrollTop = log.scrollHeight;
        });

        es.addEventListener('done', (e) => {
            es.close();
            const result = JSON.parse(e.data);
            if (result.success) {
                statusEl.innerHTML = '<span style="color:var(--accent-green)"><i class="bi bi-check-circle-fill me-1"></i>Agent installed successfully! IP addresses will now be detected automatically.</span>';
                setTimeout(() => this.loadData(), 2000);
            } else {
                statusEl.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Installation failed (exit code ${result.exit_code}). Check the log above for details.</span>`;
            }
        });

        es.addEventListener('error', (e) => {
            es.close();
            let msg = 'Connection error';
            try { msg = JSON.parse(e.data).message; } catch (_) {}
            statusEl.innerHTML = `<span class="text-danger"><i class="bi bi-x-circle me-1"></i>${Utils.escapeHtml(msg)}</span>`;
        });

        es.onerror = () => {
            es.close();
            if (statusEl.textContent === 'Running...') {
                statusEl.innerHTML = '<span class="text-danger"><i class="bi bi-x-circle me-1"></i>Connection lost.</span>';
            }
        };
    },

    renderActions(guest) {
        let html = '';

        const running = guest.status === 'running';
        const dis = (cond) => cond ? '' : 'disabled style="opacity:0.35;pointer-events:none"';

        // Install Agent button — disabled for LXC or when not running
        const agentActive = guest.type === 'qemu' && running;
        html += `<button class="btn btn-outline-secondary btn-action me-1" title="Install QEMU Guest Agent" ${dis(agentActive)}
            onclick="event.stopPropagation();Dashboard.showInstallAgent(${guest.vmid},'${Utils.escapeHtml(guest.node)}','${Utils.escapeHtml(guest.ostype || '')}')">
            <i class="bi bi-cpu"></i>
        </button>`;

        // Migrate button — disabled when not running or no target nodes available
        if (Permissions.has('vm.migrate')) {
            const otherNodes = this.nodes.filter(n => n.node !== guest.node && n.status === 'online' && !this.maintenanceNodeNames?.has(n.node));
            if (running && otherNodes.length > 0) {
                const dropId = `migrate-drop-${guest.vmid}`;
                html += `<div class="btn-group me-1" style="position:relative">
                    <button class="btn btn-outline-light btn-action" title="Migrate to another node"
                        onclick="event.stopPropagation();Dashboard._toggleMigrateMenu(this, '${dropId}')">
                        <i class="bi bi-shuffle"></i>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-dark dropdown-menu-end" id="${dropId}" style="min-width:140px">
                        <li><span class="dropdown-item-text" style="font-size:0.75rem;color:var(--text-muted)">Migrate to...</span></li>
                        <li><hr class="dropdown-divider"></li>`;
                for (const n of otherNodes) {
                    html += `<li><a class="dropdown-item" href="#" onclick="event.preventDefault();event.stopPropagation();Dashboard._closeMigrateMenus();Dashboard.migrateGuest('${guest.node}','${guest.type}',${guest.vmid},'${n.node}',true,'${Utils.escapeHtml(guest.name || '')}')">
                        <i class="bi bi-hdd-rack" style="opacity:0.5"></i> ${Utils.escapeHtml(n.node)}
                    </a></li>`;
                }
                html += `</ul></div>`;
            } else {
                html += `<button class="btn btn-outline-light btn-action me-1" disabled style="opacity:0.35;pointer-events:none" title="Migrate to another node">
                    <i class="bi bi-shuffle"></i>
                </button>`;
            }
        }

        // Snapshot manager button
        if (Permissions.has('vm.snapshot')) {
            html += `<button class="btn btn-outline-info btn-action me-1" title="Snapshot Manager"
                onclick="event.stopPropagation();Dashboard.openSnapshotManager('${guest.node}','${guest.type}',${guest.vmid},'${Utils.escapeHtml(guest.name || '')}')">
                <i class="bi bi-camera"></i>
            </button>`;
        }

        // Power controls
        html += Controls.renderButtons(guest);
        return html;
    },

    toggleSelect(checkbox) {
        const key = checkbox.dataset.key;
        if (checkbox.checked) {
            this.selectedGuests.add(key);
            checkbox.closest('tr')?.classList.add('row-selected');
        } else {
            this.selectedGuests.delete(key);
            checkbox.closest('tr')?.classList.remove('row-selected');
        }
        this._updateBulkBar();
    },

    toggleSelectAll(checkbox) {
        const cbs = document.querySelectorAll('.guest-cb');
        if (checkbox.checked) {
            cbs.forEach(cb => {
                cb.checked = true;
                this.selectedGuests.add(cb.dataset.key);
                cb.closest('tr')?.classList.add('row-selected');
            });
        } else {
            cbs.forEach(cb => {
                cb.checked = false;
                this.selectedGuests.delete(cb.dataset.key);
                cb.closest('tr')?.classList.remove('row-selected');
            });
        }
        this._updateBulkBar();
    },

    clearSelection() {
        this.selectedGuests.clear();
        document.querySelectorAll('.guest-cb').forEach(cb => {
            cb.checked = false;
            cb.closest('tr')?.classList.remove('row-selected');
        });
        const selectAll = document.getElementById('select-all-cb');
        if (selectAll) selectAll.checked = false;
        this._updateBulkBar();
    },

    _updateBulkBar() {
        const bar = document.getElementById('bulk-action-bar');
        const count = document.getElementById('bulk-count');
        if (!bar) return;
        if (this.selectedGuests.size > 0) {
            bar.style.display = '';
            if (count) count.textContent = this.selectedGuests.size;
        } else {
            bar.style.display = 'none';
        }
        // Update select-all checkbox state
        const allCbs = document.querySelectorAll('.guest-cb');
        const selectAll = document.getElementById('select-all-cb');
        if (selectAll && allCbs.length > 0) {
            selectAll.checked = this.selectedGuests.size > 0 && this.selectedGuests.size >= allCbs.length;
            selectAll.indeterminate = this.selectedGuests.size > 0 && this.selectedGuests.size < allCbs.length;
        }
    },

    _getSelectedGuests() {
        return this.guests.filter(g => this.selectedGuests.has(`${g.vmid}:${g.node}`));
    },

    async bulkAction(action) {
        const selected = this._getSelectedGuests();
        if (selected.length === 0) {
            Toast.info('No VMs/CTs selected');
            return;
        }

        let targets;
        let actionLabel;

        switch (action) {
            case 'start':
                targets = selected.filter(g => g.status === 'stopped');
                actionLabel = 'start';
                if (targets.length === 0) { Toast.info('No stopped VMs/CTs in selection'); return; }
                break;
            case 'shutdown':
                targets = selected.filter(g => g.status === 'running');
                actionLabel = 'shut down';
                if (targets.length === 0) { Toast.info('No running VMs/CTs in selection'); return; }
                break;
            case 'stop':
                targets = selected.filter(g => g.status === 'running');
                actionLabel = 'force stop';
                if (targets.length === 0) { Toast.info('No running VMs/CTs in selection'); return; }
                break;
            case 'delete':
                targets = selected.filter(g => g.status === 'stopped');
                actionLabel = 'delete';
                if (targets.length === 0) { Toast.info('Only stopped VMs/CTs can be deleted'); return; }
                break;
            default:
                return;
        }

        const names = targets.slice(0, 5).map(g => g.name || g.vmid).join(', ') + (targets.length > 5 ? ` +${targets.length - 5} more` : '');
        if (!confirm(`${actionLabel.charAt(0).toUpperCase() + actionLabel.slice(1)} ${targets.length} guest${targets.length !== 1 ? 's' : ''}?\n\n${names}`)) return;

        if (action === 'delete') {
            // Double confirmation for bulk delete
            if (!confirm(`This will permanently delete ${targets.length} VM${targets.length !== 1 ? 's' : ''}/CT${targets.length !== 1 ? 's' : ''}. This cannot be undone!\n\nAre you sure?`)) return;
        }

        Toast.info(`${actionLabel.charAt(0).toUpperCase() + actionLabel.slice(1)}ing ${targets.length} guests...`);
        let ok = 0, fail = 0;

        for (const g of targets) {
            try {
                if (action === 'delete') {
                    await API.deleteGuest(g.node, g.type, g.vmid);
                } else {
                    await API.power(g.node, g.type, g.vmid, action);
                }
                ok++;
            } catch (_) {
                fail++;
            }
        }

        const msg = `${ok}/${targets.length} ${actionLabel} initiated` + (fail > 0 ? ` (${fail} failed)` : '');
        fail > 0 ? Toast.error(msg) : Toast.success(msg);
        this.clearSelection();
        setTimeout(() => this.loadData(), 3000);
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

    openVmDetail(event, vmid, node, type) {
        // Don't open if clicking inside action buttons
        if (event.target.closest('button, .dropdown-menu, a')) return;
        const guest = this.guests.find(g => g.vmid == vmid && g.node === node);
        if (!guest) return;

        const modal = document.getElementById('vmDetailModal');
        const body = document.getElementById('vm-detail-body');
        const title = document.getElementById('vm-detail-title');

        title.innerHTML = `<i class="bi ${Utils.typeIcon(type)} me-2"></i>${Utils.escapeHtml(guest.name || String(vmid))} <small class="text-muted fs-6">(${vmid})</small>`;

        const cpuPct = guest.status === 'running' ? Utils.cpuPercent(guest.cpu) : '-';
        const memUsed = guest.status === 'running' ? Utils.formatBytes(guest.mem) : '-';
        const memMax = Utils.formatBytes(guest.maxmem);
        const diskUsed = guest.maxdisk > 0 ? Utils.formatBytes(guest.disk || 0) : '-';
        const diskMax = guest.maxdisk > 0 ? Utils.formatBytes(guest.maxdisk) : '-';
        const memPct = guest.maxmem > 0 ? Math.round((guest.mem / guest.maxmem) * 100) : 0;
        const diskPct = guest.maxdisk > 0 ? Math.round(((guest.disk || 0) / guest.maxdisk) * 100) : 0;

        body.innerHTML = `
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <div class="text-muted small mb-1">Status</div>
                    <span class="badge ${Utils.statusBadgeClass(guest.status)}"><i class="bi ${Utils.statusIcon(guest.status)}"></i> ${guest.status}</span>
                </div>
                <div class="col-6">
                    <div class="text-muted small mb-1">Node</div>
                    <strong>${Utils.escapeHtml(guest.node)}</strong>
                </div>
                <div class="col-6">
                    <div class="text-muted small mb-1">Type</div>
                    <span><i class="bi ${Utils.typeIcon(type)}" style="opacity:0.7"></i> ${Utils.typeLabel(type)}</span>
                </div>
                <div class="col-6">
                    <div class="text-muted small mb-1">OS</div>
                    <span>${Utils.escapeHtml(Dashboard.osLabel(guest.ostype, type))}</span>
                </div>
                ${guest.status === 'running' ? `
                <div class="col-6">
                    <div class="text-muted small mb-1">IP Address</div>
                    <span id="vm-detail-ip" class="text-muted small">Loading...</span>
                </div>
                ` : ''}
                ${guest.status === 'running' ? `
                <div class="col-6">
                    <div class="text-muted small mb-1">Uptime</div>
                    <span>${Utils.formatUptime(guest.uptime || 0)}</span>
                </div>
                <div class="col-6">
                    <div class="text-muted small mb-1">CPU Usage</div>
                    <span>${cpuPct}</span>
                </div>
                ` : ''}
            </div>

            ${guest.maxmem > 0 ? `
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <small class="text-muted">RAM</small>
                    <small>${memUsed} / ${memMax}</small>
                </div>
                <div class="resource-bar"><div class="progress"><div class="progress-bar ${memPct >= 90 ? 'level-danger' : memPct >= 70 ? 'level-warn' : 'level-ok'}" style="width:${memPct}%"></div></div></div>
            </div>` : ''}

            ${guest.maxdisk > 0 ? `
            <div class="mb-3">
                <div class="d-flex justify-content-between mb-1">
                    <small class="text-muted">Disk</small>
                    <small>${diskUsed} / ${diskMax}</small>
                </div>
                <div class="resource-bar"><div class="progress"><div class="progress-bar ${diskPct >= 90 ? 'level-danger' : diskPct >= 70 ? 'level-warn' : 'level-ok'}" style="width:${diskPct}%"></div></div></div>
            </div>` : ''}

            <div class="mt-3 d-flex gap-2 justify-content-end">
                ${this.renderActions(guest)}
            </div>

            <div id="vm-detail-config" class="mt-4">
                <div class="text-muted small text-center"><i class="bi bi-arrow-repeat me-1"></i>Loading config...</div>
            </div>
        `;

        const bsModal = new bootstrap.Modal(modal);
        bsModal.show();

        // Load IP async (running guests only)
        if (guest.status === 'running') {
            API.getGuestIPs(node, type, vmid).then(result => {
                const el = document.getElementById('vm-detail-ip');
                if (!el) return;
                const ips = (result.ips || []).filter(ip => ip);
                el.textContent = ips.length > 0 ? ips.join(', ') : '-';
                el.classList.remove('text-muted');
            }).catch(() => {
                const el = document.getElementById('vm-detail-ip');
                if (el) el.textContent = '-';
            });
        }

        // Load config async
        API.getGuestConfig(node, type, vmid).then(cfg => {
            const el = document.getElementById('vm-detail-config');
            if (!el) return;

            const tagsHtml = cfg.tags
                ? cfg.tags.split(';').filter(Boolean).map(t => `<span class="badge vm-tag me-1">${Utils.escapeHtml(t.trim())}</span>`).join('')
                : null;

            const rows = [
                { key: 'Cores',       val: cfg.cores ?? cfg.cpulimit ?? null,                           wide: false },
                { key: 'Memory',      val: cfg.memory ? Utils.formatBytes(cfg.memory * 1024 * 1024) : null, wide: false },
                { key: 'Network',     val: cfg.net0 ? Utils.escapeHtml(cfg.net0.split(',')[0]) : null,  wide: false },
                { key: 'Tags',        val: tagsHtml,                                                     wide: false },
                { key: 'Boot disk',   val: cfg.scsi0 || cfg.virtio0 || cfg.ide2 || cfg.rootfs ? Utils.escapeHtml(cfg.scsi0 || cfg.virtio0 || cfg.ide2 || cfg.rootfs) : null, wide: true },
                { key: 'Description', val: cfg.description ? Utils.escapeHtml(cfg.description.substring(0, 200)) : null, wide: true },
            ].filter(r => r.val !== null && r.val !== '');

            el.innerHTML = rows.length > 0 ? `
                <div class="sidebar-divider mb-3"></div>
                <div class="text-muted small mb-2 text-uppercase" style="letter-spacing:.06em">Configuration</div>
                <div class="row g-2">
                    ${rows.map(({ key, val, wide }) => `
                        <div class="${wide ? 'col-12' : 'col-6'}">
                            <div class="text-muted small">${key}</div>
                            <div style="font-size:.9rem;word-break:break-word">${val}</div>
                        </div>
                    `).join('')}
                </div>
            ` : '';
        }).catch(() => {
            const el = document.getElementById('vm-detail-config');
            if (el) el.innerHTML = '';
        });
    },

    _toggleMigrateMenu(btn, menuId) {
        const menu = document.getElementById(menuId);
        if (!menu) return;
        const isOpen = menu.classList.contains('show');
        this._closeMigrateMenus();
        if (!isOpen) {
            // Move menu to body so it's never clipped by overflow containers
            menu._originalParent = menu.parentElement;
            document.body.appendChild(menu);
            menu.style.position = 'fixed';
            menu.classList.add('show');

            const btnRect = btn.getBoundingClientRect();
            const menuHeight = menu.offsetHeight;
            const menuWidth = menu.offsetWidth;
            const viewH = window.innerHeight;

            // Horizontal: align right edge to button right edge
            let left = btnRect.right - menuWidth;
            if (left < 4) left = 4;
            menu.style.left = left + 'px';

            // Vertical: open downward, but flip up if not enough space below
            if (btnRect.bottom + menuHeight + 4 > viewH) {
                menu.style.top = (btnRect.top - menuHeight) + 'px';
            } else {
                menu.style.top = btnRect.bottom + 'px';
            }

            menu.style.zIndex = '1080';

            const close = (e) => {
                if (!menu.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
                    this._closeMigrateMenus();
                    document.removeEventListener('click', close, true);
                    window.removeEventListener('scroll', close, true);
                }
            };
            setTimeout(() => {
                document.addEventListener('click', close, true);
                window.addEventListener('scroll', close, true);
            }, 0);
        }
    },

    _closeMigrateMenus() {
        document.querySelectorAll('body > .dropdown-menu.show').forEach(m => {
            m.classList.remove('show');
            m.style.position = '';
            m.style.left = '';
            m.style.top = '';
            m.style.zIndex = '';
            // Move back to original parent
            if (m._originalParent) {
                m._originalParent.appendChild(m);
                m._originalParent = null;
            }
        });
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

    // --- Snapshot Manager ---

    _snapCtx: null, // { node, type, vmid, name }

    async openSnapshotManager(node, type, vmid, name) {
        this._snapCtx = { node, type, vmid, name };
        const title = `Snapshots — ${name || vmid}`;

        let modal = document.getElementById('snapshot-modal');
        if (!modal) {
            document.body.insertAdjacentHTML('beforeend', `
                <div class="modal fade" id="snapshot-modal" tabindex="-1">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content bg-dark text-light">
                            <div class="modal-header border-secondary">
                                <h5 class="modal-title" id="snapshot-modal-title"></h5>
                                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                            </div>
                            <div class="modal-body" id="snapshot-modal-body"></div>
                        </div>
                    </div>
                </div>
            `);
            modal = document.getElementById('snapshot-modal');
        }

        document.getElementById('snapshot-modal-title').innerHTML = `<i class="bi bi-camera me-2"></i>${Utils.escapeHtml(title)}`;
        document.getElementById('snapshot-modal-body').innerHTML = `
            <div class="text-center py-4">
                <div class="spinner-border spinner-border-sm" role="status"></div>
                <span class="ms-2 text-muted">Loading snapshots...</span>
            </div>`;

        const bsModal = bootstrap.Modal.getOrCreateInstance(modal);
        bsModal.show();

        await this._loadSnapshots();
    },

    async _loadSnapshots() {
        const ctx = this._snapCtx;
        if (!ctx) return;

        const body = document.getElementById('snapshot-modal-body');
        try {
            const snapshots = await API.getSnapshots(ctx.node, ctx.type, ctx.vmid);
            this._renderSnapshots(snapshots, body);
        } catch (err) {
            body.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${Utils.escapeHtml(err.message)}</div>`;
        }
    },

    _renderSnapshots(snapshots, body) {
        const ctx = this._snapCtx;
        const esc = Utils.escapeHtml;

        // Filter out 'current' and build tree
        const snaps = snapshots.filter(s => s.name !== 'current');
        const hasSnaps = snaps.length > 0;

        // Build parent-child map
        const childMap = {};
        for (const s of snaps) {
            const parent = s.parent || '__root__';
            if (!childMap[parent]) childMap[parent] = [];
            childMap[parent].push(s);
        }

        // Sort children by snaptime
        for (const key of Object.keys(childMap)) {
            childMap[key].sort((a, b) => (a.snaptime || 0) - (b.snaptime || 0));
        }

        // Create snapshot form
        let html = `
            <div class="mb-3 p-3" style="background:var(--card-bg);border-radius:8px;border:1px solid var(--border-color)">
                <div class="d-flex gap-2 align-items-end flex-wrap">
                    <div class="flex-grow-1">
                        <label class="form-label small mb-1">Snapshot Name</label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="snap-name"
                            placeholder="e.g. before-update" pattern="[a-zA-Z0-9_\\-]+" required>
                    </div>
                    <div class="flex-grow-1">
                        <label class="form-label small mb-1">Description (optional)</label>
                        <input type="text" class="form-control form-control-sm bg-dark text-light border-secondary" id="snap-desc" placeholder="Optional description">
                    </div>
                    <div>
                        <button class="btn btn-primary btn-sm" onclick="Dashboard._createSnapshot()">
                            <i class="bi bi-camera me-1"></i>Create
                        </button>
                    </div>
                </div>
            </div>
        `;

        if (!hasSnaps) {
            html += `
                <div class="text-center py-4" style="color:var(--text-muted)">
                    <i class="bi bi-camera" style="font-size:2rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No snapshots</p>
                </div>`;
        } else {
            html += `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">${snaps.length} snapshot${snaps.length !== 1 ? 's' : ''}</span>
                    <button class="btn btn-outline-danger btn-sm" onclick="Dashboard._deleteAllSnapshots()">
                        <i class="bi bi-trash me-1"></i>Delete All
                    </button>
                </div>
                <div class="snap-tree">
            `;

            // Render tree recursively starting from roots
            const roots = childMap['__root__'] || [];
            // Also collect orphans whose parent doesn't exist in our list
            const snapNames = new Set(snaps.map(s => s.name));
            for (const s of snaps) {
                if (s.parent && s.parent !== 'current' && !snapNames.has(s.parent)) {
                    if (!childMap['__root__']) childMap['__root__'] = [];
                    if (!roots.includes(s)) roots.push(s);
                }
            }

            const renderNode = (snap, depth) => {
                const date = snap.snaptime ? new Date(snap.snaptime * 1000).toLocaleString() : '';
                const desc = snap.description || '';
                const indent = depth * 24;
                html += `
                    <div class="snap-node d-flex align-items-center gap-2 py-2 px-2" style="margin-left:${indent}px;border-left:${depth > 0 ? '2px solid var(--border-color)' : 'none'};border-radius:4px">
                        <i class="bi bi-camera-fill" style="color:var(--accent-blue);opacity:0.7"></i>
                        <div class="flex-grow-1">
                            <strong>${esc(snap.name)}</strong>
                            ${desc ? `<span class="text-muted small ms-2">${esc(desc)}</span>` : ''}
                            ${date ? `<br><span class="text-muted small"><i class="bi bi-clock me-1"></i>${esc(date)}</span>` : ''}
                        </div>
                        <button class="btn btn-outline-danger btn-sm" title="Delete snapshot" onclick="Dashboard._deleteSnapshot('${esc(snap.name)}')">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>`;

                const children = childMap[snap.name] || [];
                for (const child of children) {
                    renderNode(child, depth + 1);
                }
            };

            for (const root of roots) {
                renderNode(root, 0);
            }

            html += `</div>`;
        }

        body.innerHTML = html;
    },

    async _createSnapshot() {
        const ctx = this._snapCtx;
        if (!ctx) return;

        const nameInput = document.getElementById('snap-name');
        const descInput = document.getElementById('snap-desc');
        const snapname = nameInput.value.trim();
        const description = descInput.value.trim();

        if (!snapname) {
            Toast.warning('Please enter a snapshot name');
            nameInput.focus();
            return;
        }
        if (!/^[a-zA-Z0-9_\-]+$/.test(snapname)) {
            Toast.warning('Snapshot name may only contain letters, numbers, dashes and underscores');
            nameInput.focus();
            return;
        }

        const body = document.getElementById('snapshot-modal-body');
        body.innerHTML = `<div class="text-center py-4"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2 text-muted">Creating snapshot...</span></div>`;

        try {
            await API.createSnapshot(ctx.node, ctx.type, ctx.vmid, snapname, description);
            Toast.success(`Snapshot "${snapname}" created`);
            await this._loadSnapshots();
        } catch (err) {
            await this._loadSnapshots();
        }
    },

    async _deleteSnapshot(snapname) {
        const ctx = this._snapCtx;
        if (!ctx) return;

        if (!confirm(`Delete snapshot "${snapname}"?`)) return;

        const body = document.getElementById('snapshot-modal-body');
        body.innerHTML = `<div class="text-center py-4"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2 text-muted">Deleting snapshot...</span></div>`;

        try {
            await API.deleteSnapshot(ctx.node, ctx.type, ctx.vmid, snapname);
            Toast.success(`Snapshot "${snapname}" deleted`);
            await this._loadSnapshots();
        } catch (err) {
            await this._loadSnapshots();
        }
    },

    async _deleteAllSnapshots() {
        const ctx = this._snapCtx;
        if (!ctx) return;

        if (!confirm(`Delete ALL snapshots for ${ctx.name || ctx.vmid}? This cannot be undone.`)) return;

        const body = document.getElementById('snapshot-modal-body');
        body.innerHTML = `<div class="text-center py-4"><div class="spinner-border spinner-border-sm" role="status"></div><span class="ms-2 text-muted">Deleting all snapshots...</span></div>`;

        try {
            const result = await API.deleteAllSnapshots(ctx.node, ctx.type, ctx.vmid);
            if (result.deleted > 0) {
                Toast.success(`${result.deleted} snapshot(s) deleted`);
            }
            if (result.errors?.length) {
                for (const e of result.errors) {
                    Toast.error(e);
                }
            }
            await this._loadSnapshots();
        } catch (err) {
            await this._loadSnapshots();
        }
    },
};
