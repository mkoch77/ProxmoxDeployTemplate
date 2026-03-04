const Users = {
    users: [],
    roles: [],

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
            <div class="form-check form-switch mb-3">
                <input class="form-check-input" type="checkbox" id="modal-active" ${user.is_active ? 'checked' : ''}>
                <label class="form-check-label" for="modal-active">Active</label>
            </div>
        `;

        const saveBtn = document.getElementById('userModalSave');
        saveBtn.onclick = () => this.updateUser();
        new bootstrap.Modal(document.getElementById('userModal')).show();
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
