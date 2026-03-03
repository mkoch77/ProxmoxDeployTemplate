const App = {
    currentPage: null,
    pages: {
        dashboard: Dashboard,
        deploy: Templates,
        tasks: Tasks,
    },

    init() {
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
            badge.innerHTML = `<span class="conn-dot"></span><span class="conn-text">${Utils.escapeHtml(result.token_id || 'Verbunden')}</span>`;
        } catch (err) {
            badge.className = 'conn-badge disconnected';
            badge.innerHTML = '<span class="conn-dot"></span><span class="conn-text">Nicht verbunden</span>';
        }
    }
};

// Start
document.addEventListener('DOMContentLoaded', () => App.init());
