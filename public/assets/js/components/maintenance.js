const Maintenance = {
    refreshInterval: null,
    nodes: [],
    maintenanceStates: {},
    nodeGuests: {},
    fastPolling: false,

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
        const interval = this.fastPolling ? 5000 : 15000;
        this.refreshInterval = setInterval(() => this.loadData(), interval);
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
                <h2><i class="bi bi-wrench-adjustable"></i> Maintenance Mode</h2>
            </div>
            <p class="text-muted mb-4">Put nodes into maintenance mode. All running VMs/CTs will be automatically migrated to other nodes.</p>
            <div id="maintenance-nodes" class="row g-3"></div>
        `;
    },

    async loadData() {
        try {
            const [healthData, maintData, guestsData] = await Promise.all([
                API.getClusterHealth(),
                API.getMaintenanceList(),
                API.getGuests(),
            ]);

            this.nodes = (healthData.nodes || []).sort((a, b) => a.node.localeCompare(b.node));

            // Group running guests by node
            this.nodeGuests = {};
            for (const g of (Array.isArray(guestsData) ? guestsData : [])) {
                if (g.status === 'running') {
                    if (!this.nodeGuests[g.node]) this.nodeGuests[g.node] = [];
                    this.nodeGuests[g.node].push(g);
                }
            }

            this.maintenanceStates = {};
            for (const m of (Array.isArray(maintData) ? maintData : [])) {
                this.maintenanceStates[m.node_name] = m;
            }

            // Check if we need fast polling (during entering or leaving)
            const hasActive = Object.values(this.maintenanceStates).some(s => s.status === 'entering' || s.status === 'leaving');
            if (hasActive && !this.fastPolling) {
                this.fastPolling = true;
                this.startAutoRefresh();
            } else if (!hasActive && this.fastPolling) {
                this.fastPolling = false;
                this.startAutoRefresh();
            }

            // Load detailed migration status for entering/leaving nodes
            for (const [nodeName, state] of Object.entries(this.maintenanceStates)) {
                if (state.status === 'entering' || state.status === 'leaving') {
                    try {
                        const detail = await API.getMaintenanceNodeStatus(nodeName);
                        if (detail.status === 'done') {
                            delete this.maintenanceStates[nodeName];
                        } else {
                            this.maintenanceStates[nodeName] = detail;
                        }
                    } catch (e) {}
                }
            }

            this.updateView();
        } catch (err) {
            Toast.error('Failed to load maintenance data');
        }
    },

    updateView() {
        const container = document.getElementById('maintenance-nodes');
        if (!container) return;

        this.nodes.sort((a, b) => (a.node || '').localeCompare(b.node || ''));
        container.innerHTML = this.nodes.map(node => {
            const maint = this.maintenanceStates[node.node];
            const isOnline = node.status === 'online';

            return `
                <div class="col-md-6 col-xl-4">
                    <div class="node-card ${maint ? 'maintenance' : ''} ${!isOnline ? 'offline' : ''}">
                        <div class="d-flex justify-content-between align-items-center mb-3">
                            <h5 class="mb-0"><i class="bi bi-hdd-rack me-2"></i>${escapeHtml(node.node)}</h5>
                            <div>
                                ${isOnline
                                    ? '<span class="badge badge-online">Online</span>'
                                    : '<span class="badge badge-offline">Offline</span>'}
                                ${maint
                                    ? `<span class="badge badge-maintenance ms-1">${this.maintStatusLabel(maint.status)}</span>`
                                    : ''}
                            </div>
                        </div>

                        ${this.renderNodeContent(node.node, maint)}

                        <div class="mt-3">
                            ${!maint && isOnline ? `
                                <button class="btn btn-warning btn-sm w-100" onclick="Maintenance.enterMaintenance('${escapeHtml(node.node)}')">
                                    <i class="bi bi-wrench me-1"></i>Enter Maintenance
                                </button>
                            ` : ''}
                            ${maint && maint.status === 'maintenance' ? `
                                <button class="btn btn-success btn-sm w-100" onclick="Maintenance.leaveMaintenance('${escapeHtml(node.node)}')">
                                    <i class="bi bi-check-circle me-1"></i>Exit Maintenance
                                </button>
                            ` : ''}
                            ${maint && maint.status === 'entering' ? `
                                <button class="btn btn-outline-light btn-sm w-100" disabled>
                                    <span class="spinner-border spinner-border-sm me-1"></span>Migration in progress...
                                </button>
                            ` : ''}
                            ${maint && maint.status === 'leaving' ? `
                                <button class="btn btn-outline-light btn-sm w-100" disabled>
                                    <span class="spinner-border spinner-border-sm me-1"></span>VMs being migrated back...
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    },

    renderNodeContent(nodeName, maint) {
        // During migration (entering or leaving): show migration progress
        if (maint && maint.migrations && maint.migrations.length > 0) {
            const isLeaving = maint.status === 'leaving';
            return `
                ${isLeaving ? '<div class="text-muted small mb-2"><i class="bi bi-arrow-left-right me-1"></i>Back-migration:</div>' : ''}
                <div class="migration-list">
                    ${maint.migrations.map(m => {
                        let icon, statusClass;
                        switch (m.status) {
                            case 'completed':
                                icon = '<i class="bi bi-check-circle-fill text-success"></i>';
                                statusClass = 'text-success';
                                break;
                            case 'error':
                                icon = '<i class="bi bi-x-circle-fill text-danger"></i>';
                                statusClass = 'text-danger';
                                break;
                            default:
                                icon = '<span class="spinner-border spinner-border-sm text-warning"></span>';
                                statusClass = 'text-warning';
                        }

                        return `
                            <div class="migration-item d-flex align-items-center gap-2 py-2 border-bottom" style="border-color: var(--border-color) !important;">
                                ${icon}
                                <div class="flex-grow-1">
                                    <strong>${escapeHtml(m.name)}</strong>
                                    <small class="text-muted ms-1">(${m.type} ${m.vmid})</small>
                                </div>
                                <small class="${statusClass}">\u2192 ${escapeHtml(m.target)}</small>
                            </div>
                        `;
                    }).join('')}
                </div>
            `;
        }

        // In maintenance mode with no migrations
        if (maint && maint.status === 'maintenance') {
            return '<div class="text-center text-muted py-2"><i class="bi bi-wrench me-1"></i>Maintenance mode active</div>';
        }

        // Normal mode: show running guests on this node
        const guests = this.nodeGuests[nodeName] || [];
        if (guests.length === 0) {
            return '<div class="text-center text-muted py-2">No running VMs/CTs on this node</div>';
        }

        const vms = guests.filter(g => g.type === 'qemu');
        const cts = guests.filter(g => g.type === 'lxc');
        const parts = [];
        if (vms.length > 0) parts.push(`${vms.length} VM${vms.length > 1 ? 's' : ''}`);
        if (cts.length > 0) parts.push(`${cts.length} CT${cts.length > 1 ? 's' : ''}`);

        return `
            <div class="guest-list">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi bi-hdd-stack text-muted"></i>
                    <span class="text-muted">${parts.join(', ')} running</span>
                </div>
                ${guests.slice(0, 8).map(g => `
                    <div class="d-flex align-items-center gap-2 py-1">
                        <i class="bi ${g.type === 'qemu' ? 'bi-pc-display' : 'bi-box'} text-muted"></i>
                        <span>${escapeHtml(g.name || `${g.type} ${g.vmid}`)}</span>
                        <small class="text-muted ms-auto">${g.vmid}</small>
                    </div>
                `).join('')}
                ${guests.length > 8 ? `<div class="text-muted small mt-1">+ ${guests.length - 8} more</div>` : ''}
            </div>
        `;
    },

    maintStatusLabel(status) {
        switch (status) {
            case 'entering': return 'Migrating...';
            case 'maintenance': return 'Maintenance';
            case 'leaving': return 'Leaving...';
            default: return status;
        }
    },

    async enterMaintenance(nodeName) {
        if (!confirm(`Put node "${nodeName}" into maintenance mode?\n\nAll running VMs/CTs will be migrated to other nodes.`)) {
            return;
        }

        try {
            await API.post('api/maintenance.php', { node: nodeName });
            Toast.info(`Initiating maintenance mode for ${nodeName}...`);
            this.fastPolling = true;
            this.startAutoRefresh();
            await this.loadData();
        } catch (err) {
            Toast.error('Failed to initiate maintenance mode');
        }
    },

    async leaveMaintenance(nodeName) {
        if (!confirm(`Exit maintenance mode for "${nodeName}"?\n\nPreviously migrated VMs/CTs will be automatically migrated back.`)) return;

        try {
            const resp = await fetch('api/maintenance.php', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': API.csrfToken,
                },
                body: JSON.stringify({ node: nodeName }),
            });
            const result = await resp.json();

            if (result.success) {
                if (result.data?.status === 'leaving') {
                    Toast.info(`VMs being migrated back to ${nodeName}...`);
                    this.fastPolling = true;
                    this.startAutoRefresh();
                } else {
                    Toast.success(`Maintenance mode ended for ${nodeName}.`);
                }
                await this.loadData();
            } else {
                Toast.error(result.message || 'Error');
            }
        } catch (err) {
            Toast.error('Failed to exit maintenance mode');
        }
    },
};
