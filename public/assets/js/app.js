const App = {
    currentPage: null,
    currentTheme: window.APP_USER?.theme || 'auto',
    pages: {
        dashboard: Dashboard,
        deploy: typeof Templates !== 'undefined' ? Templates : null,
        health: typeof Health !== 'undefined' ? Health : null,
        maintenance: typeof Maintenance !== 'undefined' ? Maintenance : null,
        loadbalancing: typeof Loadbalancer !== 'undefined' ? Loadbalancer : null,
        reports: typeof Reports !== 'undefined' ? Reports : null,
        monitoring: typeof Monitoring !== 'undefined' ? Monitoring : null,
        settings: typeof Settings !== 'undefined' ? Settings : null,
        users: typeof Users !== 'undefined' ? Users : null,
    },

    _warningInterval: null,
    _updateInterval: null,
    _warnings: [],
    _infos: [],
    _dismissedInfos: new Set(), // dismissed info keys (session only)
    _resourceAlertSince: {},    // key → timestamp when threshold first exceeded
    _lastUpdateCheck: 0,        // timestamp of last update check
    _cachedUpdateCounts: {},    // node → count of available updates

    init() {
        // Remove null pages (not loaded due to missing permissions)
        Object.keys(this.pages).forEach(k => {
            if (!this.pages[k]) delete this.pages[k];
        });

        this.setupThemeListener();
        this.checkConnection();
        this.setupRouter();
        this.navigate(this.getHash());
        this.restoreSidebarState();
        this.checkClusterHealth();
        this._warningInterval = setInterval(() => this.checkClusterHealth(), 60000);

        // Hourly update check (also runs on init)
        if (Permissions.has('cluster.update')) {
            this.checkForUpdates();
            this._updateInterval = setInterval(() => this.checkForUpdates(), 3600000);
        }
    },

    toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const collapsed = sidebar.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
    },

    toggleMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('mobile-backdrop');
        const isOpen = sidebar.classList.toggle('mobile-open');
        if (backdrop) backdrop.classList.toggle('active', isOpen);
    },

    closeMobileSidebar() {
        const sidebar = document.getElementById('sidebar');
        const backdrop = document.getElementById('mobile-backdrop');
        if (sidebar) sidebar.classList.remove('mobile-open');
        if (backdrop) backdrop.classList.remove('active');
    },

    restoreSidebarState() {
        if (localStorage.getItem('sidebar_collapsed') === '1') {
            document.getElementById('sidebar')?.classList.add('sidebar-collapsed');
        }
    },

    getHash() {
        return location.hash.replace('#', '') || 'dashboard';
    },

    setupRouter() {
        window.addEventListener('hashchange', () => {
            this.navigate(this.getHash());
        });
    },

    navigate(page) {
        if (!this.pages[page]) {
            page = 'dashboard';
        }

        // Close mobile sidebar on navigation
        this.closeMobileSidebar();

        // Hide any lingering tooltips
        ResourceTooltip.hide();

        // Destroy current page
        if (this.currentPage && this.pages[this.currentPage]?.destroy) {
            this.pages[this.currentPage].destroy();
        }

        this.currentPage = page;

        // Update sidebar
        document.querySelectorAll('.sidebar-link').forEach(link => {
            link.classList.toggle('active', link.dataset.page === page);
        });

        // Init new page
        this.pages[page].init();
    },

    async checkConnection() {
        const badge = document.getElementById('connection-status');
        try {
            const result = await API.checkAuth();
            badge.className = 'conn-badge connected';
            badge.innerHTML = '<span class="conn-dot"></span><span class="conn-text">Connected</span>';
        } catch (err) {
            badge.className = 'conn-badge disconnected';
            badge.innerHTML = '<span class="conn-dot"></span><span class="conn-text">Disconnected</span>';
        }
    },

    // Theme management
    setupThemeListener() {
        // Listen for system theme changes when in auto mode
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (this.currentTheme === 'auto') {
                this.applyTheme('auto');
            }
        });
    },

    applyTheme(pref) {
        if (pref === 'light') {
            document.documentElement.setAttribute('data-bs-theme', 'light');
        } else if (pref === 'dark') {
            document.documentElement.setAttribute('data-bs-theme', 'dark');
        } else {
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
        }
        document.documentElement.dataset.themePref = pref;
    },

    async cycleTheme() {
        const order = ['auto', 'light', 'dark'];
        const idx = order.indexOf(this.currentTheme);
        const next = order[(idx + 1) % order.length];

        this.currentTheme = next;
        this.applyTheme(next);

        // Update label in dropdown
        const label = document.getElementById('theme-label');
        if (label) label.textContent = next.charAt(0).toUpperCase() + next.slice(1);

        // Save to server
        try {
            await API.post('api/me.php', { theme: next });
        } catch (e) {
            // Non-critical, ignore
        }
    },

    async checkClusterHealth() {
        if (this._healthLoading) return;
        this._healthLoading = true;
        try {
            // Fetch all data sources in parallel
            const [data, vmAlerts, rs] = await Promise.all([
                API.getSilentAbortable('app-health', 'api/cluster-health.php'),
                Permissions.has('monitoring.view')
                    ? API.getSilent('api/monitoring.php', { action: 'vm-alerts' }).catch(() => null)
                    : null,
                Permissions.has('monitoring.view')
                    ? API.getSilent('api/monitoring-rightsizing.php').catch(() => null)
                    : null,
            ]);
            // Cache health data for checkForUpdates()
            this._lastHealthData = data.nodes || [];
            this._lastHealthTime = Date.now();

            const warnings = [];
            const infos = [];

            // ── Critical / Error (danger) ────────────────────────────
            const now = Date.now();
            const SUSTAIN_MS = 5 * 60 * 1000; // 5 minutes
            const activeKeys = new Set();

            for (const node of (data.nodes || [])) {
                if (node.status !== 'online' && !node.maintenance) {
                    warnings.push({ level: 'danger', msg: `Node <strong>${Utils.escapeHtml(node.node)}</strong> is offline`, cat: 'node' });
                }
                if (node.status === 'online') {
                    const cpuPct = Math.round((node.cpu || 0) * 100);
                    const ramPct = node.maxmem > 0 ? Math.round((node.mem / node.maxmem) * 100) : 0;
                    const diskPct = node.maxdisk > 0 ? Math.round((node.disk / node.maxdisk) * 100) : 0;
                    const n = node.node;

                    // CPU/RAM: only alert after sustained 5 minutes above threshold
                    const checks = [
                        { key: `${n}:cpu:danger`,  pct: cpuPct, thresh: 95, level: 'danger',  msg: `Node <strong>${Utils.escapeHtml(n)}</strong> CPU critically high (${cpuPct}%)` },
                        { key: `${n}:cpu:warning`, pct: cpuPct, thresh: 85, level: 'warning', msg: `Node <strong>${Utils.escapeHtml(n)}</strong> CPU high (${cpuPct}%)` },
                        { key: `${n}:ram:danger`,  pct: ramPct, thresh: 95, level: 'danger',  msg: `Node <strong>${Utils.escapeHtml(n)}</strong> RAM critically high (${ramPct}%)` },
                        { key: `${n}:ram:warning`, pct: ramPct, thresh: 85, level: 'warning', msg: `Node <strong>${Utils.escapeHtml(n)}</strong> RAM high (${ramPct}%)` },
                    ];

                    for (const c of checks) {
                        if (c.pct >= c.thresh) {
                            activeKeys.add(c.key);
                            if (!this._resourceAlertSince[c.key]) {
                                this._resourceAlertSince[c.key] = now;
                            }
                            // Only show if sustained for 5 minutes
                            if (now - this._resourceAlertSince[c.key] >= SUSTAIN_MS) {
                                // Don't add warning-level if danger-level already added for same metric
                                const metricBase = c.key.replace(/:(?:danger|warning)$/, '');
                                if (c.level === 'warning' && warnings.some(w => w._metricBase === metricBase + ':danger')) continue;
                                warnings.push({ level: c.level, msg: c.msg, cat: 'resource', _metricBase: c.key });
                            }
                        }
                    }

                    // Disk: keep instant alerts (disk doesn't fluctuate)
                    if (diskPct >= 95) {
                        warnings.push({ level: 'danger', msg: `Node <strong>${Utils.escapeHtml(n)}</strong> disk critically full (${diskPct}%)`, cat: 'resource' });
                    } else if (diskPct >= 85) {
                        warnings.push({ level: 'warning', msg: `Node <strong>${Utils.escapeHtml(n)}</strong> disk almost full (${diskPct}%)`, cat: 'resource' });
                    }
                }
            }

            // Clear timers for metrics that dropped below threshold
            for (const key of Object.keys(this._resourceAlertSince)) {
                if (!activeKeys.has(key)) delete this._resourceAlertSince[key];
            }

            // ── Warning ──────────────────────────────────────────────
            for (const node of (data.nodes || [])) {
                if (node.maintenance) {
                    const s = node.maintenance.status || 'maintenance';
                    const label = s === 'entering' ? 'entering maintenance' : s === 'leaving' ? 'leaving maintenance' : 'in maintenance mode';
                    warnings.push({ level: 'warning', msg: `Node <strong>${Utils.escapeHtml(node.node)}</strong> is ${label}`, cat: 'maintenance' });
                }
            }

            // Storage critical (≥95%) or warning (≥85%)
            for (const s of (data.storage || [])) {
                if (s.total > 0) {
                    const pct = Math.round((s.used / s.total) * 100);
                    if (pct >= 95) {
                        warnings.push({ level: 'danger', msg: `Storage <strong>${Utils.escapeHtml(s.storage)}</strong> critically full (${pct}%)`, cat: 'storage' });
                    } else if (pct >= 85) {
                        warnings.push({ level: 'warning', msg: `Storage <strong>${Utils.escapeHtml(s.storage)}</strong> almost full (${pct}%)`, cat: 'storage' });
                    }
                }
            }

            // CEPH health warnings — any OSD down or HEALTH_ERR is always critical
            if (data.ceph && data.ceph.available) {
                const osds = data.ceph.osds || {};
                const osdsDown = osds.total > 0 && osds.up < osds.total;
                const isCritical = data.ceph.health === 'HEALTH_ERR' || osdsDown;

                if (data.ceph.health === 'HEALTH_ERR') {
                    warnings.push({ level: 'danger', msg: 'CEPH cluster health: <strong>ERROR</strong>', cat: 'ceph' });
                } else if (data.ceph.health === 'HEALTH_WARN') {
                    warnings.push({ level: isCritical ? 'danger' : 'warning', msg: `CEPH cluster health: <strong>${isCritical ? 'CRITICAL' : 'WARNING'}</strong>`, cat: 'ceph' });
                }
                if (osdsDown) {
                    warnings.push({ level: 'danger', msg: `CEPH: ${osds.total - osds.up} OSD(s) down (${osds.up}/${osds.total} up)`, cat: 'ceph' });
                }
            }

            // ── VM-level CPU/RAM alerts (sustained 5 min) ────────────
            if (vmAlerts) {
                for (const a of (vmAlerts.alerts || [])) {
                    const label = a.vm_type === 'lxc' ? 'CT' : 'VM';
                    const name = a.name || String(a.vmid);
                    if (a.cpu) {
                        warnings.push({
                            level: a.cpu.level,
                            msg: `${label} <strong>${Utils.escapeHtml(name)}</strong> (${a.vmid}) CPU ${a.cpu.level === 'danger' ? 'critically ' : ''}high (${a.cpu.pct}%)`,
                            cat: 'vm-resource'
                        });
                    }
                    if (a.ram) {
                        warnings.push({
                            level: a.ram.level,
                            msg: `${label} <strong>${Utils.escapeHtml(name)}</strong> (${a.vmid}) RAM ${a.ram.level === 'danger' ? 'critically ' : ''}high (${a.ram.pct}%)`,
                            cat: 'vm-resource'
                        });
                    }
                }
            }

            // ── Info: Right-sizing suggestions ───────────────────────
            if (rs) {
                const recs = rs.recommendations || [];
                if (recs.length > 0) {
                    const critical = recs.filter(r => r.severity === 'critical' || r.severity === 'undersized').length;
                    const oversized = recs.filter(r => r.severity === 'oversized').length;
                    let msg = `<strong>${recs.length}</strong> right-sizing suggestion${recs.length !== 1 ? 's' : ''}`;
                    if (critical > 0) msg += ` (${critical} undersized)`;
                    if (oversized > 0) msg += ` (${oversized} oversized)`;
                    const key = 'rightsizing:' + msg;
                    if (this._lastRightsizingKey && this._lastRightsizingKey !== key) {
                        for (const dk of this._dismissedInfos) {
                            if (dk.startsWith('rightsizing:')) this._dismissedInfos.delete(dk);
                        }
                        if (typeof Health !== 'undefined') Health._dismissedVmids.clear();
                    }
                    this._lastRightsizingKey = key;
                    infos.push({ level: 'info', msg, cat: 'rightsizing', link: '#health' });
                } else {
                    this._lastRightsizingKey = null;
                }
            }

            // Host updates are shown via the dedicated topbar icon (no longer in info panel)

            this._warnings = warnings;
            this._infos = infos;

            // ── Update warning button (danger/warning) ──────────────
            const btn = document.getElementById('cluster-warnings-btn');
            const cnt = document.getElementById('cluster-warnings-count');
            if (btn) {
                if (warnings.length === 0) {
                    btn.classList.add('d-none');
                } else {
                    btn.classList.remove('d-none');
                    cnt.textContent = warnings.length;
                    const hasDanger = warnings.some(w => w.level === 'danger');
                    const iconEl = btn.querySelector('i');
                    if (hasDanger) {
                        btn.style.color = 'var(--bs-danger)';
                        cnt.className = 'badge bg-danger ms-1';
                        cnt.style.cssText = 'font-size:0.65rem;vertical-align:middle';
                        if (iconEl) iconEl.className = 'bi bi-exclamation-triangle-fill';
                    } else {
                        btn.style.color = 'var(--bs-warning)';
                        cnt.className = 'badge bg-warning text-dark ms-1';
                        cnt.style.cssText = 'font-size:0.65rem;vertical-align:middle';
                        if (iconEl) iconEl.className = 'bi bi-exclamation-triangle-fill';
                    }
                }
            }

            // ── Prune dismissed infos that no longer exist ───────────
            const currentInfoKeys = new Set(infos.map(i => i.cat + ':' + i.msg));
            for (const key of this._dismissedInfos) {
                if (!currentInfoKeys.has(key)) this._dismissedInfos.delete(key);
            }

            // ── Update info button (blue) — hide dismissed ───────────
            const activeInfos = infos.filter(i => !this._dismissedInfos.has(i.cat + ':' + i.msg));
            const infoBtn = document.getElementById('cluster-info-btn');
            const infoCnt = document.getElementById('cluster-info-count');
            if (infoBtn) {
                if (activeInfos.length === 0) {
                    infoBtn.classList.add('d-none');
                } else {
                    infoBtn.classList.remove('d-none');
                    infoCnt.textContent = activeInfos.length;
                }
            }
        } catch (_) { /* silent */ } finally {
            this._healthLoading = false;
        }
    },

    showClusterWarnings(filter) {
        const header = document.getElementById('cluster-warnings-header');
        const title = document.getElementById('cluster-warnings-title');
        const body = document.getElementById('cluster-warnings-body');
        if (!body) return;

        const isInfo = filter === 'info';
        const items = isInfo ? this._infos : this._warnings;

        if (isInfo) {
            header.style.borderBottomColor = 'var(--bs-info)';
            title.className = 'modal-title text-info';
            title.innerHTML = '<i class="bi bi-info-circle-fill me-2"></i>Cluster Info';
        } else {
            const hasDanger = items.some(w => w.level === 'danger');
            header.style.borderBottomColor = hasDanger ? 'var(--bs-danger)' : 'var(--bs-warning)';
            title.className = 'modal-title ' + (hasDanger ? 'text-danger' : 'text-warning');
            title.innerHTML = `<i class="bi bi-exclamation-triangle-fill me-2"></i>Cluster Alerts`;
        }

        const visibleItems = isInfo ? items.filter(i => !this._dismissedInfos.has(i.cat + ':' + i.msg)) : items;

        if (visibleItems.length === 0) {
            body.innerHTML = `<p class="text-muted mb-0">No active ${isInfo ? 'notifications' : 'alerts'}.</p>`;
        } else {
            body.innerHTML = visibleItems.map((w, idx) => {
                const cls = w.level === 'danger' ? 'danger' : w.level === 'warning' ? 'warning' : 'info';
                const icons = { danger: 'x-circle-fill', warning: 'exclamation-triangle-fill', info: 'info-circle-fill' };
                const linkHtml = w.link ? ` <a href="${w.link}" class="alert-link small" onclick="event.preventDefault();bootstrap.Modal.getInstance(document.getElementById('clusterWarningsModal'))?.hide();setTimeout(()=>location.hash='${w.link}',150)">View &rarr;</a>` : '';
                const dismissHtml = isInfo ? ` <button class="btn btn-sm btn-outline-secondary border-0 py-0 px-1" title="Dismiss" onclick="App.dismissInfo(${idx})"><i class="bi bi-x-lg"></i></button>` : '';
                return `<div class="alert alert-${cls} py-2 mb-2 d-flex align-items-center justify-content-between" data-info-idx="${idx}">
                    <span><i class="bi bi-${icons[w.level] || icons.info} me-2"></i>${w.msg}</span>
                    <span class="d-flex align-items-center gap-1">${linkHtml}${dismissHtml}</span>
                </div>`;
            }).join('');
        }
        this._visibleInfoItems = isInfo ? visibleItems : null;
        new bootstrap.Modal(document.getElementById('clusterWarningsModal')).show();
    },

    dismissInfo(idx) {
        const items = this._visibleInfoItems;
        if (!items || !items[idx]) return;
        const item = items[idx];
        const key = item.cat + ':' + item.msg;
        this._dismissedInfos.add(key);

        // Remove the alert from the modal
        const el = document.querySelector(`[data-info-idx="${idx}"]`);
        if (el) el.remove();

        // Check if modal is now empty
        const body = document.getElementById('cluster-warnings-body');
        if (body && !body.querySelector('.alert')) {
            body.innerHTML = '<p class="text-muted mb-0">No active notifications.</p>';
        }

        // Update info button in top bar
        const activeInfos = this._infos.filter(i => !this._dismissedInfos.has(i.cat + ':' + i.msg));
        const infoBtn = document.getElementById('cluster-info-btn');
        const infoCnt = document.getElementById('cluster-info-count');
        if (infoBtn) {
            if (activeInfos.length === 0) {
                infoBtn.classList.add('d-none');
                // Close modal if no more items
                bootstrap.Modal.getInstance(document.getElementById('clusterWarningsModal'))?.hide();
            } else {
                infoCnt.textContent = activeInfos.length;
            }
        }
    },

    async showProfile() {
        document.getElementById('profile-sshkeys').value = window.APP_USER.ssh_public_keys || '';
        document.getElementById('profile-theme').value = this.currentTheme || 'auto';
        const sel = document.getElementById('profile-default-storage');
        sel.innerHTML = '<option value="">— none —</option>';
        try {
            const nodes = await API.getNodes();
            const firstNode = (nodes || []).find(n => n.status === 'online')?.node || (nodes || [])[0]?.node;
            if (firstNode) {
                const storages = await API.getStorages(firstNode);
                const unique = [...new Map((storages || []).map(s => [s.storage, s])).values()];
                for (const s of unique) {
                    const opt = document.createElement('option');
                    opt.value = s.storage;
                    opt.textContent = `${s.storage} (${s.type || ''})`;
                    sel.appendChild(opt);
                }
            }
        } catch (_) {}
        sel.value = window.APP_USER.default_storage || '';
        new bootstrap.Modal(document.getElementById('profileModal')).show();
    },

    async saveProfile() {
        const keys           = document.getElementById('profile-sshkeys').value.trim();
        const defaultStorage = document.getElementById('profile-default-storage').value;
        const theme          = document.getElementById('profile-theme').value;
        try {
            await API.post('api/profile.php', { ssh_public_keys: keys, default_storage: defaultStorage });
            window.APP_USER.ssh_public_keys = keys;
            window.APP_USER.default_storage = defaultStorage;

            // Apply theme immediately and save to server
            if (theme !== this.currentTheme) {
                this.currentTheme = theme;
                this.applyTheme(theme);
                try {
                    await API.post('api/me.php', { theme });
                } catch (_) {}
            }

            bootstrap.Modal.getInstance(document.getElementById('profileModal'))?.hide();
            Toast.success('Profile saved');
        } catch (e) {
            Toast.error('Failed to save profile');
        }
    },

    async generateSshKey() {
        const btn = document.getElementById('btn-generate-sshkey');
        const infoEl = document.getElementById('sshkey-gen-info');
        const textarea = document.getElementById('profile-sshkeys');

        btn.disabled = true;
        btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i>Generating...';
        infoEl.classList.add('d-none');

        try {
            const result = await API.post('api/generate-ssh-key.php', {});

            // Download private key as file
            const blob = new Blob([result.private_key], { type: 'application/octet-stream' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = 'id_ed25519';
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);

            // Update textarea with new public key
            const existing = textarea.value.trim();
            textarea.value = existing ? existing + '\n' + result.public_key : result.public_key;

            // Update global state
            window.APP_USER.ssh_public_keys = textarea.value;

            // Show success info
            infoEl.className = 'alert alert-success mt-2 small';
            infoEl.innerHTML = `<i class="bi bi-check-circle me-1"></i><strong>Key generated!</strong> The private key (<code>id_ed25519</code>) has been downloaded. ` +
                `Store it safely — it cannot be retrieved again.<br>` +
                `<span class="text-muted">Usage: <code>ssh -i ~/Downloads/id_ed25519 &lt;user&gt;@&lt;vm-ip&gt;</code></span>`;
            infoEl.classList.remove('d-none');

            Toast.success('SSH key generated and saved');
        } catch (e) {
            infoEl.className = 'alert alert-danger mt-2 small';
            infoEl.textContent = e.message || 'Failed to generate SSH key.';
            infoEl.classList.remove('d-none');
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-key me-1"></i>Generate Key';
        }
    },

    async changePassword() {
        const errEl = document.getElementById('profile-pw-error');
        const okEl = document.getElementById('profile-pw-success');
        errEl.classList.add('d-none');
        okEl.classList.add('d-none');

        const current = document.getElementById('profile-pw-current').value;
        const newPw = document.getElementById('profile-pw-new').value;
        const confirm = document.getElementById('profile-pw-confirm').value;

        if (!current || !newPw || !confirm) {
            errEl.textContent = 'All fields are required.';
            errEl.classList.remove('d-none');
            return;
        }
        if (newPw.length < 8) {
            errEl.textContent = 'New password must be at least 8 characters.';
            errEl.classList.remove('d-none');
            return;
        }
        if (newPw !== confirm) {
            errEl.textContent = 'New passwords do not match.';
            errEl.classList.remove('d-none');
            return;
        }

        try {
            await API.post('api/change-password.php', {
                current_password: current,
                new_password: newPw,
            });
            document.getElementById('profile-pw-current').value = '';
            document.getElementById('profile-pw-new').value = '';
            document.getElementById('profile-pw-confirm').value = '';
            okEl.textContent = 'Password changed successfully.';
            okEl.classList.remove('d-none');
        } catch (e) {
            errEl.textContent = e.message || 'Failed to change password.';
            errEl.classList.remove('d-none');
        }
    },

    async checkForUpdates() {
        if (!Permissions.has('cluster.update') || !window.APP_USER?.ssh_enabled) return;
        if (this._updatesLoading) return;
        this._updatesLoading = true;
        try {
            // Use cached health data if recent (< 2 min), otherwise fetch
            let nodes;
            if (this._lastHealthData && (Date.now() - this._lastHealthTime < 120000)) {
                nodes = this._lastHealthData;
            } else {
                const data = await API.getSilent('api/cluster-health.php');
                nodes = data.nodes || [];
            }
            const onlineNodes = nodes.filter(n => n.status === 'online');
            const counts = {};
            let totalUpdates = 0;

            await Promise.allSettled(onlineNodes.map(async n => {
                try {
                    const upd = await API.getSilent('api/node-update.php', { node: n.node });
                    const count = upd?.count || 0;
                    counts[n.node] = count;
                    totalUpdates += count;
                } catch (_) {
                    counts[n.node] = 0;
                }
            }));

            this._cachedUpdateCounts = counts;
            this._lastUpdateCheck = Date.now();

            // Update topbar icon
            const btn = document.getElementById('cluster-updates-btn');
            const cnt = document.getElementById('cluster-updates-count');
            if (btn) {
                if (totalUpdates > 0) {
                    btn.classList.remove('d-none');
                    cnt.textContent = totalUpdates;
                    btn.title = `${totalUpdates} update${totalUpdates !== 1 ? 's' : ''} available`;
                } else {
                    btn.classList.add('d-none');
                }
            }
        } catch (_) { /* silent */ } finally {
            this._updatesLoading = false;
        }
    },

    async logout() {
        try {
            await API.post('api/logout.php', {});
        } catch (e) {
            // ignore
        }
        window.location.href = 'login.php';
    }
};

// Start
document.addEventListener('DOMContentLoaded', () => App.init());
