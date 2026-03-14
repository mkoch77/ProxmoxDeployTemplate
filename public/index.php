<?php
require_once __DIR__ . '/../vendor/autoload.php';
use App\Bootstrap;
use App\Auth;
use App\Session;
use App\Database;
use App\Config;

Bootstrap::init();

// Auth check - redirect to login if not authenticated
$user = Auth::check();
if (!$user) {
    header('Location: login.php');
    exit;
}

// Load user theme preference
$theme = 'auto'; // default: follow system
$db = Database::connection();
$stmt = $db->prepare('SELECT theme FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$row = $stmt->fetch();
if ($row && !empty($row['theme'])) {
    $theme = $row['theme'];
}

$csrfToken = Session::getCsrfToken();
$v = time(); // cache-busting
$perms = $user['permissions'];
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark" data-theme-pref="<?= htmlspecialchars($theme) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>ProxmoxVE Datacenter Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= $v ?>" rel="stylesheet">
    <script>
        window.APP_USER = <?= json_encode([
            'id' => $user['id'],
            'username' => $user['username'],
            'display_name' => $user['display_name'],
            'permissions' => $user['permissions'],
            'roles' => $user['roles'],
            'theme' => $theme,
            'ssh_public_keys' => $user['ssh_public_keys'] ?? '',
            'default_storage' => $user['default_storage'] ?? '',
            'ssh_enabled' => filter_var(Config::get('SSH_ENABLED', 'true'), FILTER_VALIDATE_BOOLEAN),
        ], JSON_HEX_TAG | JSON_HEX_AMP) ?>;

        // Apply theme immediately to prevent flash
        (function() {
            const pref = document.documentElement.dataset.themePref;
            if (pref === 'light') {
                document.documentElement.setAttribute('data-bs-theme', 'light');
            } else if (pref === 'dark') {
                document.documentElement.setAttribute('data-bs-theme', 'dark');
            } else {
                // auto: follow system
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
                document.documentElement.setAttribute('data-bs-theme', prefersDark ? 'dark' : 'light');
            }
        })();
    </script>
</head>
<body>
    <!-- Navbar -->
    <nav class="navbar fixed-top glass-nav">
        <div class="container-fluid px-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="#dashboard">
                <div class="brand-icon">
                    <i class="bi bi-hdd-rack-fill"></i>
                </div>
                <div>
                    <span class="brand-text">ProxmoxVE</span>
                    <span class="brand-sub d-none d-sm-inline">Datacenter Manager</span>
                    <span class="badge bg-secondary ms-2" style="font-size:0.6rem;vertical-align:middle">v0.2</span>
                </div>
            </a>
            <div class="d-flex align-items-center gap-3">
                <?php if (in_array('cluster.update', $perms)): ?>
                <button id="cluster-updates-btn" class="btn btn-link p-0 d-none d-flex flex-column align-items-center" title="Updates available"
                    onclick="location.hash='maintenance';setTimeout(()=>{if(typeof Maintenance!=='undefined')Maintenance.showTab('updates')},100)" style="font-size:1.1rem;line-height:1;color:var(--bs-success)">
                    <i class="bi bi-arrow-down-circle-fill"></i>
                    <span id="cluster-updates-count" class="badge bg-success" style="font-size:0.55rem;margin-top:2px"></span>
                </button>
                <?php endif; ?>
                <button id="cluster-info-btn" class="btn btn-link p-0 d-none d-flex flex-column align-items-center" title="Cluster Info"
                    onclick="App.showClusterWarnings('info')" style="font-size:1.1rem;line-height:1;color:var(--bs-info)">
                    <i class="bi bi-info-circle-fill"></i>
                    <span id="cluster-info-count" class="badge bg-info text-dark" style="font-size:0.55rem;margin-top:2px"></span>
                </button>
                <button id="cluster-warnings-btn" class="btn btn-link p-0 d-none d-flex flex-column align-items-center" title="Cluster Alerts"
                    onclick="App.showClusterWarnings()" style="font-size:1.1rem;line-height:1">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span id="cluster-warnings-count" class="badge" style="font-size:0.55rem;margin-top:2px"></span>
                </button>
                <span id="connection-status" class="conn-badge connecting">
                    <span class="conn-dot"></span>
                    <span class="conn-text">Connecting...</span>
                </span>
                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle d-flex align-items-center gap-2" data-bs-toggle="dropdown" style="border-color: var(--border-light); color: var(--text-secondary);">
                        <i class="bi bi-person-circle"></i>
                        <span class="d-none d-md-inline"><?= htmlspecialchars($user['display_name'] ?: $user['username']) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end dropdown-menu-dark">
                        <li><span class="dropdown-item-text text-muted small"><?= htmlspecialchars($user['username']) ?></span></li>
                        <li><hr class="dropdown-divider"></li>
                        <?php if (in_array('users.manage', $perms)): ?>
                        <li><a class="dropdown-item" href="#users" onclick="App.navigate('users');"><i class="bi bi-people-fill me-2"></i>User Management</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="#" onclick="App.showProfile(); return false;"><i class="bi bi-person-gear me-2"></i>Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="#" onclick="App.logout(); return false;"><i class="bi bi-box-arrow-right me-2"></i>Sign Out</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </nav>

    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar">
            <div class="sidebar-toggle-btn" id="sidebar-toggle" onclick="App.toggleSidebar()" title="Toggle sidebar">
                <i class="bi bi-layout-sidebar"></i>
            </div>
            <div class="sidebar-nav">
                <a href="#dashboard" class="sidebar-link active" data-page="dashboard" title="Dashboard">
                    <div class="sidebar-icon"><i class="bi bi-grid-1x2-fill"></i></div>
                    <span>Dashboard</span>
                </a>
                <?php if (in_array('cluster.health.view', $perms)): ?>
                <a href="#health" class="sidebar-link" data-page="health" title="Cluster Health">
                    <div class="sidebar-icon"><i class="bi bi-heart-pulse-fill"></i></div>
                    <span>Cluster Health</span>
                </a>
                <?php endif; ?>
                <?php if (in_array('template.deploy', $perms)): ?>
                <a href="#deploy" class="sidebar-link" data-page="deploy" title="Deploy">
                    <div class="sidebar-icon"><i class="bi bi-rocket-takeoff-fill"></i></div>
                    <span>Deploy</span>
                </a>
                <?php endif; ?>
                <?php if (in_array('cluster.maintenance', $perms)): ?>
                <a href="#maintenance" class="sidebar-link" data-page="maintenance" title="Maintenance">
                    <div class="sidebar-icon"><i class="bi bi-wrench-adjustable"></i></div>
                    <span>Maintenance</span>
                </a>
                <?php endif; ?>
                <?php if (in_array('monitoring.view', $perms)): ?>
                <a href="#monitoring" class="sidebar-link" data-page="monitoring" title="Monitoring">
                    <div class="sidebar-icon"><i class="bi bi-graph-up"></i></div>
                    <span>Monitoring</span>
                </a>
                <?php endif; ?>
                <?php if (in_array('drs.view', $perms)): ?>
                <a href="#loadbalancing" class="sidebar-link" data-page="loadbalancing" title="Loadbalancing">
                    <div class="sidebar-icon"><i class="bi bi-shuffle"></i></div>
                    <span>Loadbalancing</span>
                </a>
                <?php endif; ?>
                <?php if (in_array('cluster.health.view', $perms)): ?>
                <a href="#reports" class="sidebar-link" data-page="reports" title="Reports">
                    <div class="sidebar-icon"><i class="bi bi-file-earmark-spreadsheet-fill"></i></div>
                    <span>Reports</span>
                </a>
                <?php endif; ?>
                <?php if (in_array('admin', $user['roles'] ?? [])): ?>
                <div class="sidebar-spacer"></div>
                <a href="#settings" class="sidebar-link" data-page="settings" title="Settings">
                    <div class="sidebar-icon"><i class="bi bi-gear-fill"></i></div>
                    <span>Settings</span>
                </a>
                <?php endif; ?>
            </div>
            <div class="sidebar-footer">
                <div class="sidebar-divider"></div>
                <div class="px-3 py-2">
                    <small class="text-muted">&copy; <a href="mailto:info@mk-itc.com" style="color:inherit;text-decoration:none">mk-itc</a> 2026</small>
                </div>
            </div>
        </nav>

        <!-- Main Content -->
        <main id="main-content">
            <div id="page-content" class="p-4">
                <!-- Content loaded dynamically -->
            </div>
        </main>
    </div>

    <!-- Cluster Alerts Modal -->
    <div class="modal fade" id="clusterWarningsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content glass-modal">
                <div class="modal-header" id="cluster-warnings-header">
                    <h5 class="modal-title" id="cluster-warnings-title"><i class="bi bi-exclamation-triangle-fill me-2"></i>Cluster Alerts</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="cluster-warnings-body" style="max-height:60vh;overflow-y:auto"></div>
            </div>
        </div>
    </div>

    <!-- Node Info Modal -->
    <div class="modal fade" id="nodeInfoModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="node-info-title"><i class="bi bi-hdd-rack me-2"></i>Node Info</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="node-info-body">
                    <div class="text-center py-4"><span class="spinner-border text-secondary"></span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- VM Detail Modal -->
    <div class="modal fade" id="vmDetailModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title" id="vm-detail-title"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="vm-detail-body"></div>
            </div>
        </div>
    </div>

    <!-- Community Script Install Modal -->
    <div class="modal fade" id="communityScriptModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center gap-2" id="cs-modal-title"></h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="cs-modal-body"></div>
            </div>
        </div>
    </div>

    <!-- Profile Modal -->
    <div class="modal fade" id="profileModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-person-gear me-2"></i>Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold"><i class="bi bi-circle-half me-1"></i>Theme</label>
                        <select class="form-select" id="profile-theme">
                            <option value="auto">Auto (System)</option>
                            <option value="dark">Dark</option>
                            <option value="light">Light</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Default Storage <small class="text-muted">(pre-selected in all storage dropdowns)</small></label>
                        <select class="form-select" id="profile-default-storage">
                            <option value="">Loading…</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <label class="form-label fw-semibold mb-0">SSH Public Keys <small class="text-muted">(one per line — pre-filled in all forms)</small></label>
                            <button type="button" class="btn btn-outline-success btn-sm" onclick="App.generateSshKey()" id="btn-generate-sshkey">
                                <i class="bi bi-key me-1"></i>Generate Key
                            </button>
                        </div>
                        <textarea class="form-control font-monospace" id="profile-sshkeys" rows="4" placeholder="ssh-ed25519 AAAA...&#10;ssh-rsa AAAA..." style="font-size:0.8rem"></textarea>
                        <div id="sshkey-gen-info" class="d-none mt-2"></div>
                    </div>
                    <?php if (($user['auth_provider'] ?? 'local') === 'local'): ?>
                    <hr>
                    <h6 class="fw-semibold mb-3"><i class="bi bi-shield-lock me-1"></i>Change Password</h6>
                    <div id="profile-pw-error" class="alert alert-danger d-none mb-3" style="font-size:0.85rem"></div>
                    <div id="profile-pw-success" class="alert alert-success d-none mb-3" style="font-size:0.85rem"></div>
                    <div class="mb-2">
                        <label class="form-label small">Current Password</label>
                        <input type="password" class="form-control" id="profile-pw-current" autocomplete="current-password">
                    </div>
                    <div class="mb-2">
                        <label class="form-label small">New Password</label>
                        <input type="password" class="form-control" id="profile-pw-new" autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small">Confirm New Password</label>
                        <input type="password" class="form-control" id="profile-pw-confirm" autocomplete="new-password">
                    </div>
                    <button type="button" class="btn btn-outline-warning btn-sm" onclick="App.changePassword()">
                        <i class="bi bi-key me-1"></i>Change Password
                    </button>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="App.saveProfile()">
                        <i class="bi bi-floppy me-1"></i>Save
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- SSH Terminal Modal — keyboard=false so Bootstrap doesn't steal keys from xterm.js -->
    <div class="modal fade" id="sshTerminalModal" tabindex="-1" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title d-flex align-items-center gap-2">
                        <i class="bi bi-terminal-fill"></i>
                        <span id="ssh-terminal-title">Install</span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" onclick="Templates.closeTerminal()"></button>
                </div>
                <div class="modal-body p-0" style="background:#000;height:70vh">
                    <div id="ssh-terminal-container" style="padding:8px;height:100%;box-sizing:border-box"></div>
                </div>
                <div class="modal-footer justify-content-start py-2">
                    <span id="ssh-terminal-status" class="text-muted small"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Cloud-Init Deploy Modal -->
    <div class="modal fade" id="cloudInitModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-clouds-fill me-2"></i>Deploy Cloud Image:
                        <span id="ci-modal-image-name" class="ms-1"></span>
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="ci-form" onsubmit="Templates.submitCloudImage(event)">
                        <h6 class="text-muted mb-2"><i class="bi bi-gear me-1"></i>VM Settings</h6>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">VM Name *</label>
                                <input type="text" class="form-control" id="ci-name" required
                                    pattern="[a-zA-Z0-9][a-zA-Z0-9.\-]{0,62}" placeholder="my-ubuntu-vm">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">VMID *</label>
                                <input type="number" class="form-control" id="ci-vmid" required min="100" max="999999999">
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label class="form-label">Node *</label>
                                <select class="form-select" id="ci-node" onchange="Templates.loadCloudInitResources(this.value)"></select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Storage *</label>
                                <select class="form-select" id="ci-storage"><option value="">Loading…</option></select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Bridge *</label>
                                <select class="form-select" id="ci-bridge"><option value="">Loading…</option></select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">VLAN</label>
                                <input type="number" class="form-control" id="ci-vlan" placeholder="—" min="1" max="4094">
                            </div>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-4">
                                <label class="form-label">CPU Cores</label>
                                <input type="number" class="form-control" id="ci-cores" value="2" min="1" max="128">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Memory (MB)</label>
                                <input type="number" class="form-control" id="ci-memory" value="2048" min="256" max="131072">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Disk Size (GB)</label>
                                <input type="number" class="form-control" id="ci-disk" value="20" min="2" max="10000">
                            </div>
                        </div>

                        <h6 class="text-muted mt-4 mb-2"><i class="bi bi-person-gear me-1"></i>Cloud-Init</h6>
                        <div class="row g-2">
                            <div class="col-md-6">
                                <label class="form-label">User</label>
                                <input type="text" class="form-control" id="ci-user" placeholder="ubuntu">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Password <small class="text-muted">(optional)</small></label>
                                <input type="password" class="form-control" id="ci-password" placeholder="Leave empty for SSH key only">
                            </div>
                        </div>
                        <div class="mt-2">
                            <div class="d-flex justify-content-between align-items-center mb-1">
                                <label class="form-label mb-0">SSH Public Keys <small class="text-muted">(optional, one per line)</small></label>
                                <label class="btn btn-outline-secondary btn-sm mb-0" title="Load from .pub file">
                                    <i class="bi bi-folder2-open"></i>
                                    <input type="file" accept=".pub" class="d-none" onchange="loadSshKeyFile(this, 'ci-sshkeys')">
                                </label>
                            </div>
                            <textarea class="form-control" id="ci-sshkeys" rows="3" placeholder="ssh-rsa AAAA..."></textarea>
                        </div>
                        <div class="row g-2 mt-1">
                            <div class="col-md-6">
                                <label class="form-label">DNS Server <small class="text-muted">(optional)</small></label>
                                <input type="text" class="form-control" id="ci-nameserver" placeholder="8.8.8.8">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Search Domain <small class="text-muted">(optional)</small></label>
                                <input type="text" class="form-control" id="ci-searchdomain" placeholder="example.com">
                            </div>
                        </div>

                        <h6 class="text-muted mt-4 mb-2"><i class="bi bi-ethernet me-1"></i>Network</h6>
                        <div class="mb-2">
                            <div class="btn-group btn-group-sm" role="group">
                                <input type="radio" class="btn-check" name="ci-ip-type" id="ci-ip-dhcp" value="dhcp" checked
                                    onchange="document.getElementById('ci-static-fields').style.display='none'">
                                <label class="btn btn-outline-light" for="ci-ip-dhcp">DHCP</label>
                                <input type="radio" class="btn-check" name="ci-ip-type" id="ci-ip-static" value="static"
                                    onchange="document.getElementById('ci-static-fields').style.display=''">
                                <label class="btn btn-outline-light" for="ci-ip-static">Static IP</label>
                            </div>
                        </div>
                        <div id="ci-static-fields" style="display:none">
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">IP Address (CIDR) *</label>
                                    <input type="text" class="form-control" id="ci-ip" placeholder="192.168.1.100/24">
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Gateway <small class="text-muted">(optional)</small></label>
                                    <input type="text" class="form-control" id="ci-gw" placeholder="192.168.1.1">
                                </div>
                            </div>
                        </div>

                        <h6 class="text-muted mt-4 mb-2"><i class="bi bi-terminal me-1"></i>Custom Setup <small class="text-muted fw-normal">(optional, runs on first boot)</small></h6>
                        <div class="mb-2">
                            <label class="form-label">Packages <small class="text-muted">(one per line — cloud-init uses the native package manager: apt, dnf, yum, zypper, …)</small></label>
                            <textarea class="form-control font-monospace" id="ci-packages" rows="2" placeholder="nginx&#10;curl&#10;git"></textarea>
                        </div>
                        <div class="mb-1">
                            <label class="form-label">Run Commands <small class="text-muted">(one per line, executed after packages)</small></label>
                            <textarea class="form-control font-monospace" id="ci-runcmd" rows="3" placeholder="systemctl enable nginx&#10;echo 'hello' > /etc/motd"></textarea>
                        </div>

                        <h6 class="text-muted mt-4 mb-2"><i class="bi bi-tags me-1"></i>Tags</h6>
                        <datalist id="ci-tag-suggestions"></datalist>
                        <div class="d-flex gap-2 align-items-center">
                            <input type="text" class="form-control form-control-sm" id="ci-tag-input"
                                list="ci-tag-suggestions" placeholder="Add tag…" style="max-width:180px"
                                oninput="Templates.onCiTagInput()" onkeydown="if(event.key==='Enter'){event.preventDefault();Templates.addCiTag();}">
                            <input type="color" id="ci-tag-color" value="#0088cc"
                                title="Tag background color" style="width:34px;height:32px;padding:2px;border:1px solid var(--border-color);border-radius:4px;cursor:pointer;background:none">
                            <input type="color" id="ci-tag-fg" value="#ffffff"
                                title="Tag text color" style="width:34px;height:32px;padding:2px;border:1px solid var(--border-color);border-radius:4px;cursor:pointer;background:none">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="Templates.addCiTag()">
                                <i class="bi bi-plus-lg"></i>
                            </button>
                        </div>
                        <div class="mt-1" style="font-size:0.72rem;color:var(--text-muted)">Left color = background &nbsp;·&nbsp; Right color = text</div>
                        <div id="ci-tags-chips" class="d-flex flex-wrap gap-1 mt-2"></div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-info me-auto" onclick="Templates.showSaveServiceTemplate()">
                        <i class="bi bi-box-seam me-1"></i>Save as Service Template
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" form="ci-form" class="btn btn-success">
                        <i class="bi bi-rocket-takeoff-fill me-1"></i>Deploy
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Deploy Modal -->
    <div class="modal fade" id="deployModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-rocket-takeoff-fill me-2"></i>Deploy VM/CT</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="deploy-modal-body">
                    <!-- Filled by deploy.js -->
                </div>
            </div>
        </div>
    </div>

    <!-- Install Agent Modal -->
    <div class="modal fade" id="installAgentModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cpu me-2"></i>Install QEMU Guest Agent</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="install-agent-body"></div>
            </div>
        </div>
    </div>

    <!-- Add to HA Modal -->
    <div class="modal fade" id="addHAModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-shield-plus me-2"></i>Add Resource to HA</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Type</label>
                        <select id="ha-add-type" class="form-select">
                            <option value="vm">VM (QEMU)</option>
                            <option value="ct">CT (LXC)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">VMID</label>
                        <input type="number" id="ha-add-vmid" class="form-control" placeholder="e.g. 100" min="100" max="999999999">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">HA Group <small class="text-muted">(optional)</small></label>
                        <input type="text" id="ha-add-group" class="form-control" placeholder="Leave empty for no group">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="Health.submitAddHA()">
                        <i class="bi bi-shield-plus me-1"></i>Add to HA
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999;margin-top:70px;"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/xterm@5.3.0/css/xterm.min.css">
    <script src="https://cdn.jsdelivr.net/npm/xterm@5.3.0/lib/xterm.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/xterm-addon-fit@0.8.0/lib/xterm-addon-fit.min.js"></script>
    <script src="assets/js/utils.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/toast.js?v=<?= $v ?>"></script>
    <script src="assets/js/permissions.js?v=<?= $v ?>"></script>
    <script src="assets/js/api.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/controls.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/dashboard.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/templates.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/deploy.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/health.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/maintenance.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/updater.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/loadbalancer.js?v=<?= $v ?>"></script>
    <?php if (in_array('cluster.health.view', $perms)): ?>
    <script src="assets/js/vendor/xlsx.mini.min.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/reports.js?v=<?= $v ?>"></script>
    <?php endif; ?>
    <?php if (in_array('monitoring.view', $perms)): ?>
    <script src="assets/js/components/monitoring.js?v=<?= $v ?>"></script>
    <?php endif; ?>
    <?php if (in_array('admin', $user['roles'] ?? [])): ?>
    <script src="assets/js/components/settings.js?v=<?= $v ?>"></script>
    <?php endif; ?>
    <script src="assets/js/components/users.js?v=<?= $v ?>"></script>
    <script src="assets/js/app.js?v=<?= $v ?>"></script>
</body>
</html>
