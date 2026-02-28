<?php

declare(strict_types=1);

use Rkn\Cms\Content\ScheduleChecker;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun-schedule-test-' . uniqid();
    mkdir($this->tempDir . '/content/blog', 0755, true);

    // Entry with future publish_date
    file_put_contents($this->tempDir . '/content/blog/future-post.en.md', <<<'MD'
---
title: "Future Post"
publish_date: "2099-12-31T23:59:59"
---
This is a future post.
MD);

    // Entry with past publish_date
    file_put_contents($this->tempDir . '/content/blog/past-post.en.md', <<<'MD'
---
title: "Past Post"
publish_date: "2020-01-01T00:00:00"
---
This was published in the past.
MD);

    // Entry without publish_date
    file_put_contents($this->tempDir . '/content/blog/normal-post.en.md', <<<'MD'
---
title: "Normal Post"
---
No scheduled date.
MD);

    // Entry with date-only publish_date
    file_put_contents($this->tempDir . '/content/blog/date-only.en.md', <<<'MD'
---
title: "Date Only Post"
publish_date: "2020-06-15"
---
Date only format.
MD);
});

afterEach(function () {
    $cleanup = function (string $dir) use (&$cleanup): void {
        $items = new DirectoryIterator($dir);
        foreach ($items as $item) {
            if ($item->isDot()) continue;
            if ($item->isDir()) {
                $cleanup($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    };
    if (is_dir($this->tempDir)) {
        $cleanup($this->tempDir);
    }
});

test('entry with future publish_date is not publishable', function () {
    $checker = new ScheduleChecker($this->tempDir);
    $entry = ['publish_date' => '2099-12-31T23:59:59'];

    expect($checker->shouldPublish($entry))->toBeFalse();
    expect($checker->isScheduled($entry))->toBeTrue();
});

test('entry with past publish_date is publishable', function () {
    $checker = new ScheduleChecker($this->tempDir);
    $entry = ['publish_date' => '2020-01-01T00:00:00'];

    expect($checker->shouldPublish($entry))->toBeTrue();
    expect($checker->isScheduled($entry))->toBeFalse();
});

test('entry without publish_date is always publishable', function () {
    $checker = new ScheduleChecker($this->tempDir);
    $entry = ['title' => 'Normal Post'];

    expect($checker->shouldPublish($entry))->toBeTrue();
    expect($checker->isScheduled($entry))->toBeFalse();
});

test('publish_date in meta bag is recognized', function () {
    $checker = new ScheduleChecker($this->tempDir);
    $entry = ['meta' => ['publish_date' => '2099-12-31T23:59:59']];

    expect($checker->shouldPublish($entry))->toBeFalse();
    expect($checker->isScheduled($entry))->toBeTrue();
});

test('date-only format is supported', function () {
    $checker = new ScheduleChecker($this->tempDir);
    $entry = ['publish_date' => '2020-06-15'];

    expect($checker->shouldPublish($entry))->toBeTrue();
});

test('findPublishableEntries finds past entries', function () {
    $checker = new ScheduleChecker($this->tempDir);
    $publishable = $checker->findPublishableEntries();

    $titles = array_column($publishable, 'title');
    expect($titles)->toContain('Past Post');
    expect($titles)->toContain('Date Only Post');
    expect($titles)->not->toContain('Future Post');
    // Normal Post has no publish_date, so it's not in publishable (it's always published)
    expect($titles)->not->toContain('Normal Post');
});

test('findPublishableEntries with custom now date', function () {
    $checker = new ScheduleChecker($this->tempDir);
    // Set now to year 2100 — everything should be publishable
    $future = new DateTimeImmutable('2100-01-01');
    $publishable = $checker->findPublishableEntries($future);

    $titles = array_column($publishable, 'title');
    expect($titles)->toContain('Future Post');
    expect($titles)->toContain('Past Post');
});

test('findPublishableEntries skips _globals directory', function () {
    mkdir($this->tempDir . '/content/_globals', 0755, true);
    file_put_contents($this->tempDir . '/content/_globals/site.yaml', 'title: Test');

    $checker = new ScheduleChecker($this->tempDir);
    $publishable = $checker->findPublishableEntries();

    $collections = array_column($publishable, 'collection');
    expect($collections)->not->toContain('_globals');
});

test('shouldPublish with custom now date', function () {
    $checker = new ScheduleChecker($this->tempDir);
    $entry = ['publish_date' => '2025-06-01T00:00:00'];

    $before = new DateTimeImmutable('2025-05-01');
    $after = new DateTimeImmutable('2025-07-01');

    expect($checker->shouldPublish($entry, $before))->toBeFalse();
    expect($checker->shouldPublish($entry, $after))->toBeTrue();
});

test('invalid date format is treated as published', function () {
    $checker = new ScheduleChecker($this->tempDir);
    $entry = ['publish_date' => 'not-a-date'];

    expect($checker->shouldPublish($entry))->toBeTrue();
    expect($checker->isScheduled($entry))->toBeFalse();
});
