const Deploy = {
    currentTemplate: null,
    modal: null,

    getModal() {
        if (!this.modal) {
            this.modal = new bootstrap.Modal(document.getElementById('deployModal'));
        }
        return this.modal;
    },

    async open(template) {
        this.currentTemplate = template;
        const body = document.getElementById('deploy-modal-body');

        body.innerHTML = '<div class="loading-spinner"><div class="spinner-border text-primary"></div></div>';
        this.getModal().show();

        try {
            const [nodes, nextVmid] = await Promise.all([
                API.getNodes(),
                API.getNextVmid(),
            ]);

            const vmid = nextVmid.vmid || nextVmid;
            this.renderForm(template, nodes, vmid);

            // Load storages and networks for the default target node
            const targetNode = template.node;
            this.loadNodeResources(targetNode);
        } catch (err) {
            body.innerHTML = `<div class="alert alert-danger">Failed to load: ${Utils.escapeHtml(err.message)}</div>`;
        }
    },

    renderForm(template, nodes, vmid) {
        const body = document.getElementById('deploy-modal-body');
        const nodesOptions = nodes.map(n =>
            `<option value="${n.node}" ${n.node === template.node ? 'selected' : ''}>${n.node} (${n.status})</option>`
        ).join('');

        body.innerHTML = `
            <form id="deploy-form" onsubmit="Deploy.submit(event)">
                <div class="template-info-bar mb-3">
                    <strong><i class="bi ${Utils.typeIcon(template.type)}" style="color:var(--accent-blue)"></i> ${Utils.escapeHtml(template.name || 'Template')}</strong>
                    <span style="color:var(--text-muted)" class="ms-2">ID: ${template.vmid} | ${Utils.typeLabel(template.type)} | ${template.node}</span>
                </div>

                <h6 class="text-muted mt-3 mb-2"><i class="bi bi-gear"></i> Basic Settings</h6>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">VM Name *</label>
                        <input type="text" class="form-control" id="deploy-name" required pattern="[a-zA-Z0-9\\.\\-]+" placeholder="web-server-01">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">VMID *</label>
                        <input type="number" class="form-control" id="deploy-vmid" value="${vmid}" required min="100">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Clone Type</label>
                        <select class="form-select" id="deploy-full">
                            <option value="1" selected>Full Clone</option>
                            <option value="0">Linked Clone</option>
                        </select>
                    </div>
                </div>
                <div class="row g-2 mt-1">
                    <div class="col-md-6">
                        <label class="form-label">Target Node</label>
                        <select class="form-select" id="deploy-target-node" onchange="Deploy.loadNodeResources(this.value)">
                            ${nodesOptions}
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Storage</label>
                        <select class="form-select" id="deploy-storage">
                            <option value="">Default (from template)</option>
                        </select>
                    </div>
                </div>

                <h6 class="text-muted mt-4 mb-2"><i class="bi bi-cpu"></i> Resources</h6>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">CPU Cores</label>
                        <input type="number" class="form-control" id="deploy-cores" placeholder="From template" min="1" max="128">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">RAM (MB)</label>
                        <input type="number" class="form-control" id="deploy-memory" placeholder="From template" min="128" step="128">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Disk Resize</label>
                        <input type="text" class="form-control" id="deploy-disk-resize" placeholder="e.g. +10G">
                    </div>
                </div>

                <h6 class="text-muted mt-4 mb-2"><i class="bi bi-ethernet"></i> Network</h6>
                <div class="row g-2">
                    <div class="col-md-6">
                        <label class="form-label">Bridge</label>
                        <select class="form-select" id="deploy-bridge">
                            <option value="">From template</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">VLAN Tag</label>
                        <input type="number" class="form-control" id="deploy-vlan" placeholder="No VLAN" min="1" max="4094">
                    </div>
                </div>

                <h6 class="text-muted mt-4 mb-2">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" id="deploy-cloudinit-toggle" onchange="Deploy.toggleCloudInit()">
                        <label class="form-check-label" for="deploy-cloudinit-toggle">
                            <i class="bi bi-cloud"></i> Cloud-Init
                        </label>
                    </div>
                </h6>
                <div id="cloudinit-fields" class="cloudinit-section" style="display:none">
                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label">Hostname</label>
                            <input type="text" class="form-control" id="deploy-ci-hostname" placeholder="Same as VM name">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">User</label>
                            <input type="text" class="form-control" id="deploy-ci-user" placeholder="e.g. deploy">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">Password</label>
                            <input type="password" class="form-control" id="deploy-ci-password">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">IP Configuration</label>
                            <select class="form-select" id="deploy-ci-iptype" onchange="Deploy.toggleStaticIp()">
                                <option value="dhcp">DHCP</option>
                                <option value="static">Static</option>
                            </select>
                        </div>
                    </div>
                    <div id="static-ip-fields" class="row g-2 mt-1" style="display:none">
                        <div class="col-md-4">
                            <label class="form-label">IP/CIDR</label>
                            <input type="text" class="form-control" id="deploy-ci-ip" placeholder="192.168.1.50/24">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Gateway</label>
                            <input type="text" class="form-control" id="deploy-ci-gw" placeholder="192.168.1.1">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">DNS</label>
                            <input type="text" class="form-control" id="deploy-ci-dns" placeholder="1.1.1.1">
                        </div>
                    </div>
                    <div class="row g-2 mt-1">
                        <div class="col-md-6">
                            <label class="form-label">Search Domain</label>
                            <input type="text" class="form-control" id="deploy-ci-searchdomain" placeholder="lab.local">
                        </div>
                        <div class="col-12 mt-2">
                            <label class="form-label">SSH Public Keys</label>
                            <textarea class="form-control" id="deploy-ci-sshkeys" rows="3" placeholder="ssh-rsa AAAA..."></textarea>
                        </div>
                    </div>
                </div>

                <div class="mt-4 d-flex gap-2 justify-content-end">
                    <button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="deploy-submit-btn">
                        <i class="bi bi-rocket-takeoff-fill"></i> Deploy
                    </button>
                </div>
            </form>`;
    },

    async loadNodeResources(node) {
        try {
            const [storages, networks] = await Promise.all([
                API.getStorages(node),
                API.getNetworks(node),
            ]);

            const storageSelect = document.getElementById('deploy-storage');
            storageSelect.innerHTML = '<option value="">Default (from template)</option>';
            for (const s of storages) {
                const free = s.avail ? ` (${Utils.formatBytes(s.avail)} free)` : '';
                storageSelect.innerHTML += `<option value="${s.storage}">${s.storage} [${s.type}]${free}</option>`;
            }

            const bridgeSelect = document.getElementById('deploy-bridge');
            bridgeSelect.innerHTML = '<option value="">From template</option>';
            for (const n of networks) {
                bridgeSelect.innerHTML += `<option value="${n.iface}">${n.iface}${n.comments ? ' - ' + n.comments : ''}</option>`;
            }
        } catch (err) {
            // Non-critical, selects stay with defaults
        }
    },

    toggleCloudInit() {
        const enabled = document.getElementById('deploy-cloudinit-toggle').checked;
        document.getElementById('cloudinit-fields').style.display = enabled ? 'block' : 'none';
    },

    toggleStaticIp() {
        const isStatic = document.getElementById('deploy-ci-iptype').value === 'static';
        document.getElementById('static-ip-fields').style.display = isStatic ? 'flex' : 'none';
    },

    async submit(e) {
        e.preventDefault();

        const btn = document.getElementById('deploy-submit-btn');
        btn.disabled = true;
        btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Deploying...';

        const template = this.currentTemplate;
        const params = {
            source_node: template.node,
            source_type: template.type,
            source_vmid: template.vmid,
            newid: parseInt(document.getElementById('deploy-vmid').value),
            name: document.getElementById('deploy-name').value.trim(),
            target_node: document.getElementById('deploy-target-node').value,
            full: document.getElementById('deploy-full').value === '1',
            storage: document.getElementById('deploy-storage').value || undefined,
        };

        const cores = document.getElementById('deploy-cores').value;
        if (cores) params.cores = parseInt(cores);

        const memory = document.getElementById('deploy-memory').value;
        if (memory) params.memory = parseInt(memory);

        const diskResize = document.getElementById('deploy-disk-resize').value.trim();
        if (diskResize) params.disk_resize = diskResize;

        const bridge = document.getElementById('deploy-bridge').value;
        if (bridge) params.net_bridge = bridge;

        const vlan = document.getElementById('deploy-vlan').value;
        if (vlan) params.net_vlan = parseInt(vlan);

        // Cloud-Init
        if (document.getElementById('deploy-cloudinit-toggle').checked) {
            const ci = {};
            const ciUser = document.getElementById('deploy-ci-user').value.trim();
            if (ciUser) ci.ciuser = ciUser;

            const ciPw = document.getElementById('deploy-ci-password').value;
            if (ciPw) ci.cipassword = ciPw;

            const ciHostname = document.getElementById('deploy-ci-hostname').value.trim();
            if (ciHostname) ci.hostname = ciHostname;

            const sshKeys = document.getElementById('deploy-ci-sshkeys').value.trim();
            if (sshKeys) ci.sshkeys = sshKeys;

            const ipType = document.getElementById('deploy-ci-iptype').value;
            if (ipType === 'dhcp') {
                ci.ipconfig0 = 'ip=dhcp';
            } else {
                const ip = document.getElementById('deploy-ci-ip').value.trim();
                const gw = document.getElementById('deploy-ci-gw').value.trim();
                if (ip) {
                    ci.ipconfig0 = `ip=${ip}`;
                    if (gw) ci.ipconfig0 += `,gw=${gw}`;
                }
            }

            const dns = document.getElementById('deploy-ci-dns').value.trim();
            if (dns) ci.nameserver = dns;

            const searchdomain = document.getElementById('deploy-ci-searchdomain').value.trim();
            if (searchdomain) ci.searchdomain = searchdomain;

            if (Object.keys(ci).length > 0) {
                params.cloudinit = ci;
            }
        }

        // Remove undefined values
        Object.keys(params).forEach(k => params[k] === undefined && delete params[k]);

        try {
            const result = await API.clone(params);
            Toast.success(`VM/CT "${params.name}" (ID: ${params.newid}) deployed successfully!`);
            this.getModal().hide();

            if (result.warning) {
                Toast.warning(result.warning);
            }

            // Refresh dashboard after a short delay
            if (typeof Dashboard !== 'undefined' && Dashboard.refresh) {
                setTimeout(() => Dashboard.refresh(), 3000);
            }
        } catch (err) {
            // Error already shown
        } finally {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-rocket-takeoff"></i> Deploy';
        }
    }
};
