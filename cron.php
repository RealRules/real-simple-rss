<?php
declare(strict_types=1);

/**
 * Real Simple RSS background worker.
 *
 * Run from the system crontab:
 *   * * * * * php /path/to/cron.php
 *
 * Or trigger it over the web using the secret token:
 *   https://example.com/rss/cron.php?token=YOUR_CRON_SECRET
 */

require __DIR__ . '/lib.php';

$isCli = PHP_SAPI === 'cli';
$config = app_config();

if (!$config || empty($config['installed'])) {
    if ($isCli) {
        fwrite(STDERR, "Real Simple RSS is not installed yet.\n");
        exit(1);
    }
    http_response_code(503);
    exit('Not installed');
}

if (!$isCli) {
    $provided = (string)($_GET['token'] ?? '');
    $expected = (string)($config['cron_secret'] ?? '');
    if ($expected === '' || !hash_equals($expected, $provided)) {
        http_response_code(403);
        exit('Forbidden');
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    // Throttle web-triggered runs so a leaked token cannot be abused for DoS.
    $lastRun = (int)get_setting('last_web_cron', '0');
    if ($lastRun > 0 && (time() - $lastRun) < 15) {
        http_response_code(429);
        exit('Too many requests');
    }
    try {
        set_setting('last_web_cron', (string)time());
    } catch (Throwable $e) {
        // Ignore.
    }
}

@set_time_limit(0);
ignore_user_abort(true);

$pdo = db();

$lockName = 'rsrss_cron_lock';
try {
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 0)');
    $lockStmt->execute([$lockName]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        $message = "Another sync is already running.\n";
        echo $message;
        exit(0);
    }
} catch (Throwable $e) {
    // MySQL locking unavailable - continue without it.
}

$started = microtime(true);

try {
    $feeds = $pdo->query('SELECT id, title, feed_url FROM feeds ORDER BY id')->fetchAll();
} catch (Throwable $e) {
    $message = 'Database error: ' . $e->getMessage();
    if ($isCli) {
        fwrite(STDERR, $message . "\n");
    } else {
        http_response_code(500);
        echo $message . "\n";
    }
    exit(1);
}

$processed = 0;
$newPosts = 0;
$failures = 0;

foreach ($feeds as $feed) {
    $processed++;
    try {
        $result = refresh_feed((int)$feed['id']);
        $newPosts += (int)$result['new'];
        echo sprintf("[ok]   %s (%d new)\n", $feed['title'], (int)$result['new']);
    } catch (Throwable $e) {
        $failures++;
        echo sprintf("[fail] %s: %s\n", $feed['title'], $e->getMessage());
    }
}

// Optional retention: drop read posts older than the configured number of days.
$retention = (int)get_setting('retention_days', '0');
$purged = 0;
if ($retention > 0) {
    $stmt = $pdo->prepare('DELETE FROM posts WHERE is_read = 1 AND created_at < (NOW() - INTERVAL ? DAY)');
    $stmt->bindValue(1, $retention, PDO::PARAM_INT);
    $stmt->execute();
    $purged = $stmt->rowCount();
}

try {
    $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockName]);
} catch (Throwable $e) {
    // Ignore.
}

$elapsed = round(microtime(true) - $started, 2);
echo sprintf(
    "\nDone. %d feed(s) processed, %d new post(s), %d failure(s), %d purged in %ss.\n",
    $processed,
    $newPosts,
    $failures,
    $purged,
    $elapsed
);
