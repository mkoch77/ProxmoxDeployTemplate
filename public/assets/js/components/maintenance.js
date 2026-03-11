const Maintenance = {
    refreshInterval: null,
    nodes: [],
    maintenanceStates: {},
    nodeGuests: {},
    fastPolling: false,
    activeTab: 'nodes',

    async init() {
        this.activeTab = 'nodes';
        this.render();
        if (!Utils.sshEnabled()) return;
        await this.loadData();
        this.startAutoRefresh();
    },

    destroy() {
        this.stopAutoRefresh();
        if (typeof Updater !== 'undefined') Updater.destroy();
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
        const hasUpdates = typeof Updater !== 'undefined' && Permissions.has('cluster.update');
        const main = document.getElementById('page-content');
        if (!Utils.sshEnabled()) {
            main.innerHTML = `
                <div class="section-header">
                    <h2><i class="bi bi-wrench-adjustable"></i> Maintenance</h2>
                </div>
                ${Utils.sshDisabledHint()}
            `;
            return;
        }
        main.innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-wrench-adjustable"></i> Maintenance</h2>
            </div>
            ${hasUpdates ? `
            <ul class="nav nav-tabs mb-4" id="maintenance-tabs">
                <li class="nav-item">
                    <a class="nav-link active" href="#" id="tab-btn-nodes"
                       onclick="Maintenance.showTab('nodes'); return false;">
                        <i class="bi bi-wrench me-1"></i>Maintenance Mode
                    </a>
                </li>
                <li class="nav-item">
                    <a class="nav-link" href="#" id="tab-btn-updates"
                       onclick="Maintenance.showTab('updates'); return false;">
                        <i class="bi bi-arrow-repeat me-1"></i>Updates
                    </a>
                </li>
            </ul>
            ` : ''}
            <div id="maintenance-tab-nodes">
                <p class="text-muted mb-4">Put nodes into maintenance mode. All running VMs/CTs will be automatically migrated to other nodes.</p>
                <div id="maintenance-nodes" class="row g-3"></div>
            </div>
            <div id="maintenance-tab-updates" class="d-none">
                <div id="updater-wrapper"></div>
            </div>
        `;
    },

    showTab(tab) {
        this.activeTab = tab;
        document.getElementById('maintenance-tab-nodes')?.classList.toggle('d-none', tab !== 'nodes');
        document.getElementById('maintenance-tab-updates')?.classList.toggle('d-none', tab !== 'updates');
        document.getElementById('tab-btn-nodes')?.classList.toggle('active', tab === 'nodes');
        document.getElementById('tab-btn-updates')?.classList.toggle('active', tab === 'updates');

        if (tab === 'updates') {
            this.stopAutoRefresh();
            if (typeof Updater !== 'undefined') Updater.init();
        } else {
            if (typeof Updater !== 'undefined') Updater.destroy();
            this.loadData();
            this.startAutoRefresh();
        }
    },

    async loadData() {
        try {
            const [healthData, guestsData] = await Promise.all([
                API.getClusterHealth(),
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

            // Maintenance states come directly from cluster health (same DB source, no extra call needed)
            this.maintenanceStates = {};
            for (const node of this.nodes) {
                if (node.maintenance) {
                    this.maintenanceStates[node.node] = node.maintenance;
                }
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
                                <button class="btn btn-outline-light btn-sm w-100 mb-1" disabled>
                                    <span class="spinner-border spinner-border-sm me-1"></span>Migration in progress...
                                </button>
                            ` : ''}
                            ${maint && maint.status === 'leaving' ? `
                                <button class="btn btn-outline-light btn-sm w-100 mb-1" disabled>
                                    <span class="spinner-border spinner-border-sm me-1"></span>VMs being migrated back...
                                </button>
                            ` : ''}
                        </div>
                    </div>
                </div>
            `;
        }).join('');
    },

    formatElapsed(seconds) {
        if (!seconds || seconds < 0) return '';
        const m = Math.floor(seconds / 60);
        const s = seconds % 60;
        if (m === 0) return `${s}s`;
        return `${m}m ${s}s`;
    },

    renderNodeContent(nodeName, maint) {
        // maint can be a raw DB row (migration_tasks as JSON string)
        // or an enriched API response (migrations as parsed array).
        const migrations = maint
            ? (Array.isArray(maint.migrations)
                ? maint.migrations
                : JSON.parse(maint.migration_tasks || '[]'))
            : [];

        // During migration (entering or leaving): show migration progress
        if (maint && migrations.length > 0) {
            const isLeaving = maint.status === 'leaving';
            const hasRunning = migrations.some(m => m.status === 'running');
            return `
                ${isLeaving ? '<div class="text-muted small mb-2"><i class="bi bi-arrow-left-right me-1"></i>Back-migration:</div>' : ''}
                <div class="migration-list">
                    ${migrations.map(m => {
                        let icon, statusClass, extra = '';
                        const elapsed = m.elapsed_seconds || (m.started_at ? Math.floor((Date.now() - new Date(m.started_at).getTime()) / 1000) : 0);
                        const isLong = elapsed > 300; // > 5 min

                        switch (m.status) {
                            case 'completed':
                                icon = '<i class="bi bi-check-circle-fill text-success"></i>';
                                statusClass = 'text-success';
                                break;
                            case 'error':
                                icon = '<i class="bi bi-x-circle-fill text-danger"></i>';
                                statusClass = 'text-danger';
                                break;
                            case 'skipped':
                                icon = '<i class="bi bi-skip-forward-fill text-muted"></i>';
                                statusClass = 'text-muted';
                                break;
                            case 'timeout':
                                icon = '<i class="bi bi-clock-history text-danger"></i>';
                                statusClass = 'text-danger';
                                extra = '<small class="text-danger ms-1">(timeout)</small>';
                                break;
                            default:
                                icon = '<span class="spinner-border spinner-border-sm text-warning"></span>';
                                statusClass = 'text-warning';
                        }

                        const elapsedLabel = m.status === 'running' && elapsed > 0
                            ? `<small class="${isLong ? 'text-danger' : 'text-muted'} ms-2">${this.formatElapsed(elapsed)}</small>`
                            : '';

                        const skipBtn = m.status === 'running'
                            ? `<button class="btn btn-outline-secondary btn-sm py-0 px-1 ms-2" title="Skip this migration"
                                onclick="event.stopPropagation();Maintenance.skipMigration('${escapeHtml(nodeName)}', ${m.vmid})">
                                <i class="bi bi-skip-forward"></i>
                              </button>`
                            : '';

                        return `
                            <div class="migration-item d-flex align-items-center gap-2 py-2 border-bottom" style="border-color: var(--border-color) !important;">
                                ${icon}
                                <div class="flex-grow-1">
                                    <strong>${escapeHtml(m.name)}</strong>
                                    <small class="text-muted ms-1">(${m.type} ${m.vmid})</small>
                                    ${elapsedLabel}${extra}
                                </div>
                                <small class="${statusClass}">\u2192 ${escapeHtml(m.target)}</small>
                                ${skipBtn}
                            </div>
                        `;
                    }).join('')}
                </div>
                ${hasRunning ? `
                <div class="mt-2 text-end">
                    <button class="btn btn-outline-danger btn-sm" onclick="Maintenance.forceComplete('${escapeHtml(nodeName)}')">
                        <i class="bi bi-lightning me-1"></i>Force Complete
                    </button>
                </div>` : ''}
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
                        ${g.type === 'lxc' ? '<span class="badge bg-warning text-dark ms-1" title="LXC containers will be stopped during migration" style="font-size:0.65rem">stop/start</span>' : ''}
                        <small class="text-muted ms-auto">${g.vmid}</small>
                    </div>
                `).join('')}
                ${guests.length > 8 ? `<div class="text-muted small mt-1">+ ${guests.length - 8} more</div>` : ''}
                ${cts.length > 0 ? '<div class="text-warning small mt-2"><i class="bi bi-exclamation-triangle me-1"></i>LXC containers will be briefly stopped for migration.</div>' : ''}
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
        if (!Utils.sshEnabled()) { Toast.error('This feature requires SSH.'); return; }
        const guests = this.nodeGuests[nodeName] || [];
        const hasLxc = guests.some(g => g.type === 'lxc');
        const lxcNote = hasLxc
            ? '\n\n⚠ LXC containers cannot be live-migrated and will be stopped, moved, then restarted on the target node.'
            : '';
        if (!confirm(`Put node "${nodeName}" into maintenance mode?\n\nAll running VMs/CTs will be migrated to other nodes.${lxcNote}`)) {
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

    async skipMigration(nodeName, vmid) {
        if (!confirm(`Skip migration of VM ${vmid}? The VM will remain on its current node.`)) return;

        try {
            const resp = await fetch('api/maintenance.php', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': API.csrfToken,
                },
                body: JSON.stringify({ node: nodeName, action: 'skip-vm', vmid }),
            });
            const result = await resp.json();
            if (result.success) {
                Toast.info(`Migration of VM ${vmid} skipped.`);
                await this.loadData();
            } else {
                Toast.error(result.message || 'Failed to skip migration');
            }
        } catch (err) {
            Toast.error('Failed to skip migration');
        }
    },

    async forceComplete(nodeName) {
        if (!confirm(`Force complete all migrations for "${nodeName}"?\n\nAll pending migrations will be skipped and the node will transition to the next state.`)) return;

        try {
            const resp = await fetch('api/maintenance.php', {
                method: 'PATCH',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': API.csrfToken,
                },
                body: JSON.stringify({ node: nodeName, action: 'force-complete' }),
            });
            const result = await resp.json();
            if (result.success) {
                Toast.success(`Maintenance state for ${nodeName} force-completed.`);
                await this.loadData();
            } else {
                Toast.error(result.message || 'Failed to force complete');
            }
        } catch (err) {
            Toast.error('Failed to force complete');
        }
    },

    async leaveMaintenance(nodeName) {
        if (!Utils.sshEnabled()) { Toast.error('This feature requires SSH.'); return; }
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
