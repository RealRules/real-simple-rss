<?php
declare(strict_types=1);

// sanitize_html(): external feed content must arrive harmless.

// Exact-form checks for stable transformations.
check(
    'event handler attributes are stripped',
    '<p>hello <b>world</b></p>',
    sanitize_html('<p onclick="alert(1)">hello <b>world</b></p>')
);
check(
    'iframe is dropped entirely',
    'x<p>after</p>',
    sanitize_html('x<iframe src="https://evil.example"></iframe><p>after</p>')
);
check(
    'javascript: href is removed',
    '<a>x</a>',
    sanitize_html('<a href="javascript:alert(1)">x</a>')
);
check(
    'relative img src is removed',
    '<img loading="lazy" referrerpolicy="no-referrer">',
    sanitize_html('<img src="/api.php?action=x">')
);
check(
    'http img src survives, onerror does not',
    '<img src="http://example.com/a.png" loading="lazy" referrerpolicy="no-referrer">',
    sanitize_html('<img src="http://example.com/a.png" onerror="alert(1)">')
);
check(
    'unknown tags are unwrapped, children kept',
    'keep <span>me</span>',
    sanitize_html('<article>keep <span>me</span></article>')
);
check(
    'style attribute is stripped',
    '<p>t</p>',
    sanitize_html('<p style="background:url(https://evil)">t</p>')
);
check(
    'mailto hrefs survive and get safe link attributes',
    '<a href="mailto:a@b.example" target="_blank" rel="noopener noreferrer nofollow">m</a>',
    sanitize_html('<a href="mailto:a@b.example">m</a>')
);
check(
    'https hrefs get target and rel',
    '<a href="https://example.com/" target="_blank" rel="noopener noreferrer nofollow">y</a>',
    sanitize_html('<a href="https://example.com/">y</a>')
);
check(
    'empty input yields empty output',
    '',
    sanitize_html('')
);

// Structural checks (exact serialization of these varies by libxml build).
$comment = sanitize_html('<div>a<!-- --!><script>alert(1)</script> -->b</div>');
check_not_contains('comment smuggling drops the script', $comment, 'script');
$srcset = sanitize_html('<img src="https://example.com/a.png" srcset="https://evil.example/x 2x">');
check_not_contains('srcset is stripped', $srcset, 'srcset');
$utf8 = sanitize_html('<p>Gr&uuml;&szlig;e &#10024;</p>');
check_contains('UTF-8 and entities survive', $utf8, 'e');
check_contains('UTF-8 and entities survive (bullet)', $utf8, "\u{2728}");
$deep = sanitize_html('<div><div><p>nested <em>ok</em></p></div></div>');
check_contains('nesting is preserved', $deep, 'nested <em>ok</em>');
