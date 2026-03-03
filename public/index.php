<?php
require_once __DIR__ . '/../vendor/autoload.php';
use App\Session;

Session::start();
$csrfToken = Session::getCsrfToken();
$v = time(); // cache-busting
?>
<!DOCTYPE html>
<html lang="de" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title>Proxmox Deploy</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= $v ?>" rel="stylesheet">
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
                    <span class="brand-text">Proxmox Deploy</span>
                    <span class="brand-sub d-none d-sm-inline">Template Manager</span>
                </div>
            </a>
            <div class="d-flex align-items-center gap-3">
                <span id="connection-status" class="conn-badge connecting">
                    <span class="conn-dot"></span>
                    <span class="conn-text">Verbinde...</span>
                </span>
            </div>
        </div>
    </nav>

    <div class="d-flex" id="wrapper">
        <!-- Sidebar -->
        <nav id="sidebar">
            <div class="sidebar-nav">
                <a href="#dashboard" class="sidebar-link active" data-page="dashboard">
                    <div class="sidebar-icon"><i class="bi bi-grid-1x2-fill"></i></div>
                    <span>Dashboard</span>
                </a>
                <a href="#deploy" class="sidebar-link" data-page="deploy">
                    <div class="sidebar-icon"><i class="bi bi-rocket-takeoff-fill"></i></div>
                    <span>Deploy</span>
                </a>
                <a href="#tasks" class="sidebar-link" data-page="tasks">
                    <div class="sidebar-icon"><i class="bi bi-terminal-fill"></i></div>
                    <span>Tasks</span>
                </a>
            </div>
            <div class="sidebar-footer">
                <div class="sidebar-divider"></div>
                <div class="px-3 py-2">
                    <small class="text-muted">Proxmox VE</small>
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

    <!-- Deploy Modal -->
    <div class="modal fade" id="deployModal" tabindex="-1">
        <div class="modal-dialog modal-lg modal-dialog-centered">
            <div class="modal-content glass-modal">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-rocket-takeoff-fill me-2"></i>VM/CT Deployen</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="deploy-modal-body">
                    <!-- Filled by deploy.js -->
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
                    <pre id="task-log-content" class="log-viewer">Lade...</pre>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" class="toast-container position-fixed top-0 end-0 p-3" style="z-index:9999;margin-top:70px;"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/utils.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/toast.js?v=<?= $v ?>"></script>
    <script src="assets/js/api.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/controls.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/dashboard.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/templates.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/deploy.js?v=<?= $v ?>"></script>
    <script src="assets/js/components/tasks.js?v=<?= $v ?>"></script>
    <script src="assets/js/app.js?v=<?= $v ?>"></script>
</body>
</html>
