const API = {
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',

    async request(url, options = {}) {
        const silent = options.silent || false;
        const fetchOptions = { ...options };
        delete fetchOptions.silent;

        const defaults = {
            headers: { 'Content-Type': 'application/json' },
        };

        if (['POST', 'PUT', 'DELETE'].includes(fetchOptions.method)) {
            defaults.headers['X-CSRF-Token'] = this.csrfToken;
        }

        const config = { ...defaults, ...fetchOptions };
        config.headers = { ...defaults.headers, ...fetchOptions.headers };

        try {
            const response = await fetch(url, config);
            const data = await response.json();

            if (!response.ok || data.error) {
                throw new Error(data.message || `HTTP ${response.status}`);
            }

            return data.data ?? data;
        } catch (err) {
            if (!silent && err.name !== 'AbortError') {
                Toast.error(err.message || 'Request failed');
            }
            throw err;
        }
    },

    getSilent(url, params = {}) {
        const query = new URLSearchParams(params).toString();
        const fullUrl = query ? `${url}?${query}` : url;
        return this.request(fullUrl, { silent: true });
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

    delete(url, body = {}) {
        return this.request(url, {
            method: 'DELETE',
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

    migrate(node, type, vmid, target, online = true) {
        return this.post('api/migrate.php', { node, type, vmid, target, online });
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

    // --- New endpoints ---

    getClusterHealth() {
        return this.get('api/cluster-health.php');
    },

    getMaintenanceList() {
        return this.get('api/maintenance.php');
    },

    getMaintenanceNodeStatus(node) {
        return this.get('api/maintenance-status.php', { node });
    },

    getUsers() {
        return this.get('api/users.php');
    },

    // --- Loadbalancer ---

    getLoadbalancer() {
        return this.get('api/loadbalancer.php');
    },

    updateLoadbalancerSettings(settings) {
        return this.post('api/loadbalancer.php?action=settings', settings);
    },

    runLoadbalancer() {
        return this.post('api/loadbalancer.php?action=run');
    },

    applyLoadbalancerRecommendation(recommendationId) {
        return this.post('api/loadbalancer.php?action=apply', { recommendation_id: recommendationId });
    },

    applyAllLoadbalancerRecommendations(runId) {
        return this.post('api/loadbalancer.php?action=apply-all', { run_id: runId });
    },

    getLoadbalancerHistory(limit = 20, offset = 0) {
        return this.get('api/loadbalancer-history.php', { limit, offset });
    },

    getLoadbalancerRunDetail(runId) {
        return this.get('api/loadbalancer-history.php', { run_id: runId });
    },

    communityInstall(node, scriptPath) {
        return this.post('api/community-install.php', { node, script_path: scriptPath });
    },

    getNodeInfo(node) {
        return this.get('api/node-info.php', { node });
    },

    getGuestIPs(node, type, vmid) {
        return this.get('api/guest-ips.php', { node, type, vmid });
    },

    startAgentInstall(node, vmIp) {
        return this.post('api/terminal-start.php', { node, vm_ip: vmIp });
    },

    deleteGuest(node, type, vmid) {
        return this.post('api/delete-guest.php', { node, type, vmid });
    },

    // --- HA ---

    haEnable(sid) {
        return this.post('api/ha.php', { action: 'enable', sid });
    },

    haDisable(sid) {
        return this.post('api/ha.php', { action: 'disable', sid });
    },

    haAdd(sid, group = '', state = 'started') {
        return this.post('api/ha.php', { action: 'add', sid, group, state });
    },

    haRemove(sid) {
        return this.post('api/ha.php', { action: 'remove', sid });
    },

    // --- Rolling Update ---

    getRollingUpdateSession() {
        return this.getSilent('api/rolling-update.php');
    },

    startRollingUpdate(nodes) {
        return this.post('api/rolling-update.php', { action: 'start', nodes });
    },

    updateRollingNode(id, node, step, log = null, upgraded = null, error = null) {
        return this.post('api/rolling-update.php', { action: 'update-node', id, node, step, log, upgraded, error });
    },

    finishRollingUpdate(id, status = 'completed') {
        return this.post('api/rolling-update.php', { action: 'finish', id, status });
    },

    checkNodeUpdates(node) {
        return this.getSilent('api/node-update.php', { node });
    },

    runNodeUpdate(node) {
        return this.post('api/node-update.php', { node });
    },

    enterMaintenance(node) {
        return this.post('api/maintenance.php', { node });
    },

    leaveMaintenance(node) {
        return this.delete('api/maintenance.php', { node });
    },

    getMaintenanceStatus(node) {
        return this.getSilent('api/maintenance-status.php', { node });
    },
};
