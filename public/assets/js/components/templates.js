const Templates = {
    templates: [],
    nodes: [],
    currentFilter: { type: '', node: '', search: '' },
    currentSort: { col: 'vmid', dir: 'asc' },

    // Cloud-image tag state
    ciSelectedTags: [],
    ciPendingColors: {},
    ciExistingTags: {},

    // Custom images state
    customImages: [],
    customUnregistered: [],

    // Community scripts state
    activeTab: 'community',
    communityData: null,        // raw categories array from API
    communityFilter: { type: '', category: '', search: '' },
    communityLoading: false,

    async init() {
        this.render();
        await this.loadData();
    },

    async loadData() {
        try {
            const [templates, nodes] = await Promise.all([
                API.getTemplates(),
                API.getNodes(),
            ]);
            this.templates = templates;
            this.nodes = nodes;
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
                    <i class="bi bi-arrow-clockwise"></i> Refresh
                </button>
            </div>

            <div class="deploy-tabs mb-4">
                <button class="deploy-tab-btn ${this.activeTab === 'community' ? 'active' : ''}" onclick="Templates.switchTab('community')">
                    <i class="bi bi-cloud-download me-2"></i>Community Scripts
                    <span class="badge bg-secondary ms-1" style="font-size:0.65rem">tteck</span>
                </button>
                <button class="deploy-tab-btn ${this.activeTab === 'local' ? 'active' : ''}" onclick="Templates.switchTab('local')">
                    <i class="bi bi-hdd-stack me-2"></i>Local Templates
                </button>
                <button class="deploy-tab-btn ${this.activeTab === 'cloudinit' ? 'active' : ''}" onclick="Templates.switchTab('cloudinit')">
                    <i class="bi bi-clouds-fill me-2"></i>Cloud Images
                </button>
                <button class="deploy-tab-btn ${this.activeTab === 'custom' ? 'active' : ''}" onclick="Templates.switchTab('custom')">
                    <i class="bi bi-hdd-fill me-2"></i>Custom Images
                </button>
                <button class="deploy-tab-btn ${this.activeTab === 'windows' ? 'active' : ''}" onclick="Templates.switchTab('windows')">
                    <i class="bi bi-windows me-2"></i>Windows ISO
                </button>
            </div>

            <div id="tab-local" style="${this.activeTab === 'local' ? '' : 'display:none'}">
                <p style="color:var(--text-muted)" class="mb-3">Select a Proxmox template and deploy a new VM/CT.</p>
                <div class="filter-bar d-flex flex-wrap gap-2 align-items-center">
                    <div class="btn-group btn-group-sm" role="group">
                        <button class="btn btn-outline-light active" data-tpl-filter-type="">All</button>
                        <button class="btn btn-outline-light" data-tpl-filter-type="qemu">VMs</button>
                        <button class="btn btn-outline-light" data-tpl-filter-type="lxc">CTs</button>
                    </div>
                    <select id="tpl-filter-node" class="form-select form-select-sm" style="width:auto;">
                        <option value="">All Nodes</option>
                    </select>
                    <input id="tpl-filter-search" type="text" class="form-control form-control-sm" placeholder="Search by name or ID..." style="width:220px;">
                </div>
                <div id="templates-table-container" class="guest-table mt-3">
                    <div class="loading-spinner"><div class="spinner-border text-primary"></div></div>
                </div>
            </div>

            <div id="tab-community" style="${this.activeTab === 'community' ? '' : 'display:none'}">
                <p style="color:var(--text-muted)" class="mb-3">
                    Browse and install community-maintained scripts from
                    <a href="https://community-scripts.github.io/ProxmoxVE/scripts" target="_blank" rel="noopener" style="color:var(--accent-green)">community-scripts/ProxmoxVE</a>.
                    The install command runs directly on your Proxmox host shell.
                </p>
                <div id="community-content">
                    <div class="loading-spinner"><div class="spinner-border text-primary"></div></div>
                </div>
            </div>

            <div id="tab-cloudinit" style="${this.activeTab === 'cloudinit' ? '' : 'display:none'}">
                <p style="color:var(--text-muted)" class="mb-3">Deploy a fresh virtual machine from a cloud image with automated cloud-init configuration.</p>
                <div id="ci-grid" class="ci-images-grid"></div>
            </div>

            <div id="tab-custom" style="${this.activeTab === 'custom' ? '' : 'display:none'}">
                <p style="color:var(--text-muted)" class="mb-3">
                    Upload custom OS images (Windows with Sysprep/cloudbase-init, custom Linux, FreeBSD, etc.)
                    and deploy them to your cluster.
                </p>
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-success" onclick="Templates.showUploadCustomImage()">
                        <i class="bi bi-upload me-1"></i>Upload Image
                    </button>
                </div>
                <div id="custom-images-grid" class="ci-images-grid"></div>
                <div id="custom-unregistered"></div>
            </div>

            <div id="tab-windows" style="${this.activeTab === 'windows' ? '' : 'display:none'}">
                <p style="color:var(--text-muted)" class="mb-3">
                    Deploy Windows VMs from ISO with optional unattended install (autounattend.xml).
                    Upload the Windows ISO in the Custom Images tab first, then register an Unattend.xml here.
                </p>
                <div class="d-flex gap-2 mb-3">
                    <button class="btn btn-sm btn-outline-success" onclick="Templates.showAddWindowsImage()">
                        <i class="bi bi-plus-lg me-1"></i>Register Unattend.xml
                    </button>
                </div>
                <div id="windows-images-grid" class="ci-images-grid"></div>
            </div>
        `;

        // Local tab filter events
        content.querySelectorAll('[data-tpl-filter-type]').forEach(btn => {
            btn.addEventListener('click', () => {
                content.querySelectorAll('[data-tpl-filter-type]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.currentFilter.type = btn.dataset.tplFilterType;
                this.updateView();
            });
        });

        document.getElementById('tpl-filter-node')?.addEventListener('change', (e) => {
            this.currentFilter.node = e.target.value;
            this.updateView();
        });

        document.getElementById('tpl-filter-search')?.addEventListener('input', Utils.debounce((e) => {
            this.currentFilter.search = e.target.value.toLowerCase();
            this.updateView();
        }, 300));

        if (this.activeTab === 'community') {
            this.loadCommunityScripts();
        }
        if (this.activeTab === 'cloudinit') {
            this.renderCloudImages();
        }
        if (this.activeTab === 'custom') {
            this.loadCustomImages();
        }
        if (this.activeTab === 'windows') {
            this.loadWindowsImages();
        }
    },

    switchTab(tab) {
        this.activeTab = tab;
        document.getElementById('tab-local').style.display = tab === 'local' ? '' : 'none';
        document.getElementById('tab-community').style.display = tab === 'community' ? '' : 'none';
        document.getElementById('tab-cloudinit').style.display = tab === 'cloudinit' ? '' : 'none';
        document.getElementById('tab-custom').style.display = tab === 'custom' ? '' : 'none';
        document.getElementById('tab-windows').style.display = tab === 'windows' ? '' : 'none';

        document.querySelectorAll('.deploy-tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.deploy-tab-btn').forEach(btn => {
            if ((tab === 'local' && btn.textContent.includes('Local')) ||
                (tab === 'community' && btn.textContent.includes('Community')) ||
                (tab === 'cloudinit' && btn.textContent.includes('Cloud Images')) ||
                (tab === 'custom' && btn.textContent.includes('Custom Images')) ||
                (tab === 'windows' && btn.textContent.includes('Windows ISO'))) {
                btn.classList.add('active');
            }
        });

        if (tab === 'community' && !this.communityData && !this.communityLoading) {
            this.loadCommunityScripts();
        }
        if (tab === 'cloudinit') {
            this.renderCloudImages();
        }
        if (tab === 'custom') {
            this.loadCustomImages();
        }
        if (tab === 'windows') {
            this.loadWindowsImages();
        }
    },

    async loadCommunityScripts() {
        if (this.communityLoading) return;
        this.communityLoading = true;

        const container = document.getElementById('community-content');
        if (!container) return;
        container.innerHTML = '<div class="loading-spinner"><div class="spinner-border text-primary"></div></div>';

        try {
            const resp = await fetch('https://community-scripts.github.io/ProxmoxVE/api/categories');
            if (!resp.ok) throw new Error('Failed to load');
            this.communityData = await resp.json();
            this.renderCommunity();
        } catch (e) {
            container.innerHTML = `
                <div class="text-center py-5" style="color:var(--text-muted)">
                    <i class="bi bi-wifi-off" style="font-size:2rem;opacity:0.4"></i>
                    <p class="mt-2">Failed to load community scripts.<br>
                    <small>Check your internet connection or visit
                    <a href="https://community-scripts.github.io/ProxmoxVE/scripts" target="_blank" style="color:var(--accent-green)">community-scripts.github.io</a> directly.</small></p>
                </div>`;
        } finally {
            this.communityLoading = false;
        }
    },

    renderCommunity() {
        const container = document.getElementById('community-content');
        if (!container || !this.communityData) return;

        // Collect all scripts with category info
        const allScripts = [];
        const categories = [];
        for (const cat of this.communityData) {
            if (cat.scripts && cat.scripts.length > 0) {
                categories.push({ id: cat.id, name: cat.name, icon: cat.icon });
                for (const s of cat.scripts) {
                    if (!s.disable) {
                        if (!allScripts.find(x => x.slug === s.slug)) {
                            allScripts.push({ ...s, _categoryId: cat.id, _categoryName: cat.name });
                        }
                    }
                }
            }
        }
        allScripts.sort((a, b) => a.name.localeCompare(b.name));

        // Build filter UI
        const typeMap = { ct: 'CT', vm: 'VM', pve: 'PVE', addon: 'Addon', turnkey: 'TurnKey' };
        const types = [...new Set(allScripts.map(s => s.type))].filter(Boolean).sort();

        container.innerHTML = `
            <div class="filter-bar d-flex flex-wrap gap-2 align-items-center mb-3">
                <div class="btn-group btn-group-sm" role="group">
                    <button class="btn btn-outline-light active" data-cs-type="">All</button>
                    ${types.map(t => `<button class="btn btn-outline-light" data-cs-type="${t}">${typeMap[t] || t}</button>`).join('')}
                </div>
                <select id="cs-filter-category" class="form-select form-select-sm" style="width:auto;max-width:200px">
                    <option value="">All Categories</option>
                    ${categories.sort((a,b) => a.name.localeCompare(b.name)).map(c =>
                        `<option value="${c.id}">${escapeHtml(c.name)}</option>`
                    ).join('')}
                </select>
                <input id="cs-filter-search" type="text" class="form-control form-control-sm" placeholder="Search scripts..." style="width:220px;">
                <span id="cs-count" class="text-muted small ms-auto">${allScripts.length} scripts</span>
            </div>
            <div id="cs-grid" class="community-scripts-grid"></div>
        `;

        // Store scripts for filtering
        this._allCommunityScripts = allScripts;
        this.renderCommunityGrid(allScripts);

        // Filter events
        container.querySelectorAll('[data-cs-type]').forEach(btn => {
            btn.addEventListener('click', () => {
                container.querySelectorAll('[data-cs-type]').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
                this.communityFilter.type = btn.dataset.csType;
                this.applyCommunityFilter();
            });
        });

        document.getElementById('cs-filter-category')?.addEventListener('change', (e) => {
            this.communityFilter.category = e.target.value;
            this.applyCommunityFilter();
        });

        document.getElementById('cs-filter-search')?.addEventListener('input', Utils.debounce((e) => {
            this.communityFilter.search = e.target.value.toLowerCase();
            this.applyCommunityFilter();
        }, 250));
    },

    applyCommunityFilter() {
        let list = this._allCommunityScripts || [];
        if (this.communityFilter.type) {
            list = list.filter(s => s.type === this.communityFilter.type);
        }
        if (this.communityFilter.category) {
            list = list.filter(s => s._categoryId == this.communityFilter.category);
        }
        if (this.communityFilter.search) {
            const q = this.communityFilter.search;
            list = list.filter(s =>
                s.name.toLowerCase().includes(q) ||
                (s.description || '').toLowerCase().includes(q)
            );
        }
        const countEl = document.getElementById('cs-count');
        if (countEl) countEl.textContent = `${list.length} scripts`;
        this.renderCommunityGrid(list);
    },

    renderCommunityGrid(scripts) {
        const grid = document.getElementById('cs-grid');
        if (!grid) return;

        if (scripts.length === 0) {
            grid.innerHTML = `
                <div class="text-center py-5" style="color:var(--text-muted)">
                    <i class="bi bi-search" style="font-size:2rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No scripts found</p>
                </div>`;
            return;
        }

        const typeColors = { ct: '#4ade80', vm: '#60a5fa', pve: '#a78bfa', addon: '#fbbf24', turnkey: '#f87171' };
        const typeMap = { ct: 'CT', vm: 'VM', pve: 'PVE', addon: 'Addon', turnkey: 'TurnKey' };

        grid.innerHTML = scripts.map(s => {
            const res = s.install_methods?.[0]?.resources || {};
            const color = typeColors[s.type] || 'var(--text-muted)';
            const logo = s.logo ? `<img src="${escapeHtml(s.logo)}" alt="" class="cs-card-logo" onerror="this.style.display='none'">` : '';
            const resInfo = [
                res.cpu ? `<i class="bi bi-cpu" title="CPU"></i> ${res.cpu}` : '',
                res.ram ? `<i class="bi bi-memory" title="RAM"></i> ${res.ram}MB` : '',
                res.hdd ? `<i class="bi bi-device-hdd" title="Disk"></i> ${res.hdd}GB` : '',
            ].filter(Boolean).join(' &nbsp;');

            return `
                <div class="cs-card" onclick="Templates.openCommunityScript(${JSON.stringify(s).replace(/"/g, '&quot;')})">
                    <div class="cs-card-header">
                        ${logo}
                        <div class="cs-card-type" style="color:${color}">
                            <span class="badge" style="background:${color}20;color:${color};border:1px solid ${color}40">${typeMap[s.type] || s.type}</span>
                        </div>
                    </div>
                    <div class="cs-card-name">${escapeHtml(s.name)}</div>
                    <div class="cs-card-desc">${escapeHtml((s.description || '').substring(0, 90))}${(s.description || '').length > 90 ? '…' : ''}</div>
                    ${resInfo ? `<div class="cs-card-res text-muted">${resInfo}</div>` : ''}
                </div>
            `;
        }).join('');
    },

    openCommunityScript(script) {
        const modal = document.getElementById('communityScriptModal');
        const body = document.getElementById('cs-modal-body');
        const title = document.getElementById('cs-modal-title');

        const typeMap = { ct: 'Container', vm: 'Virtual Machine', pve: 'PVE Script', addon: 'Addon', turnkey: 'TurnKey' };
        const typeColors = { ct: '#4ade80', vm: '#60a5fa', pve: '#a78bfa', addon: '#fbbf24', turnkey: '#f87171' };
        const color = typeColors[script.type] || 'var(--text-muted)';

        title.innerHTML = `
            ${script.logo ? `<img src="${escapeHtml(script.logo)}" style="width:24px;height:24px;object-fit:contain;margin-right:8px;border-radius:4px" onerror="this.style.display='none'">` : ''}
            ${escapeHtml(script.name)}
        `;

        // Build install command from install_methods
        const installMethod = script.install_methods?.[0];
        const scriptPath = installMethod?.script || `ct/${script.slug}.sh`;
        const installCmd = `bash -c "$(wget -qLO - https://github.com/community-scripts/ProxmoxVE/raw/main/${scriptPath})"`;

        const res = installMethod?.resources || {};
        const creds = script.default_credentials || {};

        const notes = (script.notes || []).map(n => {
            const cls = n.type === 'warning' ? 'warning' : n.type === 'error' ? 'danger' : 'info';
            const icon = n.type === 'warning' ? 'exclamation-triangle' : n.type === 'error' ? 'x-circle' : 'info-circle';
            return `<div class="alert alert-${cls} py-2 px-3 small mb-2"><i class="bi bi-${icon} me-1"></i>${escapeHtml(n.text)}</div>`;
        }).join('');

        body.innerHTML = `
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge" style="background:${color}20;color:${color};border:1px solid ${color}40">${typeMap[script.type] || script.type}</span>
                <span class="text-muted small">${escapeHtml(script._categoryName || '')}</span>
                ${script.interface_port ? `<span class="badge bg-secondary ms-auto"><i class="bi bi-globe me-1"></i>Port ${script.interface_port}</span>` : ''}
            </div>

            ${script.description ? `<p class="text-muted small mb-3">${escapeHtml(script.description)}</p>` : ''}

            ${notes}

            <div class="row g-2 mb-3">
                ${res.cpu ? `<div class="col-4"><div class="text-muted small mb-1"><i class="bi bi-cpu me-1"></i>CPU</div><strong>${res.cpu} Core${res.cpu > 1 ? 's' : ''}</strong></div>` : ''}
                ${res.ram ? `<div class="col-4"><div class="text-muted small mb-1"><i class="bi bi-memory me-1"></i>RAM</div><strong>${res.ram} MB</strong></div>` : ''}
                ${res.hdd ? `<div class="col-4"><div class="text-muted small mb-1"><i class="bi bi-device-hdd me-1"></i>Disk</div><strong>${res.hdd} GB</strong></div>` : ''}
                ${res.os ? `<div class="col-4"><div class="text-muted small mb-1"><i class="bi bi-box me-1"></i>OS</div><strong>${escapeHtml(res.os)}</strong></div>` : ''}
                ${res.version ? `<div class="col-4"><div class="text-muted small mb-1"><i class="bi bi-tag me-1"></i>Version</div><strong>${escapeHtml(res.version)}</strong></div>` : ''}
            </div>

            ${(creds.username || creds.password) ? `
            <div class="mb-3 p-2 rounded" style="background:var(--bg-elevated);border:1px solid var(--border-color)">
                <div class="text-muted small mb-2"><i class="bi bi-key me-1"></i>Default Credentials</div>
                <div class="d-flex gap-3 small">
                    ${creds.username ? `<span><span class="text-muted">User:</span> <code>${escapeHtml(creds.username)}</code></span>` : ''}
                    ${creds.password ? `<span><span class="text-muted">Pass:</span> <code>${escapeHtml(creds.password)}</code></span>` : ''}
                </div>
            </div>` : ''}

            <div class="mb-3">
                <div class="text-muted small mb-2"><i class="bi bi-terminal me-1"></i>Install Command <span class="text-muted">(run on Proxmox host shell)</span></div>
                <div class="install-cmd-box">
                    <code id="cs-install-cmd">${escapeHtml(installCmd)}</code>
                    <button class="btn btn-sm btn-outline-light" onclick="Templates.copyInstallCmd()" title="Copy">
                        <i class="bi bi-clipboard" id="cs-copy-icon"></i>
                    </button>
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap mb-3">
                ${script.documentation ? `<a href="${escapeHtml(script.documentation)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light"><i class="bi bi-book me-1"></i>Documentation</a>` : ''}
                ${script.website ? `<a href="${escapeHtml(script.website)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light"><i class="bi bi-globe me-1"></i>Website</a>` : ''}
                <a href="https://github.com/community-scripts/ProxmoxVE/blob/main/${escapeHtml(scriptPath)}" target="_blank" rel="noopener" class="btn btn-sm btn-outline-light"><i class="bi bi-github me-1"></i>Source</a>
            </div>

            ${Permissions.isAdmin() ? `
            <hr style="border-color:var(--border-color)">
            <div class="text-muted small mb-2"><i class="bi bi-terminal-fill me-1"></i>Install on Node</div>
            <div class="d-flex gap-2 align-items-center">
                <select id="cs-ssh-node" class="form-select form-select-sm" style="width:auto;min-width:140px">
                    ${(this.nodes || []).filter(n => n.status === 'online').map(n => `<option value="${escapeHtml(n.node)}">${escapeHtml(n.node)}</option>`).join('')}
                </select>
                <button class="btn btn-sm btn-success" onclick="Templates.openTerminal()">
                    <i class="bi bi-terminal-fill me-1"></i>Open Terminal &amp; Install
                </button>
            </div>
            ` : ''}
        `;

        // Store for copy + SSH
        this._currentInstallCmd = installCmd;
        this._currentScriptPath = scriptPath;

        new bootstrap.Modal(modal).show();
    },

    copyInstallCmd() {
        if (!this._currentInstallCmd) return;
        navigator.clipboard.writeText(this._currentInstallCmd).then(() => {
            const icon = document.getElementById('cs-copy-icon');
            if (icon) {
                icon.className = 'bi bi-check-lg text-success';
                setTimeout(() => { icon.className = 'bi bi-clipboard'; }, 2000);
            }
            Toast.success('Install command copied!');
        }).catch(() => {
            Toast.error('Copy failed — please copy manually');
        });
    },

    async openTerminal() {
        const nodeSelect = document.getElementById('cs-ssh-node');
        const node = nodeSelect?.value;
        if (!node || !this._currentScriptPath) return;

        // Close the script info modal
        bootstrap.Modal.getInstance(document.getElementById('communityScriptModal'))?.hide();

        // Set terminal modal title
        document.getElementById('ssh-terminal-title').textContent =
            `Installing on ${node}…`;
        document.getElementById('ssh-terminal-status').textContent = 'Connecting…';

        // Init xterm.js before showing modal so the DOM element exists
        const container = document.getElementById('ssh-terminal-container');
        container.innerHTML = '';

        const term = new Terminal({
            theme: { background: '#0d1117', foreground: '#e6edf3', cursor: '#58a6ff' },
            fontSize: 13,
            fontFamily: '"Cascadia Code", "Fira Code", monospace',
            scrollback: 5000,
            convertEol: true,
            cursorBlink: true,
        });
        const fitAddon = new FitAddon.FitAddon();
        term.loadAddon(fitAddon);
        term.open(container);

        // Open terminal modal
        // focus:false prevents Bootstrap from stealing keyboard focus away from xterm.js
        const termModalEl = document.getElementById('sshTerminalModal');
        const termModal = new bootstrap.Modal(termModalEl, { focus: false });

        termModalEl.addEventListener('shown.bs.modal', () => {
            fitAddon.fit();
            term.focus();
        }, { once: true });

        // Refit on container resize (e.g. window resize)
        const resizeObs = new ResizeObserver(() => { try { fitAddon.fit(); } catch(_) {} });
        resizeObs.observe(container);
        termModalEl.addEventListener('hidden.bs.modal', () => resizeObs.disconnect(), { once: true });

        // Re-focus whenever the user clicks inside the terminal area
        container.addEventListener('mousedown', () => term.focus());

        termModal.show();

        this._term = term;
        this._termEventSource = null;
        this._termToken = null;

        // Step 1: POST to get a session token
        let token;
        try {
            const res = await API.post('api/terminal-start.php', {
                node: node,
                script_path: this._currentScriptPath,
            });
            token = res.token;
            this._termToken = token;
        } catch (err) {
            term.write('\r\n\x1B[31mFailed to start session: ' + (err.message || err) + '\x1B[0m\r\n');
            document.getElementById('ssh-terminal-status').textContent = 'Error';
            return;
        }

        // Step 2: Connect to SSE output stream
        const es = new EventSource('api/terminal-output.php?token=' + encodeURIComponent(token));
        this._termEventSource = es;

        es.addEventListener('data', (e) => {
            term.write(Uint8Array.from(atob(e.data), c => c.charCodeAt(0)));
        });

        es.addEventListener('done', (e) => {
            es.close();
            const result = JSON.parse(e.data);
            const status = document.getElementById('ssh-terminal-status');
            if (result.success) {
                term.write('\r\n\x1B[32m✓ Installation completed successfully\x1B[0m\r\n');
                status.innerHTML = '<span class="text-success">Completed</span>';
                Toast.success('Installation completed');
            } else {
                term.write(`\r\n\x1B[31m✗ Exited with code ${result.exit_code}\x1B[0m\r\n`);
                status.innerHTML = `<span class="text-danger">Failed (exit ${result.exit_code})</span>`;
                Toast.error('Installation failed');
            }
        });

        es.addEventListener('error', (e) => {
            try {
                const d = JSON.parse(e.data);
                term.write('\r\n\x1B[31mError: ' + d.message + '\x1B[0m\r\n');
            } catch (_) {}
            document.getElementById('ssh-terminal-status').textContent = 'Connection lost';
            es.close();
        });

        es.onopen = () => {
            document.getElementById('ssh-terminal-status').textContent = 'Connected — installation running…';
        };

        // Step 3: Forward keyboard input to the server
        const statusEl = document.getElementById('ssh-terminal-status');
        term.onData((data) => {
            if (!this._termToken) {
                statusEl.textContent = 'Token not ready — please wait';
                return;
            }
            // Flash the status bar so the user can see input IS being captured
            statusEl.textContent = 'Sending…';
            API.post('api/terminal-input.php', {
                token: this._termToken,
                data: btoa(data),
            }).then(() => {
                statusEl.textContent = 'Connected — installation running…';
            }).catch((err) => {
                statusEl.textContent = 'Input error: ' + (err?.message || err);
            });
        });
    },

    closeTerminal() {
        if (this._termEventSource) {
            this._termEventSource.close();
            this._termEventSource = null;
        }
        if (this._term) {
            this._term.dispose();
            this._term = null;
        }
        this._termToken = null;
    },

    // ===== Cloud Images =====

    CI_IMAGES: {
        // Ubuntu
        'ubuntu-24.04':      { name: 'Ubuntu 24.04 LTS',  subtitle: 'Noble Numbat',   color: '#E95420', default_user: 'ubuntu' },
        'ubuntu-22.04':      { name: 'Ubuntu 22.04 LTS',  subtitle: 'Jammy Jellyfish', color: '#E95420', default_user: 'ubuntu' },
        'ubuntu-20.04':      { name: 'Ubuntu 20.04 LTS',  subtitle: 'Focal Fossa',    color: '#E95420', default_user: 'ubuntu' },
        // Debian
        'debian-12':         { name: 'Debian 12',         subtitle: 'Bookworm',        color: '#D70A53', default_user: 'debian' },
        'debian-11':         { name: 'Debian 11',         subtitle: 'Bullseye',        color: '#D70A53', default_user: 'debian' },
        // RHEL-family
        'rocky-9':           { name: 'Rocky Linux 9',     subtitle: 'GenericCloud',    color: '#10B981', default_user: 'rocky' },
        'almalinux-9':       { name: 'AlmaLinux 9',       subtitle: 'GenericCloud',    color: '#1D6FA4', default_user: 'almalinux' },
        'centos-stream-9':   { name: 'CentOS Stream 9',   subtitle: 'GenericCloud',    color: '#9CDD05', default_user: 'cloud-user' },
        // Other Linux
        'fedora-41':         { name: 'Fedora 41',         subtitle: 'Cloud Base',      color: '#51A2DA', default_user: 'fedora' },
        'opensuse-leap-15.6':{ name: 'openSUSE Leap 15.6',subtitle: 'Minimal Cloud',   color: '#73BA25', default_user: 'opensuse' },
        'arch-linux':        { name: 'Arch Linux',        subtitle: 'Rolling (latest)', color: '#1793D1', default_user: 'arch' },
        // BSD
        'freebsd-14':        { name: 'FreeBSD 14.4',      subtitle: 'RELEASE',         color: '#AB2B28', default_user: 'freebsd' },
    },

    renderCloudImages() {
        const grid = document.getElementById('ci-grid');
        if (!grid) return;

        grid.innerHTML = Object.entries(this.CI_IMAGES).map(([id, img]) => `
            <div class="ci-card" onclick="Templates.openCloudImageModal('${id}')">
                <div class="ci-card-accent" style="background:${img.color}"></div>
                <div class="ci-card-body">
                    <div class="ci-card-name">${escapeHtml(img.name)}</div>
                    <div class="ci-card-sub">${escapeHtml(img.subtitle)}</div>
                    <div class="mt-2">
                        <span class="badge" style="background:${img.color}22;color:${img.color};border:1px solid ${img.color}44;font-size:0.7rem">
                            user: ${escapeHtml(img.default_user)}
                        </span>
                    </div>
                </div>
                <div class="ci-card-footer">
                    <i class="bi bi-rocket-takeoff-fill me-1"></i>Deploy
                </div>
            </div>
        `).join('');
    },

    async openCloudImageModal(imageId) {
        let img;
        if (imageId.startsWith('custom:')) {
            const customId = parseInt(imageId.split(':')[1]);
            const ci = this.customImages.find(i => i.id === customId || i.id === String(customId));
            if (!ci) return;
            img = { name: ci.name, default_user: ci.default_user, color: this.OS_TYPE_COLORS[ci.ostype] || '#6c757d' };
        } else {
            img = this.CI_IMAGES[imageId];
        }
        if (!img) return;
        this._ciImageId = imageId;

        document.getElementById('ci-modal-image-name').textContent = img.name;

        // Reset all form fields
        document.getElementById('ci-name').value = '';
        document.getElementById('ci-cores').value = '2';
        document.getElementById('ci-memory').value = '2048';
        document.getElementById('ci-disk').value = '10';
        document.getElementById('ci-user').value = img.default_user;
        document.getElementById('ci-password').value = '';
        document.getElementById('ci-sshkeys').value = window.APP_USER?.ssh_public_keys || '';
        document.getElementById('ci-packages').value = '';
        document.getElementById('ci-runcmd').value = '';

        // Reset tags
        this.ciSelectedTags = [];
        this.ciPendingColors = {};
        document.getElementById('ci-tag-input').value = '';
        document.getElementById('ci-tag-color').value = '#0088cc';
        document.getElementById('ci-tag-fg').value = '#ffffff';
        this.renderCiTagChips();
        API.getTags().then(data => {
            this.ciExistingTags = data.colors || {};
            const list = document.getElementById('ci-tag-suggestions');
            if (list) list.innerHTML = (data.tags || []).map(t => `<option value="${escapeHtml(t)}">`).join('');
        }).catch(() => {});
        document.getElementById('ci-nameserver').value = '';
        document.getElementById('ci-searchdomain').value = '';
        document.getElementById('ci-ip').value = '';
        document.getElementById('ci-gw').value = '';

        // Fill node select
        const nodeSelect = document.getElementById('ci-node');
        const onlineNodes = (this.nodes || []).filter(n => n.status === 'online');
        nodeSelect.innerHTML = onlineNodes.map(n => `<option value="${escapeHtml(n.node)}">${escapeHtml(n.node)}</option>`).join('');

        // Reset IP type to DHCP
        document.getElementById('ci-ip-dhcp').checked = true;
        document.getElementById('ci-static-fields').style.display = 'none';

        // Load next VMID
        try {
            const res = await API.getNextVmid();
            document.getElementById('ci-vmid').value = res.vmid || res;
        } catch (_) {}

        // Load storages/bridges for default node
        if (onlineNodes.length > 0) {
            this.loadCloudInitResources(onlineNodes[0].node);
        }

        new bootstrap.Modal(document.getElementById('cloudInitModal')).show();
    },

    async loadCloudInitResources(node) {
        if (!node) return;

        document.getElementById('ci-storage').innerHTML = '<option value="">Loading...</option>';
        document.getElementById('ci-bridge').innerHTML = '<option value="">Loading...</option>';

        try {
            const [storages, networks] = await Promise.all([
                API.getStorages(node, 'images'),
                API.getNetworks(node),
            ]);

            const storageOpts = (storages || [])
                .filter(s => s.enabled !== 0)
                .map(s => `<option value="${escapeHtml(s.storage)}">${escapeHtml(s.storage)} (${escapeHtml(s.type || '')})</option>`)
                .join('');
            document.getElementById('ci-storage').innerHTML = storageOpts || '<option value="">No storages found</option>';
            const defStorage = window.APP_USER?.default_storage;
            if (defStorage && [...document.getElementById('ci-storage').options].some(o => o.value === defStorage)) {
                document.getElementById('ci-storage').value = defStorage;
            }

            const bridgeOpts = (networks || [])
                .filter(n => n.type === 'bridge')
                .map(n => `<option value="${escapeHtml(n.iface)}">${escapeHtml(n.iface)}</option>`)
                .join('');
            document.getElementById('ci-bridge').innerHTML = bridgeOpts || '<option value="">No bridges found</option>';
        } catch (_) {
            document.getElementById('ci-storage').innerHTML = '<option value="">Load failed</option>';
            document.getElementById('ci-bridge').innerHTML = '<option value="">Load failed</option>';
        }
    },

    async submitCloudImage(event) {
        event.preventDefault();

        const ipType = document.querySelector('input[name="ci-ip-type"]:checked')?.value || 'dhcp';

        const params = {
            image_id:        this._ciImageId,
            vmid:            parseInt(document.getElementById('ci-vmid').value),
            name:            document.getElementById('ci-name').value.trim(),
            node:            document.getElementById('ci-node').value,
            storage:         document.getElementById('ci-storage').value,
            bridge:          document.getElementById('ci-bridge').value,
            cores:           parseInt(document.getElementById('ci-cores').value),
            memory:          parseInt(document.getElementById('ci-memory').value),
            disk_size:       parseInt(document.getElementById('ci-disk').value),
            ci_user:         document.getElementById('ci-user').value.trim(),
            ci_password:     document.getElementById('ci-password').value,
            ci_sshkeys:      document.getElementById('ci-sshkeys').value.trim(),
            ci_nameserver:   document.getElementById('ci-nameserver').value.trim(),
            ci_searchdomain: document.getElementById('ci-searchdomain').value.trim(),
            ip_type:         ipType,
            tags:            this.ciSelectedTags.join(';'),
            ci_packages:     document.getElementById('ci-packages').value,
            ci_runcmd:       document.getElementById('ci-runcmd').value,
        };

        if (ipType === 'static') {
            params.ci_ip = document.getElementById('ci-ip').value.trim();
            params.ci_gw  = document.getElementById('ci-gw').value.trim();
        }

        // Persist new/changed tag colors
        const colorUpdates = Object.entries(this.ciPendingColors);
        if (colorUpdates.length > 0) {
            await Promise.allSettled(colorUpdates.map(([tag, color]) => API.setTagColor(tag, color.bg, color.fg)));
        }

        // Close the config modal
        bootstrap.Modal.getInstance(document.getElementById('cloudInitModal'))?.hide();

        // Set terminal title and open terminal modal
        const title = `Deploying ${this.CI_IMAGES[this._ciImageId]?.name || ''} (VM ${params.vmid}) on ${params.node}`;
        document.getElementById('ssh-terminal-title').textContent = title;
        document.getElementById('ssh-terminal-status').textContent = 'Starting…';

        const container = document.getElementById('ssh-terminal-container');
        container.innerHTML = '';

        const term = new Terminal({
            theme: { background: '#0d1117', foreground: '#e6edf3', cursor: '#58a6ff' },
            fontSize: 13,
            fontFamily: '"Cascadia Code", "Fira Code", monospace',
            scrollback: 5000,
            convertEol: true,
            cursorBlink: true,
        });
        const fitAddon = new FitAddon.FitAddon();
        term.loadAddon(fitAddon);
        term.open(container);

        const termModalEl = document.getElementById('sshTerminalModal');
        const termModal = new bootstrap.Modal(termModalEl, { focus: false });
        termModalEl.addEventListener('shown.bs.modal', () => { fitAddon.fit(); term.focus(); }, { once: true });
        const resizeObs2 = new ResizeObserver(() => { try { fitAddon.fit(); } catch(_) {} });
        resizeObs2.observe(container);
        termModalEl.addEventListener('hidden.bs.modal', () => resizeObs2.disconnect(), { once: true });
        container.addEventListener('mousedown', () => term.focus());
        termModal.show();

        this._term = term;
        this._termEventSource = null;
        this._termToken = null;

        // Get session token
        let token;
        try {
            const res = await API.cloudInitStart(params);
            token = res.token;
            this._termToken = token;
        } catch (err) {
            term.write('\r\n\x1B[31mFailed to start deployment: ' + (err.message || err) + '\x1B[0m\r\n');
            document.getElementById('ssh-terminal-status').textContent = 'Error';
            return;
        }

        // Connect SSE stream
        const es = new EventSource('api/terminal-output.php?token=' + encodeURIComponent(token));
        this._termEventSource = es;

        es.addEventListener('data', (e) => {
            term.write(Uint8Array.from(atob(e.data), c => c.charCodeAt(0)));
        });

        es.addEventListener('done', (e) => {
            es.close();
            const result = JSON.parse(e.data);
            const statusEl = document.getElementById('ssh-terminal-status');
            if (result.success) {
                term.write('\r\n\x1B[32m✓ Deployment completed successfully\x1B[0m\r\n');
                statusEl.innerHTML = '<span class="text-success">Completed</span>';
                Toast.success('VM deployed successfully');
            } else {
                term.write(`\r\n\x1B[31m✗ Exited with code ${result.exit_code}\x1B[0m\r\n`);
                statusEl.innerHTML = `<span class="text-danger">Failed (exit ${result.exit_code})</span>`;
                Toast.error('Deployment failed');
            }
        });

        es.addEventListener('error', (e) => {
            try { const d = JSON.parse(e.data); term.write('\r\n\x1B[31mError: ' + d.message + '\x1B[0m\r\n'); } catch (_) {}
            document.getElementById('ssh-terminal-status').textContent = 'Connection lost';
            es.close();
        });

        es.onopen = () => {
            document.getElementById('ssh-terminal-status').textContent = 'Connected — deployment running…';
        };

        const statusEl = document.getElementById('ssh-terminal-status');
        term.onData((data) => {
            if (!this._termToken) return;
            API.post('api/terminal-input.php', { token: this._termToken, data: btoa(data) })
                .catch(() => {});
        });
    },

    // ===== Local Templates =====

    setSort(col) {
        if (this.currentSort.col === col) {
            this.currentSort.dir = this.currentSort.dir === 'asc' ? 'desc' : 'asc';
        } else {
            this.currentSort.col = col;
            this.currentSort.dir = 'asc';
        }
        this.updateView();
    },

    sortIcon(col) {
        if (this.currentSort.col !== col) return '<i class="bi bi-chevron-expand" style="opacity:0.3;font-size:0.7em"></i>';
        return this.currentSort.dir === 'asc'
            ? '<i class="bi bi-chevron-up" style="font-size:0.7em"></i>'
            : '<i class="bi bi-chevron-down" style="font-size:0.7em"></i>';
    },

    sortTemplates(list) {
        const { col, dir } = this.currentSort;
        const mult = dir === 'asc' ? 1 : -1;

        return list.sort((a, b) => {
            let va, vb;
            switch (col) {
                case 'vmid':
                    return (a.vmid - b.vmid) * mult;
                case 'name':
                    va = (a.name || '').toLowerCase();
                    vb = (b.name || '').toLowerCase();
                    return va.localeCompare(vb) * mult;
                case 'type':
                    return (a.type || '').localeCompare(b.type || '') * mult;
                case 'node':
                    return (a.node || '').localeCompare(b.node || '') * mult;
                case 'disk':
                    return ((a.maxdisk || 0) - (b.maxdisk || 0)) * mult;
                default:
                    return 0;
            }
        });
    },

    updateView() {
        // Update node filter dropdown
        const nodeSelect = document.getElementById('tpl-filter-node');
        if (!nodeSelect) return;
        const currentVal = nodeSelect.value;
        nodeSelect.innerHTML = '<option value="">All Nodes</option>';
        this.nodes.forEach(n => {
            nodeSelect.innerHTML += `<option value="${n.node}">${n.node}</option>`;
        });
        nodeSelect.value = currentVal;

        // Filter
        let filtered = [...this.templates];
        if (this.currentFilter.type) {
            filtered = filtered.filter(t => t.type === this.currentFilter.type);
        }
        if (this.currentFilter.node) {
            filtered = filtered.filter(t => t.node === this.currentFilter.node);
        }
        if (this.currentFilter.search) {
            filtered = filtered.filter(t =>
                (t.name || '').toLowerCase().includes(this.currentFilter.search) ||
                String(t.vmid).includes(this.currentFilter.search)
            );
        }

        filtered = this.sortTemplates(filtered);
        this.renderTable(filtered);
    },

    renderTable(templates) {
        const container = document.getElementById('templates-table-container');
        if (!container) return;

        if (templates.length === 0) {
            container.innerHTML = `
                <div class="text-center p-5" style="color:var(--text-muted)">
                    <i class="bi bi-layers" style="font-size:2.5rem;opacity:0.3"></i>
                    <p class="mt-2 mb-0">No templates found</p>
                </div>`;
            return;
        }

        const cols = [
            { key: 'vmid', label: 'ID' },
            { key: 'name', label: 'Name' },
            { key: 'type', label: 'Type' },
            { key: 'node', label: 'Node' },
            { key: 'disk', label: 'Disk' },
        ];

        let html = `<table class="table table-dark table-hover mb-0">
            <thead>
                <tr>`;

        for (const c of cols) {
            html += `<th class="sortable-th" onclick="Templates.setSort('${c.key}')" style="cursor:pointer;user-select:none">${c.label} ${this.sortIcon(c.key)}</th>`;
        }
        html += `<th style="text-align:right">Action</th>
                </tr>
            </thead>
            <tbody>`;

        for (const t of templates) {
            html += `<tr>
                <td><strong style="color:var(--accent-blue)">${t.vmid}</strong></td>
                <td>${Utils.escapeHtml(t.name || 'Unnamed')}</td>
                <td><i class="bi ${Utils.typeIcon(t.type)}" style="opacity:0.6"></i> ${Utils.typeLabel(t.type)}</td>
                <td style="color:var(--text-secondary)">${Utils.escapeHtml(t.node)}</td>
                <td style="color:var(--text-secondary)">${t.maxdisk ? Utils.formatBytes(t.maxdisk) : '-'}</td>
                <td style="text-align:right;white-space:nowrap">
                    <button class="btn btn-primary btn-sm btn-action me-1"
                        onclick="Deploy.open(${JSON.stringify(t).replace(/"/g, '&quot;')})">
                        <i class="bi bi-rocket-takeoff-fill"></i> Deploy
                    </button>
                    ${Permissions.has('vm.delete') ? `<button class="btn btn-outline-danger btn-sm btn-action"
                        data-vmid="${t.vmid}" data-node="${escapeHtml(t.node)}" data-type="${escapeHtml(t.type)}" data-name="${escapeHtml(t.name || 'Unnamed')}"
                        onclick="Templates.deleteTemplate(this)">
                        <i class="bi bi-trash"></i>
                    </button>` : ''}
                </td>
            </tr>`;
        }

        html += '</tbody></table>';
        container.innerHTML = html;
    },

    onCiTagInput() {
        const tagName = document.getElementById('ci-tag-input').value.trim().toLowerCase();
        const existing = this.ciExistingTags[tagName];
        if (existing) {
            document.getElementById('ci-tag-color').value = '#' + existing.bg;
            document.getElementById('ci-tag-fg').value = '#' + existing.fg;
        }
    },

    addCiTag() {
        const input = document.getElementById('ci-tag-input');
        const tagName = input.value.trim().toLowerCase();
        if (!tagName || !/^[a-z0-9\-_]+$/.test(tagName)) return;
        if (this.ciSelectedTags.includes(tagName)) { input.value = ''; return; }
        const bg = document.getElementById('ci-tag-color').value.replace('#', '');
        const fg = document.getElementById('ci-tag-fg').value.replace('#', '');
        const existing = this.ciExistingTags[tagName];
        if (!existing || existing.bg !== bg || existing.fg !== fg) {
            this.ciPendingColors[tagName] = { bg, fg };
        }
        this.ciSelectedTags.push(tagName);
        input.value = '';
        document.getElementById('ci-tag-color').value = '#0088cc';
        document.getElementById('ci-tag-fg').value = '#ffffff';
        this.renderCiTagChips();
    },

    removeCiTag(tagName) {
        this.ciSelectedTags = this.ciSelectedTags.filter(t => t !== tagName);
        delete this.ciPendingColors[tagName];
        this.renderCiTagChips();
    },

    renderCiTagChips() {
        const container = document.getElementById('ci-tags-chips');
        if (!container) return;
        container.innerHTML = this.ciSelectedTags.map(tag => {
            const color = this.ciPendingColors[tag] || this.ciExistingTags[tag] || { bg: '6c757d', fg: 'ffffff' };
            return `<span class="badge d-inline-flex align-items-center gap-1" style="background:#${color.bg};color:#${color.fg}">
                <i class="bi bi-tag-fill" style="font-size:0.65rem"></i>
                ${escapeHtml(tag)}
                <i class="bi bi-x" style="cursor:pointer;font-size:0.75rem" onclick="Templates.removeCiTag('${escapeHtml(tag)}')"></i>
            </span>`;
        }).join('');
    },

    async deleteTemplate(btn) {
        const { vmid, node, type, name } = btn.dataset;
        if (!confirm(`Delete template "${name}" (${vmid}) on ${node}? This cannot be undone.`)) return;
        btn.disabled = true;
        try {
            await API.deleteGuest(node, type, vmid);
            Toast.success(`Template ${name} deleted`);
            this.templates = this.templates.filter(t => !(t.vmid == vmid && t.node === node));
            this.updateView();
        } catch (e) {
            Toast.error(e.message || 'Delete failed');
            btn.disabled = false;
        }
    },

    // ===== Custom Images =====

    OS_TYPE_LABELS: {
        l26: 'Linux',
        win10: 'Windows 10/Server 2016+',
        win11: 'Windows 11/Server 2022+',
        other: 'Other / FreeBSD',
    },

    OS_TYPE_COLORS: {
        l26: '#E95420',
        win10: '#0078D4',
        win11: '#0078D4',
        other: '#6c757d',
    },

    async loadCustomImages() {
        const grid = document.getElementById('custom-images-grid');
        const unreg = document.getElementById('custom-unregistered');
        if (!grid) return;

        try {
            const data = await API.getCustomImages();
            this.customImages = data.images || [];
            this.customUnregistered = data.unregistered || [];
            this.renderCustomImages();
        } catch (e) {
            grid.innerHTML = `<p class="text-danger">Failed to load custom images</p>`;
        }
    },

    renderCustomImages() {
        const grid = document.getElementById('custom-images-grid');
        if (!grid) return;

        if (this.customImages.length === 0 && this.customUnregistered.length === 0) {
            grid.innerHTML = `
                <div class="text-center py-4" style="color:var(--text-muted);grid-column:1/-1">
                    <i class="bi bi-hdd" style="font-size:2rem"></i>
                    <p class="mt-2 mb-1">No custom images yet</p>
                    <p class="small">Upload a .qcow2, .img, .raw, .iso, .vhd or .vhdx file, or place it in <code>data/images/</code></p>
                </div>`;
            document.getElementById('custom-unregistered').innerHTML = '';
            return;
        }

        grid.innerHTML = this.customImages.map(img => {
            const color = this.OS_TYPE_COLORS[img.ostype] || '#6c757d';
            const osLabel = this.OS_TYPE_LABELS[img.ostype] || img.ostype;
            const size = img.file_size ? Utils.formatBytes(img.file_size) : '?';
            const missing = !img.file_exists;
            return `
            <div class="ci-card${missing ? ' opacity-50' : ''}" onclick="${missing ? '' : `Templates.openCloudImageModal('custom:${img.id}')`}" style="${missing ? 'cursor:default' : ''}">
                <div class="ci-card-accent" style="background:${color}"></div>
                <div class="ci-card-body">
                    <div class="ci-card-name">${escapeHtml(img.name)}</div>
                    <div class="ci-card-sub">${escapeHtml(img.filename)}</div>
                    <div class="mt-2">
                        <span class="badge" style="background:${color}22;color:${color};border:1px solid ${color}44;font-size:0.7rem">
                            ${escapeHtml(osLabel)}
                        </span>
                        <span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);font-size:0.7rem">
                            user: ${escapeHtml(img.default_user)}
                        </span>
                        <span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);font-size:0.7rem">
                            ${size}
                        </span>
                        ${missing ? '<span class="badge bg-danger" style="font-size:0.7rem">file missing</span>' : ''}
                    </div>
                </div>
                <div class="ci-card-footer">
                    <button class="btn btn-sm btn-outline-info me-1" title="Distribute to all nodes"
                        onclick="event.stopPropagation();Templates.distributeCustomImage(${img.id},this)">
                        <i class="bi bi-send"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" title="Delete"
                        onclick="event.stopPropagation();Templates.deleteCustomImage(${img.id},'${escapeHtml(img.name)}')">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>`;
        }).join('');

        // Unregistered files
        const unreg = document.getElementById('custom-unregistered');
        if (this.customUnregistered.length > 0) {
            unreg.innerHTML = `
                <h6 class="text-muted mt-4 mb-2"><i class="bi bi-file-earmark-plus me-1"></i>Unregistered files in data/images/</h6>
                <div class="list-group">
                    ${this.customUnregistered.map(f => `
                        <div class="list-group-item d-flex justify-content-between align-items-center" style="background:var(--bg-secondary);border-color:var(--border-color);color:var(--text-primary)">
                            <div>
                                <code>${escapeHtml(f.filename)}</code>
                                <small class="text-muted ms-2">${Utils.formatBytes(f.file_size)}</small>
                            </div>
                            <button class="btn btn-sm btn-outline-success" onclick="Templates.registerCustomImage('${escapeHtml(f.filename)}')">
                                <i class="bi bi-plus-lg me-1"></i>Register
                            </button>
                        </div>
                    `).join('')}
                </div>`;
        } else {
            unreg.innerHTML = '';
        }
    },

    showUploadCustomImage() {
        const html = `
            <div class="modal fade" id="uploadCustomImageModal" tabindex="-1">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content glass-modal">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Upload Custom Image</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Image File <small class="text-muted">(.qcow2, .img, .raw, .iso, .vhd, .vhdx)</small></label>
                                <input type="file" class="form-control" id="custom-image-file" accept=".qcow2,.img,.raw,.iso,.vhd,.vhdx">
                            </div>
                            <div class="mb-3">
                                <label class="form-label">Display Name</label>
                                <input type="text" class="form-control" id="custom-image-name" placeholder="Windows Server 2022">
                            </div>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">Default User</label>
                                    <input type="text" class="form-control" id="custom-image-user" value="user" placeholder="Administrator">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">OS Type</label>
                                    <select class="form-select" id="custom-image-ostype">
                                        <option value="l26">Linux</option>
                                        <option value="win10">Windows 10 / Server 2016+</option>
                                        <option value="win11">Windows 11 / Server 2022+</option>
                                        <option value="other">Other / FreeBSD</option>
                                    </select>
                                </div>
                            </div>
                            <div class="progress mt-3 d-none" id="custom-upload-progress" style="height:24px">
                                <div class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%"></div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-success" id="custom-upload-btn" onclick="Templates.doUploadCustomImage()">
                                <i class="bi bi-upload me-1"></i>Upload
                            </button>
                        </div>
                    </div>
                </div>
            </div>`;
        // Remove existing modal if any
        document.getElementById('uploadCustomImageModal')?.remove();
        document.body.insertAdjacentHTML('beforeend', html);

        const fileInput = document.getElementById('custom-image-file');
        fileInput.addEventListener('change', () => {
            const nameInput = document.getElementById('custom-image-name');
            if (!nameInput.value && fileInput.files[0]) {
                nameInput.value = fileInput.files[0].name.replace(/\.(qcow2|img|raw|iso|vhd|vhdx)$/i, '').replace(/[._-]/g, ' ');
            }
        });

        new bootstrap.Modal(document.getElementById('uploadCustomImageModal')).show();
    },

    async doUploadCustomImage() {
        const fileInput = document.getElementById('custom-image-file');
        const name     = document.getElementById('custom-image-name').value.trim();
        const user     = document.getElementById('custom-image-user').value.trim();
        const ostype   = document.getElementById('custom-image-ostype').value;
        const btn      = document.getElementById('custom-upload-btn');

        if (!fileInput.files[0]) { Toast.error('Please select a file'); return; }
        if (!name) { Toast.error('Please enter a display name'); return; }

        const file = fileInput.files[0];
        const CHUNK_SIZE = 50 * 1024 * 1024; // 50 MB per chunk
        const totalChunks = Math.ceil(file.size / CHUNK_SIZE);
        const uploadId = crypto.randomUUID ? crypto.randomUUID() : Date.now() + '_' + Math.random().toString(36).slice(2);
        const csrfToken = document.querySelector('meta[name=csrf-token]')?.content || '';

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Uploading…';

        const progressBar = document.getElementById('custom-upload-progress');
        const bar = progressBar.querySelector('.progress-bar');
        progressBar.classList.remove('d-none');

        try {
            for (let i = 0; i < totalChunks; i++) {
                const start = i * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const chunk = file.slice(start, end);

                const formData = new FormData();
                formData.append('chunk', chunk, file.name);
                formData.append('chunk_index', i);
                formData.append('total_chunks', totalChunks);
                formData.append('upload_id', uploadId);
                formData.append('filename', file.name);

                // Send metadata with the last chunk
                if (i === totalChunks - 1) {
                    formData.append('name', name);
                    formData.append('default_user', user || 'user');
                    formData.append('ostype', ostype);
                }

                const resp = await fetch('api/upload-chunk.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: formData,
                });

                let data;
                try { data = await resp.json(); } catch {
                    throw new Error(`Server error on chunk ${i + 1}/${totalChunks} (HTTP ${resp.status})`);
                }
                if (!resp.ok || data.error) {
                    throw new Error(data.message || `Chunk ${i + 1} failed`);
                }

                const pct = Math.round(((i + 1) / totalChunks) * 100);
                bar.style.width = pct + '%';
                bar.textContent = pct + '%';
            }

            Toast.success(`Image "${name}" uploaded successfully`);
            bootstrap.Modal.getInstance(document.getElementById('uploadCustomImageModal'))?.hide();
            this.loadCustomImages();
        } catch (e) {
            Toast.error(e.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-upload me-1"></i>Upload';
        }
    },

    async registerCustomImage(filename) {
        const name = prompt('Display name for this image:', filename.replace(/\.(qcow2|img|raw|iso|vhd|vhdx)$/i, ''));
        if (!name) return;

        const ostype = prompt('OS type (l26 = Linux, win10, win11, other):', 'l26') || 'l26';
        const defaultUser = prompt('Default user:', 'user') || 'user';

        try {
            await API.registerCustomImage({ filename, name, default_user: defaultUser, ostype });
            Toast.success(`"${name}" registered`);
            this.loadCustomImages();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async distributeCustomImage(id, btn) {
        if (!confirm('Copy this image to all online Proxmox nodes via SCP?\nThis may take a while for large files.')) return;

        const origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';

        try {
            const data = await API.distributeCustomImage(id);
            const results = data.results || {};
            const summary = Object.entries(results).map(([node, r]) =>
                `${node}: ${r.ok ? 'OK' : r.error}`
            ).join('\n');
            const allOk = Object.values(results).every(r => r.ok);
            allOk ? Toast.success('Image distributed to all nodes') : Toast.warning('Distribution partial:\n' + summary);
        } catch (e) {
            Toast.error(e.message);
        } finally {
            btn.disabled = false;
            btn.innerHTML = origHtml;
        }
    },

    async deleteCustomImage(id, name) {
        if (!confirm(`Remove custom image "${name}"?\nThe image file will also be deleted from the container.`)) return;

        try {
            await API.deleteCustomImage(id, true);
            Toast.success(`"${name}" deleted`);
            this.loadCustomImages();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    // ===== Windows ISO Deploy =====

    windowsImages: [],

    async loadWindowsImages() {
        const grid = document.getElementById('windows-images-grid');
        if (!grid) return;
        grid.innerHTML = '<div class="loading-spinner"><div class="spinner-border text-primary"></div></div>';

        try {
            const data = await API.getWindowsImages();
            this.windowsImages = data.images || data || [];
            this.renderWindowsImages();
        } catch (e) {
            grid.innerHTML = '<p class="text-danger">Failed to load Windows images</p>';
        }
    },

    renderWindowsImages() {
        const grid = document.getElementById('windows-images-grid');
        if (!grid) return;

        if (this.windowsImages.length === 0) {
            grid.innerHTML = `
                <div class="text-center py-4" style="color:var(--text-muted);grid-column:1/-1">
                    <i class="bi bi-windows" style="font-size:2rem"></i>
                    <p class="mt-2 mb-1">No Windows Unattend.xml registered</p>
                    <p class="small">Register an Unattend.xml for a Windows ISO uploaded in Custom Images.</p>
                </div>`;
            return;
        }

        grid.innerHTML = this.windowsImages.map(img => {
            const hasXml = !!img.autounattend_xml;
            const hasKey = !!img.product_key;
            return `
            <div class="ci-card" onclick="Templates.openWindowsDeploy(${img.id})" style="cursor:pointer">
                <div class="ci-card-accent" style="background:#0078D4"></div>
                <div class="ci-card-body">
                    <div class="ci-card-name">${escapeHtml(img.name)}</div>
                    <div class="ci-card-sub">${escapeHtml(img.iso_filename)}</div>
                    <div class="mt-2">
                        ${hasXml ? '<span class="badge" style="background:#0078D422;color:#0078D4;border:1px solid #0078D444;font-size:0.7rem"><i class="bi bi-file-code me-1"></i>Unattended</span>' : '<span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);font-size:0.7rem">Manual install</span>'}
                        ${hasKey ? '<span class="badge" style="background:#28a74522;color:#28a745;border:1px solid #28a74544;font-size:0.7rem"><i class="bi bi-key me-1"></i>Product Key</span>' : ''}
                        ${img.install_guest_tools ? '<span class="badge" style="background:var(--bg-secondary);color:var(--text-muted);font-size:0.7rem"><i class="bi bi-cpu me-1"></i>Guest Tools</span>' : ''}
                    </div>
                    ${img.notes ? `<div class="mt-1 small" style="color:var(--text-muted)">${escapeHtml(img.notes)}</div>` : ''}
                </div>
                <div class="ci-card-footer">
                    <button class="btn btn-sm btn-outline-warning me-1" title="Edit"
                        onclick="event.stopPropagation();Templates.showEditWindowsImage(${img.id})">
                        <i class="bi bi-pencil"></i>
                    </button>
                    <button class="btn btn-sm btn-outline-danger" title="Delete"
                        onclick="event.stopPropagation();Templates.deleteWindowsImage(${img.id},'${escapeHtml(img.name)}')">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>`;
        }).join('');
    },

    async showAddWindowsImage() {
        if (!this.customImages.length) await this.loadCustomImages();
        this._editingWindowsImageId = null;
        this._showWindowsImageModal('Register Unattend.xml', {});
    },

    async showEditWindowsImage(id) {
        if (!this.customImages.length) await this.loadCustomImages();
        const img = this.windowsImages.find(i => i.id === id);
        if (!img) return;
        this._editingWindowsImageId = id;
        this._showWindowsImageModal('Edit Unattend.xml', img);
    },

    _showWindowsImageModal(title, img) {
        document.getElementById('windowsImageModal')?.remove();
        const html = `
        <div class="modal fade" id="windowsImageModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content glass-modal">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-windows me-2"></i>${title}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Display Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="win-img-name" value="${escapeHtml(img.name || '')}" placeholder="Windows 11 Pro">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">ISO Image <span class="text-danger">*</span></label>
                                <select class="form-select" id="win-img-iso">
                                    <option value="">— Select ISO —</option>
                                    ${(this.customImages || []).filter(ci => /\.iso$/i.test(ci.filename)).map(ci =>
                                        `<option value="${escapeHtml(ci.filename)}" ${ci.filename === (img.iso_filename || '') ? 'selected' : ''}>${escapeHtml(ci.name)} (${escapeHtml(ci.filename)})</option>`
                                    ).join('')}
                                </select>
                                <small class="text-muted">Upload ISOs in the Custom Images tab first</small>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Product Key <small class="text-muted">(optional)</small></label>
                                <input type="text" class="form-control" id="win-img-key" value="${escapeHtml(img.product_key || '')}" placeholder="XXXXX-XXXXX-XXXXX-XXXXX-XXXXX">
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="win-img-tools" ${img.install_guest_tools !== false ? 'checked' : ''}>
                                    <label class="form-check-label" for="win-img-tools">Install QEMU Guest Agent after setup</label>
                                </div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">autounattend.xml <small class="text-muted">(optional — enables unattended install)</small></label>
                                <div class="mb-2">
                                    <select class="form-select form-select-sm" id="win-xml-template" onchange="Templates._applyXmlTemplate()" style="max-width:320px;display:inline-block">
                                        <option value="">— Load template —</option>
                                        <option value="win11pro">Windows 11 Pro</option>
                                        <option value="win11ent">Windows 11 Enterprise</option>
                                        <option value="win10pro">Windows 10 Pro</option>
                                        <option value="srv2025">Windows Server 2025</option>
                                        <option value="srv2022">Windows Server 2022</option>
                                        <option value="srv2022core">Windows Server 2022 Core</option>
                                    </select>
                                    <small class="text-muted ms-2">Pre-built template with {{PRODUCT_KEY}} placeholder</small>
                                </div>
                                <textarea class="form-control" id="win-img-xml" rows="10" style="font-family:monospace;font-size:0.8rem" placeholder="Paste your autounattend.xml content here...&#10;Use {{PRODUCT_KEY}} as placeholder for the product key.">${escapeHtml(img.autounattend_xml || '')}</textarea>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
                                <input type="text" class="form-control" id="win-img-notes" value="${escapeHtml(img.notes || '')}" placeholder="e.g. Enterprise LTSC, Volume License">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="button" class="btn btn-success" onclick="Templates.saveWindowsImage()">
                            <i class="bi bi-check-lg me-1"></i>Save
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
        new bootstrap.Modal(document.getElementById('windowsImageModal')).show();
    },

    _xmlTemplates: {
        win11pro: {
            name: 'Windows 11 Pro',
            imageIndex: 6,
            xml: `<?xml version="1.0" encoding="utf-8"?>
<unattend xmlns="urn:schemas-microsoft-com:unattend" xmlns:wcm="http://schemas.microsoft.com/WMIConfig/2002/State">
    <settings pass="windowsPE">
        <component name="Microsoft-Windows-International-Core-WinPE" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS">
            <SetupUILanguage>
                <UILanguage>en-US</UILanguage>
            </SetupUILanguage>
            <InputLocale>en-US</InputLocale>
            <SystemLocale>en-US</SystemLocale>
            <UILanguage>en-US</UILanguage>
            <UserLocale>en-US</UserLocale>
        </component>
        <component name="Microsoft-Windows-Setup" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS">
            <DiskConfiguration>
                <Disk wcm:action="add">
                    <CreatePartitions>
                        <CreatePartition wcm:action="add">
                            <Order>1</Order>
                            <Size>600</Size>
                            <Type>EFI</Type>
                        </CreatePartition>
                        <CreatePartition wcm:action="add">
                            <Order>2</Order>
                            <Size>128</Size>
                            <Type>MSR</Type>
                        </CreatePartition>
                        <CreatePartition wcm:action="add">
                            <Order>3</Order>
                            <Extend>true</Extend>
                            <Type>Primary</Type>
                        </CreatePartition>
                    </CreatePartitions>
                    <ModifyPartitions>
                        <ModifyPartition wcm:action="add">
                            <Order>1</Order>
                            <PartitionID>1</PartitionID>
                            <Format>FAT32</Format>
                            <Label>EFI</Label>
                        </ModifyPartition>
                        <ModifyPartition wcm:action="add">
                            <Order>2</Order>
                            <PartitionID>2</PartitionID>
                        </ModifyPartition>
                        <ModifyPartition wcm:action="add">
                            <Order>3</Order>
                            <PartitionID>3</PartitionID>
                            <Format>NTFS</Format>
                            <Label>Windows</Label>
                        </ModifyPartition>
                    </ModifyPartitions>
                    <DiskID>0</DiskID>
                    <WillWipeDisk>true</WillWipeDisk>
                </Disk>
            </DiskConfiguration>
            <ImageInstall>
                <OSImage>
                    <InstallTo>
                        <DiskID>0</DiskID>
                        <PartitionID>3</PartitionID>
                    </InstallTo>
                    <InstallFrom>
                        <MetaData wcm:action="add">
                            <Key>/IMAGE/INDEX</Key>
                            <Value>6</Value>
                        </MetaData>
                    </InstallFrom>
                </OSImage>
            </ImageInstall>
            <UserData>
                <AcceptEula>true</AcceptEula>
                <ProductKey>
                    <Key>{{PRODUCT_KEY}}</Key>
                </ProductKey>
            </UserData>
        </component>
    </settings>
    <settings pass="specialize">
        <component name="Microsoft-Windows-Shell-Setup" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS">
            <ComputerName>*</ComputerName>
            <TimeZone>UTC</TimeZone>
        </component>
        <component name="Microsoft-Windows-TerminalServices-LocalSessionManager" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS">
            <fDenyTSConnections>false</fDenyTSConnections>
        </component>
    </settings>
    <settings pass="oobeSystem">
        <component name="Microsoft-Windows-Shell-Setup" processorArchitecture="amd64" publicKeyToken="31bf3856ad364e35" language="neutral" versionScope="nonSxS">
            <OOBE>
                <HideEULAPage>true</HideEULAPage>
                <HideLocalAccountScreen>true</HideLocalAccountScreen>
                <HideOnlineAccountScreens>true</HideOnlineAccountScreens>
                <HideWirelessSetupInOOBE>true</HideWirelessSetupInOOBE>
                <ProtectYourPC>3</ProtectYourPC>
            </OOBE>
            <UserAccounts>
                <LocalAccounts>
                    <LocalAccount wcm:action="add">
                        <Name>Admin</Name>
                        <Group>Administrators</Group>
                        <Password>
                            <Value>Admin123!</Value>
                            <PlainText>true</PlainText>
                        </Password>
                    </LocalAccount>
                </LocalAccounts>
            </UserAccounts>
            <AutoLogon>
                <Enabled>true</Enabled>
                <Username>Admin</Username>
                <Password>
                    <Value>Admin123!</Value>
                    <PlainText>true</PlainText>
                </Password>
                <LogonCount>1</LogonCount>
            </AutoLogon>
            <FirstLogonCommands>
                <SynchronousCommand wcm:action="add">
                    <Order>1</Order>
                    <CommandLine>powershell -Command "Set-NetFirewallRule -DisplayGroup 'Remote Desktop' -Enabled True"</CommandLine>
                    <Description>Enable RDP Firewall Rule</Description>
                </SynchronousCommand>
                <SynchronousCommand wcm:action="add">
                    <Order>2</Order>
                    <CommandLine>powershell -Command "Enable-NetFirewallRule -DisplayName 'File and Printer Sharing (Echo Request - ICMPv4-In)'"</CommandLine>
                    <Description>Enable Ping</Description>
                </SynchronousCommand>
            </FirstLogonCommands>
        </component>
    </settings>
</unattend>`
        },
        win11ent: {
            name: 'Windows 11 Enterprise',
            xml: null // built dynamically from win11pro with index 6 → 6 (same) but different key handling
        },
        win10pro: {
            name: 'Windows 10 Pro',
            xml: null
        },
        srv2025: {
            name: 'Windows Server 2025 (Desktop Experience)',
            xml: null
        },
        srv2022: {
            name: 'Windows Server 2022 (Desktop Experience)',
            xml: null
        },
        srv2022core: {
            name: 'Windows Server 2022 Core',
            xml: null
        },
    },

    _getXmlTemplate(key) {
        const base = this._xmlTemplates.win11pro.xml;
        const variants = {
            win11pro:     { index: 6 },
            win11ent:     { index: 6 },
            win10pro:     { index: 6 },
            srv2025:      { index: 2 },
            srv2022:      { index: 2 },
            srv2022core:  { index: 1 },
        };
        const v = variants[key];
        if (!v) return '';
        let xml = base.replace(/<Value>6<\/Value>(\s*<\/MetaData>)/, `<Value>${v.index}</Value>$1`);
        // Server Core: skip OOBE online/wireless hiding (not applicable)
        if (key === 'srv2022core') {
            xml = xml.replace(/<HideOnlineAccountScreens>true<\/HideOnlineAccountScreens>\n\s*<HideWirelessSetupInOOBE>true<\/HideWirelessSetupInOOBE>/, '<HideOnlineAccountScreens>true</HideOnlineAccountScreens>');
        }
        return xml;
    },

    _applyXmlTemplate() {
        const sel = document.getElementById('win-xml-template');
        const textarea = document.getElementById('win-img-xml');
        if (!sel || !textarea || !sel.value) return;

        if (textarea.value.trim() && !confirm('This will replace the current XML content. Continue?')) {
            sel.value = '';
            return;
        }

        textarea.value = this._getXmlTemplate(sel.value);
        sel.value = '';
    },

    async saveWindowsImage() {
        const name = document.getElementById('win-img-name').value.trim();
        const iso = document.getElementById('win-img-iso').value.trim();
        if (!name) { Toast.error('Name is required'); return; }
        if (!iso) { Toast.error('Please select an ISO image'); return; }

        const data = {
            name,
            iso_filename: iso,
            product_key: document.getElementById('win-img-key').value.trim(),
            install_guest_tools: document.getElementById('win-img-tools').checked,
            autounattend_xml: document.getElementById('win-img-xml').value,
            notes: document.getElementById('win-img-notes').value.trim(),
        };
        if (this._editingWindowsImageId) data.id = this._editingWindowsImageId;

        try {
            await API.saveWindowsImage(data);
            Toast.success(this._editingWindowsImageId ? 'Windows image updated' : 'Windows image registered');
            bootstrap.Modal.getInstance(document.getElementById('windowsImageModal'))?.hide();
            this.loadWindowsImages();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async deleteWindowsImage(id, name) {
        if (!confirm(`Delete Windows image "${name}"?`)) return;
        try {
            await API.deleteWindowsImage(id);
            Toast.success(`"${name}" deleted`);
            this.loadWindowsImages();
        } catch (e) {
            Toast.error(e.message);
        }
    },

    async openWindowsDeploy(imageId) {
        const img = this.windowsImages.find(i => i.id === imageId);
        if (!img) return;
        this._winDeployImage = img;

        document.getElementById('windowsDeployModal')?.remove();
        const html = `
        <div class="modal fade" id="windowsDeployModal" tabindex="-1">
            <div class="modal-dialog modal-lg modal-dialog-centered">
                <div class="modal-content glass-modal">
                    <div class="modal-header">
                        <h5 class="modal-title"><i class="bi bi-windows me-2"></i>Deploy: ${escapeHtml(img.name)}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form onsubmit="Templates.submitWindowsDeploy(event)">
                    <div class="modal-body">
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">VMID</label>
                                <input type="number" class="form-control" id="win-vmid" min="100" max="999999999" required>
                            </div>
                            <div class="col-md-8">
                                <label class="form-label">VM Name</label>
                                <input type="text" class="form-control" id="win-name" pattern="[a-zA-Z0-9][a-zA-Z0-9.\\-]{0,62}" required placeholder="win11-pc1">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Node</label>
                                <select class="form-select" id="win-node" required onchange="Templates.loadWindowsDeployResources(this.value)">
                                    <option value="">Select node...</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Storage</label>
                                <select class="form-select" id="win-storage" required>
                                    <option value="">Select node first</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Network Bridge</label>
                                <select class="form-select" id="win-bridge" required>
                                    <option value="">Select node first</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">CPU Cores</label>
                                <input type="number" class="form-control" id="win-cores" value="4" min="1" max="128">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Memory (MB)</label>
                                <input type="number" class="form-control" id="win-memory" value="4096" min="2048" max="131072" step="1024">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Disk Size (GB)</label>
                                <input type="number" class="form-control" id="win-disk" value="64" min="30" max="10000">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Tags <small class="text-muted">(semicolon-separated)</small></label>
                                <input type="text" class="form-control" id="win-tags" placeholder="windows;production">
                            </div>
                        </div>
                        <div class="alert alert-info mt-3 py-2 small">
                            <i class="bi bi-info-circle me-1"></i>
                            ${img.autounattend_xml ? 'This image has an <strong>autounattend.xml</strong> — Windows will install unattended.' : 'No autounattend.xml configured — you will need to complete the installation manually via VNC/console.'}
                            ${img.install_guest_tools ? ' QEMU Guest Agent will be installed automatically.' : ''}
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary" id="win-deploy-btn">
                            <i class="bi bi-rocket-takeoff-fill me-1"></i>Deploy
                        </button>
                    </div>
                    </form>
                </div>
            </div>
        </div>`;
        document.body.insertAdjacentHTML('beforeend', html);

        // Load nodes and next VMID
        try {
            const [nodes, nextVmid] = await Promise.all([
                API.getNodes(),
                API.getNextVmid(),
            ]);
            const nodeSelect = document.getElementById('win-node');
            (nodes || []).filter(n => n.status === 'online').forEach(n => {
                nodeSelect.innerHTML += `<option value="${escapeHtml(n.node)}">${escapeHtml(n.node)}</option>`;
            });
            if (nextVmid?.vmid) document.getElementById('win-vmid').value = nextVmid.vmid;
        } catch (_) {}

        new bootstrap.Modal(document.getElementById('windowsDeployModal')).show();
    },

    async loadWindowsDeployResources(node) {
        if (!node) return;
        document.getElementById('win-storage').innerHTML = '<option value="">Loading...</option>';
        document.getElementById('win-bridge').innerHTML = '<option value="">Loading...</option>';

        try {
            const [storages, networks] = await Promise.all([
                API.getStorages(node, 'images'),
                API.getNetworks(node),
            ]);
            const storageOpts = (storages || []).filter(s => s.enabled !== 0)
                .map(s => `<option value="${escapeHtml(s.storage)}">${escapeHtml(s.storage)} (${escapeHtml(s.type || '')})</option>`).join('');
            document.getElementById('win-storage').innerHTML = storageOpts || '<option value="">No storages</option>';
            const defStorage = window.APP_USER?.default_storage;
            if (defStorage && [...document.getElementById('win-storage').options].some(o => o.value === defStorage)) {
                document.getElementById('win-storage').value = defStorage;
            }

            const bridgeOpts = (networks || []).filter(n => n.type === 'bridge')
                .map(n => `<option value="${escapeHtml(n.iface)}">${escapeHtml(n.iface)}</option>`).join('');
            document.getElementById('win-bridge').innerHTML = bridgeOpts || '<option value="">No bridges</option>';
        } catch (_) {
            document.getElementById('win-storage').innerHTML = '<option value="">Load failed</option>';
            document.getElementById('win-bridge').innerHTML = '<option value="">Load failed</option>';
        }
    },

    async submitWindowsDeploy(event) {
        event.preventDefault();

        const params = {
            image_id:    this._winDeployImage.id,
            vmid:        parseInt(document.getElementById('win-vmid').value),
            name:        document.getElementById('win-name').value.trim(),
            node:        document.getElementById('win-node').value,
            storage:     document.getElementById('win-storage').value,
            bridge:      document.getElementById('win-bridge').value,
            cores:       parseInt(document.getElementById('win-cores').value),
            memory:      parseInt(document.getElementById('win-memory').value),
            disk_size:   parseInt(document.getElementById('win-disk').value),
            tags:        document.getElementById('win-tags').value.trim(),
        };

        if (!params.node) { Toast.error('Please select a node'); return; }
        if (!params.storage) { Toast.error('Please select a storage'); return; }
        if (!params.bridge) { Toast.error('Please select a bridge'); return; }

        // Close deploy modal
        bootstrap.Modal.getInstance(document.getElementById('windowsDeployModal'))?.hide();

        // Open terminal modal
        const title = `Deploying ${escapeHtml(this._winDeployImage.name)} (VM ${params.vmid}) on ${params.node}`;
        document.getElementById('ssh-terminal-title').textContent = title;
        document.getElementById('ssh-terminal-status').textContent = 'Starting…';

        const container = document.getElementById('ssh-terminal-container');
        container.innerHTML = '';

        const term = new Terminal({
            theme: { background: '#0d1117', foreground: '#e6edf3', cursor: '#58a6ff' },
            fontSize: 13,
            fontFamily: '"Cascadia Code", "Fira Code", monospace',
            scrollback: 5000,
            convertEol: true,
            cursorBlink: true,
        });
        const fitAddon = new FitAddon.FitAddon();
        term.loadAddon(fitAddon);
        term.open(container);

        const termModalEl = document.getElementById('sshTerminalModal');
        const termModal = new bootstrap.Modal(termModalEl, { focus: false });
        termModalEl.addEventListener('shown.bs.modal', () => { fitAddon.fit(); term.focus(); }, { once: true });
        const resizeObs = new ResizeObserver(() => { try { fitAddon.fit(); } catch(_) {} });
        resizeObs.observe(container);
        termModalEl.addEventListener('hidden.bs.modal', () => resizeObs.disconnect(), { once: true });
        container.addEventListener('mousedown', () => term.focus());
        termModal.show();

        this._term = term;
        this._termEventSource = null;
        this._termToken = null;

        let token;
        try {
            const res = await API.windowsDeploy(params);
            token = res.token;
            this._termToken = token;
        } catch (err) {
            term.write('\r\n\x1B[31mFailed to start deployment: ' + (err.message || err) + '\x1B[0m\r\n');
            document.getElementById('ssh-terminal-status').textContent = 'Error';
            return;
        }

        const es = new EventSource('api/terminal-output.php?token=' + encodeURIComponent(token));
        this._termEventSource = es;

        es.addEventListener('data', (e) => {
            term.write(Uint8Array.from(atob(e.data), c => c.charCodeAt(0)));
        });

        es.addEventListener('done', (e) => {
            es.close();
            const result = JSON.parse(e.data);
            const statusEl = document.getElementById('ssh-terminal-status');
            if (result.success) {
                term.write('\r\n\x1B[32m✓ Windows VM created and started successfully\x1B[0m\r\n');
                statusEl.innerHTML = '<span class="text-success">Completed</span>';
                Toast.success('Windows VM deployed successfully');
            } else {
                term.write(`\r\n\x1B[31m✗ Exited with code ${result.exit_code}\x1B[0m\r\n`);
                statusEl.innerHTML = `<span class="text-danger">Failed (exit ${result.exit_code})</span>`;
                Toast.error('Deployment failed');
            }
        });

        es.addEventListener('error', (e) => {
            try { const d = JSON.parse(e.data); term.write('\r\n\x1B[31mError: ' + d.message + '\x1B[0m\r\n'); } catch (_) {}
            document.getElementById('ssh-terminal-status').textContent = 'Connection lost';
            es.close();
        });

        es.onopen = () => {
            document.getElementById('ssh-terminal-status').textContent = 'Connected — deployment running…';
        };

        term.onData((data) => {
            if (!this._termToken) return;
            API.post('api/terminal-input.php', { token: this._termToken, data: btoa(data) }).catch(() => {});
        });
    },
};
