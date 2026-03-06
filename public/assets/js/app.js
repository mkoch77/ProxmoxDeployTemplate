const App = {
    currentPage: null,
    currentTheme: window.APP_USER?.theme || 'auto',
    pages: {
        dashboard: Dashboard,
        deploy: typeof Templates !== 'undefined' ? Templates : null,
        tasks: Tasks,
        health: typeof Health !== 'undefined' ? Health : null,
        maintenance: typeof Maintenance !== 'undefined' ? Maintenance : null,
        loadbalancing: typeof Loadbalancer !== 'undefined' ? Loadbalancer : null,
        users: typeof Users !== 'undefined' ? Users : null,
    },

    _warningInterval: null,
    _warnings: [],

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
    },

    toggleSidebar() {
        const sidebar = document.getElementById('sidebar');
        const collapsed = sidebar.classList.toggle('sidebar-collapsed');
        localStorage.setItem('sidebar_collapsed', collapsed ? '1' : '0');
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
            badge.innerHTML = `<span class="conn-dot"></span><span class="conn-text">${Utils.escapeHtml(result.token_id || 'Connected')}</span>`;
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
        try {
            const data = await API.getSilent('api/cluster-health.php');
            const warnings = [];

            // Offline nodes (not in maintenance)
            for (const node of (data.nodes || [])) {
                if (node.status !== 'online' && !node.maintenance) {
                    warnings.push({ level: 'danger', msg: `Node <strong>${Utils.escapeHtml(node.node)}</strong> is offline` });
                }
            }

            // Nodes in maintenance
            for (const node of (data.nodes || [])) {
                if (node.maintenance) {
                    const s = node.maintenance.status || 'maintenance';
                    const label = s === 'entering' ? 'entering maintenance' : s === 'leaving' ? 'leaving maintenance' : 'in maintenance mode';
                    warnings.push({ level: 'info', msg: `Node <strong>${Utils.escapeHtml(node.node)}</strong> is ${label}` });
                }
            }

            // Storage critical (≥95%) or warning (≥85%)
            for (const s of (data.storage || [])) {
                if (s.total > 0) {
                    const pct = Math.round((s.used / s.total) * 100);
                    if (pct >= 95) {
                        warnings.push({ level: 'danger', msg: `Storage <strong>${Utils.escapeHtml(s.storage)}</strong> is critically full (${pct}%)` });
                    } else if (pct >= 85) {
                        warnings.push({ level: 'warning', msg: `Storage <strong>${Utils.escapeHtml(s.storage)}</strong> is almost full (${pct}%)` });
                    }
                }
            }

            this._warnings = warnings;
            const btn = document.getElementById('cluster-warnings-btn');
            const cnt = document.getElementById('cluster-warnings-count');
            if (!btn) return;

            if (warnings.length === 0) {
                btn.classList.add('d-none');
            } else {
                btn.classList.remove('d-none');
                cnt.textContent = warnings.length;
                const hasDanger  = warnings.some(w => w.level === 'danger');
                const hasWarning = warnings.some(w => w.level === 'warning');
                const iconEl = btn.querySelector('i');
                if (hasDanger) {
                    btn.style.color = 'var(--bs-danger)';
                    if (iconEl) iconEl.className = 'bi bi-exclamation-triangle-fill';
                } else if (hasWarning) {
                    btn.style.color = 'var(--bs-warning)';
                    if (iconEl) iconEl.className = 'bi bi-exclamation-triangle-fill';
                } else {
                    // Info only (e.g. maintenance)
                    btn.style.color = 'var(--bs-info)';
                    if (iconEl) iconEl.className = 'bi bi-wrench-adjustable-circle-fill';
                }
            }
        } catch (_) { /* silent */ }
    },

    showClusterWarnings() {
        const body = document.getElementById('cluster-warnings-body');
        if (!body) return;
        if (this._warnings.length === 0) {
            body.innerHTML = '<p class="text-muted mb-0">No active alerts.</p>';
        } else {
            body.innerHTML = this._warnings.map(w => {
                const cls  = w.level === 'danger' ? 'danger' : w.level === 'warning' ? 'warning' : 'info';
                const icon = w.level === 'danger' ? 'x-circle-fill' : w.level === 'warning' ? 'exclamation-triangle-fill' : 'wrench-adjustable-circle-fill';
                return `<div class="alert alert-${cls} py-2 mb-2"><i class="bi bi-${icon} me-2"></i>${w.msg}</div>`;
            }).join('');
        }
        new bootstrap.Modal(document.getElementById('clusterWarningsModal')).show();
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
