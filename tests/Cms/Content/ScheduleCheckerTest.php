<?php

declare(strict_types=1);

use Rkn\Cms\Content\ScheduleChecker;

test('isDuePublishable: status future + past date is due', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->isDuePublishable(['status' => 'future', 'date' => '2020-01-01 00:00:00']))->toBeTrue();
    expect($sc->isDuePublishable(['status' => 'scheduled', 'date' => '2018-05-05']))->toBeTrue();
    expect($sc->isDuePublishable(['status' => 'pending', 'date' => '2019-12-31 23:59:59']))->toBeTrue();
});

test('isDuePublishable: status future + future date is NOT due', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->isDuePublishable(['status' => 'future', 'date' => '2999-01-01 00:00:00']))->toBeFalse();
});

test('isDuePublishable: publish_date past is due (legacy gate)', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->isDuePublishable(['publish_date' => '2020-01-01T00:00:00']))->toBeTrue();
    expect($sc->isDuePublishable(['publish_date' => '2999-01-01T00:00:00']))->toBeFalse();
});

test('isDuePublishable: published/draft entries are never due', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->isDuePublishable(['status' => 'publish', 'date' => '2020-01-01']))->toBeFalse();
    expect($sc->isDuePublishable(['status' => 'draft', 'date' => '2020-01-01']))->toBeFalse();
    expect($sc->isDuePublishable(['title' => 'no status, no dates']))->toBeFalse();
});

test('isDuePublishable: scheduled status but no date is not due', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->isDuePublishable(['status' => 'future']))->toBeFalse();
});

test('isDuePublishable: reads from meta bag too', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->isDuePublishable(['meta' => ['status' => 'future', 'date' => '2020-01-01']]))->toBeTrue();
});

test('isDuePublishable: custom now', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    $entry = ['status' => 'future', 'date' => '2025-06-01 00:00:00'];
    expect($sc->isDuePublishable($entry, new DateTimeImmutable('2025-05-01')))->toBeFalse();
    expect($sc->isDuePublishable($entry, new DateTimeImmutable('2025-07-01')))->toBeTrue();
});

// ── shouldPublish / isScheduled (publish_date gate, used by EntryStatus) ──────

test('entry with future publish_date is not publishable', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    $entry = ['publish_date' => '2099-12-31T23:59:59'];
    expect($sc->shouldPublish($entry))->toBeFalse();
    expect($sc->isScheduled($entry))->toBeTrue();
});

test('entry with past publish_date is publishable', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    $entry = ['publish_date' => '2020-01-01T00:00:00'];
    expect($sc->shouldPublish($entry))->toBeTrue();
    expect($sc->isScheduled($entry))->toBeFalse();
});

test('entry without publish_date is always publishable', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->shouldPublish(['title' => 'Normal']))->toBeTrue();
    expect($sc->isScheduled(['title' => 'Normal']))->toBeFalse();
});

test('date-only and invalid formats', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->shouldPublish(['publish_date' => '2020-06-15']))->toBeTrue();
    expect($sc->shouldPublish(['publish_date' => 'not-a-date']))->toBeTrue();
});

// ── isScheduledByDateFallback (legacy `date`, WXR) ────────────────────────────

test('isScheduledByDateFallback honours the legacy date field', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->isScheduledByDateFallback(['date' => '2999-01-01']))->toBeTrue();
    expect($sc->isScheduledByDateFallback(['date' => '2020-01-01']))->toBeFalse();
    expect($sc->isScheduledByDateFallback(['publish_date' => '2020-01-01', 'date' => '2999-01-01']))->toBeFalse();
});

test('space-separated WordPress datetime is parsed', function () {
    $sc = new ScheduleChecker(sys_get_temp_dir());
    expect($sc->isScheduledByDateFallback(['date' => '2999-01-01 10:00:00']))->toBeTrue();
    expect($sc->isScheduledByDateFallback(['date' => '2018-03-15 10:30:00']))->toBeFalse();
});

// ── findPublishableEntries (file scan; recursive + status-aware) ──────────────

test('findPublishableEntries: detects status=future past + recurses nested dirs', function () {
    $dir = sys_get_temp_dir() . '/rkn-sched-' . uniqid();
    mkdir($dir . '/content/blog/2020/01', 0755, true);
    file_put_contents("{$dir}/content/blog/2020/01/nested-due.md",
        "---\ntitle: \"Nested Due\"\nstatus: \"future\"\ndate: \"2020-01-05 00:00:00\"\n---\nx\n");
    file_put_contents("{$dir}/content/blog/future-pending.md",
        "---\ntitle: \"Pending\"\nstatus: \"future\"\ndate: \"2999-01-01\"\n---\nx\n");
    file_put_contents("{$dir}/content/blog/plain.md",
        "---\ntitle: \"Plain\"\ndate: \"2019-01-01\"\n---\nx\n");

    $titles = array_column((new ScheduleChecker($dir))->findPublishableEntries(), 'title');
    expect($titles)->toContain('Nested Due');     // recursivo + status future + past
    expect($titles)->not->toContain('Pending');   // status future + future date
    expect($titles)->not->toContain('Plain');     // no programada

    $rm = function (string $d) use (&$rm) {
        foreach (new DirectoryIterator($d) as $i) {
            if ($i->isDot()) continue;
            $i->isDir() ? $rm($i->getPathname()) : unlink($i->getPathname());
        }
        rmdir($d);
    };
    $rm($dir);
});
