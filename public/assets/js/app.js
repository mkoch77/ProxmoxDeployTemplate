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

    init() {
        // Remove null pages (not loaded due to missing permissions)
        Object.keys(this.pages).forEach(k => {
            if (!this.pages[k]) delete this.pages[k];
        });

        this.setupThemeListener();
        this.checkConnection();
        this.setupRouter();
        this.navigate(this.getHash());
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
