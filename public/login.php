<?php

require_once __DIR__ . '/../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\EntraID;
use App\Session;

Bootstrap::init();

// Already logged in? Redirect to app
$user = Auth::check();
if ($user) {
    header('Location: index.php');
    exit;
}

$entraIdEnabled = EntraID::isConfigured();
$csrfToken = Session::getCsrfToken();
$v = time();
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In - ProxmoxVE Datacenter Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="assets/css/app.css?v=<?= $v ?>" rel="stylesheet">
    <style>
        body {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 0;
        }
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 1rem;
        }
        .login-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-lg);
            padding: 2.5rem;
            box-shadow: var(--shadow-lg);
        }
        .login-brand {
            text-align: center;
            margin-bottom: 2rem;
        }
        .login-brand .brand-icon {
            width: 56px;
            height: 56px;
            border-radius: var(--radius-md);
            background: #27272a;
            border: 1px solid var(--border-light);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            color: var(--text-primary);
            margin-bottom: 1rem;
        }
        .login-brand h1 {
            font-size: 1.4rem;
            font-weight: 600;
            color: var(--text-primary);
            margin: 0;
        }
        .login-brand p {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin: 0.25rem 0 0;
        }
        .login-divider {
            display: flex;
            align-items: center;
            margin: 1.5rem 0;
            color: var(--text-muted);
            font-size: 0.8rem;
        }
        .login-divider::before,
        .login-divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border-color);
        }
        .login-divider span {
            padding: 0 1rem;
        }
        .btn-microsoft {
            background: var(--bg-elevated);
            border: 1px solid var(--border-light);
            color: var(--text-primary);
            padding: 0.625rem 1.25rem;
            border-radius: var(--radius-sm);
            font-weight: 500;
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            transition: all 0.2s;
        }
        .btn-microsoft:hover {
            background: var(--bg-card-hover);
            border-color: var(--text-muted);
            color: var(--text-primary);
        }
        .login-error {
            display: none;
            background: rgba(248, 113, 113, 0.1);
            border: 1px solid rgba(248, 113, 113, 0.3);
            color: var(--accent-red);
            border-radius: var(--radius-sm);
            padding: 0.75rem 1rem;
            font-size: 0.875rem;
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-brand">
                <div class="brand-icon">
                    <i class="bi bi-server"></i>
                </div>
                <h1>ProxmoxVE</h1>
                <p>Datacenter Manager</p>
            </div>

            <div id="login-error" class="login-error"></div>

            <form id="login-form" autocomplete="on">
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input type="text" class="form-control" id="username" name="username" required autofocus autocomplete="username">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input type="password" class="form-control" id="password" name="password" required autocomplete="current-password">
                </div>
                <button type="submit" class="btn btn-primary w-100" id="btn-login">
                    <span class="btn-text">Sign In</span>
                    <span class="btn-loading d-none"><span class="spinner-border spinner-border-sm me-1"></span>Signing in...</span>
                </button>
            </form>

            <?php if ($entraIdEnabled): ?>
            <div class="login-divider"><span>or</span></div>
            <a href="api/auth-entraid.php" class="btn btn-microsoft">
                <svg width="20" height="20" viewBox="0 0 21 21"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>
                Sign in with Microsoft
            </a>
            <?php endif; ?>
        </div>
    </div>

    <script>
        const CSRF_TOKEN = '<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>';

        document.getElementById('login-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const errorEl = document.getElementById('login-error');
            const btnLogin = document.getElementById('btn-login');
            const btnText = btnLogin.querySelector('.btn-text');
            const btnLoading = btnLogin.querySelector('.btn-loading');

            errorEl.style.display = 'none';
            btnText.classList.add('d-none');
            btnLoading.classList.remove('d-none');
            btnLogin.disabled = true;

            try {
                const resp = await fetch('api/login.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN,
                    },
                    body: JSON.stringify({
                        username: document.getElementById('username').value,
                        password: document.getElementById('password').value,
                    }),
                });

                const data = await resp.json();

                if (data.success) {
                    window.location.href = 'index.php#dashboard';
                } else {
                    errorEl.textContent = data.message || 'Login failed';
                    errorEl.style.display = 'block';
                }
            } catch (err) {
                errorEl.textContent = 'Connection error';
                errorEl.style.display = 'block';
            } finally {
                btnText.classList.remove('d-none');
                btnLoading.classList.add('d-none');
                btnLogin.disabled = false;
            }
        });
    </script>
</body>
</html>
