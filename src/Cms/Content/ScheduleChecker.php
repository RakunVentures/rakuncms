<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

use Spatie\YamlFrontMatter\YamlFrontMatter;

final class ScheduleChecker
{
    private string $contentPath;

    public function __construct(string $basePath)
    {
        $this->contentPath = $basePath . '/content';
    }

    /**
     * Check if an entry should be published based on its publish_date.
     *
     * @param array<string, mixed> $entryData Raw entry data from index
     */
    public function shouldPublish(array $entryData, ?\DateTimeInterface $now = null): bool
    {
        $publishDate = $entryData['meta']['publish_date']
            ?? $entryData['publish_date']
            ?? null;

        if ($publishDate === null) {
            return true; // No publish_date means always published
        }

        $now ??= new \DateTimeImmutable();
        $scheduled = $this->parseDate((string) $publishDate);

        if ($scheduled === null) {
            return true; // Unparseable date = treat as published
        }

        return $scheduled <= $now;
    }

    /**
     * Check if an entry is scheduled for future publication.
     *
     * @param array<string, mixed> $entryData
     */
    public function isScheduled(array $entryData, ?\DateTimeInterface $now = null): bool
    {
        $publishDate = $entryData['meta']['publish_date']
            ?? $entryData['publish_date']
            ?? null;

        if ($publishDate === null) {
            return false;
        }

        $now ??= new \DateTimeImmutable();
        $scheduled = $this->parseDate((string) $publishDate);

        if ($scheduled === null) {
            return false;
        }

        return $scheduled > $now;
    }

    /**
     * Scan content directory for entries with publish_date that should now be published.
     *
     * @return list<array{file: string, collection: string, title: string}>
     */
    public function findPublishableEntries(?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable();
        $publishable = [];

        if (!is_dir($this->contentPath)) {
            return $publishable;
        }

        $collections = glob($this->contentPath . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($collections as $collectionDir) {
            $collectionName = basename($collectionDir);
            if (str_starts_with($collectionName, '_')) {
                continue;
            }

            $files = glob($collectionDir . '/*.md') ?: [];
            foreach ($files as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                $document = YamlFrontMatter::parse($content);
                $matter = $document->matter();

                $publishDate = $matter['publish_date'] ?? ($matter['meta']['publish_date'] ?? null);
                if ($publishDate === null) {
                    continue;
                }

                $scheduled = $this->parseDate((string) $publishDate);
                if ($scheduled === null || $scheduled > $now) {
                    continue;
                }

                // This entry has a publish_date in the past — it should be in the index
                $publishable[] = [
                    'file' => $file,
                    'collection' => $collectionName,
                    'title' => $matter['title'] ?? basename($file, '.md'),
                ];
            }
        }

        return $publishable;
    }

    private function parseDate(string $date): ?\DateTimeImmutable
    {
        // Try ISO 8601 with time
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $date);
        if ($dt !== false) {
            return $dt;
        }

        // Try ISO 8601 with timezone
        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $date);
        if ($dt !== false) {
            return $dt;
        }

        // Try date-only
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d', $date);
        if ($dt !== false) {
            return $dt->setTime(0, 0);
        }

        return null;
    }
}
