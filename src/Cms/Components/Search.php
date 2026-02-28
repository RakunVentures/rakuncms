<?php

declare(strict_types=1);

namespace Rkn\Cms\Components;

use Clickfwd\Yoyo\Component;

/**
 * Yoyo live search component that queries the content index.
 */
class Search extends Component
{
    public string $query = '';

    /** @var list<array<string, mixed>> */
    public array $results = [];

    public function updatedQuery(): void
    {
        $this->results = [];

        if (strlen(trim($this->query)) < 2) {
            return;
        }

        $container = \Rkn\Framework\Application::getInstance()?->getContainer();
        if (!$container) {
            return;
        }

        $basePath = $container->has('base_path') ? $container->get('base_path') : getcwd();
        $indexFile = $basePath . '/cache/content-index.php';

        if (!is_file($indexFile)) {
            return;
        }

        $index = require $indexFile;
        if (!is_array($index) || !isset($index['entries'])) {
            return;
        }

        $locale = $container->has('locale') ? $container->get('locale') : 'es';
        $search = mb_strtolower(trim($this->query));
        $matched = [];

        foreach ($index['entries'] as $entry) {
            if (($entry['locale'] ?? '') !== $locale) {
                continue;
            }

            $title = mb_strtolower($entry['title'] ?? '');
            $description = mb_strtolower($entry['meta']['description'] ?? '');
            $content = mb_strtolower($entry['title'] ?? '');

            if (str_contains($title, $search) || str_contains($description, $search)) {
                $matched[] = [
                    'title' => $entry['title'],
                    'url' => $entry['url'] ?? '#',
                    'collection' => $entry['collection'] ?? 'pages',
                ];
                if (count($matched) >= 10) {
                    break;
                }
            }
        }

        $this->results = $matched;
    }

    public function render(): string
    {
        return $this->view('yoyo/search');
    }
}
