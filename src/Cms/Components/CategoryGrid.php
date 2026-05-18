<?php

declare(strict_types=1);

namespace Rkn\Cms\Components;

use Clickfwd\Yoyo\Component;
use Rkn\Cms\Content\Query;
use Rkn\Framework\Application;

class CategoryGrid extends Component
{
    public string $category = '';
    public string $collection = 'blog';
    public string $locale = 'es';
    public int $limit = 6;

    protected $props = ['category', 'collection', 'locale', 'limit'];

    public function loadMore(): void
    {
        $this->limit += 6;
    }

    /** @return string|\Clickfwd\Yoyo\Interfaces\ViewProviderInterface */
    public function render(): string|\Clickfwd\Yoyo\Interfaces\ViewProviderInterface
    {
        $container = Application::getInstance()?->container();
        $basePath = $container->has('base_path') ? $container->get('base_path') : getcwd();
        $indexFile = $basePath . '/cache/content-index.php';
        
        $posts = [];
        if (is_file($indexFile)) {
            $index = require $indexFile;
            $query = new Query($index);
            $posts = $query->collection($this->collection)
                ->locale($this->locale)
                ->where('categories', 'has', $this->category)
                ->sort('date', 'desc')
                ->limit($this->limit)
                ->get();
        }

        return $this->view('yoyo/category-grid', [
            'posts' => $posts,
            'limit' => $this->limit,
            'category' => $this->category
        ]);
    }
}
