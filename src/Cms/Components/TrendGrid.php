<?php

declare(strict_types=1);

namespace Rkn\Cms\Components;

use Clickfwd\Yoyo\Component;
use Rkn\Cms\Content\Query;
use Rkn\Framework\Application;

class TrendGrid extends Component
{
    public string $tag = '';
    public int $limit = 6;

    protected $props = ['tag', 'limit'];

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
            $q = $query->collection('blog')->sort('date', 'desc');
            
            if ($this->tag) {
                $q = $q->where('tags', 'has', $this->tag);
            }
            
            $posts = $q->limit($this->limit)->get();
        }

        return $this->view('yoyo/trend-grid', [
            'posts' => $posts,
            'limit' => $this->limit,
            'tag' => $this->tag
        ]);
    }
}
