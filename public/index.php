<?php
require_once __DIR__ . '/../vendor/autoload.php';
use App\Bootstrap;
use App\Auth;
use App\Session;
use App\Database;

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
                <button id="cluster-warnings-btn" class="btn btn-link p-0 text-danger d-none" title="Cluster Alerts"
                    onclick="App.showClusterWarnings()" style="font-size:1.1rem;line-height:1">
                    <i class="bi bi-exclamation-triangle-fill"></i>
                    <span id="cluster-warnings-count" class="badge bg-danger ms-1" style="font-size:0.65rem;vertical-align:middle"></span>
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
                        <li>
                            <a class="dropdown-item d-flex align-items-center justify-content-between" href="#" onclick="App.cycleTheme(); return false;">
                                <span><i class="bi bi-circle-half me-2"></i>Theme</span>
                                <span id="theme-label" class="badge bg-secondary ms-2"><?= ucfirst($theme) ?></span>
                            </a>
                        </li>
                        <?php if (in_array('users.manage', $perms)): ?>
                        <li><a class="dropdown-item" href="#users" onclick="App.navigate('users');"><i class="bi bi-people-fill me-2"></i>User Management</a></li>
                        <?php endif; ?>
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
                <?php if (in_array('cluster.maintenance', $perms)): ?>
                <a href="#maintenance" class="sidebar-link" data-page="maintenance" title="Maintenance">
                    <div class="sidebar-icon"><i class="bi bi-wrench-adjustable"></i></div>
                    <span>Maintenance</span>
                </a>
                <?php endif; ?>
                <?php if (in_array('drs.view', $perms)): ?>
                <a href="#loadbalancing" class="sidebar-link" data-page="loadbalancing" title="Loadbalancing">
                    <div class="sidebar-icon"><i class="bi bi-shuffle"></i></div>
                    <span>Loadbalancing</span>
                </a>
                <?php endif; ?>
                <?php if (in_array('template.deploy', $perms)): ?>
                <a href="#deploy" class="sidebar-link" data-page="deploy" title="Deploy">
                    <div class="sidebar-icon"><i class="bi bi-rocket-takeoff-fill"></i></div>
                    <span>Deploy</span>
                </a>
                <?php endif; ?>
                <a href="#tasks" class="sidebar-link" data-page="tasks" title="Tasks">
                    <div class="sidebar-icon"><i class="bi bi-terminal-fill"></i></div>
                    <span>Tasks</span>
                </a>
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

    <!-- Cluster Warnings Modal -->
    <div class="modal fade" id="clusterWarningsModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header border-danger" style="border-bottom-color:var(--bs-danger)!important">
                    <h5 class="modal-title text-danger"><i class="bi bi-exclamation-triangle-fill me-2"></i>Cluster Alerts</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="cluster-warnings-body"></div>
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
                <div class="modal-body p-0" style="background:#000">
                    <div id="ssh-terminal-container" style="padding:8px"></div>
                </div>
                <div class="modal-footer justify-content-start py-2">
                    <span id="ssh-terminal-status" class="text-muted small"></span>
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

    <!-- Task Log Modal -->
    <div class="modal fade" id="taskLogModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-terminal-fill me-2"></i>Task Log</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <pre id="task-log-content" class="log-viewer">Loading...</pre>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999;margin-top:70px;"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
    <script src="assets/js/components/tasks.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/health.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/maintenance.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/loadbalancer.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/users.js?v=<?= $v ?>"></script>
    <script src="assets/js/app.js?v=<?= $v ?>"></script>
</body>
</html>
