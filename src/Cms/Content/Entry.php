<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

final class Entry
{
    private ?string $renderedContent = null;

    /**
     * @param array<string, mixed> $meta
     * @param array<string, string> $slugs
     * @param list<string> $tags
     */
    public function __construct(
        private string $title,
        private string $slug,
        private string $collection,
        private string $locale,
        private string $file,
        private ?string $template = null,
        private ?string $date = null,
        private int $order = 0,
        private bool $draft = false,
        private array $meta = [],
        private array $slugs = [],
        private int $mtime = 0,
        private array $tags = [],
    ) {
    }

    /**
     * Create from index array data.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            title: $data['title'] ?? '',
            slug: $data['slug'] ?? '',
            collection: $data['collection'] ?? '',
            locale: $data['locale'] ?? 'es',
            file: $data['file'] ?? '',
            template: $data['template'] ?? null,
            date: $data['date'] ?? null,
            order: (int) ($data['order'] ?? 0),
            draft: (bool) ($data['draft'] ?? false),
            meta: $data['meta'] ?? [],
            slugs: $data['slugs'] ?? [],
            mtime: (int) ($data['mtime'] ?? 0),
            tags: $data['tags'] ?? [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'slug' => $this->slug,
            'collection' => $this->collection,
            'locale' => $this->locale,
            'file' => $this->file,
            'template' => $this->template,
            'date' => $this->date,
            'order' => $this->order,
            'draft' => $this->draft,
            'meta' => $this->meta,
            'slugs' => $this->slugs,
            'mtime' => $this->mtime,
            'tags' => $this->tags,
        ];
    }

    public function title(): string { return $this->title; }
    public function slug(): string { return $this->slug; }
    public function collection(): string { return $this->collection; }
    public function locale(): string { return $this->locale; }
    public function file(): string { return $this->file; }
    public function template(): ?string { return $this->template; }
    public function date(): ?string { return $this->date; }
    public function order(): int { return $this->order; }
    public function isDraft(): bool { return $this->draft; }
    public function mtime(): int { return $this->mtime; }

    /** @return list<string> */
    public function tags(): array { return $this->tags; }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array { return $this->meta; }

    /**
     * @return array<string, string>
     */
    public function slugs(): array { return $this->slugs; }

    /**
     * Get a specific meta value.
     */
    public function getMeta(string $key, mixed $default = null): mixed
    {
        return $this->meta[$key] ?? $default;
    }

    /**
     * Get the slug for a specific locale.
     */
    public function slugForLocale(string $locale): string
    {
        return $this->slugs[$locale] ?? $this->slug;
    }

    /**
     * Get the rendered Markdown content (lazy loaded).
     */
    public function content(): string
    {
        if ($this->renderedContent === null) {
            $parser = new Parser();
            $this->renderedContent = $parser->renderContent($this->file);
        }
        return $this->renderedContent;
    }

    /**
     * Get the URL for this entry.
     */
    public function url(): string
    {
        $locale = $this->locale;
        $slug = $this->slugForLocale($locale);

        if ($this->collection === 'pages') {
            if ($slug === 'home' || $slug === 'inicio') {
                return '/' . $locale . '/';
            }
            return '/' . $locale . '/' . $slug;
        }

        // For collection items, use collection name in URL
        $collectionSlug = $this->collection;
        if ($locale === 'en') {
            // Map Spanish collection names to English
            $map = ['habitaciones' => 'rooms'];
            $collectionSlug = $map[$collectionSlug] ?? $collectionSlug;
        }

        return '/' . $locale . '/' . $collectionSlug . '/' . $slug;
    }
}
