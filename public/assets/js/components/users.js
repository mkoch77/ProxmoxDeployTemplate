// Feature groups: define which permissions map to RO and RW access per page/function.
// ro_perms: permissions that enable read-only access.
// rw_perms: additional permissions on top of ro for full write access.
// If ro_perms is empty, options are Default / Granted / Denied (no RO concept).
const PERMISSION_FEATURES = [
    {
        id: 'cluster_health',
        label: 'Cluster Health',
        icon: 'bi-heart-pulse',
        ro_perms: ['cluster.health.view'],
        rw_perms: [],
    },
    {
        id: 'vm_power',
        label: 'VM Power Control',
        icon: 'bi-power',
        ro_perms: [],
        rw_perms: ['vm.start', 'vm.stop', 'vm.reboot', 'vm.shutdown'],
    },
    {
        id: 'vm_migrate',
        label: 'VM Migration',
        icon: 'bi-arrow-left-right',
        ro_perms: [],
        rw_perms: ['vm.migrate'],
    },
    {
        id: 'vm_delete',
        label: 'VM Delete',
        icon: 'bi-trash',
        ro_perms: [],
        rw_perms: ['vm.delete'],
    },
    {
        id: 'deploy',
        label: 'Deploy Templates',
        icon: 'bi-rocket-takeoff',
        ro_perms: [],
        rw_perms: ['template.deploy'],
    },
    {
        id: 'maintenance',
        label: 'Maintenance & Updates',
        icon: 'bi-wrench-adjustable',
        ro_perms: [],
        rw_perms: ['cluster.maintenance', 'cluster.update'],
    },
    {
        id: 'loadbalancing',
        label: 'Load Balancing',
        icon: 'bi-diagram-3',
        ro_perms: ['drs.view'],
        rw_perms: ['drs.manage'],
    },
    {
        id: 'ha',
        label: 'High Availability',
        icon: 'bi-shield-check',
        ro_perms: [],
        rw_perms: ['cluster.ha'],
    },
    {
        id: 'users',
        label: 'User Management',
        icon: 'bi-people-fill',
        ro_perms: [],
        rw_perms: ['users.manage'],
    },
    {
        id: 'community_install',
        label: 'Community Script Install',
        icon: 'bi-cloud-arrow-down',
        ro_perms: [],
        rw_perms: ['community.install'],
    },
];

const Users = {
    users: [],
    roles: [],
    rolePermissions: {}, // roleId → [permKeys]

    async init() {
        this.render();
        await this.loadData();
    },

    destroy() {},

    render() {
        const main = document.getElementById('page-content');
        main.innerHTML = `
            <div class="section-header d-flex justify-content-between align-items-center">
                <h2><i class="bi bi-people-fill"></i> User Management</h2>
                <button class="btn btn-primary btn-sm" onclick="Users.showCreateModal()">
                    <i class="bi bi-person-plus me-1"></i>New User
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-dark table-hover align-middle">
                    <thead>
                        <tr>
                            <th>Username</th>
                            <th>Display Name</th>
                            <th>Email</th>
                            <th>Auth</th>
                            <th>Roles</th>
                            <th>Status</th>
                            <th style="width:120px">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="users-tbody">
                        <tr><td colspan="7" class="text-center text-muted py-4">Loading users...</td></tr>
                    </tbody>
                </table>
            </div>

            <div class="modal fade" id="userModal" tabindex="-1">
                <div class="modal-dialog glass-modal">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="userModalTitle">New User</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body" id="userModalBody"></div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                            <button type="button" class="btn btn-primary btn-sm" id="userModalSave">Save</button>
                        </div>
                    </div>
                </div>
            </div>
        `;
    },

    async loadData() {
        try {
            const data = await API.getUsers();
            this.users = data.users || [];
            this.roles = data.roles || [];
            this.rolePermissions = data.role_permissions || {};
            this.renderTable();
        } catch (err) {
            Toast.error('Failed to load users');
        }
    },

    renderTable() {
        const tbody = document.getElementById('users-tbody');
        if (!this.users.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-4">No users found</td></tr>';
            return;
        }

        tbody.innerHTML = this.users.map(u => `
            <tr>
                <td><strong>${escapeHtml(u.username)}</strong></td>
                <td>${escapeHtml(u.display_name)}</td>
                <td class="text-muted">${escapeHtml(u.email || '-')}</td>
                <td>
                    <span class="badge ${u.auth_provider === 'entraid' ? 'bg-info' : 'bg-secondary'}">
                        ${u.auth_provider === 'entraid' ? 'EntraID' : 'Local'}
                    </span>
                </td>
                <td>${u.roles.map(r => `<span class="badge bg-secondary me-1">${escapeHtml(r.name)}</span>`).join('')}</td>
                <td>
                    <span class="badge ${u.is_active ? 'bg-success' : 'bg-danger'}">
                        ${u.is_active ? 'Active' : 'Inactive'}
                    </span>
                </td>
                <td>
                    <button class="btn btn-outline-light btn-sm me-1" onclick="Users.showEditModal(${u.id})" title="Edit">
                        <i class="bi bi-pencil"></i>
                    </button>
                    ${u.id !== Permissions.user.id ? `
                        <button class="btn btn-outline-danger btn-sm" onclick="Users.deleteUser(${u.id}, '${escapeHtml(u.username)}')" title="Delete">
                            <i class="bi bi-trash"></i>
                        </button>
                    ` : ''}
                </td>
            </tr>
        `).join('');
    },

    showCreateModal() {
        document.getElementById('userModalTitle').textContent = 'New User';
        const roleChecks = this.roles.map(r => `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="role-${r.id}" value="${r.id}">
                <label class="form-check-label" for="role-${r.id}">${escapeHtml(r.name)} <small class="text-muted">- ${escapeHtml(r.description)}</small></label>
            </div>
        `).join('');

        document.getElementById('userModalBody').innerHTML = `
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" id="modal-username" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Display Name</label>
                <input type="text" class="form-control" id="modal-displayname">
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" id="modal-email">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" class="form-control" id="modal-password" required>
            </div>
            <div class="mb-3">
                <label class="form-label">Roles</label>
                ${roleChecks}
            </div>
        `;

        const saveBtn = document.getElementById('userModalSave');
        saveBtn.onclick = () => this.createUser();
        new bootstrap.Modal(document.getElementById('userModal')).show();
    },

    showEditModal(userId) {
        const user = this.users.find(u => u.id === userId);
        if (!user) return;

        document.getElementById('userModalTitle').textContent = 'Edit User';
        const userRoleIds = user.roles.map(r => r.id);
        const roleChecks = this.roles.map(r => `
            <div class="form-check">
                <input class="form-check-input" type="checkbox" id="role-${r.id}" value="${r.id}" ${userRoleIds.includes(r.id) ? 'checked' : ''}>
                <label class="form-check-label" for="role-${r.id}">${escapeHtml(r.name)} <small class="text-muted">- ${escapeHtml(r.description)}</small></label>
            </div>
        `).join('');

        const overrides = user.permission_overrides || {};
        const featureRows = PERMISSION_FEATURES.map(f => this.renderFeatureRow(f, overrides, userRoleIds)).join('');

        document.getElementById('userModalBody').innerHTML = `
            <input type="hidden" id="modal-userid" value="${user.id}">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" value="${escapeHtml(user.username)}" disabled>
            </div>
            <div class="mb-3">
                <label class="form-label">Display Name</label>
                <input type="text" class="form-control" id="modal-displayname" value="${escapeHtml(user.display_name)}">
            </div>
            <div class="mb-3">
                <label class="form-label">Email</label>
                <input type="email" class="form-control" id="modal-email" value="${escapeHtml(user.email || '')}">
            </div>
            ${user.auth_provider === 'local' ? `
                <div class="mb-3">
                    <label class="form-label">New Password <small class="text-muted">(leave empty to keep current)</small></label>
                    <input type="password" class="form-control" id="modal-password">
                </div>
            ` : ''}
            <div class="mb-3">
                <label class="form-label">Roles</label>
                ${roleChecks}
            </div>
            <div class="mb-3">
                <label class="form-label d-flex align-items-center gap-2">
                    Permission Overrides
                    <span class="badge bg-secondary" style="font-size:0.68rem;font-weight:normal">overrides role defaults per function</span>
                </label>
                <div style="border:1px solid var(--border-color);border-radius:6px;overflow:hidden">
                    ${featureRows}
                </div>
            </div>
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="modal-active" ${user.is_active ? 'checked' : ''}>
                <label class="form-check-label" for="modal-active">Active</label>
            </div>
        `;

        // Wire up override button groups
        document.querySelectorAll('.perm-feature-btns button').forEach(btn => {
            btn.addEventListener('click', () => {
                const group = btn.closest('.perm-feature-btns');
                group.querySelectorAll('button').forEach(b => b.classList.remove('active'));
                btn.classList.add('active');
            });
        });

        const saveBtn = document.getElementById('userModalSave');
        saveBtn.onclick = () => this.updateUser();
        new bootstrap.Modal(document.getElementById('userModal')).show();
    },

    // Returns the current state of a feature given existing overrides
    getFeatureState(feature, overrides) {
        const allPerms = [...feature.ro_perms, ...feature.rw_perms];
        if (allPerms.length === 0) return 'default';

        const hasAnyOverride = allPerms.some(p => p in overrides);
        if (!hasAnyOverride) return 'default';

        const allGranted = allPerms.every(p => overrides[p] === true);
        if (allGranted) return 'rw';

        const allDenied = allPerms.every(p => overrides[p] === false);
        if (allDenied) return 'deny';

        // RO: ro_perms granted, rw_perms denied
        if (feature.ro_perms.length > 0 && feature.rw_perms.length > 0) {
            const roGranted = feature.ro_perms.every(p => overrides[p] === true);
            const rwDenied  = feature.rw_perms.every(p => overrides[p] === false);
            if (roGranted && rwDenied) return 'ro';
        }

        return 'default';
    },

    // Returns true if the user's roles provide any permission in this feature
    roleHasFeature(feature, userRoleIds) {
        const allPerms = [...feature.ro_perms, ...feature.rw_perms];
        for (const roleId of userRoleIds) {
            const rolePerms = this.rolePermissions[roleId] || [];
            if (allPerms.some(p => rolePerms.includes(p))) return true;
        }
        return false;
    },

    renderFeatureRow(feature, overrides, userRoleIds) {
        const state = this.getFeatureState(feature, overrides);
        const hasRO = feature.ro_perms.length > 0 && feature.rw_perms.length > 0;
        const fromRole = this.roleHasFeature(feature, userRoleIds);

        const roleHint = `<small class="text-muted ms-auto me-2" style="font-size:0.72rem">
            Role: ${fromRole ? '<span class="text-success">✓</span>' : '<span class="text-muted">–</span>'}
        </small>`;

        const btn = (s, label, cls) =>
            `<button type="button" class="btn btn-sm ${cls} ${state === s ? 'active' : ''}" data-state="${s}">${label}</button>`;

        const buttons = hasRO
            ? `${btn('default', 'Default', 'btn-outline-secondary')}${btn('ro', 'Read Only', 'btn-outline-info')}${btn('rw', 'Full', 'btn-outline-success')}${btn('deny', 'Deny', 'btn-outline-danger')}`
            : `${btn('default', 'Default', 'btn-outline-secondary')}${btn('rw', 'Granted', 'btn-outline-success')}${btn('deny', 'Deny', 'btn-outline-danger')}`;

        return `
            <div class="d-flex align-items-center gap-2 px-3 py-2" style="border-bottom:1px solid var(--border-color)" data-feature="${feature.id}">
                <i class="bi ${feature.icon} text-muted" style="width:16px;flex-shrink:0"></i>
                <span class="small" style="min-width:160px">${feature.label}</span>
                ${roleHint}
                <div class="btn-group btn-group-sm perm-feature-btns" role="group" data-feature="${feature.id}">
                    ${buttons}
                </div>
            </div>
        `;
    },

    // Collects permission override dict from the feature button groups
    collectPermissionOverrides() {
        const overrides = {};
        document.querySelectorAll('[data-feature].d-flex').forEach(row => {
            const featureId = row.dataset.feature;
            const feature = PERMISSION_FEATURES.find(f => f.id === featureId);
            if (!feature) return;

            const activeBtn = row.querySelector('.perm-feature-btns button.active');
            const state = activeBtn?.dataset.state || 'default';
            if (state === 'default') return;

            const allPerms = [...feature.ro_perms, ...feature.rw_perms];

            if (state === 'rw') {
                allPerms.forEach(p => { overrides[p] = true; });
            } else if (state === 'deny') {
                allPerms.forEach(p => { overrides[p] = false; });
            } else if (state === 'ro') {
                feature.ro_perms.forEach(p => { overrides[p] = true; });
                feature.rw_perms.forEach(p => { overrides[p] = false; });
            }
        });
        return overrides;
    },

    getSelectedRoleIds() {
        return this.roles
            .filter(r => document.getElementById(`role-${r.id}`)?.checked)
            .map(r => r.id);
    },

    async createUser() {
        const username = document.getElementById('modal-username').value.trim();
        const password = document.getElementById('modal-password').value;

        if (!username || !password) {
            Toast.error('Username and password required');
            return;
        }

        try {
            const result = await API.post('api/users.php', {
                username,
                display_name: document.getElementById('modal-displayname').value.trim(),
                email: document.getElementById('modal-email').value.trim(),
                password,
                role_ids: this.getSelectedRoleIds(),
            });

            bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
            Toast.success('User created');
            await this.loadData();
        } catch (err) {
            // Error already shown by API wrapper
        }
    },

    async updateUser() {
        const id = parseInt(document.getElementById('modal-userid').value);
        const data = {
            id,
            display_name: document.getElementById('modal-displayname').value.trim(),
            email: document.getElementById('modal-email').value.trim(),
            role_ids: this.getSelectedRoleIds(),
            permission_overrides: this.collectPermissionOverrides(),
        };

        const passwordEl = document.getElementById('modal-password');
        if (passwordEl && passwordEl.value) {
            data.password = passwordEl.value;
        }

        const activeEl = document.getElementById('modal-active');
        if (activeEl) {
            data.is_active = activeEl.checked ? 1 : 0;
        }

        try {
            await API.request('api/users.php', {
                method: 'PUT',
                body: JSON.stringify(data),
            });
            bootstrap.Modal.getInstance(document.getElementById('userModal')).hide();
            Toast.success('User updated');
            await this.loadData();
        } catch (err) {
            // Error already shown by API wrapper
        }
    },

    async deleteUser(id, username) {
        if (!confirm(`Really delete user "${username}"?`)) return;

        try {
            const resp = await fetch('api/users.php', {
                method: 'DELETE',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': API.csrfToken,
                },
                body: JSON.stringify({ id }),
            });
            const result = await resp.json();

            if (result.success) {
                Toast.success('User deleted');
                await this.loadData();
            } else {
                Toast.error(result.message || 'Failed to delete');
            }
        } catch (err) {
            Toast.error('Failed to delete user');
        }
    },
};
