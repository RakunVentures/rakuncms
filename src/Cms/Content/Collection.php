<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

final class Collection
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private string $name,
        private array $config = [],
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function urlPattern(): string
    {
        return $this->config['url_pattern'] ?? '/{locale}/{slug}';
    }

    public function urlPatternForLocale(string $locale): string
    {
        $key = 'url_pattern_' . $locale;
        return $this->config[$key] ?? $this->urlPattern();
    }

    public function sortField(): string
    {
        return $this->config['sort'] ?? 'order';
    }

    public function sortDirection(): string
    {
        return $this->config['sort_direction'] ?? 'asc';
    }

    public function template(): ?string
    {
        return $this->config['template'] ?? null;
    }

    public function perPage(): int
    {
        return (int) ($this->config['per_page'] ?? 0);
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }
}
