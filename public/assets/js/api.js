const API = {
    csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
    _controllers: {},  // keyed AbortControllers for deduplication

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
            let data;
            try {
                data = await response.json();
            } catch {
                throw new Error(`Server error (HTTP ${response.status}) — invalid response`);
            }

            if (response.status === 401) {
                window.location.href = 'login.php';
                return;
            }

            if (!response.ok || data.error) {
                const err = new Error(data.message || `HTTP ${response.status}`);
                err.status = response.status;
                err.details = data.details || null;
                throw err;
            }

            return data.data ?? data;
        } catch (err) {
            if (!silent && err.name !== 'AbortError') {
                const msg = (err instanceof TypeError && err.message.includes('NetworkError'))
                    ? 'Connection lost — server not reachable'
                    : (err.message || 'Request failed');
                Toast.error(msg);
            }
            throw err;
        }
    },

    /**
     * Abort-aware GET: cancels any previous in-flight request with the same key.
     * Use for polling endpoints to prevent request pile-up when backend is slow.
     */
    getSilentAbortable(key, url, params = {}) {
        if (this._controllers[key]) this._controllers[key].abort();
        const controller = new AbortController();
        this._controllers[key] = controller;
        const query = new URLSearchParams(params).toString();
        const fullUrl = query ? `${url}?${query}` : url;
        return this.request(fullUrl, { silent: true, signal: controller.signal }).finally(() => {
            if (this._controllers[key] === controller) delete this._controllers[key];
        });
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

    postSilent(url, body = {}) {
        return this.request(url, {
            method: 'POST',
            body: JSON.stringify(body),
            silent: true,
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

    getClusterStats() {
        return this.get('api/cluster-stats.php');
    },

    getMaintenanceNodeStatus(node) {
        return this.get('api/maintenance-status.php', { node });
    },

    getUsers() {
        return this.get('api/users.php');
    },

    // --- Tags ---

    getTags() {
        return this.getSilent('api/tags.php');
    },

    setTagColor(tag, color, textColor = 'ffffff') {
        return this.post('api/tags.php', { tag, color, text_color: textColor });
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

    cloudInitStart(params) {
        return this.post('api/cloud-init-start.php', params);
    },

    // --- Service Templates ---

    getServiceTemplates() {
        return this.get('api/service-templates.php');
    },

    saveServiceTemplate(data) {
        return this.post('api/service-templates.php', data);
    },

    deleteServiceTemplate(id) {
        return this.post('api/service-templates.php', { action: 'delete', id });
    },

    // --- Custom Images ---

    getCustomImages() {
        return this.get('api/custom-images.php');
    },

    registerCustomImage(data) {
        return this.post('api/custom-images.php', data);
    },

    uploadCustomImage(formData) {
        return fetch('api/custom-images.php', {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.csrfToken },
            body: formData,
        }).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.error || 'Upload failed'); return d.data; });
    },

    deleteCustomImage(id, deleteFile = false) {
        return fetch(`api/custom-images.php?id=${id}${deleteFile ? '&delete_file=1' : ''}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': this.csrfToken },
        }).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.error || 'Delete failed'); return d.data; });
    },

    deleteStorageIso(volid) {
        return fetch(`api/custom-images.php?volid=${encodeURIComponent(volid)}`, {
            method: 'DELETE',
            headers: { 'X-CSRF-Token': this.csrfToken },
        }).then(r => r.json()).then(d => { if (!d.success) throw new Error(d.message || 'Delete failed'); return d.data; });
    },

    distributeCustomImage(id) {
        return this.post('api/custom-images-distribute.php', { id });
    },

    getNodeInfo(node) {
        return this.get('api/node-info.php', { node });
    },

    // --- Cloud-Init Key Rotation ---

    previewCiKeyRotation() {
        return this.get('api/cloud-init-rotate-key.php');
    },

    rotateCiKey() {
        return this.post('api/cloud-init-rotate-key.php');
    },

    // --- Windows Deploy ---

    getWindowsImages() {
        return this.get('api/windows-images.php');
    },

    saveWindowsImage(data) {
        return this.post('api/windows-images.php', data);
    },

    deleteWindowsImage(id) {
        return this.delete(`api/windows-images.php?id=${id}`);
    },

    windowsDeploy(params) {
        return this.post('api/windows-deploy.php', params);
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

    // --- Snapshots ---

    getSnapshots(node, type, vmid) {
        return this.get('api/snapshots.php', { node, type, vmid });
    },

    createSnapshot(node, type, vmid, snapname, description = '', vmstate = false) {
        return this.post('api/snapshots.php', { node, type, vmid, action: 'create', snapname, description, vmstate });
    },

    deleteSnapshot(node, type, vmid, snapname) {
        return this.post('api/snapshots.php', { node, type, vmid, action: 'delete', snapname });
    },

    deleteAllSnapshots(node, type, vmid) {
        return this.post('api/snapshots.php', { node, type, vmid, action: 'delete-all' });
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
