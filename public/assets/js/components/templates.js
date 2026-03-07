const Templates = {
    templates: [],
    nodes: [],
    currentFilter: { type: '', node: '', search: '' },
    currentSort: { col: 'vmid', dir: 'asc' },

    // Cloud-image tag state
    ciSelectedTags: [],
    ciPendingColors: {},
    ciExistingTags: {},

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
    },

    switchTab(tab) {
        this.activeTab = tab;
        document.getElementById('tab-local').style.display = tab === 'local' ? '' : 'none';
        document.getElementById('tab-community').style.display = tab === 'community' ? '' : 'none';
        document.getElementById('tab-cloudinit').style.display = tab === 'cloudinit' ? '' : 'none';

        document.querySelectorAll('.deploy-tab-btn').forEach(btn => btn.classList.remove('active'));
        document.querySelectorAll('.deploy-tab-btn').forEach(btn => {
            if ((tab === 'local' && btn.textContent.includes('Local')) ||
                (tab === 'community' && btn.textContent.includes('Community')) ||
                (tab === 'cloudinit' && btn.textContent.includes('Cloud Images'))) {
                btn.classList.add('active');
            }
        });

        if (tab === 'community' && !this.communityData && !this.communityLoading) {
            this.loadCommunityScripts();
        }
        if (tab === 'cloudinit') {
            this.renderCloudImages();
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
        'ubuntu-24.04': { name: 'Ubuntu 24.04 LTS', subtitle: 'Noble Numbat', color: '#E95420', default_user: 'ubuntu' },
        'ubuntu-22.04': { name: 'Ubuntu 22.04 LTS', subtitle: 'Jammy Jellyfish', color: '#E95420', default_user: 'ubuntu' },
        'debian-12':    { name: 'Debian 12', subtitle: 'Bookworm', color: '#D70A53', default_user: 'debian' },
        'debian-11':    { name: 'Debian 11', subtitle: 'Bullseye', color: '#D70A53', default_user: 'debian' },
        'rocky-9':      { name: 'Rocky Linux 9', subtitle: 'GenericCloud', color: '#10B981', default_user: 'rocky' },
        'almalinux-9':  { name: 'AlmaLinux 9', subtitle: 'GenericCloud', color: '#1D6FA4', default_user: 'almalinux' },
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
        const img = this.CI_IMAGES[imageId];
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
};
