<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

use Spatie\YamlFrontMatter\YamlFrontMatter;

final class DraftResolver
{
    private string $contentPath;

    public function __construct(string $basePath)
    {
        $this->contentPath = $basePath . '/content';
    }

    /**
     * Check if the preview token is valid.
     */
    public function isValidToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        $configToken = '';
        try {
            $configToken = (string) \config('preview.token', '');
        } catch (\Throwable) {
        }

        return $configToken !== '' && hash_equals($configToken, $token);
    }

    /**
     * Find a draft entry by collection, locale, and slug.
     */
    public function findDraft(string $collection, string $locale, string $slug): ?Entry
    {
        $collectionPath = $this->contentPath . '/' . $collection;
        if (!is_dir($collectionPath)) {
            return null;
        }

        $files = glob($collectionPath . '/*.md') ?: [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                continue;
            }

            $document = YamlFrontMatter::parse($content);
            $matter = $document->matter();

            if (empty($matter['draft'])) {
                continue;
            }

            $basename = basename($file, '.md');
            $entrySlug = $this->extractSlug($basename);
            $entryLocale = $this->detectLocale($basename, $locale);

            if ($entrySlug === $slug && $entryLocale === $locale) {
                return Entry::fromArray([
                    'title' => $matter['title'] ?? ucfirst($slug),
                    'slug' => $matter['slug'] ?? $entrySlug,
                    'collection' => $collection,
                    'locale' => $entryLocale,
                    'file' => $this->relativePath($file),
                    'template' => $matter['template'] ?? null,
                    'date' => isset($matter['date']) ? (string) $matter['date'] : null,
                    'order' => (int) ($matter['order'] ?? 0),
                    'draft' => true,
                    'meta' => $matter['meta'] ?? $matter,
                    'slugs' => $matter['slugs'] ?? [],
                    'mtime' => filemtime($file) ?: 0,
                ]);
            }
        }

        return null;
    }

    /**
     * Wrap rendered HTML with a draft banner.
     */
    public function injectDraftBanner(string $html): string
    {
        $banner = '<div style="position:fixed;top:0;left:0;right:0;z-index:99999;background:#f59e0b;color:#000;text-align:center;padding:8px 16px;font-family:system-ui,sans-serif;font-weight:bold;font-size:14px;">DRAFT PREVIEW</div>';

        // Inject after <body> tag if present
        if (preg_match('/<body[^>]*>/i', $html, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1] + strlen($matches[0][0]);
            return substr($html, 0, $pos) . $banner . substr($html, $pos);
        }

        return $banner . $html;
    }

    private function extractSlug(string $basename): string
    {
        $name = preg_replace('/^\d+\./', '', $basename);
        if ($name === null) {
            $name = $basename;
        }

        $parts = explode('.', $name);
        if (count($parts) >= 2) {
            $possibleLocale = end($parts);
            if (strlen($possibleLocale) === 2) {
                array_pop($parts);
                return implode('.', $parts);
            }
        }

        return $name;
    }

    private function detectLocale(string $basename, string $defaultLocale): string
    {
        $name = preg_replace('/^\d+\./', '', $basename);
        if ($name === null) {
            $name = $basename;
        }

        $parts = explode('.', $name);
        if (count($parts) >= 2) {
            $possibleLocale = end($parts);
            if (strlen($possibleLocale) === 2) {
                return $possibleLocale;
            }
        }

        return $defaultLocale;
    }

    private function relativePath(string $filePath): string
    {
        $contentParent = dirname($this->contentPath);
        if (str_starts_with($filePath, $contentParent)) {
            return ltrim(substr($filePath, strlen($contentParent)), '/');
        }
        return $filePath;
    }
}
