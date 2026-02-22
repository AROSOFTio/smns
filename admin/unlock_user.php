<?php
/**
 * Admin Tool: Unlock User Account
 */
require_once '../config.php';

$session = new Session('admin');
$auth = new Auth('admin');

if (!$auth->isLoggedIn() || $auth->getRole() !== 'admin') {
    header('Location: ' . BASE_URL . '/views/admin/login.php?error=unauthorized');
    exit;
}

$currentUser = $auth->getCurrentUser();
$success = '';
$error = '';
$identifier = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token. Please refresh and try again.';
    } else {
        $identifier = trim($_POST['identifier'] ?? '');
        if ($identifier === '') {
            $error = 'Please enter a username or email address.';
        } else {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                $stmt = $conn->prepare("
                    UPDATE users
                    SET status = 'active',
                        account_locked_until = NULL,
                        failed_login_attempts = 0
                    WHERE username = :identifier OR email = :identifier
                ");
                $stmt->execute(['identifier' => $identifier]);

                if ($stmt->rowCount() > 0) {
                    $success = "User account for '{$identifier}' has been unlocked successfully.";
                } else {
                    $error = "No user found for '{$identifier}'.";
                }
            } catch (Exception $e) {
                $error = 'Unable to unlock account right now. Please try again.';
            }
        }
    }
}

$pageTitle = 'Unlock User Account - ' . APP_NAME;
$additionalCSS = ['admin.css'];
include '../includes/header.php';
?>

<?php include '../includes/admin/sidebar.php'; ?>

<div class="main-content" id="mainContent">
    <div class="topbar unlock-topbar">
        <div class="topbar-left">
            <button class="sidebar-toggle" id="sidebarToggle" title="Toggle Sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h4>Unlock User Account</h4>
        </div>
        <span class="unlock-pill">
            <i class="fas fa-shield-alt"></i>
            Security Tool
        </span>
    </div>

    <div class="content-area unlock-content">
        <div class="unlock-shell">
            <section class="unlock-panel">
                <div class="unlock-panel-head">
                    <div class="unlock-icon-wrap">
                        <i class="fas fa-user-lock"></i>
                    </div>
                    <div>
                        <h5>Account Access Recovery</h5>
                        <p>Unlock a user by username or email. This resets failed login attempts and lock timer.</p>
                    </div>
                </div>

                <?php if ($success): ?>
                    <div class="alert alert-success unlock-alert" role="alert">
                        <i class="fas fa-check-circle"></i>
                        <span><?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger unlock-alert" role="alert">
                        <i class="fas fa-exclamation-circle"></i>
                        <span><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" class="unlock-form">
                    <?php echo csrfField(); ?>
                    <label for="identifier">Username or Email Address</label>
                    <div class="unlock-input-wrap">
                        <i class="fas fa-at"></i>
                        <input
                            type="text"
                            name="identifier"
                            id="identifier"
                            class="form-control"
                            value="<?php echo htmlspecialchars($identifier, ENT_QUOTES, 'UTF-8'); ?>"
                            placeholder="e.g. admin01 or admin@school.edu"
                            required
                        >
                    </div>
                    <small>Only active admin sessions should use this action.</small>
                    <button type="submit" class="btn btn-primary unlock-btn">
                        <i class="fas fa-unlock-alt"></i>
                        Unlock Account
                    </button>
                </form>
            </section>

            <aside class="unlock-side-note">
                <h6>Quick Notes</h6>
                <ul>
                    <li>Use the exact username or registered email.</li>
                    <li>Changes apply immediately after submission.</li>
                    <li>Ask the user to sign in again after unlock.</li>
                </ul>
            </aside>
        </div>
    </div>
</div>

<style>
.unlock-content {
    padding: 28px;
}
.unlock-topbar {
    border-bottom: 1px solid #e2e8f0;
}
.unlock-pill {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    padding: 7px 12px;
    border-radius: 999px;
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.02em;
    background: linear-gradient(135deg, #dbeafe, #bfdbfe);
    color: #1d4ed8;
    border: 1px solid #bfdbfe;
}
.unlock-shell {
    max-width: 1020px;
    margin: 0 auto;
    display: grid;
    grid-template-columns: minmax(0, 2.1fr) minmax(220px, 1fr);
    gap: 22px;
    align-items: start;
}
.unlock-panel {
    background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    border: 1px solid #dbe5f0;
    border-radius: 18px;
    padding: 24px;
    box-shadow: 0 14px 35px rgba(15, 23, 42, 0.08);
}
.unlock-panel-head {
    display: flex;
    gap: 15px;
    align-items: flex-start;
    margin-bottom: 20px;
}
.unlock-icon-wrap {
    width: 48px;
    height: 48px;
    border-radius: 12px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 19px;
    color: #1d4ed8;
    background: linear-gradient(140deg, #dbeafe, #bfdbfe);
    border: 1px solid #bfdbfe;
    flex-shrink: 0;
}
.unlock-panel-head h5 {
    margin: 0 0 6px 0;
    font-size: 1.03rem;
    font-weight: 700;
    color: #0f172a;
}
.unlock-panel-head p {
    margin: 0;
    color: #475569;
    font-size: 0.88rem;
}
.unlock-alert {
    border-radius: 12px;
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 14px;
}
.unlock-form label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #0f172a;
}
.unlock-input-wrap {
    position: relative;
}
.unlock-input-wrap i {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #64748b;
    font-size: 0.9rem;
}
.unlock-input-wrap .form-control {
    height: 46px;
    border-radius: 11px;
    border: 1px solid #cbd5e1;
    padding-left: 36px;
    background: #fff;
    color: #0f172a;
}
.unlock-input-wrap .form-control:focus {
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.16);
}
.unlock-form small {
    display: inline-block;
    margin-top: 8px;
    color: #64748b;
}
.unlock-btn {
    margin-top: 16px;
    border: none;
    border-radius: 12px;
    height: 44px;
    width: 100%;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    font-weight: 600;
    background: linear-gradient(135deg, #2563eb, #1d4ed8);
    box-shadow: 0 12px 20px rgba(29, 78, 216, 0.24);
}
.unlock-btn:hover {
    filter: brightness(1.03);
}
.unlock-side-note {
    background: linear-gradient(165deg, #eff6ff, #dbeafe);
    border: 1px solid #bfdbfe;
    color: #1e3a8a;
    border-radius: 16px;
    padding: 18px;
    box-shadow: 0 10px 24px rgba(30, 64, 175, 0.12);
}
.unlock-side-note h6 {
    margin: 0 0 10px 0;
    font-size: 0.85rem;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    font-weight: 700;
}
.unlock-side-note ul {
    margin: 0;
    padding-left: 17px;
}
.unlock-side-note li {
    margin-bottom: 8px;
    line-height: 1.4;
    font-size: 0.84rem;
}
.unlock-side-note li:last-child {
    margin-bottom: 0;
}
@media (max-width: 992px) {
    .unlock-shell {
        grid-template-columns: 1fr;
    }
}
@media (max-width: 768px) {
    .unlock-content {
        padding: 16px;
    }
    .unlock-topbar {
        padding: 12px 16px;
    }
    .unlock-topbar .topbar-left h4 {
        font-size: 1rem;
    }
    .unlock-pill {
        display: none;
    }
    .unlock-panel {
        padding: 18px;
        border-radius: 14px;
    }
}
html[data-theme='dark'] .unlock-topbar {
    border-bottom-color: #334155;
}
html[data-theme='dark'] .unlock-pill {
    background: linear-gradient(135deg, #1e3a8a, #1d4ed8);
    color: #dbeafe;
    border-color: #3b82f6;
}
html[data-theme='dark'] .unlock-panel {
    background: linear-gradient(180deg, #0f172a 0%, #111827 100%);
    border-color: #334155;
    box-shadow: 0 14px 30px rgba(2, 6, 23, 0.55);
}
html[data-theme='dark'] .unlock-panel-head h5 {
    color: #f8fafc;
}
html[data-theme='dark'] .unlock-panel-head p {
    color: #cbd5e1;
}
html[data-theme='dark'] .unlock-icon-wrap {
    background: linear-gradient(135deg, #1e3a8a, #2563eb);
    border-color: #3b82f6;
    color: #dbeafe;
}
html[data-theme='dark'] .unlock-form label {
    color: #e2e8f0;
}
html[data-theme='dark'] .unlock-input-wrap i {
    color: #94a3b8;
}
html[data-theme='dark'] .unlock-input-wrap .form-control {
    background: #0b1220;
    border-color: #334155;
    color: #e2e8f0;
}
html[data-theme='dark'] .unlock-input-wrap .form-control::placeholder {
    color: #94a3b8;
}
html[data-theme='dark'] .unlock-form small {
    color: #94a3b8;
}
html[data-theme='dark'] .unlock-side-note {
    background: linear-gradient(165deg, #172554, #1e40af);
    border-color: #3b82f6;
    color: #dbeafe;
}
</style>

<?php include '../includes/footer.php'; ?>
