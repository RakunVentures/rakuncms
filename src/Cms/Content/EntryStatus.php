<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

/**
 * Canonical status computation for a content entry.
 * Centralises the draft/scheduled/published decision so every store
 * (PhpArray, SQLite) and every consumer derives the same value.
 */
final class EntryStatus
{
    private function __construct() {}

    /**
     * Compute the canonical status string for an entry.
     *
     * @param array<string, mixed> $entry  indexFile-shaped row
     * @param ScheduleChecker      $sc     checker for publish_date evaluation
     * @return 'draft'|'scheduled'|'published'
     */
    public static function of(array $entry, ScheduleChecker $sc): string
    {
        $raw = strtolower((string) ($entry['meta']['status'] ?? ''));

        if ($entry['draft'] === true || $raw === 'draft') {
            return 'draft';
        }

        // A raw `scheduled`/`future`/`pending` status (e.g. copied verbatim by the
        // WXR importer) only means "scheduled" while its effective date is still in
        // the future. With a past date it is already due → fall through to published.
        $isScheduledRaw = in_array($raw, ['future', 'scheduled', 'pending'], true);

        if ($sc->isScheduled($entry) || ($isScheduledRaw && $sc->isScheduledByDateFallback($entry))) {
            return 'scheduled';
        }

        return 'published';
    }
}
