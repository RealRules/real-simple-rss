<?php
declare(strict_types=1);

// remove_dot_segments(): RFC 3986 section 5.2.4 examples.
$dotVectors = [
    ['/a/b/c/./../../g', '/a/g'],
    ['mid/content=5/../6', 'mid/6'],
    ['/../', '/'],
    ['/./', '/'],
    ['/.', '/'],
    ['..', ''],
    ['.', ''],
    ['/a/b/..', '/a/'],
    ['/a/b/../..', '/'],
    ['/a/./b/.', '/a/b/'],
    ['//', '//'],
];
foreach ($dotVectors as [$input, $want]) {
    check("remove_dot_segments('$input')", $want, remove_dot_segments($input));
}

// resolve_url(): RFC 3986 section 5.4 reference vectors against the base
// "http://a/b/c/d;p?q". Fragment-bearing vectors expect the fragment to be
// dropped, because fragments never change what is fetched.
$base = 'http://a/b/c/d;p?q';
$vectors = [
    ['g',             'http://a/b/c/g'],
    ['./g',           'http://a/b/c/g'],
    ['g/',            'http://a/b/c/g/'],
    ['/g',            'http://a/g'],
    ['//g',           'http://g'],
    ['?y',            'http://a/b/c/d;p?y'],
    ['g?y',           'http://a/b/c/g?y'],
    ['#s',            'http://a/b/c/d;p?q'],
    ['g#s',           'http://a/b/c/g'],
    ['g?y#s',         'http://a/b/c/g?y'],
    [';x',            'http://a/b/c/;x'],
    ['g;x=1/y',       'http://a/b/c/g;x=1/y'],
    ['g;y',           'http://a/b/c/g;y'],
    ['../g',          'http://a/b/g'],
    ['./../g',        'http://a/b/g'],
    ['../../g',       'http://a/g'],
    ['../../../g',    'http://a/g'],
    ['../../../../g', 'http://a/g'],
    ['/./g',          'http://a/g'],
    ['/../g',         'http://a/g'],
    ['g.',            'http://a/b/c/g.'],
    ['.g',            'http://a/b/c/.g'],
    ['g..',           'http://a/b/c/g..'],
    ['./g.',          'http://a/b/c/g.'],
    ['g/./h',         'http://a/b/c/g/h'],
    ['g/../h',        'http://a/b/c/h'],
    ['g;x=1?y/./x',   'http://a/b/c/g;x=1?y/./x'],
];
foreach ($vectors as [$ref, $want]) {
    check("resolve_url('$base' + '$ref')", $want, resolve_url($base, $ref));
}

// resolve_url(): real-world regression cases.
check(
    'trailing-slash base keeps its last segment',
    'https://example.com/a/b/c.xml',
    resolve_url('https://example.com/a/b/', 'c.xml')
);
check(
    'file-style base path resolves against its directory',
    'https://example.com/a/b/articles/rss.xml',
    resolve_url('https://example.com/a/b/feed', 'articles/rss.xml')
);
check(
    'parent of trailing-slash base',
    'https://example.com/a/d/e.xml',
    resolve_url('https://example.com/a/b/', '../d/e.xml')
);
check(
    'query-only reference keeps the full base path',
    'https://example.com/a/b/feed?page=2',
    resolve_url('https://example.com/a/b/feed', '?page=2')
);
check(
    'fragment is dropped',
    'https://example.com/a/b/c.xml',
    resolve_url('https://example.com/a/b/', 'c.xml#frag')
);
check(
    'absolute URL passes through',
    'https://other.example/x',
    resolve_url('https://example.com/a/b', 'https://other.example/x')
);
check(
    'base without path resolves against root',
    'https://example.com/feed.xml',
    resolve_url('https://example.com', 'feed.xml')
);

// safe_url(): only absolute http(s) URLs pass.
check('safe_url keeps https', 'https://example.com/feed', safe_url('https://example.com/feed'));
check('safe_url keeps http', 'http://example.com/feed', safe_url('http://example.com/feed'));
check('safe_url is case-insensitive', 'HTTP://EXAMPLE.COM/x', safe_url('HTTP://EXAMPLE.COM/x'));
check('safe_url trims whitespace', 'https://example.com/x', safe_url('  https://example.com/x  '));
check('safe_url rejects javascript:', '', safe_url('javascript:alert(1)'));
check('safe_url rejects data:', '', safe_url('data:text/html,x'));
check('safe_url rejects relative paths', '', safe_url('/api.php?action=x'));
check('safe_url rejects empty', '', safe_url(''));

// resolve_remote_target(): literal-IP inputs only, so no DNS is involved.
// Skipped when config.php exists (its allow_private_hosts / allowed_feed_ports
// settings would change the expectations).
if (is_file(config_path())) {
    echo "  (skipping resolve_remote_target checks: config.php is present)\n";
} else {
    check_null('private IPv4 is refused', resolve_remote_target('http://192.168.1.5/feed'));
    check_null('loopback IPv4 is refused', resolve_remote_target('http://127.0.0.1/feed'));
    check_null('loopback IPv6 is refused', resolve_remote_target('http://[::1]/feed'));
    check_null('non-http scheme is refused', resolve_remote_target('ftp://8.8.8.8/x'));
    check_null('non-standard port is refused', resolve_remote_target('http://8.8.8.8:8080/feed'));

    $target = resolve_remote_target('http://8.8.8.8/feed');
    check('public http target resolves', true, is_array($target));
    check('target scheme', 'http', $target['scheme'] ?? '');
    check('target host', '8.8.8.8', $target['host'] ?? '');
    check('target port', 80, $target['port'] ?? 0);
    check('target ip is pinned', '8.8.8.8', $target['ip'] ?? '');
    check('target is literal', true, $target['literal'] ?? false);

    $https = resolve_remote_target('https://8.8.8.8/x');
    check('https uses port 443', 443, $https['port'] ?? 0);
}
