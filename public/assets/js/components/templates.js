const Templates = {
    templates: [],
    nodes: [],
    currentFilter: { type: '', node: '', search: '' },
    currentSort: { col: 'vmid', dir: 'asc' },

    async init() {
        this.render();
        await this.loadData();
    },

    async loadData() {
        try {
            const [templates, nodes] = await Promise.all([
                API.getTemplates(),
                API.getNodes(),
            ]);
            this.templates = templates;
            this.nodes = nodes;
            this.updateView();
        } catch (err) {
            // Error shown by API
        }
    },

    render() {
        const content = document.getElementById('page-content');
        content.innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-rocket-takeoff-fill"></i>Deploy</h2>
                <button class="btn btn-outline-light btn-sm" onclick="Templates.loadData()">
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>
            <p style="color:var(--text-muted)" class="mb-3">Select a template and deploy a new VM/CT.</p>

            <div class="filter-bar d-flex flex-wrap gap-2 align-items-center">
                <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-light active" data-tpl-filter-type="">All</button>
                    <button class="btn btn-outline-light" data-tpl-filter-type="qemu">VMs</button>
                    <button class="btn btn-outline-light" data-tpl-filter-type="lxc">CTs</button>
                </div>
                <select id="tpl-filter-node" class="form-select form-select-sm" style="width:auto;">
                    <option value="">All Nodes</option>
                </select>
                <input id="tpl-filter-search" type="text" class="form-control form-control-sm" placeholder="Search by name or ID..." style="width:220px;">
            </div>

            <div id="templates-table-container" class="guest-table mt-3">
                <div class="loading-spinner"><div class="spinner-border text-primary"></div></div>
            </div>`;

        // Filter events
        content.querySelectorAll('[data-tpl-filter-type]').forEach(btn => {
            btn.addEventListener('click', () => {
                content.querySelectorAll('[data-tpl-filter-type]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.currentFilter.type = btn.dataset.tplFilterType;
                this.updateView();
            });
        });

        document.getElementById('tpl-filter-node').addEventListener('change', (e) => {
            this.currentFilter.node = e.target.value;
            this.updateView();
        });

        document.getElementById('tpl-filter-search').addEventListener('input', Utils.debounce((e) => {
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

    sortIcon(col) {
        if (this.currentSort.col !== col) return '<i class="bi bi-chevron-expand" style="opacity:0.3;font-size:0.7em"></i>';
        return this.currentSort.dir === 'asc'
            ? '<i class="bi bi-chevron-up" style="font-size:0.7em"></i>'
            : '<i class="bi bi-chevron-down" style="font-size:0.7em"></i>';
    },

    sortTemplates(list) {
        const { col, dir } = this.currentSort;
        const mult = dir === 'asc' ? 1 : -1;

        return list.sort((a, b) => {
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
                case 'disk':
                    return ((a.maxdisk || 0) - (b.maxdisk || 0)) * mult;
                default:
                    return 0;
            }
        });
    },

    updateView() {
        // Update node filter dropdown
        const nodeSelect = document.getElementById('tpl-filter-node');
        const currentVal = nodeSelect.value;
        nodeSelect.innerHTML = '<option value="">All Nodes</option>';
        this.nodes.forEach(n => {
            nodeSelect.innerHTML += `<option value="${n.node}">${n.node}</option>`;
        });
        nodeSelect.value = currentVal;

        // Filter
        let filtered = [...this.templates];
        if (this.currentFilter.type) {
            filtered = filtered.filter(t => t.type === this.currentFilter.type);
        }
        if (this.currentFilter.node) {
            filtered = filtered.filter(t => t.node === this.currentFilter.node);
        }
        if (this.currentFilter.search) {
            filtered = filtered.filter(t =>
                (t.name || '').toLowerCase().includes(this.currentFilter.search) ||
                String(t.vmid).includes(this.currentFilter.search)
            );
        }

        // Sort
        filtered = this.sortTemplates(filtered);

        this.renderTable(filtered);
    },

    renderTable(templates) {
        const container = document.getElementById('templates-table-container');

        if (templates.length === 0) {
            container.innerHTML = `
                <div class="text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-layers" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No templates found</p>
                </div>`;
            return;
        }

        const cols = [
            { key: 'vmid', label: 'ID' },
            { key: 'name', label: 'Name' },
            { key: 'type', label: 'Type' },
            { key: 'node', label: 'Node' },
            { key: 'disk', label: 'Disk' },
        ];

        let html = `<table class="table table-dark table-hover mb-0">
            <thead>
                <tr>`;

        for (const c of cols) {
            html += `<th class="sortable-th" onclick="Templates.setSort('${c.key}')" style="cursor:pointer;user-select:none">${c.label} ${this.sortIcon(c.key)}</th>`;
        }
        html += `<th style="text-align:right">Action</th>
                </tr>
            </thead>
            <tbody>`;

        for (const t of templates) {
            html += `<tr>
                <td><strong style="color:var(--accent-blue)">${t.vmid}</strong></td>
                <td>${Utils.escapeHtml(t.name || 'Unnamed')}</td>
                <td><i class="bi ${Utils.typeIcon(t.type)}" style="opacity:0.6"></i> ${Utils.typeLabel(t.type)}</td>
                <td style="color:var(--text-secondary)">${Utils.escapeHtml(t.node)}</td>
                <td style="color:var(--text-secondary)">${t.maxdisk ? Utils.formatBytes(t.maxdisk) : '-'}</td>
                <td style="text-align:right">
                    <button class="btn btn-primary btn-sm btn-action"
                        onclick="Deploy.open(${JSON.stringify(t).replace(/"/g, '&quot;')})">
                        <i class="bi bi-rocket-takeoff-fill"></i> Deploy
                    </button>
                </td>
            </tr>`;
        }

        html += '</tbody></table>';
        container.innerHTML = html;
    }
};
