const Templates = {
    templates: [],

    async init() {
        this.render();
        await this.loadData();
    },

    async loadData() {
        try {
            this.templates = await API.getTemplates();
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
                    <i class="bi bi-arrow-clockwise"></i> Aktualisieren
                </button>
            </div>
            <p style="color:var(--text-muted)" class="mb-3">Template auswaehlen und eine neue VM/CT deployen.</p>
            <div id="templates-grid" class="row g-3">
                <div class="loading-spinner"><div class="spinner-border text-primary"></div></div>
            </div>`;
    },

    updateView() {
        const grid = document.getElementById('templates-grid');

        if (this.templates.length === 0) {
            grid.innerHTML = `
                <div class="col-12 text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-layers" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">Keine Templates gefunden</p>
                </div>`;
            return;
        }

        let html = '';
        for (const t of this.templates) {
            const typeColor = t.type === 'qemu' ? 'var(--accent-blue)' : 'var(--accent-cyan)';
            html += `
                <div class="col-12 col-sm-6 col-lg-4 col-xl-3">
                    <div class="template-card h-100 d-flex flex-column">
                        <div class="d-flex justify-content-between align-items-start mb-2">
                            <span class="template-type" style="color:${typeColor}">
                                <i class="bi ${Utils.typeIcon(t.type)}"></i> ${Utils.typeLabel(t.type)}
                            </span>
                            <span class="badge" style="background:var(--bg-elevated);color:var(--text-secondary);font-weight:500">ID: ${t.vmid}</span>
                        </div>
                        <h5 class="mb-1" style="font-size:1rem">${Utils.escapeHtml(t.name || 'Unbenannt')}</h5>
                        <p style="color:var(--text-muted);font-size:0.82rem" class="mb-3 mt-auto">
                            <i class="bi bi-hdd-rack"></i> ${Utils.escapeHtml(t.node)}
                            ${t.maxdisk ? ' &middot; ' + Utils.formatBytes(t.maxdisk) : ''}
                        </p>
                        <button class="btn btn-primary btn-sm w-100"
                            onclick="Deploy.open(${JSON.stringify(t).replace(/"/g, '&quot;')})">
                            <i class="bi bi-rocket-takeoff-fill"></i> Deployen
                        </button>
                    </div>
                </div>`;
        }

        grid.innerHTML = html;
    }
};
