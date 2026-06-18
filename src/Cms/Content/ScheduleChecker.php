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
     * Date-based scheduling check that also honours a legacy `date` field
     * (e.g. WXR-imported posts) when no explicit publish_date is present.
     * Used to validate a raw `scheduled`/`future`/`pending` status string: only
     * a still-future effective date should keep the entry out of the public index.
     *
     * @param array<string, mixed> $entryData
     */
    public function isScheduledByDateFallback(array $entryData, ?\DateTimeInterface $now = null): bool
    {
        $effectiveDate = $entryData['meta']['publish_date']
            ?? $entryData['publish_date']
            ?? $entryData['meta']['date']
            ?? $entryData['date']
            ?? null;

        if ($effectiveDate === null) {
            return false;
        }

        $now ??= new \DateTimeImmutable();
        $scheduled = $this->parseDate((string) $effectiveDate);

        return $scheduled !== null && $scheduled > $now;
    }

    /** Status crudos que indican "programado" (WP/WXR + admin). */
    private const SCHEDULED_STATUSES = ['future', 'scheduled', 'pending'];

    /**
     * Escanea el contenido por entradas PROGRAMADAS que ya son exigibles (su
     * fecha efectiva pasó) y por tanto deben pasar a publicadas.
     *
     * Una entrada es programada si:
     *   - tiene `publish_date` (gate explícito), o
     *   - su `status` es future/scheduled/pending (estilo WXR/admin).
     * Es exigible si su fecha efectiva (publish_date, o el `date` como fallback)
     * es <= ahora.
     *
     * Recursivo: el contenido importado de WordPress anida por /YYYY/MM/, así que
     * un glob plano no veía esas entradas (causa raíz de que nunca se publicaran).
     *
     * @return list<array{file: string, collection: string, title: string, status: string}>
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

            foreach ($this->markdownFiles($collectionDir) as $file) {
                $content = file_get_contents($file);
                if ($content === false) {
                    continue;
                }

                try {
                    $document = YamlFrontMatter::parse($content);
                } catch (\Throwable $e) {
                    error_log('[rakun] skipping unparseable frontmatter in ' . $file . ': ' . $e->getMessage());
                    continue;
                }
                $matter = $document->matter();

                $rawStatus = strtolower(trim((string) ($matter['status'] ?? ($matter['meta']['status'] ?? ''))));
                $isScheduledRaw = in_array($rawStatus, self::SCHEDULED_STATUSES, true);
                $publishDate = $matter['publish_date'] ?? ($matter['meta']['publish_date'] ?? null);

                // No es una entrada programada: ni publish_date ni status programado.
                if ($publishDate === null && !$isScheduledRaw) {
                    continue;
                }

                // Fecha efectiva: publish_date manda; si no, el `date` (WXR/admin).
                $effective = $publishDate
                    ?? $matter['date']
                    ?? ($matter['meta']['date'] ?? null);
                if ($effective === null) {
                    continue;
                }

                $scheduled = $this->parseDate((string) $effective);
                if ($scheduled === null || $scheduled > $now) {
                    continue; // aún en el futuro → sigue programada
                }

                $publishable[] = [
                    'file' => $file,
                    'collection' => $collectionName,
                    'title' => $matter['title'] ?? basename($file, '.md'),
                    'status' => $rawStatus,
                ];
            }
        }

        return $publishable;
    }

    /**
     * Todos los .md bajo un directorio de colección, recursivo. Salta cualquier
     * subdirectorio con prefijo `_` (drafts, layouts, etc.).
     *
     * @return list<string>
     */
    private function markdownFiles(string $dir): array
    {
        $out = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $entry) {
            if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'md') {
                continue;
            }
            $rel = substr($entry->getPathname(), strlen($dir) + 1);
            if (preg_match('#(^|/)_#', $rel) === 1) {
                continue;
            }
            $out[] = $entry->getPathname();
        }

        return $out;
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

        // Try space-separated datetime (WordPress post_date / WXR imports)
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $date);
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
