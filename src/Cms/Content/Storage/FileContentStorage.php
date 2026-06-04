<?php

declare(strict_types=1);

namespace Rkn\Cms\Content\Storage;

use Rkn\Cms\Content\ContentBody;
use Rkn\Cms\Content\ContentDraft;
use Rkn\Cms\Content\ContentRef;
use Rkn\Cms\Content\ContentStorage;
use Symfony\Component\Yaml\Yaml;

/**
 * Flat-file content storage: `.md` files with YAML frontmatter under
 * `content/{collection}/{slug}.{locale}.md`. This IS the source of truth for the
 * `file` driver (OSS default) and mirrors the format ContentApiController writes.
 */
final class FileContentStorage implements ContentStorage
{
    public function __construct(
        private readonly string $basePath,
        private readonly string $defaultLocale = 'en',
    ) {
    }

    public function read(string $collection, string $locale, string $slug): ?ContentBody
    {
        $file = $this->locate($collection, $locale, $slug);
        if ($file === null) {
            return null;
        }

        [$frontmatter, $body] = $this->parse($file);

        return new ContentBody($collection, $locale, $slug, $frontmatter, $body, $this->relative($file));
    }

    public function write(ContentDraft $draft): ContentRef
    {
        $dir = $this->collectionDir($draft->collection);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Sobrescribir el archivo existente IN SITU (preservando su forma de nombre)
        // para NO crear duplicados al editar: un {slug}.md (locale por defecto) se
        // reescribía como {slug}.{locale}.md, dejando ambos. Si no existe, se crea con
        // la convención de siempre ({slug}.{locale}.md).
        $existing = $this->locate($draft->collection, $draft->locale, $draft->slug);
        if ($existing !== null) {
            $file = $existing;
        } else {
            $suffix = $draft->locale !== '' ? ".{$draft->locale}" : '';
            $file   = "{$dir}/{$draft->slug}{$suffix}.md";
        }

        // The slug may contain a section path (e.g. "category/post"); ensure the
        // intermediate directories exist.
        $parent = dirname($file);
        if (!is_dir($parent)) {
            mkdir($parent, 0755, true);
        }

        $content = "---\n" . Yaml::dump($draft->frontmatter, 2) . "---\n\n" . $draft->body;
        file_put_contents($file, $content);

        return new ContentRef($draft->collection, $draft->locale, $draft->slug, $this->relative($file));
    }

    public function delete(string $collection, string $locale, string $slug): bool
    {
        $file = $this->locate($collection, $locale, $slug);
        if ($file === null) {
            return false;
        }

        return unlink($file);
    }

    public function listKeys(): iterable
    {
        $root = $this->basePath . '/content';
        if (!is_dir($root)) {
            return;
        }

        foreach (new \DirectoryIterator($root) as $collectionDir) {
            if (!$collectionDir->isDir() || $collectionDir->isDot() || str_starts_with($collectionDir->getFilename(), '_')) {
                continue;
            }
            $collection = $collectionDir->getFilename();

            $collectionPath = $collectionDir->getPathname();
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($collectionPath, \RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'md' || str_starts_with($file->getFilename(), '.')) {
                    continue;
                }

                // Path within the collection, sans extension (e.g. "section/post.es").
                $relWithin = substr($file->getPathname(), strlen($collectionPath) + 1);
                $relWithin = (string) preg_replace('/\.md$/', '', $relWithin);
                [$slug, $locale] = $this->splitRelative($relWithin);

                yield new ContentRef($collection, $locale, $slug, $this->relative($file->getPathname()));
            }
        }
    }

    // --------------------------------------------------------------- internals

    private function locate(string $collection, string $locale, string $slug): ?string
    {
        $dir = $this->collectionDir($collection);
        foreach (["{$dir}/{$slug}.{$locale}.md", "{$dir}/{$slug}.md"] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    private function parse(string $file): array
    {
        $raw   = (string) file_get_contents($file);
        $parts = explode('---', $raw, 3);

        $frontmatter = [];
        $body        = $raw;
        if (count($parts) >= 3) {
            $parsed = Yaml::parse($parts[1]);
            if (is_array($parsed)) {
                $frontmatter = $parsed;
            }
            $body = ltrim($parts[2], "\n");
        }

        return [$frontmatter, $body];
    }

    /**
     * Split a path-within-collection (sans .md) into [slug, locale]. The slug may
     * contain a section path (e.g. "section/post"); only the LAST segment's
     * trailing 2-char part is treated as the locale, else the default locale.
     *
     * @return array{0: string, 1: string}
     */
    private function splitRelative(string $rel): array
    {
        $slash = strrpos($rel, '/');
        $dir   = $slash !== false ? substr($rel, 0, $slash + 1) : '';
        $name  = $slash !== false ? substr($rel, $slash + 1) : $rel;

        $segments = explode('.', $name);
        if (count($segments) >= 2 && strlen((string) end($segments)) === 2) {
            $locale = (string) array_pop($segments);

            return [$dir . implode('.', $segments), $locale];
        }

        return [$dir . $name, $this->defaultLocale];
    }

    private function collectionDir(string $collection): string
    {
        return $this->basePath . '/content/' . $collection;
    }

    private function relative(string $file): string
    {
        return str_replace($this->basePath . '/', '', $file);
    }
}
