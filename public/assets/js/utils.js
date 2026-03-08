const Utils = {
    formatBytes(bytes) {
        if (bytes === 0 || bytes == null) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    },

    formatUptime(seconds) {
        if (!seconds) return '-';
        const d = Math.floor(seconds / 86400);
        const h = Math.floor((seconds % 86400) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        if (d > 0) return `${d}d ${h}h ${m}m`;
        if (h > 0) return `${h}h ${m}m`;
        return `${m}m`;
    },

    statusBadgeClass(status) {
        switch (status) {
            case 'running': return 'badge-running';
            case 'stopped': return 'badge-stopped';
            case 'paused':  return 'badge-paused';
            default:        return 'badge-unknown';
        }
    },

    statusIcon(status) {
        switch (status) {
            case 'running': return 'bi-play-circle-fill';
            case 'stopped': return 'bi-stop-circle-fill';
            case 'paused':  return 'bi-pause-circle-fill';
            default:        return 'bi-question-circle-fill';
        }
    },

    typeIcon(type) {
        return type === 'qemu' ? 'bi-pc-display' : 'bi-box';
    },

    typeLabel(type) {
        return type === 'qemu' ? 'VM' : 'CT';
    },

    escapeHtml(str) {
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    },

    debounce(fn, ms) {
        let timer;
        return function (...args) {
            clearTimeout(timer);
            timer = setTimeout(() => fn.apply(this, args), ms);
        };
    },

    formatDate(timestamp) {
        if (!timestamp) return '-';
        const d = new Date(timestamp * 1000);
        return d.toLocaleString('de-DE');
    },

    cpuPercent(cpu) {
        if (cpu == null) return '-';
        return (cpu * 100).toFixed(1) + '%';
    }
};

// Global alias used by components
function escapeHtml(str) { return Utils.escapeHtml(str); }

// Load a .pub file into a textarea, appending to existing content
function loadSshKeyFile(input, targetId) {
    const file = input.files[0];
    if (!file) return;
    const reader = new FileReader();
    reader.onload = e => {
        const key = e.target.result.trim();
        const ta = document.getElementById(targetId);
        if (!ta) return;
        const existing = ta.value.trim();
        ta.value = existing ? existing + '\n' + key : key;
        input.value = ''; // reset so same file can be reloaded
    };
    reader.readAsText(file);
}

/**
 * Get the least loaded online node name from the loadbalancer.
 * Returns null if unavailable. Caches result for 30 seconds.
 */
Utils._leastLoadedCache = { node: null, ts: 0 };
Utils.getLeastLoadedNode = async function() {
    const now = Date.now();
    if (this._leastLoadedCache.node && now - this._leastLoadedCache.ts < 30000) {
        return this._leastLoadedCache.node;
    }
    try {
        const data = await API.getSilent('api/loadbalancer.php');
        const nodes = data?.balance?.nodes;
        if (!nodes || !nodes.length) return null;
        // balance.nodes only contains online non-maintenance nodes, sorted by score (lowest = least loaded)
        const sorted = [...nodes].sort((a, b) => (a.score ?? 100) - (b.score ?? 100));
        this._leastLoadedCache = { node: sorted[0].node, ts: now };
        return sorted[0].node;
    } catch (_) {
        return null;
    }
};
