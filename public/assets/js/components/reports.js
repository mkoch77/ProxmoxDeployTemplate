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

            <div class="row g-3 mb-4">
                ${this._reportCard(
                    'vm-inventory',
                    'bi-hdd-rack',
                    'VM / CT Inventory',
                    'Overview of all VMs and containers including CPU, RAM, disk, OS, and IP addresses.'
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
            if (reportId === 'vm-inventory') {
                await this._generateVmInventory(output);
            }
        } catch (err) {
            output.innerHTML = `<div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i>${Utils.escapeHtml(err.message)}</div>`;
        } finally {
            this._loading = false;
        }
    },

    async _generateVmInventory(output) {
        const data = await API.get('api/reports.php', { report: 'vm-inventory' });
        const rows = data.rows || [];
        this._data = { id: 'vm-inventory', rows };

        if (rows.length === 0) {
            output.innerHTML = `
                <div class="text-center py-5" style="color:var(--text-muted)">
                    <i class="bi bi-inbox" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No VMs or CTs found</p>
                </div>
            `;
            return;
        }

        const fmtBytes = Utils.formatBytes;
        const esc = Utils.escapeHtml;

        let html = `
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0"><i class="bi bi-hdd-rack me-2"></i>VM / CT Inventory <span class="badge bg-secondary">${rows.length}</span></h5>
                <button class="btn btn-success btn-sm" onclick="Reports.exportExcel()">
                    <i class="bi bi-file-earmark-arrow-down me-1"></i>Export Excel
                </button>
            </div>
            <div class="guest-table">
                <table class="table table-dark table-hover mb-0">
                    <thead>
                        <tr>
                            <th>VMID</th>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Node</th>
                            <th>Status</th>
                            <th>CPUs</th>
                            <th>RAM</th>
                            <th>Disk (max)</th>
                            <th>Disk (used)</th>
                            <th>OS</th>
                            <th>IP</th>
                            <th>Tags</th>
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
                <td style="color:var(--text-secondary)">${esc(r.ostype || '-')}</td>
                <td><code class="small">${esc(r.primary_ip || '-')}</code></td>
                <td>${r.tags ? r.tags.split(';').filter(Boolean).map(t => `<span class="badge vm-tag me-1">${esc(t.trim())}</span>`).join('') : '<span style="color:var(--text-muted)">-</span>'}</td>
            </tr>`;
        }

        html += '</tbody></table></div>';
        output.innerHTML = html;
    },

    exportExcel() {
        if (!this._data || !this._data.rows.length) {
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

        if (id === 'vm-inventory') {
            const wsData = [
                ['VMID', 'Name', 'Type', 'Node', 'Status', 'CPUs', 'RAM (MB)', 'Disk Max (GB)', 'Disk Used (GB)', 'OS', 'IP', 'Tags']
            ];
            for (const r of rows) {
                wsData.push([
                    r.vmid,
                    r.name || '',
                    r.type === 'qemu' ? 'VM' : 'CT',
                    r.node,
                    r.status,
                    r.cpus,
                    fmtMB(r.ram_bytes),
                    fmtGB(r.disk_max_bytes),
                    fmtGB(r.disk_used_bytes),
                    r.ostype || '',
                    r.primary_ip || '',
                    r.tags ? r.tags.replace(/;/g, ', ') : '',
                ]);
            }

            const wb = XLSX.utils.book_new();
            const ws = XLSX.utils.aoa_to_sheet(wsData);

            // Column widths
            ws['!cols'] = [
                { wch: 6 }, { wch: 25 }, { wch: 5 }, { wch: 12 }, { wch: 9 },
                { wch: 5 }, { wch: 10 }, { wch: 13 }, { wch: 13 }, { wch: 12 },
                { wch: 16 }, { wch: 20 },
            ];

            XLSX.utils.book_append_sheet(wb, ws, 'VM Inventory');

            const date = new Date().toISOString().slice(0, 10);
            XLSX.writeFile(wb, `VM_Inventory_${date}.xlsx`);
            Toast.success(`Exported ${rows.length} entries`);
        }
    },
};
