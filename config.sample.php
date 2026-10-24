<?php
// Real Simple RSS configuration template.
// This file is provided as a reference. The installer (setup.php) generates
// the real config.php in this directory. Do not commit real credentials.

return [
    'installed'   => false,
    'app_name'    => 'Real Simple RSS',
    'db_host'     => 'localhost',
    'db_port'     => '3306',
    'db_name'     => 'real_simple_rss',
    'db_user'     => 'rsrss_user',
    'db_pass'     => 'change-me',
    'cron_secret' => 'generate-a-long-random-secret',

    // Set to true only if you intentionally subscribe to feeds on private or
    // loopback addresses (e.g. a feed served from your LAN). Disabled by default
    // to prevent server-side request forgery.
    'allow_private_hosts' => false,

    // Ports that feed URLs may connect to. Keep this narrow to avoid using the
    // reader to probe other services. Add a port if a feed lives on one.
    'allowed_feed_ports'  => [80, 443],

    // List of proxy IPs whose X-Forwarded-Proto header may be trusted when
    // determining whether the request arrived over HTTPS.
    'trusted_proxies'     => [],
];
