<?php
declare(strict_types=1);

// normalize_date(): the leading weekday name is decorative and must not be
// allowed to shift the stated date (feeds emit wrong day names in the wild).
check(
    'mismatched weekday does not shift the date',
    normalize_date('Mon, 03 Oct 2026 12:00:00 +0200'),
    normalize_date('03 Oct 2026 12:00:00 +0200')
);
check(
    'weekday without comma is stripped too',
    normalize_date('Sat 03 Oct 2026 12:00:00 GMT'),
    normalize_date('03 Oct 2026 12:00:00 GMT')
);
check(
    'full weekday name is stripped',
    normalize_date('Wednesday, 01 Oct 2026 09:00:00 GMT'),
    normalize_date('01 Oct 2026 09:00:00 GMT')
);
check(
    'matching weekday is unaffected',
    normalize_date('Sat, 03 Oct 2026 12:00:00 GMT'),
    normalize_date('03 Oct 2026 12:00:00 GMT')
);
check(
    'ISO 8601 untouched',
    normalize_date('2026-10-03T12:00:00Z'),
    date('Y-m-d H:i:s', strtotime('2026-10-03T12:00:00Z'))
);
check(
    'date without weekday untouched',
    normalize_date('03 October 2026 10:00:00 GMT'),
    date('Y-m-d H:i:s', strtotime('03 October 2026 10:00:00 GMT'))
);
check_null('garbage returns null', normalize_date('garbage'));
check_null('empty string returns null', normalize_date(''));
check_null('weekday-only string returns null', normalize_date('Sat,'));
check_null('null returns null', normalize_date(null));
