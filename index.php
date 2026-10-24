<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
start_session();
send_security_headers();
header('Cache-Control: no-store, no-cache, must-revalidate');

if (!is_installed()) {
    header('Location: setup.php');
    exit;
}

$loginError = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $do = (string)($_POST['do'] ?? '');
    $token = (string)($_POST['csrf'] ?? '');

    if ($do === 'login') {
        if (!csrf_check($token)) {
            $loginError = 'Your session expired. Please try again.';
        } else {
            $identity = mb_substr(trim((string)($_POST['username'] ?? '')), 0, 255);
            $password = (string)($_POST['password'] ?? '');
            if (login_is_throttled($identity)) {
                $loginError = 'Too many failed attempts. Please try again in a few minutes.';
            } else {
                $account = false;
                try {
                    $stmt = db()->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
                    $stmt->execute([$identity, $identity]);
                    $account = $stmt->fetch();
                } catch (Throwable $e) {
                    error_log('Real Simple RSS login database error: ' . $e->getMessage());
                    $loginError = 'Could not connect to the database. Please try again in a moment.';
                }
                if ($loginError === '') {
                    if ($account && password_verify($password, (string)$account['password_hash'])) {
                        clear_login_attempts($identity);
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = (int)$account['id'];
                        $_SESSION['csrf'] = bin2hex(random_bytes(32));
                        header('Location: index.php');
                        exit;
                    }
                    record_failed_login($identity);
                    $loginError = 'Invalid username or password.';
                }
            }
        }
    } elseif ($do === 'logout') {
        if (csrf_check($token)) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
            }
            session_destroy();
        }
        header('Location: index.php');
        exit;
    }
}

$user = current_user();

if (!$user) {
    $token = csrf_token();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in &middot; <?= e(app_name()) ?></title>
<link rel="stylesheet" href="assets/app.css">
</head>
<body class="login-page">
<div class="login-card">
    <h1 class="login-title"><?= e(app_name()) ?></h1>
    <p class="login-sub">Sign in to your reader</p>
    <?php if ($loginError !== ''): ?>
        <div class="notice error"><?= e($loginError) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="on">
        <input type="hidden" name="do" value="login">
        <input type="hidden" name="csrf" value="<?= e($token) ?>">
        <label class="block-label">Username or email
            <input type="text" name="username" required autofocus>
        </label>
        <label class="block-label">Password
            <input type="password" name="password" required>
        </label>
        <button type="submit" class="btn primary block">Sign in</button>
    </form>
</div>
</body>
</html>
    <?php
    exit;
}

$token = csrf_token();
$isAdmin = (int)$user['is_admin'] === 1;
$cronSecret = $isAdmin ? (string)(app_config()['cron_secret'] ?? '') : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(app_name()) ?></title>
<meta name="csrf" content="<?= e($token) ?>">
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="app" id="app">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <button class="icon-btn menu-btn" id="menuToggle" title="Toggle feed list" aria-label="Toggle feed list">&#9776;</button>
            <span class="brand"><?= e(app_name()) ?></span>
            <div class="user-menu">
                <button class="avatar-btn" id="userBtn" title="Account"><?= e(strtoupper(mb_substr($user['username'], 0, 1))) ?></button>
                <div class="dropdown" id="userDropdown" hidden>
                    <div class="dropdown-head">
                        <strong><?= e($user['username']) ?></strong>
                        <small><?= e($user['email']) ?></small>
                    </div>
                    <?php if ((int)$user['is_admin'] === 1): ?>
                        <button type="button" data-menu="admin">Admin panel</button>
                    <?php endif; ?>
                    <button type="button" data-menu="account">Change password</button>
                    <form method="post">
                        <input type="hidden" name="do" value="logout">
                        <input type="hidden" name="csrf" value="<?= e($token) ?>">
                        <button type="submit" class="danger-text">Sign out</button>
                    </form>
                </div>
            </div>
        </div>

        <div class="sidebar-actions">
            <button class="btn primary" id="addFeedBtn">+ Add feed</button>
            <button class="btn" id="importBtn">Import</button>
            <button class="btn" id="exportBtn">Export</button>
            <input type="file" id="opmlFile" accept=".opml,.xml,text/xml,text/x-opml,application/xml" hidden>
        </div>

        <nav class="feed-list" id="feedList" aria-label="Feeds"></nav>

        <?php if ($isAdmin): ?>
        <div class="sidebar-foot">
            <button class="link-btn" id="cronInfoBtn">Cron / sync info</button>
        </div>
        <?php endif; ?>
    </aside>

    <div class="resizer" id="resizerSidebar" data-resize="sidebar" role="separator" aria-orientation="vertical" title="Drag to resize"></div>

    <section class="list-pane" id="listPane">
        <div class="list-head">
            <button class="icon-btn back-btn" id="backToFeeds" title="Back to feeds">&#8249;</button>
            <h2 id="listTitle">All items</h2>
            <div class="list-actions" id="listActions"></div>
            <label class="toggle" title="Show unread only">
                <input type="checkbox" id="unreadToggle"> <span>Unread</span>
            </label>
            <button class="icon-btn" id="markAllReadBtn" title="Mark everything read">&#10003;</button>
        </div>
        <div class="post-list" id="postList"></div>
    </section>

    <div class="resizer" id="resizerList" data-resize="list" role="separator" aria-orientation="vertical" title="Drag to resize"></div>

    <main class="article-pane" id="articlePane">
        <div class="article-toolbar">
            <button class="icon-btn back-btn" id="backToList" title="Back to list">&#8249;</button>
            <div class="article-tools">
                <button class="btn small" id="openExternalBtn" disabled>Open original</button>
                <button class="btn small" id="toggleReadBtn" disabled>Mark unread</button>
            </div>
        </div>
        <div class="article-body" id="articleBody">
            <div class="empty-state">
                <p>Select an article to read it here.</p>
            </div>
        </div>
    </main>
</div>

<div class="modal-root" id="modalRoot" hidden>
    <div class="modal-backdrop" data-close></div>
    <div class="modal" id="modalBox" role="dialog" aria-modal="true"></div>
</div>

<div class="toast-root" id="toastRoot"></div>

<script nonce="<?= e(csp_nonce()) ?>">
window.RSRSS = {
    csrf: <?= json_encode($token) ?>,
    user: <?= json_encode([
        'id'       => (int)$user['id'],
        'username' => $user['username'],
        'email'    => $user['email'],
        'is_admin' => (int)$user['is_admin'],
    ]) ?>,
    cronUrl: <?= json_encode('cron.php?token=' . $cronSecret) ?>
};
</script>
<script src="assets/app.js"></script>
</body>
</html>
