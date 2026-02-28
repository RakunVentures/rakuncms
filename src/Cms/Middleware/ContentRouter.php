<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Content\Entry;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Template\Engine;
use Symfony\Component\Yaml\Yaml;

final class ContentRouter implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = trim($request->getUri()->getPath(), '/');
        $locale = $request->getAttribute('locale', 'es');

        // Load index
        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $index = $indexer->load();
        $query = new Query($index);

        // Parse path: /{locale}/{collection?}/{slug}
        $segments = $path ? explode('/', $path) : [];

        // Remove locale prefix if it matches
        if (!empty($segments) && strlen($segments[0]) === 2) {
            $locale = array_shift($segments);
        }

        $entry = null;

        if (empty($segments) || (count($segments) === 1 && $segments[0] === '')) {
            // Homepage: try empty slug first (frontmatter slugs.es: ""), then named slugs
            $entry = $query->findBySlug('pages', $locale, '')
                ?? $query->findBySlug('pages', $locale, 'home')
                ?? $query->findBySlug('pages', $locale, 'inicio');
        } elseif (count($segments) === 1) {
            // Single segment: page
            $entry = $query->findBySlug('pages', $locale, $segments[0]);
        } elseif (count($segments) === 2) {
            // Two segments: collection/slug
            $collectionName = $segments[0];
            $slug = $segments[1];

            // Try direct collection match
            $entry = $query->findBySlug($collectionName, $locale, $slug);

            // Try mapped collection names (rooms -> habitaciones)
            if ($entry === null) {
                $collectionMap = [
                    'rooms' => 'habitaciones',
                    'habitaciones' => 'habitaciones',
                ];
                $mappedCollection = $collectionMap[$collectionName] ?? $collectionName;
                if ($mappedCollection !== $collectionName) {
                    $entry = $query->findBySlug($mappedCollection, $locale, $slug);
                }
            }
        }

        if ($entry === null) {
            return $handler->handle($request);
        }

        // Store entry and query in container for templates
        $container = \app();
        $container->set('current_entry', $entry);
        $container->set('content.query', fn () => new Query($index));
        $container->set('locale', $locale);

        // Resolve template
        $templateName = $this->resolveTemplate($entry, $basePath);

        // Load globals
        $globals = $this->loadGlobals($basePath, $locale);

        // Render
        $engine = Engine::create($basePath);
        $html = $engine->render($templateName, [
            'entry' => $entry,
            'page' => $entry,
            'locale' => $locale,
            'site' => $globals['site'] ?? [],
            'nav' => $globals['nav'] ?? [],
            'globals' => $globals,
        ]);

        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
    }

    private function resolveTemplate(Entry $entry, string $basePath): string
    {
        $templateDir = $basePath . '/templates';
        $collection = $entry->collection();
        $slug = $entry->slug();

        // 1. Frontmatter template field
        if ($entry->template() !== null) {
            return $entry->template() . '.twig';
        }

        // 2. templates/{collection}/{slug}.twig
        if (file_exists($templateDir . '/' . $collection . '/' . $slug . '.twig')) {
            return $collection . '/' . $slug . '.twig';
        }

        // 3. templates/{collection}/show.twig
        if (file_exists($templateDir . '/' . $collection . '/show.twig')) {
            return $collection . '/show.twig';
        }

        // 4. templates/_layouts/{collection}.twig
        if (file_exists($templateDir . '/_layouts/' . $collection . '.twig')) {
            return '_layouts/' . $collection . '.twig';
        }

        // 5. templates/_layouts/page.twig
        return '_layouts/page.twig';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadGlobals(string $basePath, string $locale): array
    {
        $globalsPath = $basePath . '/content/_globals';
        $globals = [];

        if (!is_dir($globalsPath)) {
            return $globals;
        }

        $files = glob($globalsPath . '/*.yaml') ?: [];
        foreach ($files as $file) {
            $name = basename($file, '.yaml');
            $data = Yaml::parseFile($file);
            $globals[$name] = is_array($data) ? $data : [];
        }

        return $globals;
    }
}
