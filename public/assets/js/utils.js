const Utils = {
    formatBytes(bytes) {
        if (bytes === 0 || bytes == null) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    },

    formatRate(bytesPerSec) {
        if (!bytesPerSec || bytesPerSec < 1) return '0 B/s';
        const k = 1024;
        const sizes = ['B/s', 'KB/s', 'MB/s', 'GB/s'];
        const i = Math.min(Math.floor(Math.log(bytesPerSec) / Math.log(k)), sizes.length - 1);
        return parseFloat((bytesPerSec / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    },

    formatNumber(n) {
        if (n == null || isNaN(n)) return '0';
        if (n >= 1e9) return (n / 1e9).toFixed(1) + 'B';
        if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
        if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
        return Math.round(n).toString();
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
    },

    sshEnabled() {
        return !!(window.APP_USER && window.APP_USER.ssh_enabled);
    },

    sshDisabledHint() {
        return '<div class="alert alert-secondary d-flex align-items-center gap-2 mb-0"><i class="bi bi-info-circle"></i>This feature requires SSH. Enable <code>SSH_ENABLED=true</code> in your <code>.env</code> configuration.</div>';
    },

    /**
     * Paginate an array and return the current page slice + metadata.
     * @param {Array} items - Full data array
     * @param {number} page - Current page (1-based)
     * @param {number} perPage - Items per page
     * @returns {{ items: Array, page: number, perPage: number, totalPages: number, total: number }}
     */
    paginate(items, page, perPage) {
        const total = items.length;
        const totalPages = Math.max(1, Math.ceil(total / perPage));
        const p = Math.max(1, Math.min(page, totalPages));
        const start = (p - 1) * perPage;
        return {
            items: items.slice(start, start + perPage),
            page: p,
            perPage,
            totalPages,
            total,
        };
    },

    /**
     * Render pagination controls HTML.
     * @param {object} pag - Result from Utils.paginate()
     * @param {string} callbackPrefix - JS expression prefix, e.g. "Dashboard.setPage"
     * @param {string} perPageCallback - JS expression prefix for per-page change, e.g. "Dashboard.setPerPage"
     * @returns {string} HTML string
     */
    paginationHtml(pag, callbackPrefix, perPageCallback) {
        if (pag.total === 0) return '';
        const { page, totalPages, total, perPage } = pag;
        const options = [25, 50, 100, 200];
        let html = `<div class="d-flex justify-content-between align-items-center px-2 py-2 pagination-bar">`;
        html += `<div class="text-muted small">Showing ${((page - 1) * perPage) + 1}–${Math.min(page * perPage, total)} of ${total}</div>`;
        html += `<div class="d-flex align-items-center gap-2">`;
        html += `<select class="form-select form-select-sm pagination-per-page" onchange="${perPageCallback}(+this.value)">`;
        for (const o of options) {
            html += `<option value="${o}" ${o === perPage ? 'selected' : ''}>${o} / page</option>`;
        }
        html += `</select>`;
        html += `<div class="btn-group btn-group-sm">`;
        html += `<button class="btn btn-outline-secondary" ${page <= 1 ? 'disabled' : ''} onclick="${callbackPrefix}(1)" title="First"><i class="bi bi-chevron-double-left"></i></button>`;
        html += `<button class="btn btn-outline-secondary" ${page <= 1 ? 'disabled' : ''} onclick="${callbackPrefix}(${page - 1})" title="Previous"><i class="bi bi-chevron-left"></i></button>`;
        html += `<span class="btn btn-outline-secondary disabled" style="min-width:80px">${page} / ${totalPages}</span>`;
        html += `<button class="btn btn-outline-secondary" ${page >= totalPages ? 'disabled' : ''} onclick="${callbackPrefix}(${page + 1})" title="Next"><i class="bi bi-chevron-right"></i></button>`;
        html += `<button class="btn btn-outline-secondary" ${page >= totalPages ? 'disabled' : ''} onclick="${callbackPrefix}(${totalPages})" title="Last"><i class="bi bi-chevron-double-right"></i></button>`;
        html += `</div></div></div>`;
        return html;
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

/**
 * Resource hover tooltip for VMs and Nodes.
 * Shows mini resource bars with monitoring data on hover.
 */
const ResourceTooltip = {
    _el: null,
    _cache: new Map(),
    _timer: null,
    _abortCtrl: null,
    _anchorEl: null,
    _safetyTimer: null,

    _getEl() {
        if (!this._el) {
            const div = document.createElement('div');
            div.id = 'resource-tooltip';
            div.className = 'resource-tooltip';
            div.addEventListener('mouseleave', () => this.hide());
            document.body.appendChild(div);
            this._el = div;
        }
        return this._el;
    },

    _bar(label, pct, val) {
        const p = Math.min(100, Math.max(0, pct));
        const cls = p >= 90 ? 'bg-danger' : p >= 70 ? 'bg-warning' : 'bg-success';
        return `<div class="rt-row">
            <span class="rt-label">${label}</span>
            <div class="rt-bar-track"><div class="rt-bar-fill ${cls}" style="width:${p}%"></div></div>
            <span class="rt-val">${val}</span>
        </div>`;
    },

    showVm(el, vmid, status) {
        if (status !== 'running') return;
        this._anchorEl = el;
        this._startSafety();
        this._timer = setTimeout(() => this._loadVm(el, vmid), 300);
    },

    async _loadVm(el, vmid) {
        const cacheKey = `vm:${vmid}`;
        const cached = this._cache.get(cacheKey);
        const now = Date.now();
        if (cached && now - cached.ts < 15000) {
            this._render(el, cached.html);
            return;
        }

        const tip = this._getEl();
        tip.innerHTML = '<div class="rt-loading"><div class="spinner-border spinner-border-sm"></div></div>';
        this._position(el, tip);
        tip.classList.add('visible');

        try {
            this._abortCtrl = new AbortController();
            const data = await API.getSilent('api/monitoring.php', { action: 'vm-summary', vmid, timerange: '1h' });
            const s = data?.summary;
            if (!s || !s.samples || +s.samples === 0) {
                this._render(el, '<div class="rt-empty">No monitoring data</div>');
                return;
            }
            const cpuPct = Math.round((+s.avg_cpu || 0) * 100);
            const cpuMax = Math.round((+s.max_cpu || 0) * 100);
            const memTotal = +s.mem_total || 0;
            const memAvg = +s.avg_mem || 0;
            const memPct = memTotal > 0 ? Math.round((memAvg / memTotal) * 100) : 0;
            const netIn = +s.avg_net_in || 0;
            const netOut = +s.avg_net_out || 0;

            let html = '<div class="rt-title">1h Average</div>';
            html += this._bar('CPU', cpuPct, `${cpuPct}% (max ${cpuMax}%)`);
            html += this._bar('RAM', memPct, `${Utils.formatBytes(memAvg)} / ${Utils.formatBytes(memTotal)}`);
            html += `<div class="rt-row">
                <span class="rt-label">Net</span>
                <span class="rt-val" style="flex:1;text-align:right"><i class="bi bi-arrow-down" style="color:var(--accent-green)"></i> ${Utils.formatBytes(netIn)}/s &nbsp;<i class="bi bi-arrow-up" style="color:var(--accent-blue)"></i> ${Utils.formatBytes(netOut)}/s</span>
            </div>`;

            this._cache.set(cacheKey, { html, ts: now });
            this._render(el, html);
        } catch (_) {
            this.hide();
        }
    },

    showNode(el, nodeName) {
        this._anchorEl = el;
        this._startSafety();
        this._timer = setTimeout(() => this._loadNode(el, nodeName), 300);
    },

    async _loadNode(el, nodeName) {
        const cacheKey = `node:${nodeName}`;
        const cached = this._cache.get(cacheKey);
        const now = Date.now();
        if (cached && now - cached.ts < 15000) {
            this._render(el, cached.html);
            return;
        }

        const tip = this._getEl();
        tip.innerHTML = '<div class="rt-loading"><div class="spinner-border spinner-border-sm"></div></div>';
        this._position(el, tip);
        tip.classList.add('visible');

        try {
            const data = await API.getSilent('api/monitoring.php', { action: 'node', node: nodeName, timerange: '1h' });
            const metrics = data?.metrics || [];
            if (metrics.length === 0) {
                this._render(el, '<div class="rt-empty">No monitoring data</div>');
                return;
            }
            // Calculate averages from metrics array
            const avg = (arr, key) => arr.reduce((s, m) => s + (+m[key] || 0), 0) / arr.length;
            const max = (arr, key) => Math.max(...arr.map(m => +m[key] || 0));
            const cpuAvg = Math.round(avg(metrics, 'cpu_pct') * 100);
            const cpuMax = Math.round(max(metrics, 'cpu_pct') * 100);
            const last = metrics[metrics.length - 1];
            const memTotal = +last.mem_total || 0;
            const memAvg = Math.round(avg(metrics, 'mem_used'));
            const memPct = memTotal > 0 ? Math.round((memAvg / memTotal) * 100) : 0;
            const netIn = Math.round(avg(metrics, 'net_in_bytes'));
            const netOut = Math.round(avg(metrics, 'net_out_bytes'));

            let html = '<div class="rt-title">1h Average</div>';
            html += this._bar('CPU', cpuAvg, `${cpuAvg}% (max ${cpuMax}%)`);
            html += this._bar('RAM', memPct, `${Utils.formatBytes(memAvg)} / ${Utils.formatBytes(memTotal)}`);
            html += `<div class="rt-row">
                <span class="rt-label">Net</span>
                <span class="rt-val" style="flex:1;text-align:right"><i class="bi bi-arrow-down" style="color:var(--accent-green)"></i> ${Utils.formatBytes(netIn)}/s &nbsp;<i class="bi bi-arrow-up" style="color:var(--accent-blue)"></i> ${Utils.formatBytes(netOut)}/s</span>
            </div>`;

            this._cache.set(cacheKey, { html, ts: now });
            this._render(el, html);
        } catch (_) {
            this.hide();
        }
    },

    _render(el, html) {
        const tip = this._getEl();
        tip.innerHTML = html;
        this._position(el, tip);
        tip.classList.add('visible');
    },

    _position(el, tip) {
        const rect = el.getBoundingClientRect();
        tip.style.display = 'block';
        const tipRect = tip.getBoundingClientRect();
        let top = rect.top - tipRect.height - 8;
        let left = rect.left + (rect.width / 2) - (tipRect.width / 2);
        // Flip below if not enough space above
        if (top < 8) top = rect.bottom + 8;
        // Keep within viewport
        left = Math.max(8, Math.min(left, window.innerWidth - tipRect.width - 8));
        tip.style.top = top + 'px';
        tip.style.left = left + 'px';
    },

    hide() {
        clearTimeout(this._timer);
        this._timer = null;
        clearInterval(this._safetyTimer);
        this._safetyTimer = null;
        this._anchorEl = null;
        if (this._el) {
            this._el.classList.remove('visible');
        }
    },

    _startSafety() {
        clearInterval(this._safetyTimer);
        this._safetyTimer = setInterval(() => {
            // Hide if anchor element was removed from DOM (e.g. page re-render)
            if (this._anchorEl && !document.body.contains(this._anchorEl)) {
                this.hide();
            }
        }, 500);
    }
};
