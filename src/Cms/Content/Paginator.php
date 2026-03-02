<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

final class Paginator
{
    /** @var list<Entry> */
    private array $items;
    private int $totalItems;
    private int $totalPages;
    private string $baseUrl;

    public function __construct(
        private Query $query,
        private int $perPage = 10,
        private int $currentPage = 1,
    ) {
        if ($this->currentPage < 1) {
            $this->currentPage = 1;
        }
        if ($this->perPage < 1) {
            $this->perPage = 10;
        }

        $this->totalItems = $this->query->count();
        $this->totalPages = (int) max(1, ceil($this->totalItems / $this->perPage));

        $offset = ($this->currentPage - 1) * $this->perPage;
        $this->items = $this->query->offset($offset)->limit($this->perPage)->get();

        // Resolve base URL from the current request path
        $this->baseUrl = $this->resolveBaseUrl();
    }

    /** @return list<Entry> */
    public function items(): array
    {
        return $this->items;
    }

    public function currentPage(): int
    {
        return $this->currentPage;
    }

    public function totalPages(): int
    {
        return $this->totalPages;
    }

    public function totalItems(): int
    {
        return $this->totalItems;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->totalPages;
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function nextPageUrl(): string
    {
        if (!$this->hasNextPage()) {
            return '';
        }
        return $this->pageUrl($this->currentPage + 1);
    }

    public function previousPageUrl(): string
    {
        if (!$this->hasPreviousPage()) {
            return '';
        }
        if ($this->currentPage - 1 === 1) {
            return $this->baseUrl;
        }
        return $this->pageUrl($this->currentPage - 1);
    }

    public function pageUrl(int $page): string
    {
        if ($page === 1) {
            return $this->baseUrl;
        }
        return rtrim($this->baseUrl, '/') . '/page/' . $page;
    }

    /**
     * @return array{items: list<array<string, mixed>>, current_page: int, total_pages: int, total_items: int, per_page: int, has_next: bool, has_previous: bool}
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(fn (Entry $e) => $e->toArray(), $this->items),
            'current_page' => $this->currentPage,
            'total_pages' => $this->totalPages,
            'total_items' => $this->totalItems,
            'per_page' => $this->perPage,
            'has_next' => $this->hasNextPage(),
            'has_previous' => $this->hasPreviousPage(),
        ];
    }

    private function resolveBaseUrl(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        
        // Strip out /page/N
        $path = preg_replace('#/page/\d+/?$#', '', $path);
        
        if ($path === null || $path === '' || $path === '/') {
            try {
                return '/' . \app('locale');
            } catch (\Throwable) {
                return '/';
            }
        }
        
        return rtrim($path, '/');
    }
}
