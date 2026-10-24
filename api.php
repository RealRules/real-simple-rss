<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';
start_session();
send_security_headers();

if (!is_installed()) {
    json_error('not_installed', 503);
}

$user = current_user();
if (!$user) {
    json_error('unauthorized', 401);
}
$userId = (int)$user['id'];
$isAdmin = (int)$user['is_admin'] === 1;

$action = (string)($_GET['action'] ?? '');
$input = request_data();

// Read-only actions may use GET. Every other (state-changing) action must be a
// POST with a valid CSRF token, so state cannot be changed by a GET request
// (e.g. an <img> tag embedded in subscribed feed content).
$readOnlyActions = ['get_feeds', 'get_posts', 'get_post', 'export_opml', 'get_users', 'get_settings'];

if (!in_array($action, $readOnlyActions, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_error('method_not_allowed', 405);
    }
    $token = (string)($input['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if (!csrf_check($token)) {
        json_error('invalid_csrf', 403);
    }
}

try {
    switch ($action) {
        case 'get_feeds':
            action_get_feeds($userId);
            break;

        case 'get_posts':
            action_get_posts($userId, $input);
            break;

        case 'get_post':
            action_get_post($userId, $input);
            break;

        case 'add_feed':
            action_add_feed($userId, $input);
            break;

        case 'remove_feed':
            action_remove_feed($userId, $input);
            break;

        case 'rename_feed':
            action_rename_feed($userId, $input);
            break;

        case 'update_feed':
            action_update_feed($userId, $input);
            break;

        case 'refresh_feed':
            action_refresh_feed($userId, $input);
            break;

        case 'mark_read':
            action_mark_read($userId, $input);
            break;

        case 'mark_feed_status':
            action_mark_feed_status($userId, $input);
            break;

        case 'mark_all_read':
            action_mark_all_read($userId);
            break;

        case 'import_opml':
            action_import_opml($userId);
            break;

        case 'export_opml':
            action_export_opml($userId);
            break;

        case 'change_password':
            action_change_password($userId, $input);
            break;

        case 'get_users':
            require_admin($isAdmin);
            action_get_users();
            break;

        case 'create_user':
            require_admin($isAdmin);
            action_create_user($input);
            break;

        case 'update_user':
            require_admin($isAdmin);
            action_update_user($input, $userId);
            break;

        case 'delete_user':
            require_admin($isAdmin);
            action_delete_user($input, $userId);
            break;

        case 'get_settings':
            require_admin($isAdmin);
            action_get_settings();
            break;

        case 'save_settings':
            require_admin($isAdmin);
            action_save_settings($input);
            break;

        default:
            json_error('unknown_action', 404);
    }
} catch (PDOException $e) {
    error_log('Real Simple RSS API database error: ' . $e->getMessage());
    json_error('database_error', 500);
} catch (RuntimeException $e) {
    json_error($e->getMessage(), 400);
} catch (Throwable $e) {
    error_log('Real Simple RSS API error: ' . $e->getMessage());
    json_error('server_error', 500);
}

/* -------------------------------------------------------------------------
 * Actions
 * ---------------------------------------------------------------------- */

function require_admin(bool $isAdmin): void
{
    if (!$isAdmin) {
        json_error('forbidden', 403);
    }
}

function action_get_feeds(int $userId): void
{
    $stmt = db()->prepare(
        'SELECT f.id, f.title, f.feed_url, f.site_url, f.last_fetched, f.last_status,
                COALESCE(SUM(CASE WHEN p.is_read = 0 THEN 1 ELSE 0 END), 0) AS unread,
                COUNT(p.id) AS total,
                MAX(COALESCE(p.pub_date, p.created_at)) AS last_post,
                (CASE WHEN COUNT(p.id) > 0
                       AND MAX(COALESCE(p.pub_date, p.created_at)) < (NOW() - INTERVAL 1 YEAR)
                      THEN 1 ELSE 0 END) AS inactive
         FROM feeds f
         LEFT JOIN posts p ON p.feed_id = f.id
         WHERE f.user_id = ?
         GROUP BY f.id
         ORDER BY f.title ASC'
    );
    $stmt->execute([$userId]);
    $feeds = $stmt->fetchAll();

    foreach ($feeds as &$feed) {
        $feed['id'] = (int)$feed['id'];
        $feed['unread'] = (int)$feed['unread'];
        $feed['total'] = (int)$feed['total'];
        $feed['inactive'] = (int)$feed['inactive'];
        $feed['feed_url'] = safe_url($feed['feed_url']);
        $feed['site_url'] = safe_url($feed['site_url']);
    }
    unset($feed);

    $totalUnread = 0;
    $totalPosts = 0;
    foreach ($feeds as $feed) {
        $totalUnread += $feed['unread'];
        $totalPosts += $feed['total'];
    }

    json_response([
        'feeds' => $feeds,
        'totals' => ['unread' => $totalUnread, 'total' => $totalPosts],
    ]);
}

function action_get_posts(int $userId, array $input): void
{
    $feedId = (int)($input['feed_id'] ?? 0);
    $unreadOnly = !empty($input['unread_only']) && $input['unread_only'] !== 'false' && $input['unread_only'] !== '0';
    $limit = max(1, min(200, (int)($input['limit'] ?? 50)));
    $offset = max(0, (int)($input['offset'] ?? 0));
    $beforeId = (int)($input['before_id'] ?? 0);
    $beforeDate = trim((string)($input['before_date'] ?? ''));

    $sql = 'SELECT p.id, p.feed_id, p.title, p.url, p.author, p.pub_date, p.is_read,
                   COALESCE(p.pub_date, p.created_at) AS sort_date, f.title AS feed_title
            FROM posts p
            JOIN feeds f ON f.id = p.feed_id
            WHERE f.user_id = ?';
    $params = [$userId];

    if ($feedId > 0) {
        $sql .= ' AND p.feed_id = ?';
        $params[] = $feedId;
    }
    if ($unreadOnly) {
        $sql .= ' AND p.is_read = 0';
    }
    if ($beforeId > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $beforeDate)) {
        // Keyset pagination: return posts strictly older than the given one,
        // so pages stay correct even when posts are inserted or marked read
        // between requests (an offset would drift in both cases).
        $sql .= ' AND (COALESCE(p.pub_date, p.created_at) < ?'
            . ' OR (COALESCE(p.pub_date, p.created_at) = ? AND p.id < ?))';
        $params[] = $beforeDate;
        $params[] = $beforeDate;
        $params[] = $beforeId;
    }
    $sql .= ' ORDER BY COALESCE(p.pub_date, p.created_at) DESC, p.id DESC LIMIT ? OFFSET ?';

    $stmt = db()->prepare($sql);
    $i = 1;
    foreach ($params as $param) {
        $stmt->bindValue($i++, $param, is_int($param) ? PDO::PARAM_INT : PDO::PARAM_STR);
    }
    $stmt->bindValue($i++, $limit, PDO::PARAM_INT);
    $stmt->bindValue($i, $offset, PDO::PARAM_INT);
    $stmt->execute();
    $posts = $stmt->fetchAll();

    foreach ($posts as &$post) {
        $post['id'] = (int)$post['id'];
        $post['feed_id'] = (int)$post['feed_id'];
        $post['is_read'] = (int)$post['is_read'];
        $post['url'] = safe_url($post['url']);
    }
    unset($post);

    json_response(['posts' => $posts]);
}

function action_get_post(int $userId, array $input): void
{
    $postId = (int)($input['post_id'] ?? 0);
    if ($postId <= 0) {
        json_error('missing_post_id');
    }
    $stmt = db()->prepare(
        'SELECT p.id, p.feed_id, p.title, p.url, p.author, p.pub_date, p.is_read, p.content,
                f.title AS feed_title, f.site_url AS feed_site
         FROM posts p
         JOIN feeds f ON f.id = p.feed_id
         WHERE p.id = ? AND f.user_id = ?'
    );
    $stmt->execute([$postId, $userId]);
    $post = $stmt->fetch();
    if (!$post) {
        json_error('not_found', 404);
    }
    $post['id'] = (int)$post['id'];
    $post['feed_id'] = (int)$post['feed_id'];
    $post['is_read'] = (int)$post['is_read'];
    $post['url'] = safe_url($post['url']);
    $post['feed_site'] = safe_url($post['feed_site']);
    $post['content'] = sanitize_html((string)$post['content']);
    json_response(['post' => $post]);
}

function action_add_feed(int $userId, array $input): void
{
    $url = trim((string)($input['url'] ?? ''));
    $title = trim((string)($input['title'] ?? ''));
    if ($url === '') {
        json_error('missing_url');
    }

    $discovered = discover_feed($url);
    $feedUrl = $discovered['feed_url'];
    if ($title === '') {
        $title = $discovered['title'] !== '' ? $discovered['title'] : $feedUrl;
    }
    $title = mb_substr($title, 0, 255);

    $check = db()->prepare('SELECT id FROM feeds WHERE user_id = ? AND feed_url = ? LIMIT 1');
    $check->execute([$userId, $feedUrl]);
    if ($check->fetchColumn()) {
        json_error('already_exists', 409, ['message' => 'You are already subscribed to this feed.']);
    }

    $stmt = db()->prepare('INSERT INTO feeds (user_id, title, feed_url, site_url) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $title, $feedUrl, $discovered['site_url']]);
    $feedId = (int)db()->lastInsertId();

    $new = 0;
    try {
        $result = refresh_feed($feedId);
        $new = (int)$result['new'];
    } catch (Throwable $e) {
        // Feed saved, but initial fetch failed; the cron job will retry.
    }

    json_response(['ok' => true, 'feed_id' => $feedId, 'new' => $new]);
}

function action_remove_feed(int $userId, array $input): void
{
    $feedId = (int)($input['feed_id'] ?? 0);
    $stmt = db()->prepare('DELETE FROM feeds WHERE id = ? AND user_id = ?');
    $stmt->execute([$feedId, $userId]);
    if ($stmt->rowCount() === 0) {
        json_error('not_found', 404);
    }
    json_response(['ok' => true]);
}

function action_rename_feed(int $userId, array $input): void
{
    $feedId = (int)($input['feed_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    if ($feedId <= 0 || $title === '') {
        json_error('missing_fields');
    }
    $stmt = db()->prepare('UPDATE feeds SET title = ? WHERE id = ? AND user_id = ?');
    $stmt->execute([mb_substr($title, 0, 255), $feedId, $userId]);
    if ($stmt->rowCount() === 0) {
        json_error('not_found', 404);
    }
    json_response(['ok' => true]);
}

function action_update_feed(int $userId, array $input): void
{
    $feedId = (int)($input['feed_id'] ?? 0);
    $title = trim((string)($input['title'] ?? ''));
    $feedUrl = safe_url($input['feed_url'] ?? '');

    if ($feedId <= 0 || $title === '') {
        json_error('missing_fields');
    }
    if ($feedUrl === '') {
        json_error('Please enter a valid http(s) feed URL.');
    }

    $stmt = db()->prepare('SELECT feed_url FROM feeds WHERE id = ? AND user_id = ?');
    $stmt->execute([$feedId, $userId]);
    $currentUrl = $stmt->fetchColumn();
    if ($currentUrl === false) {
        json_error('not_found', 404);
    }

    $title = mb_substr($title, 0, 255);
    $urlChanged = ($currentUrl !== $feedUrl);

    if ($urlChanged) {
        // Clear the cached site URL so it is re-derived from the new feed.
        db()->prepare('UPDATE feeds SET title = ?, feed_url = ?, site_url = NULL WHERE id = ? AND user_id = ?')
            ->execute([$title, $feedUrl, $feedId, $userId]);
    } else {
        db()->prepare('UPDATE feeds SET title = ? WHERE id = ? AND user_id = ?')
            ->execute([$title, $feedId, $userId]);
    }

    $new = 0;
    if ($urlChanged) {
        try {
            $result = refresh_feed($feedId);
            $new = (int)$result['new'];
        } catch (Throwable $e) {
            // Saved, but the initial fetch failed; cron will retry.
        }
    }

    json_response(['ok' => true, 'new' => $new]);
}

function action_refresh_feed(int $userId, array $input): void
{
    $feedId = (int)($input['feed_id'] ?? 0);
    $stmt = db()->prepare('SELECT id FROM feeds WHERE id = ? AND user_id = ?');
    $stmt->execute([$feedId, $userId]);
    if (!$stmt->fetchColumn()) {
        json_error('not_found', 404);
    }
    $result = refresh_feed($feedId);
    json_response(['ok' => true, 'new' => (int)$result['new']]);
}

function action_mark_read(int $userId, array $input): void
{
    $postId = (int)($input['post_id'] ?? 0);
    $read = array_key_exists('read', $input) ? (int)$input['read'] : 1;
    if ($postId <= 0) {
        json_error('missing_post_id');
    }
    $stmt = db()->prepare(
        'UPDATE posts p
         JOIN feeds f ON f.id = p.feed_id
         SET p.is_read = ?
         WHERE p.id = ? AND f.user_id = ?'
    );
    $stmt->execute([$read ? 1 : 0, $postId, $userId]);
    json_response(['ok' => true]);
}

function action_mark_feed_status(int $userId, array $input): void
{
    $feedId = (int)($input['feed_id'] ?? 0);
    $read = array_key_exists('read', $input) ? (int)$input['read'] : 1;
    if ($feedId <= 0) {
        json_error('missing_feed_id');
    }
    $stmt = db()->prepare(
        'UPDATE posts p
         JOIN feeds f ON f.id = p.feed_id
         SET p.is_read = ?
         WHERE f.id = ? AND f.user_id = ?'
    );
    $stmt->execute([$read ? 1 : 0, $feedId, $userId]);
    json_response(['ok' => true]);
}

function action_mark_all_read(int $userId): void
{
    $stmt = db()->prepare(
        'UPDATE posts p
         JOIN feeds f ON f.id = p.feed_id
         SET p.is_read = 1
         WHERE f.user_id = ?'
    );
    $stmt->execute([$userId]);
    json_response(['ok' => true]);
}

function action_import_opml(int $userId): void
{
    if (empty($_FILES['opml']) || !is_uploaded_file($_FILES['opml']['tmp_name'])) {
        json_error('missing_file');
    }
    if ((int)($_FILES['opml']['size'] ?? 0) > 5 * 1024 * 1024) {
        json_error('file_too_large');
    }
    $contents = (string)file_get_contents($_FILES['opml']['tmp_name']);
    if ($contents === '') {
        json_error('empty_file');
    }
    $feeds = parse_opml($contents);
    if (!$feeds) {
        json_error('no_feeds_found');
    }

    $check = db()->prepare('SELECT id FROM feeds WHERE user_id = ? AND feed_url = ? LIMIT 1');
    $insert = db()->prepare('INSERT INTO feeds (user_id, title, feed_url, site_url) VALUES (?, ?, ?, ?)');

    $imported = 0;
    $skipped = 0;
    $ids = [];
    foreach ($feeds as $feed) {
        $check->execute([$userId, $feed['feed_url']]);
        if ($check->fetchColumn()) {
            $skipped++;
            continue;
        }
        $insert->execute([
            $userId,
            mb_substr($feed['title'], 0, 255),
            $feed['feed_url'],
            $feed['site_url'] ?: null,
        ]);
        $ids[] = (int)db()->lastInsertId();
        $imported++;
    }

    $newItems = 0;
    foreach ($ids as $feedId) {
        try {
            $result = refresh_feed($feedId);
            $newItems += (int)$result['new'];
        } catch (Throwable $e) {
            // Ignore individual fetch failures during import.
        }
    }

    json_response(['ok' => true, 'imported' => $imported, 'skipped' => $skipped, 'new' => $newItems]);
}

function action_export_opml(int $userId): void
{
    $stmt = db()->prepare('SELECT title, feed_url, site_url FROM feeds WHERE user_id = ? ORDER BY title');
    $stmt->execute([$userId]);
    $feeds = $stmt->fetchAll();

    $xml = build_opml($feeds);
    header('Content-Type: text/x-opml; charset=utf-8');
    header('Content-Disposition: attachment; filename="real-simple-rss-subscriptions.opml"');
    echo $xml;
    exit;
}

function action_change_password(int $userId, array $input): void
{
    $current = (string)($input['current_password'] ?? '');
    $new = (string)($input['new_password'] ?? '');
    $confirm = (string)($input['confirm_password'] ?? '');

    if (strlen($new) < 8) {
        json_error('Password must be at least 8 characters long.');
    }
    if ($new !== $confirm) {
        json_error('The new passwords do not match.');
    }

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $hash = (string)$stmt->fetchColumn();
    if (!password_verify($current, $hash)) {
        json_error('Your current password is incorrect.');
    }

    db()->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);

    json_response(['ok' => true]);
}

function action_get_users(): void
{
    $users = db()->query(
        'SELECT u.id, u.username, u.email, u.is_admin, u.created_at,
                (SELECT COUNT(*) FROM feeds f WHERE f.user_id = u.id) AS feed_count
         FROM users u ORDER BY u.username'
    )->fetchAll();
    foreach ($users as &$u) {
        $u['id'] = (int)$u['id'];
        $u['is_admin'] = (int)$u['is_admin'];
        $u['feed_count'] = (int)$u['feed_count'];
    }
    unset($u);
    json_response(['users' => $users]);
}

function action_create_user(array $input): void
{
    $username = trim((string)($input['username'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $isAdmin = !empty($input['is_admin']) ? 1 : 0;

    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        json_error('Username must be 3-50 characters (letters, numbers, dot, dash, underscore).');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Please provide a valid email address.');
    }
    if (strlen($password) < 8) {
        json_error('Password must be at least 8 characters long.');
    }

    try {
        $stmt = db()->prepare('INSERT INTO users (username, email, password_hash, is_admin) VALUES (?, ?, ?, ?)');
        $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $isAdmin]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_error('That username or email is already taken.', 409);
        }
        throw $e;
    }
    json_response(['ok' => true, 'id' => (int)db()->lastInsertId()]);
}

function action_update_user(array $input, int $currentUserId): void
{
    $id = (int)($input['id'] ?? 0);
    $username = trim((string)($input['username'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $isAdmin = !empty($input['is_admin']) ? 1 : 0;

    if ($id <= 0) {
        json_error('missing_user_id');
    }
    if (!preg_match('/^[A-Za-z0-9_.-]{3,50}$/', $username)) {
        json_error('Username must be 3-50 characters (letters, numbers, dot, dash, underscore).');
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        json_error('Please provide a valid email address.');
    }
    if ($id === $currentUserId) {
        $isAdmin = 1;
    }

    try {
        if ($password !== '') {
            if (strlen($password) < 8) {
                json_error('Password must be at least 8 characters long.');
            }
            $stmt = db()->prepare('UPDATE users SET username = ?, email = ?, is_admin = ?, password_hash = ? WHERE id = ?');
            $stmt->execute([$username, $email, $isAdmin, password_hash($password, PASSWORD_DEFAULT), $id]);
        } else {
            $stmt = db()->prepare('UPDATE users SET username = ?, email = ?, is_admin = ? WHERE id = ?');
            $stmt->execute([$username, $email, $isAdmin, $id]);
        }
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            json_error('That username or email is already taken.', 409);
        }
        throw $e;
    }
    json_response(['ok' => true]);
}

function action_delete_user(array $input, int $currentUserId): void
{
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        json_error('missing_user_id');
    }
    if ($id === $currentUserId) {
        json_error('You cannot delete your own account.');
    }
    $adminCount = (int)db()->query('SELECT COUNT(*) FROM users WHERE is_admin = 1')->fetchColumn();
    $stmt = db()->prepare('SELECT is_admin FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $isAdmin = (int)$stmt->fetchColumn();
    if ($isAdmin === 1 && $adminCount <= 1) {
        json_error('You cannot delete the last administrator.');
    }
    $stmt = db()->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
    json_response(['ok' => true]);
}

function action_get_settings(): void
{
    json_response([
        'retention_days' => (int)get_setting('retention_days', '0'),
        'cron_secret'    => (string)(app_config()['cron_secret'] ?? ''),
    ]);
}

function action_save_settings(array $input): void
{
    $retention = max(0, min(3650, (int)($input['retention_days'] ?? 0)));
    set_setting('retention_days', (string)$retention);
    json_response(['ok' => true]);
}
