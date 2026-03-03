const API = {
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',

    async request(url, options = {}) {
        const defaults = {
            headers: { 'Content-Type': 'application/json' },
        };

        if (['POST', 'PUT', 'DELETE'].includes(options.method)) {
            defaults.headers['X-CSRF-Token'] = this.csrfToken;
        }

        const config = { ...defaults, ...options };
        config.headers = { ...defaults.headers, ...options.headers };

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok || data.error) {
                throw new Error(data.message || `HTTP ${response.status}`);
            }

            return data.data ?? data;
        } catch (err) {
            if (err.name !== 'AbortError') {
                Toast.error(err.message || 'Anfrage fehlgeschlagen');
            }
            throw err;
        }
    },

    get(url, params = {}) {
        const query = new URLSearchParams(params).toString();
        const fullUrl = query ? `${url}?${query}` : url;
        return this.request(fullUrl);
    },

    post(url, body = {}) {
        return this.request(url, {
            method: 'POST',
            body: JSON.stringify(body),
        });
    },

    // --- Endpoints ---

    checkAuth() {
        return this.get('api/auth.php');
    },

    getNodes() {
        return this.get('api/nodes.php');
    },

    getTemplates(node = null) {
        const params = {};
        if (node) params.node = node;
        return this.get('api/templates.php', params);
    },

    getGuests(node = null, type = null) {
        const params = {};
        if (node) params.node = node;
        if (type) params.type = type;
        return this.get('api/guests.php', params);
    },

    getGuestConfig(node, type, vmid) {
        return this.get('api/guest-config.php', { node, type, vmid });
    },

    getNextVmid() {
        return this.get('api/next-vmid.php');
    },

    getStorages(node, content = null) {
        const params = { node };
        if (content) params.content = content;
        return this.get('api/storages.php', params);
    },

    getNetworks(node) {
        return this.get('api/networks.php', { node });
    },

    clone(params) {
        return this.post('api/clone.php', params);
    },

    power(node, type, vmid, action) {
        return this.post('api/power.php', { node, type, vmid, action });
    },

    getTasks(node, limit = 50) {
        return this.get('api/tasks.php', { node, limit });
    },

    getTaskStatus(node, upid) {
        return this.get('api/tasks.php', { node, upid });
    },

    getTaskLog(node, upid) {
        return this.get('api/task-log.php', { node, upid });
    },
};
