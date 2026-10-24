<?php
declare(strict_types=1);

/**
 * Real Simple RSS - shared library.
 * Database access, authentication, HTML sanitization and feed parsing helpers.
 */

define('APP_ROOT', __DIR__);

// This application targets PHP 8.4 or newer.
if (PHP_VERSION_ID < 80400) {
    $rsrssVersionError = 'Real Simple RSS requires PHP 8.4 or newer; this server is running PHP ' . PHP_VERSION . '.';
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $rsrssVersionError . "\n");
    } else {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
        }
        echo $rsrssVersionError;
    }
    exit(1);
}

/* -------------------------------------------------------------------------
 * Configuration
 * ---------------------------------------------------------------------- */

function config_path(): string
{
    return APP_ROOT . '/config.php';
}

function app_config(): ?array
{
    static $cfg = null;
    static $loaded = false;
    if ($loaded) {
        return $cfg;
    }
    $loaded = true;
    if (!is_file(config_path())) {
        $cfg = null;
        return null;
    }
    $data = require config_path();
    $cfg = is_array($data) ? $data : null;
    return $cfg;
}

function is_installed(): bool
{
    $cfg = app_config();
    return $cfg !== null && !empty($cfg['installed']);
}

function app_name(): string
{
    $cfg = app_config();
    return $cfg['app_name'] ?? 'Real Simple RSS';
}

/* -------------------------------------------------------------------------
 * Database
 * ---------------------------------------------------------------------- */

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = app_config();
    if (!$cfg) {
        throw new RuntimeException('Application is not installed.');
    }
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $cfg['db_host'] ?? 'localhost',
        $cfg['db_port'] ?? '3306',
        $cfg['db_name'] ?? ''
    );
    $pdo = new PDO($dsn, $cfg['db_user'] ?? '', $cfg['db_pass'] ?? '', [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    return $pdo;
}

/**
 * Connect to an arbitrary database (used by the installer).
 */
function db_connect(string $host, string $port, string $name, string $user, string $pass): PDO
{
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}

/* -------------------------------------------------------------------------
 * Sessions, authentication and CSRF
 * ---------------------------------------------------------------------- */

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if ((string)($_SERVER['SERVER_PORT'] ?? '') === '443') {
        return true;
    }
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $cfg = app_config();
    $trusted = (is_array($cfg) && isset($cfg['trusted_proxies']) && is_array($cfg['trusted_proxies']))
        ? $cfg['trusted_proxies']
        : [];
    if ($remote !== '' && in_array($remote, $trusted, true)
        && strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https') {
        return true;
    }
    return false;
}

function client_ip(): string
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}

function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    }
    return $nonce;
}

function send_security_headers(): void
{
    if (headers_sent()) {
        return;
    }
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-" . csp_nonce() . "'; "
        . "style-src 'self' 'unsafe-inline'; img-src 'self' https: http: data:; connect-src 'self'; "
        . "font-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'"
    );
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    // Logins do not expire: a one-year cookie plus a matching server-side
    // garbage-collection lifetime.
    $lifetime = 31536000;

    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path'     => '/',
        'httponly' => true,
        'secure'   => is_https(),
        'samesite' => 'Lax',
    ]);
    ini_set('session.gc_maxlifetime', (string)$lifetime);

    // Keep session files in a dedicated, app-local directory so a shared host's
    // session garbage collector (with a shorter lifetime) cannot expire active
    // logins. Falls back to the default store if the directory is not writable.
    $dir = APP_ROOT . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }

    session_name('rsrss_sid');
    session_start();
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_check(?string $token): bool
{
    return is_string($token) && $token !== '' && hash_equals($_SESSION['csrf'] ?? '', $token);
}

function current_user(): ?array
{
    static $user = null;
    static $resolved = false;
    if ($resolved) {
        return $user;
    }
    $resolved = true;
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    try {
        $stmt = db()->prepare('SELECT id, username, email, is_admin FROM users WHERE id = ?');
        $stmt->execute([(int)$_SESSION['user_id']]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        $row = false;
    }
    if (!$row) {
        unset($_SESSION['user_id']);
        return null;
    }
    $user = $row;
    return $user;
}

/* -------------------------------------------------------------------------
 * Login throttling
 * ---------------------------------------------------------------------- */

function ensure_login_attempts_table(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    // The installer creates this table. Probe it first so a least-privilege
    // runtime account (no CREATE privilege) never issues DDL.
    try {
        db()->query('SELECT 1 FROM login_attempts LIMIT 1');
        return;
    } catch (Throwable $e) {
        // Missing (e.g. an install upgraded from before this table existed).
    }

    // Best effort only: if the account cannot CREATE, throttling is skipped.
    try {
        db()->exec(
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                identifier VARCHAR(255) NOT NULL,
                ip VARCHAR(45) NOT NULL,
                attempted_at DATETIME NOT NULL,
                KEY idx_identifier (identifier, attempted_at),
                KEY idx_ip (ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
    } catch (Throwable $e) {
        // Ignore.
    }
}

function login_is_throttled(string $identifier): bool
{
    try {
        ensure_login_attempts_table();
        $pdo = db();
        $pdo->prepare('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)')->execute();

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE identifier = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
        $stmt->execute([$identifier]);
        if ((int)$stmt->fetchColumn() >= 10) {
            return true;
        }

        $stmt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > (NOW() - INTERVAL 15 MINUTE)');
        $stmt->execute([client_ip()]);
        return (int)$stmt->fetchColumn() >= 30;
    } catch (Throwable $e) {
        return false;
    }
}

function record_failed_login(string $identifier): void
{
    try {
        ensure_login_attempts_table();
        db()->prepare('INSERT INTO login_attempts (identifier, ip, attempted_at) VALUES (?, ?, NOW())')
            ->execute([mb_substr($identifier, 0, 255), client_ip()]);
    } catch (Throwable $e) {
        // Throttling must never break the request path.
    }
}

function clear_login_attempts(string $identifier): void
{
    try {
        db()->prepare('DELETE FROM login_attempts WHERE identifier = ?')->execute([$identifier]);
    } catch (Throwable $e) {
        // Ignore.
    }
}

/* -------------------------------------------------------------------------
 * Output helpers
 * ---------------------------------------------------------------------- */

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Return the URL only when it uses a safe absolute http(s) scheme.
 */
function safe_url(?string $url): string
{
    $url = trim((string)$url);
    if ($url === '' || !preg_match('#^https?://#i', $url)) {
        return '';
    }
    return $url;
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400, array $extra = []): void
{
    json_response(array_merge(['error' => $message], $extra), $status);
}

/**
 * Collect request parameters from the query string, form body and JSON body.
 * Body values take precedence over query-string values.
 */
function request_data(): array
{
    $data = $_GET;
    $data = array_merge($data, $_POST);
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw !== false && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $data = array_merge($data, $decoded);
            }
        }
    }
    return $data;
}

/* -------------------------------------------------------------------------
 * HTML sanitization (XSS protection for external feed content)
 * ---------------------------------------------------------------------- */

function sanitize_html(string $html): string
{
    $html = trim($html);
    if ($html === '') {
        return '';
    }

    $allowedTags = [
        'p', 'br', 'a', 'b', 'strong', 'i', 'em', 'u', 's', 'small', 'sub', 'sup',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code', 'hr',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'img', 'figure', 'figcaption', 'picture', 'source',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
        'span', 'div', 'cite', 'abbr', 'mark', 'time', 'audio', 'video',
    ];
    $allowedAttrs = [
        'href', 'src', 'alt', 'title', 'width', 'height',
        'colspan', 'rowspan', 'datetime', 'controls', 'type',
    ];
    $voidTags = ['br', 'hr', 'img', 'source', 'input', 'meta', 'link'];
    $dangerous = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input',
        'button', 'select', 'textarea', 'link', 'meta', 'base', 'noscript'];

    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $wrapped = '<?xml encoding="UTF-8"><div id="rsrss-root">' . $html . '</div>';
    $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $root = $doc->getElementById('rsrss-root');
    if (!$root) {
        $root = $doc->documentElement;
    }
    if (!$root) {
        return htmlspecialchars($html, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    $sanitizeNode = function (DOMNode $node) use (&$sanitizeNode, $allowedTags, $allowedAttrs, $voidTags, $dangerous): void {
        if (!$node->hasChildNodes()) {
            return;
        }
        $children = [];
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            // Drop comments and processing instructions entirely: libxml's HTML4
            // parser and browsers disagree about comment termination (e.g. "--!>"),
            // which can smuggle live markup through a retained comment.
            if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
                $node->removeChild($child);
                continue;
            }
            if (!($child instanceof DOMElement)) {
                continue;
            }
            $tag = strtolower($child->tagName);

            if (in_array($tag, $dangerous, true)) {
                if ($child->parentNode) {
                    $child->parentNode->removeChild($child);
                }
                continue;
            }

            if (!in_array($tag, $allowedTags, true)) {
                // Unwrap unknown tags, keeping their text/inline content.
                $sanitizeNode($child);
                while ($child->firstChild) {
                    $node->insertBefore($child->firstChild, $child);
                }
                if ($child->parentNode) {
                    $child->parentNode->removeChild($child);
                }
                continue;
            }

            // Strip attributes that are not whitelisted.
            if ($child->hasAttributes()) {
                $names = [];
                foreach ($child->attributes as $attr) {
                    $names[] = $attr->name;
                }
                foreach ($names as $name) {
                    if (!in_array(strtolower($name), $allowedAttrs, true)) {
                        $child->removeAttribute($name);
                    }
                }
            }

            if ($child->hasAttribute('href')) {
                $href = trim($child->getAttribute('href'));
                if (!preg_match('#^(https?:|mailto:|/|\#)#i', $href)) {
                    $child->removeAttribute('href');
                } else {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener noreferrer nofollow');
                }
            }
            if ($child->hasAttribute('src')) {
                // Only absolute http(s) sources. Relative / protocol-relative
                // URLs are dropped because they resolve against the reader's own
                // origin, which would allow same-origin requests (e.g. CSRF via
                // <img src="/api.php?...">) and unintended resource loads.
                $src = trim($child->getAttribute('src'));
                if (!preg_match('#^https?://#i', $src)) {
                    $child->removeAttribute('src');
                }
            }
            if ($child->hasAttribute('srcset')) {
                $child->removeAttribute('srcset');
            }
            if ($tag === 'img') {
                $child->setAttribute('loading', 'lazy');
                $child->setAttribute('referrerpolicy', 'no-referrer');
            }

            $sanitizeNode($child);
        }
    };

    $sanitizeNode($root);

    $output = '';
    foreach ($root->childNodes as $child) {
        $output .= $doc->saveHTML($child);
    }
    return trim($output);
}

/* -------------------------------------------------------------------------
 * HTTP fetch
 * ---------------------------------------------------------------------- */

/**
 * Resolve a hostname to its A/AAAA addresses.
 */
function resolve_host_ips(string $host): array
{
    $ips = [];
    if (function_exists('dns_get_record')) {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = $record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }
    }
    if (!$ips) {
        $fallback = @gethostbynamel($host);
        if (is_array($fallback)) {
            $ips = $fallback;
        }
    }
    return array_values(array_unique($ips));
}

function is_public_ip(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $bin = @inet_pton($ip);
    if ($bin === false) {
        return false;
    }

    if (strlen($bin) === 4) {
        // GLOBAL_RANGE does not exclude IPv4 multicast (224.0.0.0/4).
        $long = ip2long($ip);
        if ($long !== false && ($long & 0xF0000000) === 0xE0000000) {
            return false;
        }
    } else {
        $bytes = array_values(unpack('C*', $bin));
        // Block IPv6 multicast (ff00::/8).
        if ($bytes[0] === 0xff) {
            return false;
        }
        // IPv6 forms that wrap an IPv4 address (NAT64, 6to4, IPv4-mapped) must
        // be judged on the embedded address too.
        $embedded = embedded_ipv4($bin);
        if ($embedded !== null && !is_public_ip($embedded)) {
            return false;
        }
    }

    if (defined('FILTER_FLAG_GLOBAL_RANGE')) {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) !== false;
    }

    // Fallback for PHP < 8.2 (no GLOBAL_RANGE): NO_PRIV/NO_RES plus the extra
    // non-global ranges those flags miss.
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
        return false;
    }
    return is_globally_routable_fallback($ip);
}

/**
 * Extract the IPv4 address embedded in NAT64 (64:ff9b::/96), 6to4 (2002::/16)
 * or IPv4-mapped/compatible IPv6 forms. $bin is a 16-byte packed address.
 */
function embedded_ipv4(string $bin): ?string
{
    if (strlen($bin) !== 16) {
        return null;
    }
    $b = array_values(unpack('C*', $bin));
    $octets = static fn(int $a, int $c, int $d, int $e): string => sprintf('%d.%d.%d.%d', $a, $c, $d, $e);
    $zeros10 = array_fill(0, 10, 0);
    $zeros12 = array_fill(0, 12, 0);
    $zeros8 = array_fill(0, 8, 0);

    // IPv4-mapped ::ffff:a.b.c.d
    if (array_slice($b, 0, 10) === $zeros10 && $b[10] === 0xff && $b[11] === 0xff) {
        return $octets($b[12], $b[13], $b[14], $b[15]);
    }
    // IPv4-compatible ::a.b.c.d (deprecated)
    if (array_slice($b, 0, 12) === $zeros12) {
        return $octets($b[12], $b[13], $b[14], $b[15]);
    }
    // NAT64 64:ff9b::/96
    if ($b[0] === 0x00 && $b[1] === 0x64 && $b[2] === 0xff && $b[3] === 0x9b
        && array_slice($b, 4, 8) === $zeros8) {
        return $octets($b[12], $b[13], $b[14], $b[15]);
    }
    // 6to4 2002::/16
    if ($b[0] === 0x20 && $b[1] === 0x02) {
        return $octets($b[2], $b[3], $b[4], $b[5]);
    }

    return null;
}

function is_globally_routable_fallback(string $ip): bool
{
    if (strpos($ip, ':') !== false) {
        return true;
    }
    $long = ip2long($ip);
    if ($long === false) {
        return false;
    }
    $nonGlobal = [
        ['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8],
        ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24],
        ['192.88.99.0', 24], ['192.168.0.0', 16], ['198.18.0.0', 15], ['198.51.100.0', 24],
        ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4],
    ];
    foreach ($nonGlobal as [$network, $bits]) {
        $mask = -1 << (32 - $bits);
        if (($long & $mask) === (ip2long($network) & $mask)) {
            return false;
        }
    }
    return true;
}

/**
 * Resolve a URL to a validated connection target, or null when it must not be
 * fetched. The resolved IP is pinned so the HTTP client cannot re-resolve the
 * hostname (which would allow a DNS-rebinding SSRF bypass).
 */
function resolve_remote_target(string $url): ?array
{
    $parts = parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower((string)$parts['scheme']);
    if ($scheme !== 'http' && $scheme !== 'https') {
        return null;
    }

    $host = (string)$parts['host'];
    $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);

    $cfg = app_config();
    $allowedPorts = [80, 443];
    if (is_array($cfg) && isset($cfg['allowed_feed_ports']) && is_array($cfg['allowed_feed_ports'])) {
        $allowedPorts = array_map('intval', $cfg['allowed_feed_ports']);
    }
    if (!in_array($port, $allowedPorts, true)) {
        return null;
    }

    $allowPrivate = is_array($cfg) && !empty($cfg['allow_private_hosts']);
    $isLiteral = filter_var($host, FILTER_VALIDATE_IP) !== false;
    $ips = $isLiteral ? [$host] : resolve_host_ips($host);
    if (!$ips) {
        return null;
    }
    if (!$allowPrivate) {
        foreach ($ips as $ip) {
            if (!is_public_ip($ip)) {
                return null;
            }
        }
    }

    return [
        'scheme'  => $scheme,
        'host'    => $host,
        'port'    => $port,
        'ip'      => $ips[0],
        'literal' => $isLiteral,
    ];
}

/**
 * Backwards-compatible predicate.
 */
function is_safe_remote_url(string $url): bool
{
    return resolve_remote_target($url) !== null;
}

/**
 * Single HTTP request without following redirects, connecting to the pinned IP.
 * Returns [status, body, location] or null on transport failure / oversized body.
 */
function http_get_once(string $url, array $target, int $timeout, int $maxBytes): ?array
{
    if (function_exists('curl_init')) {
        $headers = [];
        $body = '';
        $tooLarge = false;
        $ch = curl_init($url);
        $options = [
            CURLOPT_CONNECTTIMEOUT  => 10,
            CURLOPT_TIMEOUT         => $timeout,
            CURLOPT_FOLLOWLOCATION  => false,
            CURLOPT_USERAGENT       => 'RealSimpleRSS/1.0 (+rss-reader)',
            CURLOPT_SSL_VERIFYPEER  => true,
            CURLOPT_SSL_VERIFYHOST  => 2,
            CURLOPT_PROTOCOLS       => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_HTTPHEADER      => ['Accept: application/rss+xml, application/atom+xml, application/xml, text/xml, text/html, */*'],
            CURLOPT_HEADERFUNCTION  => function ($ch, $line) use (&$headers) {
                $pos = strpos($line, ':');
                if ($pos !== false) {
                    $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION   => function ($ch, $chunk) use (&$body, &$tooLarge, $maxBytes) {
                $body .= $chunk;
                if (strlen($body) > $maxBytes) {
                    $tooLarge = true;
                    return 0;
                }
                return strlen($chunk);
            },
        ];
        if (!$target['literal']) {
            $ip = strpos($target['ip'], ':') !== false ? '[' . $target['ip'] . ']' : $target['ip'];
            $options[CURLOPT_RESOLVE] = [$target['host'] . ':' . $target['port'] . ':' . $ip];
        }
        curl_setopt_array($ch, $options);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        // The cURL handle is freed automatically when it goes out of scope; the
        // explicit close function is a no-op and deprecated as of PHP 8.5.
        if ($tooLarge || $ok === false) {
            return null;
        }
        return [$status, $body, $headers['location'] ?? ''];
    }

    // Stream fallback: connect to the validated IP while preserving Host/TLS name.
    $parts = parse_url($url);
    $path = (string)($parts['path'] ?? '/');
    if (isset($parts['query'])) {
        $path .= '?' . $parts['query'];
    }
    $ip = strpos($target['ip'], ':') !== false ? '[' . $target['ip'] . ']' : $target['ip'];
    $hostHeader = $target['host'];
    $isDefaultPort = ($target['scheme'] === 'http' && $target['port'] === 80)
        || ($target['scheme'] === 'https' && $target['port'] === 443);
    if (!$isDefaultPort) {
        $hostHeader .= ':' . $target['port'];
    }
    $connectUrl = $target['scheme'] . '://' . $ip . ':' . $target['port'] . $path;
    $context = stream_context_create([
        'http' => [
            'timeout'         => $timeout,
            'user_agent'      => 'RealSimpleRSS/1.0 (+rss-reader)',
            'follow_location' => 0,
            'max_redirects'   => 0,
            'ignore_errors'   => true,
            'header'          => 'Host: ' . $hostHeader . "\r\n",
        ],
        'ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
            'peer_name'        => $target['host'],
            'SNI_enabled'      => true,
        ],
    ]);
    $body = @file_get_contents($connectUrl, false, $context);
    if ($body === false || strlen($body) > $maxBytes) {
        return null;
    }
    $status = 0;
    $location = '';
    foreach (($http_response_header ?? []) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
            $status = (int)$match[1];
        } elseif (stripos($line, 'Location:') === 0) {
            $location = trim(substr($line, 9));
        }
    }
    return [$status, $body, $location];
}

/**
 * Fetch a remote URL, validating and pinning every hop and capping the size.
 */
function fetch_url(string $url, int $timeout = 20): ?string
{
    $maxBytes = 5 * 1024 * 1024;
    $current = trim($url);

    for ($hop = 0; $hop <= 5; $hop++) {
        $target = resolve_remote_target($current);
        if ($target === null) {
            return null;
        }
        $result = http_get_once($current, $target, $timeout, $maxBytes);
        if ($result === null) {
            return null;
        }
        [$status, $body, $location] = $result;
        if ($status >= 300 && $status < 400 && $location !== '') {
            $current = resolve_url($current, $location);
            continue;
        }
        if ($status >= 400) {
            return null;
        }
        return $body;
    }
    return null;
}

/* -------------------------------------------------------------------------
 * Feed parsing (RSS 0.9x/2.0, RDF/RSS 1.0 and Atom)
 * ---------------------------------------------------------------------- */

function normalize_date(?string $value): ?string
{
    $value = trim((string)$value);
    if ($value === '') {
        return null;
    }
    // RFC 822 dates open with a weekday name ("Sat, 03 Oct 2026 12:00:00"),
    // and strtotime() lets that weekday override the stated date. Feeds that
    // emit a wrong day name would have every post shifted by days, so the
    // decorative weekday is stripped and the date itself decides.
    $value = (string)preg_replace(
        '/^(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|mon|tue|wed|thu|fri|sat|sun)(?:\s*,)?\s*/i',
        '',
        $value
    );
    if ($value === '') {
        return null;
    }
    $ts = strtotime($value);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function text_of(?SimpleXMLElement $node): string
{
    if ($node === null) {
        return '';
    }
    return trim((string)$node);
}

function parse_feed(string $xml): array
{
    $previous = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $result = ['title' => '', 'site_url' => '', 'items' => []];
    if ($doc === false) {
        return $result;
    }

    $contentNs = 'http://purl.org/rss/1.0/modules/content/';
    $dcNs = 'http://purl.org/dc/elements/1.1/';
    $rootName = strtolower($doc->getName());

    if ($rootName === 'feed') {
        $result['title'] = text_of($doc->title);
        foreach ($doc->link as $link) {
            $rel = (string)$link['rel'];
            if ($rel === '' || $rel === 'alternate') {
                $result['site_url'] = (string)$link['href'];
                break;
            }
        }
        foreach ($doc->entry as $entry) {
            $title = text_of($entry->title);
            $link = '';
            foreach ($entry->link as $l) {
                $rel = (string)$l['rel'];
                if ($rel === '' || $rel === 'alternate') {
                    $link = (string)$l['href'];
                    break;
                }
            }
            $guid = text_of($entry->id) ?: $link;
            $content = trim((string)($entry->content ?? ''));
            if ($content === '') {
                $content = trim((string)($entry->summary ?? ''));
            }
            $author = text_of($entry->author->name ?? null);
            $date = (string)($entry->updated ?? '');
            if ($date === '') {
                $date = (string)($entry->published ?? '');
            }
            $result['items'][] = [
                'guid'    => $guid,
                'title'   => $title,
                'url'     => $link,
                'content' => $content,
                'author'  => $author,
                'pub_date' => $date,
            ];
        }
        return $result;
    }

    // RSS 2.0 (items inside <channel>) and RDF/RSS 1.0 (items at root level).
    $channel = isset($doc->channel) ? $doc->channel : $doc;
    $result['title'] = text_of($channel->title ?? $doc->title ?? null);
    $result['site_url'] = text_of($channel->link ?? $doc->link ?? null);

    $items = [];
    if (isset($channel->item) && count($channel->item) > 0) {
        $items = $channel->item;
    } elseif (isset($doc->item) && count($doc->item) > 0) {
        $items = $doc->item;
    }

    foreach ($items as $item) {
        $title = text_of($item->title);
        $link = text_of($item->link);
        $guid = text_of($item->guid) ?: $link;

        $content = '';
        $encoded = $item->children($contentNs);
        if (isset($encoded->encoded)) {
            $content = (string)$encoded->encoded;
        }
        if (trim($content) === '') {
            $content = (string)$item->description;
        }

        $author = '';
        $dc = $item->children($dcNs);
        if (isset($dc->creator)) {
            $author = (string)$dc->creator;
        }
        if ($author === '') {
            $author = text_of($item->author ?? null);
        }

        $date = (string)$item->pubDate;
        if ($date === '' && isset($dc->date)) {
            $date = (string)$dc->date;
        }

        $result['items'][] = [
            'guid'    => $guid,
            'title'   => $title,
            'url'     => $link,
            'content' => $content,
            'author'  => $author,
            'pub_date' => $date,
        ];
    }

    return $result;
}

/**
 * Attempt to locate a feed URL, either directly or via <link rel="alternate">.
 */
function discover_feed(string $url): array
{
    $body = fetch_url($url);
    if ($body === null) {
        throw new RuntimeException('Could not download the URL.');
    }

    $parsed = parse_feed($body);
    if (count($parsed['items']) > 0 || $parsed['title'] !== '') {
        return ['feed_url' => $url, 'title' => $parsed['title'], 'site_url' => safe_url($parsed['site_url'])];
    }

    // Not a feed itself - look for an advertised feed in the HTML head.
    $previous = libxml_use_internal_errors(true);
    $doc = new DOMDocument();
    $doc->loadHTML($body, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    foreach ($doc->getElementsByTagName('link') as $link) {
        $type = strtolower((string)$link->getAttribute('type'));
        $rel = strtolower((string)$link->getAttribute('rel'));
        $href = trim((string)$link->getAttribute('href'));
        if ($href === '' || empty($type)) {
            continue;
        }
        $isFeed = strpos($type, 'rss') !== false
            || strpos($type, 'atom') !== false
            || strpos($type, 'xml') !== false;
        if ($isFeed && ($rel === '' || strpos($rel, 'alternate') !== false)) {
            if (!preg_match('#^https?://#i', $href)) {
                $href = resolve_url($url, $href);
            }
            $sub = fetch_url($href);
            if ($sub === null) {
                continue;
            }
            $subParsed = parse_feed($sub);
            if (count($subParsed['items']) > 0 || $subParsed['title'] !== '') {
                $title = $subParsed['title'] !== '' ? $subParsed['title'] : (string)$doc->getElementsByTagName('title')->item(0)?->textContent;
                return ['feed_url' => $href, 'title' => trim($title), 'site_url' => safe_url($url)];
            }
        }
    }

    throw new RuntimeException('No valid RSS or Atom feed found at that address.');
}

/**
 * Normalize "." and ".." path segments per RFC 3986 section 5.2.4.
 */
function remove_dot_segments(string $path): string
{
    $input = $path;
    $output = '';
    while ($input !== '') {
        if (strncmp($input, '../', 3) === 0) {
            $input = substr($input, 3);
        } elseif (strncmp($input, './', 2) === 0) {
            $input = substr($input, 2);
        } elseif ($input === '.' || $input === '..') {
            $input = '';
        } elseif ($input === '/.' || strncmp($input, '/./', 3) === 0) {
            $input = $input === '/.' ? '/' : '/' . substr($input, 3);
        } elseif ($input === '/..' || strncmp($input, '/../', 4) === 0) {
            $input = $input === '/..' ? '/' : '/' . substr($input, 4);
            $slash = strrpos($output, '/');
            $output = $slash === false ? '' : substr($output, 0, $slash);
        } else {
            // Move the leading segment (including its "/") to the output.
            $start = $input[0] === '/' ? 1 : 0;
            $next = strpos($input, '/', $start);
            $segment = $next === false ? $input : substr($input, 0, $next);
            $output .= $segment;
            $input = substr($input, strlen($segment));
        }
    }
    return $output;
}

/**
 * Resolve a relative URL reference against a base URL (RFC 3986 section 5),
 * normalizing "." and ".." segments. Used for redirect targets and for feed
 * links discovered in HTML pages. Fragments are dropped: they never change
 * what is fetched.
 */
function resolve_url(string $base, string $relative): string
{
    if (preg_match('#^https?://#i', $relative)) {
        return $relative;
    }
    $parts = parse_url($base);
    if (!$parts || empty($parts['scheme']) || empty($parts['host'])) {
        return $relative;
    }
    $root = $parts['scheme'] . '://' . $parts['host'];
    if (!empty($parts['port'])) {
        $root .= ':' . $parts['port'];
    }

    // Scheme-relative reference ("//host/path").
    if (strpos($relative, '//') === 0) {
        return $parts['scheme'] . ':' . $relative;
    }

    $path = $relative;
    $query = '';
    $cut = strpos($path, '#');
    if ($cut !== false) {
        $path = substr($path, 0, $cut);
    }
    $cut = strpos($path, '?');
    if ($cut !== false) {
        $query = substr($path, $cut);
        $path = substr($path, 0, $cut);
    }

    if ($path === '' && $query === '') {
        // Fragment-only reference: the base URL itself.
        return $base;
    }

    $basePath = (string)($parts['path'] ?? '');

    if ($path === '') {
        // Query-only reference keeps the base path.
        return $root . ($basePath === '' ? '/' : $basePath) . $query;
    }

    if ($path[0] !== '/') {
        // Merge with the base path up to and including its rightmost "/".
        // A base path ending in "/" is itself the directory (RFC 3986 5.2.3);
        // dirname() would wrongly drop that trailing segment.
        if ($basePath === '') {
            $dir = '/';
        } elseif (substr($basePath, -1) === '/') {
            $dir = $basePath;
        } else {
            $slash = strrpos($basePath, '/');
            $dir = $slash === false ? '/' : substr($basePath, 0, $slash + 1);
        }
        $path = $dir . $path;
    }

    return $root . remove_dot_segments($path) . $query;
}

/* -------------------------------------------------------------------------
 * Post persistence
 * ---------------------------------------------------------------------- */

function store_posts(int $feedId, array $items): int
{
    if (!$items) {
        return 0;
    }
    $items = array_slice($items, 0, 500);
    $pdo = db();
    $insert = $pdo->prepare(
        'INSERT INTO posts (feed_id, guid, title, url, content, author, pub_date)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            url = VALUES(url),
            content = VALUES(content),
            author = VALUES(author),
            pub_date = VALUES(pub_date)'
    );
    $exists = $pdo->prepare('SELECT id FROM posts WHERE feed_id = ? AND guid = ? LIMIT 1');

    $newCount = 0;
    foreach ($items as $item) {
        $guid = trim((string)($item['guid'] ?? ''));
        if ($guid === '') {
            $guid = md5(($item['url'] ?? '') . ($item['title'] ?? ''));
        }
        if (strlen($guid) > 255) {
            $guid = hash('sha256', $guid);
        }
        $title = trim((string)($item['title'] ?? ''));
        if ($title === '') {
            $title = '(untitled)';
        }
        $title = mb_substr($title, 0, 255);
        $url = safe_url($item['url'] ?? '');
        $content = (string)($item['content'] ?? '');
        if (mb_strlen($content) > 200000) {
            $content = mb_substr($content, 0, 200000);
        }
        $author = mb_substr(trim((string)($item['author'] ?? '')), 0, 100);
        $pubDate = normalize_date($item['pub_date'] ?? null);

        $exists->execute([$feedId, $guid]);
        $already = $exists->fetchColumn();
        if ($already !== false) {
            continue;
        }

        $insert->execute([$feedId, $guid, $title, $url, $content, $author, $pubDate]);
        $newCount++;
    }
    return $newCount;
}

function refresh_feed(int $feedId): array
{
    $stmt = db()->prepare('SELECT * FROM feeds WHERE id = ?');
    $stmt->execute([$feedId]);
    $feed = $stmt->fetch();
    if (!$feed) {
        throw new RuntimeException('Feed not found.');
    }

    $body = fetch_url((string)$feed['feed_url']);
    if ($body === null) {
        db()->prepare('UPDATE feeds SET last_status = ? WHERE id = ?')
            ->execute(['Fetch failed', $feedId]);
        throw new RuntimeException('Could not fetch the feed.');
    }

    $parsed = parse_feed($body);
    $newCount = store_posts((int)$feed['id'], $parsed['items']);

    $title = $feed['title'];
    if (($title === '' || $title === '(untitled)') && $parsed['title'] !== '') {
        $title = mb_substr($parsed['title'], 0, 255);
    }
    $siteUrl = safe_url($feed['site_url'] ?: $parsed['site_url']);

    db()->prepare('UPDATE feeds SET title = ?, site_url = ?, last_fetched = NOW(), last_status = ? WHERE id = ?')
        ->execute([$title, $siteUrl, 'OK - ' . $newCount . ' new', $feedId]);

    return ['new' => $newCount, 'title' => $title];
}

/* -------------------------------------------------------------------------
 * OPML helpers
 * ---------------------------------------------------------------------- */

function parse_opml(string $xml): array
{
    $previous = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    if ($doc === false) {
        throw new RuntimeException('The uploaded file is not valid XML.');
    }

    $feeds = [];
    $walk = function (SimpleXMLElement $node) use (&$walk, &$feeds): void {
        foreach ($node->outline as $outline) {
            if (count($feeds) >= 2000) {
                return;
            }
            $xmlUrl = trim((string)$outline['xmlUrl']);
            $title = trim((string)$outline['title']);
            if ($title === '') {
                $title = trim((string)$outline['text']);
            }
            if ($xmlUrl !== '') {
                $feeds[] = [
                    'title'    => $title !== '' ? $title : $xmlUrl,
                    'feed_url' => $xmlUrl,
                    'site_url' => trim((string)$outline['htmlUrl']),
                ];
            }
            if (count($outline->outline) > 0) {
                $walk($outline);
            }
        }
    };

    $body = isset($doc->body) ? $doc->body : $doc;
    $walk($body);
    if (!$feeds && $body !== $doc) {
        $walk($doc);
    }

    return $feeds;
}

function build_opml(array $feeds): string
{
    $doc = new DOMDocument('1.0', 'UTF-8');
    $doc->formatOutput = true;

    $opml = $doc->createElement('opml');
    $opml->setAttribute('version', '2.0');
    $doc->appendChild($opml);

    $head = $doc->createElement('head');
    $title = $doc->createElement('title', app_name() . ' subscriptions');
    $head->appendChild($title);
    $head->appendChild($doc->createElement('dateCreated', gmdate('D, d M Y H:i:s') . ' GMT'));
    $opml->appendChild($head);

    $body = $doc->createElement('body');
    $opml->appendChild($body);

    foreach ($feeds as $feed) {
        $outline = $doc->createElement('outline');
        $outline->setAttribute('type', 'rss');
        $outline->setAttribute('text', (string)$feed['title']);
        $outline->setAttribute('title', (string)$feed['title']);
        $outline->setAttribute('xmlUrl', (string)$feed['feed_url']);
        if (!empty($feed['site_url'])) {
            $outline->setAttribute('htmlUrl', (string)$feed['site_url']);
        }
        $body->appendChild($outline);
    }

    return $doc->saveXML();
}

/* -------------------------------------------------------------------------
 * Settings
 * ---------------------------------------------------------------------- */

function get_setting(string $key, ?string $default = null): ?string
{
    try {
        $stmt = db()->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
    } catch (Throwable $e) {
        return $default;
    }
    return $value === false ? $default : (string)$value;
}

function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );
    $stmt->execute([$key, $value]);
}
