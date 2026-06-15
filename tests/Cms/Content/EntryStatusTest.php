<?php

declare(strict_types=1);

use Rkn\Cms\Content\EntryStatus;
use Rkn\Cms\Content\ScheduleChecker;

/**
 * Canonical status decision. Covers the Causa B regression: a raw
 * `scheduled`/`future`/`pending` status string (e.g. from a WXR import) must NOT
 * pin the entry to "scheduled" when its effective date is already in the past —
 * it should fall through to "published". The legacy WP `date` field is honoured
 * as a fallback when no explicit `publish_date` is present.
 */

/** @param array<string,mixed> $overrides */
function entryRow(array $overrides = []): array
{
    return array_merge(['draft' => false, 'meta' => []], $overrides);
}

function checker(): ScheduleChecker
{
    // contentPath is irrelevant for isScheduled()/isScheduledByDateFallback().
    return new ScheduleChecker(sys_get_temp_dir());
}

test('future publish_date is scheduled regardless of raw status', function () {
    $entry = entryRow(['meta' => ['publish_date' => '2999-12-31T00:00:00']]);
    expect(EntryStatus::of($entry, checker()))->toBe('scheduled');
});

test('raw "scheduled" status with a past date is promoted to published', function () {
    // WXR shape: status copied verbatim, only a legacy `date`, no publish_date.
    $entry = entryRow(['meta' => ['status' => 'scheduled'], 'date' => '2020-01-01']);
    expect(EntryStatus::of($entry, checker()))->toBe('published');
});

test('raw "future" status with a past date is promoted to published', function () {
    $entry = entryRow(['meta' => ['status' => 'future'], 'date' => '2018-03-15']);
    expect(EntryStatus::of($entry, checker()))->toBe('published');
});

test('raw "pending" status with a past date is promoted to published', function () {
    $entry = entryRow(['meta' => ['status' => 'pending'], 'date' => '2019-06-08']);
    expect(EntryStatus::of($entry, checker()))->toBe('published');
});

test('raw "scheduled" status with a genuinely future date stays scheduled', function () {
    // Safety property: a real future WXR `date` must keep the post out of public.
    $entry = entryRow(['meta' => ['status' => 'scheduled'], 'date' => '2999-01-01']);
    expect(EntryStatus::of($entry, checker()))->toBe('scheduled');
});

test('raw "scheduled" status with no resolvable date publishes (Case 4)', function () {
    // Hand-authored frontmatter that says "scheduled" but forgot a date: there is
    // no future moment to wait for, so it must not hide forever — publish it.
    $entry = entryRow(['meta' => ['status' => 'scheduled']]);
    expect(EntryStatus::of($entry, checker()))->toBe('published');
});

test('draft flag wins over everything', function () {
    $entry = entryRow(['draft' => true, 'meta' => ['status' => 'scheduled', 'publish_date' => '2999-01-01']]);
    expect(EntryStatus::of($entry, checker()))->toBe('draft');
});

test('raw draft status is draft', function () {
    $entry = entryRow(['meta' => ['status' => 'draft']]);
    expect(EntryStatus::of($entry, checker()))->toBe('draft');
});

test('no status and no dates is published', function () {
    $entry = entryRow(['meta' => ['status' => 'publish']]);
    expect(EntryStatus::of($entry, checker()))->toBe('published');
});
