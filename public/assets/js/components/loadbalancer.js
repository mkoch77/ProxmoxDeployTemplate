const Loadbalancer = {
    refreshInterval: null,
    data: null,
    history: null,

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
        this.refreshInterval = setInterval(() => this.loadData(), 15000);
    },

    stopAutoRefresh() {
        if (this.refreshInterval) {
            clearInterval(this.refreshInterval);
            this.refreshInterval = null;
        }
    },

    render() {
        const main = document.getElementById('page-content');
        const canManage = Permissions.has('drs.manage');

        main.innerHTML = `
            <div class="section-header">
                <h2><i class="bi bi-shuffle"></i> Loadbalancing</h2>
            </div>
            <p class="text-muted mb-4">Automatic workload distribution of VMs/CTs across cluster nodes, similar to VMware DRS.</p>

            <div id="lb-balance-stats" class="row g-3 mb-4"></div>

            <div class="section-header mt-4">
                <h2><i class="bi bi-bar-chart-fill"></i> Node Utilization</h2>
            </div>
            <div id="lb-node-bars" class="mb-4"></div>

            ${canManage ? `
                <div class="section-header mt-4">
                    <h2><i class="bi bi-gear-fill"></i> Settings</h2>
                </div>
                <div id="lb-settings" class="mb-4"></div>
            ` : ''}

            <div class="section-header mt-4">
                <h2><i class="bi bi-lightbulb-fill"></i> Recommendations</h2>
            </div>
            <div id="lb-recommendations" class="mb-4"></div>

            <div class="section-header mt-4">
                <h2><i class="bi bi-clock-history"></i> History</h2>
            </div>
            <div id="lb-history" class="mb-4"></div>
        `;
    },

    async loadData() {
        try {
            const [lbData, historyData] = await Promise.all([
                API.getLoadbalancer(),
                API.getLoadbalancerHistory(10, 0),
            ]);
            this.data = lbData;
            this.history = historyData;
            this.updateView();
        } catch (err) {
            // Error already shown by API wrapper
        }
    },

    updateView() {
        if (!this.data) return;
        this.renderBalanceStats();
        this.renderNodeBars();
        if (Permissions.has('drs.manage')) {
            this.renderSettings();
        }
        this.renderRecommendations();
        this.renderHistory();
    },

    renderBalanceStats() {
        const container = document.getElementById('lb-balance-stats');
        if (!container) return;

        const balance = this.data.balance || {};
        const latestRun = this.data.latest_run;
        const nodeCount = (balance.nodes || []).length;

        const lastRunTime = latestRun
            ? new Date(latestRun.created_at).toLocaleString('en-US')
            : 'No runs yet';

        container.innerHTML = `
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-speedometer2"></i></div>
                    <div class="stat-value">${balance.avg_score ?? 0}%</div>
                    <div class="stat-label">Avg. Score</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-arrows-collapse"></i></div>
                    <div class="stat-value">${balance.std_dev ?? 0}%</div>
                    <div class="stat-label">Std. Deviation</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-hdd-rack"></i></div>
                    <div class="stat-value">${nodeCount}</div>
                    <div class="stat-label">Active Nodes</div>
                </div>
            </div>
            <div class="col-6 col-lg-3">
                <div class="stat-card">
                    <div class="stat-icon" style="color:var(--text-secondary)"><i class="bi bi-clock"></i></div>
                    <div class="stat-value" style="font-size:1rem">${escapeHtml(lastRunTime)}</div>
                    <div class="stat-label">Last Run</div>
                </div>
            </div>
        `;
    },

    renderNodeBars() {
        const container = document.getElementById('lb-node-bars');
        if (!container) return;

        const balance = this.data.balance || {};
        const nodes = (balance.nodes || []).slice().sort((a, b) => a.node.localeCompare(b.node));
        const avgScore = balance.avg_score || 0;

        if (nodes.length === 0) {
            container.innerHTML = '<div class="text-muted text-center py-3">No node data available</div>';
            return;
        }

        container.innerHTML = nodes.map(node => {
            const levelClass = this.levelClass(node.score);
            return `
                <div class="lb-node-bar">
                    <div class="node-name">${escapeHtml(node.node)}</div>
                    <div class="score-bar">
                        <div class="score-fill ${levelClass}" style="width:${Math.min(node.score, 100)}%"></div>
                    </div>
                    <div class="score-value">${node.score}%</div>
                    <div class="score-details">
                        CPU: ${node.cpu_pct}% | RAM: ${node.ram_pct}% | ${node.guest_count} VMs
                    </div>
                </div>
            `;
        }).join('') + `
            <div class="text-muted small mt-2">
                <i class="bi bi-dash-lg text-warning me-1"></i>Cluster Average: ${avgScore}%
            </div>
        `;
    },

    renderSettings() {
        const container = document.getElementById('lb-settings');
        if (!container) return;

        const s = this.data.settings || {};
        const thresholdLabels = {
            1: 'Aggressive',
            2: 'Moderate',
            3: 'Default',
            4: 'Conservative',
            5: 'Very Conservative',
        };

        container.innerHTML = `
            <div class="lb-settings-card">
                <div class="row g-3">
                    <div class="col-12">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="lb-enabled" ${s.enabled ? 'checked' : ''}>
                            <label class="form-check-label" for="lb-enabled">Loadbalancing enabled</label>
                        </div>
                    </div>

                    <div class="col-12">
                        <label class="form-label text-muted small mb-2">Automation Level</label>
                        <div class="lb-automation-toggle">
                            <button class="btn btn-sm ${s.automation_level === 'manual' ? 'active' : 'btn-outline-light'}" data-level="manual">Manual</button>
                            <button class="btn btn-sm ${s.automation_level === 'partial' ? 'active' : 'btn-outline-light'}" data-level="partial">Semi-Automatic</button>
                            <button class="btn btn-sm ${s.automation_level === 'full' ? 'active' : 'btn-outline-light'}" data-level="full">Fully Automatic</button>
                        </div>
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-muted small">CPU Weight: <span id="lb-cpu-val">${s.cpu_weight}</span>%</label>
                        <input type="range" class="form-range" id="lb-cpu-weight" min="0" max="100" value="${s.cpu_weight}">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label text-muted small">RAM Weight: <span id="lb-ram-val">${s.ram_weight}</span>%</label>
                        <input type="range" class="form-range" id="lb-ram-weight" min="0" max="100" value="${s.ram_weight}">
                    </div>

                    <div class="col-md-6">
                        <label class="form-label text-muted small">Threshold: <span id="lb-threshold-val">${s.threshold} - ${thresholdLabels[s.threshold] || ''}</span></label>
                        <input type="range" class="form-range" id="lb-threshold" min="1" max="5" value="${s.threshold}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label text-muted small">Interval (minutes)</label>
                        <input type="number" class="form-control form-control-sm" id="lb-interval" min="1" max="60" value="${s.interval_minutes}">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label text-muted small">Max. concurrent</label>
                        <input type="number" class="form-control form-control-sm" id="lb-max-concurrent" min="1" max="10" value="${s.max_concurrent || 3}">
                    </div>

                    <div class="col-12 d-flex gap-2">
                        <button class="btn btn-primary btn-sm" onclick="Loadbalancer.saveSettings()">
                            <i class="bi bi-check-lg me-1"></i>Save Settings
                        </button>
                        <button class="btn btn-outline-light btn-sm" onclick="Loadbalancer.resetDefaults()">
                            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset to Default
                        </button>
                    </div>

                    <div class="col-12 mt-2">
                        <div class="text-muted small">
                            <i class="bi bi-terminal me-1"></i>Cron command:
                            <code>*/${s.interval_minutes || 5} * * * * php /path/to/cli/loadbalancer-run.php >> /var/log/proxmox-lb.log 2>&1</code>
                        </div>
                    </div>
                </div>
            </div>
        `;

        // Event listeners for sliders
        document.getElementById('lb-cpu-weight')?.addEventListener('input', (e) => {
            document.getElementById('lb-cpu-val').textContent = e.target.value;
        });
        document.getElementById('lb-ram-weight')?.addEventListener('input', (e) => {
            document.getElementById('lb-ram-val').textContent = e.target.value;
        });
        document.getElementById('lb-threshold')?.addEventListener('input', (e) => {
            const val = parseInt(e.target.value);
            const labels = { 1: 'Aggressive', 2: 'Moderate', 3: 'Default', 4: 'Conservative', 5: 'Very Conservative' };
            document.getElementById('lb-threshold-val').textContent = `${val} - ${labels[val] || ''}`;
        });

        document.querySelectorAll('.lb-automation-toggle .btn').forEach(btn => {
            btn.addEventListener('click', () => {
                document.querySelectorAll('.lb-automation-toggle .btn').forEach(b => {
                    b.classList.remove('active');
                    b.classList.add('btn-outline-light');
                });
                btn.classList.add('active');
                btn.classList.remove('btn-outline-light');
            });
        });
    },

    renderRecommendations() {
        const container = document.getElementById('lb-recommendations');
        if (!container) return;

        const latestRun = this.data.latest_run;
        const settings = this.data.settings || {};
        const canManage = Permissions.has('drs.manage');
        const isPartial = settings.automation_level === 'partial';
        const recs = latestRun?.recommendations || [];

        const actionButtons = canManage ? `
            <div class="d-flex gap-2 mb-3">
                <button class="btn btn-primary btn-sm" onclick="Loadbalancer.runNow()">
                    <i class="bi bi-play-fill me-1"></i>Evaluate Now
                </button>
                ${isPartial && recs.some(r => r.status === 'pending') ? `
                    <button class="btn btn-success btn-sm" onclick="Loadbalancer.applyAll(${latestRun?.id})">
                        <i class="bi bi-check-all me-1"></i>Apply All
                    </button>
                ` : ''}
            </div>
        ` : '';

        if (recs.length === 0) {
            const skipped = latestRun?.skipped || [];
            let skippedHtml = '';
            if (skipped.length > 0) {
                skippedHtml = `
                    <div class="mt-3 text-start" style="max-width:600px;margin:0 auto">
                        <div class="text-warning small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Migrations blocked:</div>
                        ${skipped.map(s => {
                            const name = s.vm_name || `VM ${s.vmid}`;
                            if (s.reason === 'ram') {
                                return `<div class="text-muted small mb-1">
                                    <strong>${escapeHtml(name)}</strong> (${s.vm_type}:${s.vmid}) on <strong>${escapeHtml(s.source_node)}</strong>
                                    — requires ${Utils.formatBytes(s.required_mem)} RAM, best target has only ${Utils.formatBytes(s.best_target_free)} free
                                </div>`;
                            }
                            return `<div class="text-muted small mb-1"><strong>${escapeHtml(name)}</strong> — cannot be migrated</div>`;
                        }).join('')}
                    </div>
                `;
            }

            container.innerHTML = `
                ${actionButtons}
                <div class="text-muted text-center py-3">
                    ${latestRun ? 'No recommendations - cluster is balanced' : 'No evaluation performed yet'}
                    ${skippedHtml}
                </div>
            `;
            return;
        }

        container.innerHTML = `
            ${actionButtons}
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle">
                    <thead>
                        <tr>
                            <th>VM/CT</th>
                            <th>Type</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Impact</th>
                            <th>Status</th>
                            ${canManage && isPartial ? '<th>Action</th>' : ''}
                        </tr>
                    </thead>
                    <tbody>
                        ${recs.map(rec => `
                            <tr>
                                <td><strong>${escapeHtml(rec.vm_name)}</strong> <small class="text-muted">(${rec.vmid})</small></td>
                                <td><span class="badge bg-secondary">${rec.vm_type}</span></td>
                                <td>${escapeHtml(rec.source_node)}</td>
                                <td><i class="bi bi-arrow-right text-muted mx-1"></i>${escapeHtml(rec.target_node)}</td>
                                <td><span class="text-success">-${rec.impact_score}%</span></td>
                                <td>${this.statusBadge(rec.status)}</td>
                                ${canManage && isPartial ? `
                                    <td>
                                        ${rec.status === 'pending' ? `
                                            <button class="btn btn-sm btn-success btn-action" onclick="Loadbalancer.applyOne(${rec.id})">
                                                <i class="bi bi-play-fill"></i> Migrate
                                            </button>
                                        ` : ''}
                                    </td>
                                ` : ''}
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    },

    renderHistory() {
        const container = document.getElementById('lb-history');
        if (!container || !this.history) return;

        const runs = this.history.runs || [];

        if (runs.length === 0) {
            container.innerHTML = '<div class="text-muted text-center py-3">No runs yet</div>';
            return;
        }

        container.innerHTML = `
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Triggered By</th>
                            <th>Nodes</th>
                            <th>Avg. Score</th>
                            <th>Recommendations</th>
                            <th>Executed</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${runs.map(run => `
                            <tr>
                                <td>${new Date(run.created_at).toLocaleString('en-US')}</td>
                                <td><span class="badge ${run.triggered_by === 'cron' ? 'bg-secondary' : 'bg-info'}">${escapeHtml(run.triggered_by)}</span></td>
                                <td>${run.node_count}</td>
                                <td>${run.cluster_avg_score}%</td>
                                <td>${run.recommendations_count}</td>
                                <td>${run.executed_count}</td>
                            </tr>
                        `).join('')}
                    </tbody>
                </table>
            </div>
        `;
    },

    statusBadge(status) {
        switch (status) {
            case 'pending': return '<span class="badge bg-warning text-dark">Pending</span>';
            case 'applied': return '<span class="badge bg-success">Applied</span>';
            case 'error': return '<span class="badge bg-danger">Error</span>';
            case 'skipped': return '<span class="badge bg-secondary">Skipped</span>';
            default: return `<span class="badge bg-secondary">${escapeHtml(status)}</span>`;
        }
    },

    levelClass(pct) {
        if (pct >= 90) return 'level-danger';
        if (pct >= 70) return 'level-warn';
        return 'level-ok';
    },

    async saveSettings() {
        const activeBtn = document.querySelector('.lb-automation-toggle .btn.active');
        const settings = {
            enabled: document.getElementById('lb-enabled')?.checked ? 1 : 0,
            automation_level: activeBtn?.dataset.level || 'manual',
            cpu_weight: parseInt(document.getElementById('lb-cpu-weight')?.value || '50'),
            ram_weight: parseInt(document.getElementById('lb-ram-weight')?.value || '50'),
            threshold: parseInt(document.getElementById('lb-threshold')?.value || '3'),
            interval_minutes: parseInt(document.getElementById('lb-interval')?.value || '5'),
            max_concurrent: parseInt(document.getElementById('lb-max-concurrent')?.value || '3'),
        };

        try {
            await API.updateLoadbalancerSettings(settings);
            Toast.success('Settings saved');
            await this.loadData();
        } catch (err) {
            Toast.error('Failed to save settings');
        }
    },

    async resetDefaults() {
        if (!confirm('Reset all loadbalancer settings to default values?')) return;

        const defaults = {
            enabled: 0,
            automation_level: 'manual',
            cpu_weight: 50,
            ram_weight: 50,
            threshold: 3,
            interval_minutes: 5,
            max_concurrent: 3,
        };

        try {
            await API.updateLoadbalancerSettings(defaults);
            Toast.success('Settings reset to defaults');
            await this.loadData();
        } catch (err) {
            Toast.error('Failed to reset settings');
        }
    },

    async runNow() {
        try {
            Toast.info('Running evaluation...');
            await API.runLoadbalancer();
            Toast.success('Evaluation complete');
            await this.loadData();
        } catch (err) {
            Toast.error('Evaluation failed');
        }
    },

    async applyOne(recId) {
        try {
            Toast.info('Starting migration...');
            const result = await API.applyLoadbalancerRecommendation(recId);
            Toast.success('Migration started');
            await this.loadData();
            return result;
        } catch (err) {
            Toast.error('Failed to start migration');
            return null;
        }
    },

    async waitForTask(node, upid) {
        // Extract node from UPID (format: UPID:node:...)
        const upidNode = upid?.split(':')[1] || node;
        for (let i = 0; i < 150; i++) {
            await new Promise(r => setTimeout(r, 2000));
            try {
                const status = await API.getTaskStatus(upidNode, upid);
                if (status?.status === 'stopped') {
                    return status.exitstatus === 'OK';
                }
            } catch (_) { /* keep polling */ }
        }
        return false;
    },

    applyAllRunning: false,

    async applyAll(runId) {
        if (this.applyAllRunning) return;
        if (!confirm('Apply all pending recommendations? VMs will be live-migrated sequentially.')) return;

        this.applyAllRunning = true;
        let applied = 0;
        let errors = 0;

        try {
            // Collect all pending recommendation IDs from this run upfront
            const recs = this.data?.latest_run?.recommendations || [];
            const pendingIds = recs.filter(r => r.status === 'pending').map(r => ({ id: r.id, name: r.vm_name, target: r.target_node, source: r.source_node }));
            const total = pendingIds.length;

            if (total === 0) { Toast.info('No pending migrations'); return; }

            Toast.info(`Migrating ${total} VM${total > 1 ? 's' : ''} sequentially...`);

            for (const rec of pendingIds) {
                let result;
                try {
                    result = await API.applyLoadbalancerRecommendation(rec.id);
                } catch (_) {
                    errors++;
                    continue;
                }

                if (!result || result.status === 'error') {
                    errors++;
                    continue;
                }

                // Wait for migration task to complete
                const upid = result.upid;
                if (upid) {
                    const success = await this.waitForTask(rec.source, upid);
                    if (!success) {
                        errors++;
                        continue;
                    }
                } else {
                    await new Promise(r => setTimeout(r, 5000));
                }

                applied++;
                await this.loadData();
            }

            // Final re-evaluation with fresh cluster state
            await new Promise(r => setTimeout(r, 3000));
            try { await API.runLoadbalancer(); } catch (_) { /* ok */ }

            const msg = `Done — ${applied}/${total} migrated` + (errors > 0 ? ` (${errors} failed)` : '');
            errors > 0 ? Toast.error(msg) : Toast.success(msg);
        } catch (err) {
            Toast.error('Migration process stopped');
        } finally {
            this.applyAllRunning = false;
            await this.loadData();
        }
    },
};
