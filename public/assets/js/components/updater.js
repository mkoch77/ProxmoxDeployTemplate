const Updater = {
    nodes: [],
    updateCounts: {},     // node → count of available updates
    updatePackages: {},   // node → array of pending packages
    session: null,
    running: false,
    cancelled: false,

    async init() {
        this.running   = false;
        this.cancelled = false;
        this.render();
        await this.loadNodes();
        // Auto-check on tab open
        if (this.nodes.length > 0) {
            await this.checkAllUpdates();
        }
    },

    destroy() {
        this.cancelled = true;
    },

    render() {
        const content = document.getElementById('page-content');
        if (!Utils.sshEnabled()) {
            content.innerHTML = `
                <div class="content-header"><h1><i class="bi bi-arrow-repeat me-2"></i>Update Manager</h1></div>
                ${Utils.sshDisabledHint()}
            `;
            return;
        }
        document.getElementById('updater-wrapper').innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-arrow-repeat"></i> Update Manager</h2>
                <button class="btn btn-outline-light btn-sm" onclick="Updater.checkAllUpdates()" id="updater-check-btn">
                    <i class="bi bi-search me-1"></i>Check for Updates
                </button>
            </div>
            <p class="text-muted mb-4">
                Updates nodes one by one. Each node is put into maintenance mode (VMs migrated away) before updating,
                then maintenance mode is deactivated afterwards (VMs migrated back).
            </p>
            <div id="updater-nodes" class="row g-3 mb-4"></div>
            <div id="updater-controls" class="d-none mb-4">
                <button class="btn btn-primary me-2" id="updater-start-btn" onclick="Updater.startUpdate()">
                    <i class="bi bi-play-fill me-1"></i>Start Rolling Update
                </button>
                <button class="btn btn-outline-secondary" onclick="Updater.selectAll(true)">All</button>
                <button class="btn btn-outline-secondary ms-1" onclick="Updater.selectAll(false)">None</button>
            </div>
            <div id="updater-progress" class="d-none"></div>
        `;
    },

    async loadNodes() {
        try {
            const health = await API.getClusterHealth();
            this.nodes = (health.nodes || [])
                .filter(n => n.status === 'online')
                .sort((a, b) => a.node.localeCompare(b.node));
            this.renderNodeSelection();
            document.getElementById('updater-controls').classList.remove('d-none');
        } catch (_) {
            document.getElementById('updater-nodes').innerHTML =
                '<p class="text-danger">Failed to load nodes.</p>';
        }
    },

    renderNodeSelection() {
        const container = document.getElementById('updater-nodes');
        if (!this.nodes.length) {
            container.innerHTML = '<p class="text-muted">No online nodes found.</p>';
            return;
        }
        const startBtn = document.getElementById('updater-start-btn');
        if (startBtn) {
            const hasFailures = this.nodes.some(n => (this.updateCounts[n.node] ?? 0) < 0);
            startBtn.disabled = hasFailures;
            startBtn.title = hasFailures ? 'Resolve failed update checks before starting' : '';
        }

        container.innerHTML = this.nodes.map(n => {
            const count    = this.updateCounts[n.node];
            const packages = this.updatePackages[n.node] || [];
            const nodeId   = escapeHtml(n.node);

            let countBadge;
            if (count === undefined) {
                countBadge = '<span class="badge bg-secondary ms-2">not checked</span>';
            } else if (count === 0) {
                countBadge = '<span class="badge bg-success ms-2">up to date</span>';
            } else if (count < 0) {
                countBadge = '<span class="badge bg-danger ms-2" title="Could not reach node or check failed">check failed</span>';
            } else {
                countBadge = `<span class="badge bg-warning text-dark ms-2" style="cursor:pointer"
                    title="Click to show packages"
                    onclick="document.getElementById('pkgs-${nodeId}').classList.toggle('d-none')"
                >${count} update${count !== 1 ? 's' : ''}</span>`;
            }

            const pkgList = packages.length ? `
                <div id="pkgs-${nodeId}" class="d-none mt-2" style="font-size:0.78rem;max-height:180px;overflow-y:auto">
                    ${packages.map(p => `
                        <div class="d-flex justify-content-between py-1 border-bottom" style="border-color:var(--border-color)!important">
                            <span class="text-truncate me-2">${escapeHtml(p.name)}</span>
                            <small class="text-muted text-nowrap">${escapeHtml(p.new_version)}</small>
                        </div>`).join('')}
                </div>` : '';

            return `
                <div class="col-md-6 col-xl-4">
                    <div class="node-card" style="cursor:default">
                        <div class="d-flex align-items-center gap-3">
                            <input type="checkbox" class="form-check-input updater-node-check"
                                id="check-${nodeId}"
                                value="${nodeId}"
                                style="width:1.1rem;height:1.1rem;cursor:pointer"
                                ${count === 0 ? '' : 'checked'}>
                            <label class="flex-grow-1 mb-0" for="check-${nodeId}" style="cursor:pointer">
                                <i class="bi bi-hdd-rack me-2 text-muted"></i>
                                <strong>${escapeHtml(n.node)}</strong>
                                ${countBadge}
                            </label>
                        </div>
                        ${pkgList}
                    </div>
                </div>
            `;
        }).join('');
    },

    selectAll(checked) {
        document.querySelectorAll('.updater-node-check').forEach(cb => cb.checked = checked);
    },

    async checkAllUpdates() {
        const btn = document.getElementById('updater-check-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Checking...';

        await Promise.allSettled(this.nodes.map(async n => {
            try {
                const r = await API.checkNodeUpdates(n.node);
                this.updateCounts[n.node]   = r.count ?? 0;
                this.updatePackages[n.node] = r.packages ?? [];
            } catch (_) {
                this.updateCounts[n.node]   = -1;
                this.updatePackages[n.node] = [];
            }
        }));

        this.renderNodeSelection();
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-search me-1"></i>Check for Updates';
    },

    getSelectedNodes() {
        return [...document.querySelectorAll('.updater-node-check:checked')].map(cb => cb.value);
    },

    async startUpdate() {
        const nodes = this.getSelectedNodes();
        if (!nodes.length) {
            Toast.error('No nodes selected.');
            return;
        }
        if (!confirm(`Start rolling update for ${nodes.length} node(s)?\n\n${nodes.join(', ')}\n\nVMs will be migrated off each node before updating.`)) {
            return;
        }

        this.cancelled = false;
        this.running   = true;

        try {
            const session = await API.startRollingUpdate(nodes);
            this.session = session;
        } catch (_) {
            return;
        }

        // Switch to progress view
        document.getElementById('updater-controls').classList.add('d-none');
        document.getElementById('updater-nodes').classList.add('d-none');
        document.getElementById('updater-progress').classList.remove('d-none');
        this.renderProgress();

        let overallSuccess = true;

        for (const node of nodes) {
            if (this.cancelled) break;

            try {
                await this.updateNode(node);
            } catch (err) {
                overallSuccess = false;
                this.setNodeStep(node, 'failed', null, null, err.message);
                await API.updateRollingNode(this.session.id, node, 'failed', null, null, err.message).catch(() => {});
                // Continue with next node
            }
        }

        const finalStatus = this.cancelled ? 'cancelled' : (overallSuccess ? 'completed' : 'failed');
        await API.finishRollingUpdate(this.session.id, finalStatus).catch(() => {});
        this.running = false;

        const summaryEl = document.getElementById('updater-summary');
        if (summaryEl) {
            const icon  = finalStatus === 'completed' ? 'bi-check-circle-fill text-success' : 'bi-exclamation-triangle-fill text-warning';
            const label = finalStatus === 'completed' ? 'Rolling update completed successfully.' :
                          finalStatus === 'cancelled'  ? 'Rolling update was cancelled.' :
                          'Rolling update finished with errors.';
            summaryEl.innerHTML = `<div class="alert alert-${finalStatus === 'completed' ? 'success' : 'warning'} mt-3">
                <i class="bi ${icon} me-2"></i>${label}
            </div>`;
        }
    },

    async updateNode(node) {
        // ── Step 1: Enter Maintenance ─────────────────────────────────
        this.setNodeStep(node, 'entering_maintenance');
        await API.updateRollingNode(this.session.id, node, 'entering_maintenance');
        await API.enterMaintenance(node);

        // Wait until maintenance is active (all migrations done)
        await this.waitMaintenance(node, 'maintenance');

        // ── Step 2: Run apt upgrade ────────────────────────────────────
        this.setNodeStep(node, 'updating');
        await API.updateRollingNode(this.session.id, node, 'updating');

        let updateResult = { success: true, log: '', upgraded: 0 };
        try {
            updateResult = await API.runNodeUpdate(node);
        } catch (err) {
            // SSH/update failure — still exit maintenance so node isn't stuck
            updateResult = { success: false, log: err.message || 'Update failed', upgraded: 0 };
        }

        // ── Step 3: Leave Maintenance ─────────────────────────────────
        this.setNodeStep(node, 'leaving_maintenance', updateResult.log, updateResult.upgraded);
        await API.updateRollingNode(this.session.id, node, 'leaving_maintenance',
            updateResult.log, updateResult.upgraded);

        try {
            await API.leaveMaintenance(node);
            await this.waitMaintenance(node, 'done');
        } catch (_) {
            // Best effort — maintenance record may already be gone
        }

        // ── Step 4: Done ──────────────────────────────────────────────
        if (!updateResult.success) {
            throw new Error(updateResult.log || 'Update command failed');
        }

        this.setNodeStep(node, 'completed', updateResult.log, updateResult.upgraded);
        await API.updateRollingNode(this.session.id, node, 'completed',
            updateResult.log, updateResult.upgraded);
    },

    async waitMaintenance(node, target) {
        const maxWait = 20 * 60 * 1000; // 20 minutes max
        const start   = Date.now();

        while (Date.now() - start < maxWait) {
            if (this.cancelled) throw new Error('Cancelled');
            await this.sleep(4000);

            try {
                const s = await API.getMaintenanceStatus(node);
                this.updateMaintenanceTasks(node, s);
                if (target === 'maintenance' && s.status === 'maintenance') return;
                if (target === 'done'        && s.status === 'done')        return;
            } catch (err) {
                // 404 = record deleted = done
                if (target === 'done') return;
                // For 'maintenance' target: if node has no guests it goes directly to maintenance
                // without a DB record being created in 'entering' state; check if it's there
            }
        }
        throw new Error(`Timeout waiting for maintenance on ${node}`);
    },

    updateMaintenanceTasks(node, status) {
        const taskEl = document.getElementById(`updater-tasks-${node}`);
        if (!taskEl || !status.migrations?.length) return;
        taskEl.innerHTML = status.migrations.map(m => {
            const icon = m.status === 'completed'
                ? '<i class="bi bi-check-circle-fill text-success"></i>'
                : m.status === 'error'
                    ? '<i class="bi bi-x-circle-fill text-danger"></i>'
                    : '<span class="spinner-border spinner-border-sm text-warning"></span>';
            return `<div class="d-flex align-items-center gap-2 py-1">
                ${icon}
                <small>${escapeHtml(m.name || `VM ${m.vmid}`)}</small>
                <small class="text-muted ms-auto">→ ${escapeHtml(m.target || '')}</small>
            </div>`;
        }).join('');
    },

    // ── Progress rendering ─────────────────────────────────────────────

    renderProgress() {
        const nodes  = this.session.nodes || [];
        const pEl    = document.getElementById('updater-progress');

        pEl.innerHTML = `
            <div class="d-flex align-items-center gap-3 mb-4">
                <span class="spinner-border spinner-border-sm text-primary"></span>
                <span class="text-muted">Rolling update in progress…</span>
                <button class="btn btn-sm btn-outline-danger ms-auto" onclick="Updater.cancel()">
                    <i class="bi bi-x-circle me-1"></i>Cancel
                </button>
            </div>
            ${nodes.map(n => this.renderNodeCard(n, 'pending')).join('')}
            <div id="updater-summary"></div>
        `;
    },

    renderNodeCard(node, step) {
        return `
            <div class="node-card mb-3" id="updater-card-${escapeHtml(node)}">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="mb-0"><i class="bi bi-hdd-rack me-2 text-muted"></i>${escapeHtml(node)}</h6>
                    <div id="updater-badge-${escapeHtml(node)}">${this.stepBadge(step)}</div>
                </div>
                <div id="updater-tasks-${escapeHtml(node)}" class="migration-list"></div>
                <div id="updater-log-${escapeHtml(node)}" class="mt-2"></div>
            </div>
        `;
    },

    stepBadge(step) {
        const map = {
            pending:              ['bg-secondary',              'Pending'],
            entering_maintenance: ['bg-warning text-dark',      'Entering Maintenance…'],
            updating:             ['bg-primary',                'Updating…'],
            leaving_maintenance:  ['bg-info text-dark',         'Leaving Maintenance…'],
            completed:            ['bg-success',                'Completed'],
            failed:               ['bg-danger',                 'Failed'],
            no_updates:           ['bg-secondary',              'No Updates'],
        };
        const [cls, label] = map[step] || ['bg-secondary', step];
        const spinner = ['entering_maintenance', 'updating', 'leaving_maintenance'].includes(step)
            ? '<span class="spinner-border spinner-border-sm me-1"></span>'
            : '';
        return `<span class="badge ${cls}">${spinner}${label}</span>`;
    },

    setNodeStep(node, step, log = null, upgraded = null, error = null) {
        const badgeEl = document.getElementById(`updater-badge-${node}`);
        if (badgeEl) badgeEl.innerHTML = this.stepBadge(step);

        const logEl = document.getElementById(`updater-log-${node}`);
        if (!logEl) return;

        if (error) {
            logEl.innerHTML = `<div class="text-danger small mt-1"><i class="bi bi-exclamation-circle me-1"></i>${escapeHtml(error)}</div>`;
            return;
        }

        if (step === 'completed' && log) {
            const upgradeInfo = upgraded !== null ? `<span class="badge bg-success ms-2">${upgraded} package${upgraded !== 1 ? 's' : ''} upgraded</span>` : '';
            logEl.innerHTML = `
                <div class="d-flex align-items-center gap-2 mt-1">
                    <i class="bi bi-check-circle-fill text-success"></i>
                    <small class="text-success">Update successful</small>
                    ${upgradeInfo}
                    <button class="btn btn-link btn-sm p-0 ms-auto text-muted" style="font-size:0.75rem"
                        onclick="this.nextElementSibling.classList.toggle('d-none')">Show log</button>
                </div>
                <pre class="d-none mt-2" style="font-size:0.72rem;max-height:200px;overflow-y:auto;background:var(--bg-elevated);padding:0.5rem;border-radius:4px">${escapeHtml(log)}</pre>
            `;
        }
        if (step === 'leaving_maintenance' && log) {
            const upgradeInfo = upgraded !== null ? `<span class="badge bg-primary ms-2">${upgraded} upgraded</span>` : '';
            logEl.innerHTML = `<div class="d-flex align-items-center gap-2 mt-1">
                <i class="bi bi-arrow-repeat text-info"></i>
                <small class="text-info">Update done, migrating VMs back…</small>
                ${upgradeInfo}
            </div>`;
        }
    },

    cancel() {
        if (!confirm('Cancel the rolling update? The current node operation will still complete.')) return;
        this.cancelled = true;
        Toast.info('Rolling update will stop after the current node finishes.');
    },

    sleep(ms) {
        return new Promise(r => setTimeout(r, ms));
    },
};
