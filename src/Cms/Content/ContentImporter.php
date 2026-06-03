<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

/**
 * Copies every entry from one ContentStorage to another, preserving frontmatter
 * and body. Drives both directions:
 *   - file  → mysql : migrate a flat-file site into the MySQL SSoT (WS-C).
 *   - mysql → file  : regenerate the `.md` cache from the SSoT (rebuild-cache).
 *
 * Streams via listKeys() so it never holds all entries in memory at once.
 */
final class ContentImporter
{
    /**
     * @param  callable(ContentRef): void|null  $onEach  optional progress hook
     */
    public function importAll(ContentStorage $from, ContentStorage $to, ?callable $onEach = null): int
    {
        $count = 0;
        foreach ($from->listKeys() as $ref) {
            $body = $from->read($ref->collection, $ref->locale, $ref->slug);
            if ($body === null) {
                continue;
            }

            $to->write(new ContentDraft(
                $ref->collection,
                $ref->locale,
                $ref->slug,
                $body->frontmatter,
                $body->body,
            ));

            $count++;
            if ($onEach !== null) {
                $onEach($ref);
            }
        }

        return $count;
    }
}
