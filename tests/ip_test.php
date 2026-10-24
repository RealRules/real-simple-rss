<?php
declare(strict_types=1);

// is_public_ip(): feed fetching must not reach private or non-routable space.
$publicIps = [
    '8.8.8.8', '1.1.1.1', '93.184.216.34',
    '2606:4700::1111', '2a00:1450::1',
];
foreach ($publicIps as $ip) {
    check("is_public_ip('$ip')", true, is_public_ip($ip));
}

$nonPublicIps = [
    '127.0.0.1',           // loopback
    '10.0.0.1',             // private
    '192.168.1.1',          // private
    '172.16.0.1',           // private (start of /12)
    '172.31.255.254',       // private (end of /12)
    '169.254.1.1',          // link-local
    '100.64.0.1',           // CGNAT
    '0.0.0.0',              // "this" network
    '224.0.0.1',            // multicast
    '240.0.0.1',            // reserved
    '192.0.2.1',            // TEST-NET-1
    '198.51.100.1',         // TEST-NET-2
    '203.0.113.1',          // TEST-NET-3
    '198.18.0.1',           // benchmark
    '::1',                  // IPv6 loopback
    'fe80::1',              // IPv6 link-local
    'ff00::1',              // IPv6 multicast
    '::ffff:192.168.1.1',   // IPv4-mapped private
    '::ffff:127.0.0.1',     // IPv4-mapped loopback
    '64:ff9b::192.168.1.1',  // NAT64 wrapping private IPv4
    '2002:7f00:1::',        // 6to4 wrapping loopback
];
foreach ($nonPublicIps as $ip) {
    check("is_public_ip('$ip')", false, is_public_ip($ip));
}

// IPv6 forms that wrap an IPv4 address: NAT64 and 6to4 are IANA
// special-purpose ranges, so they stay blocked even when the embedded IPv4
// is public (safe default for SSRF protection).
check('NAT64 wrapping public IPv4 is still blocked', false, is_public_ip('64:ff9b::8.8.8.8'));
check('6to4 wrapping public IPv4 is still blocked', false, is_public_ip('2002:0808:0808::'));

// embedded_ipv4(): extraction from NAT64, 6to4 and IPv4-mapped forms.
check('embedded in IPv4-mapped form', '192.168.1.1', embedded_ipv4((string)inet_pton('::ffff:192.168.1.1')));
check('embedded in NAT64 form', '8.8.8.8', embedded_ipv4((string)inet_pton('64:ff9b::8.8.8.8')));
check('embedded in 6to4 form', '8.8.8.8', embedded_ipv4((string)inet_pton('2002:0808:0808::')));
check('no embedded IPv4 in regular address', null, embedded_ipv4((string)inet_pton('2001:db8::1')));
