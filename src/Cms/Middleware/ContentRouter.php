<?php

declare(strict_types=1);

namespace Rkn\Cms\Middleware;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Content\DraftResolver;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Content\TaxonomyRouter;
use Rkn\Cms\Template\Engine;
use Rkn\Cms\Template\TemplateResolver;
use Symfony\Component\Yaml\Yaml;

final class ContentRouter implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = trim($request->getUri()->getPath(), '/');
        $locale = $request->getAttribute('locale', 'es');

        // Active content index (php array or sqlite), memoised per request.
        $basePath = \app('base_path');
        $store = \app('index_store');
        $query = new Query($store);

        // Parse path: /{locale}/{collection?}/{slug}
        $segments = $path ? explode('/', $path) : [];

        // Remove locale prefix if it matches
        if (!empty($segments) && strlen($segments[0]) === 2) {
            $locale = array_shift($segments);
        }

        $entry = null;
        $currentPageNumber = 1;

        // Detect pagination: /{collection}/page/{n}
        if (count($segments) === 3 && $segments[1] === 'page' && ctype_digit($segments[2])) {
            $currentPageNumber = (int) $segments[2];
            // Remove page segments, keep collection for template resolution
            $segments = [$segments[0]];
        }

        // ── Vista previa: con un token válido se renderiza la versión de la
        // FUENTE DE VERDAD (MySQL/.md) SIN filtrar por status, tomando
        // precedencia sobre la búsqueda pública. El bypass de page cache vive en
        // PageCacheReader/Writer (no se sirve ni se cachea con ?preview). ──────
        $isPreview = false;
        $previewToken = (string) ($request->getQueryParams()['preview'] ?? '');
        if ($previewToken !== '') {
            $resolver = new DraftResolver($basePath);
            $verified = $resolver->verifyToken($previewToken);
            if ($verified !== null) {
                if (!empty($verified['global'])) {
                    [$pc, $pl, $ps] = $this->previewIdentity($segments, $locale);
                } else {
                    $pc = (string) ($verified['collection'] ?? '');
                    $pl = ((string) ($verified['locale'] ?? '')) ?: $locale;
                    $ps = (string) ($verified['slug'] ?? '');
                }
                if ($pc !== '' && $ps !== '') {
                    $entry = $resolver->resolveEntry($pc, $pl, $ps);
                    if ($entry !== null) {
                        $locale = $pl;
                        $isPreview = true;
                    }
                }
            }
        }

        if (!$isPreview) {
        if (empty($segments) || (count($segments) === 1 && $segments[0] === '')) {
            // Homepage: try empty slug first (frontmatter slugs.es: ""), then named slugs
            $entry = $query->findBySlug('pages', $locale, '')
                ?? $query->findBySlug('pages', $locale, 'index')
                ?? $query->findBySlug('pages', $locale, 'home')
                ?? $query->findBySlug('pages', $locale, 'inicio');
        } elseif (count($segments) === 1) {
            // Single segment: page
            $entry = $query->findBySlug('pages', $locale, $segments[0]);
        } else {
            // Multi-segment: collection/{section.../}slug
            $collectionName = $segments[0];
            $slug = implode('/', array_slice($segments, 1));

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
        } // fin: búsqueda pública (omitida en modo preview)

        // Taxonomy routes: /{collection}/tag/{tag}, /{collection}/archive/{year}/{month}
        if ($entry === null && count($segments) >= 3) {
            $taxonomyRouter = new TaxonomyRouter();
            $taxonomy = $taxonomyRouter->resolve($segments, $locale, $query);

            if ($taxonomy !== null) {
                $container = \app();
                $container->set('content.query', fn () => new Query($store));
                $container->set('locale', $locale);
                $container->set('current_page_number', $currentPageNumber);

                $globals = $this->loadGlobals($basePath, $locale);
                $engine = Engine::create($basePath);
                $templateDir = $basePath . '/templates';

                // Resolve taxonomy template
                $templateName = 'taxonomy/' . $taxonomy['type'] . '.twig';
                if (!file_exists($templateDir . '/' . $templateName)) {
                    $templateName = '_layouts/taxonomy.twig';
                    if (!file_exists($templateDir . '/' . $templateName)) {
                        $templateName = '_layouts/page.twig';
                    }
                }

                $html = $engine->render($templateName, [
                    'taxonomy_type' => $taxonomy['type'],
                    'taxonomy_value' => $taxonomy['value'],
                    'taxonomy_collection' => $taxonomy['collection'],
                    'taxonomy_entries' => $taxonomy['query'],
                    'locale' => $locale,
                    'site' => $globals['site'] ?? [],
                    'nav' => $globals['nav'] ?? [],
                    'globals' => $globals,
                ]);

                return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $html);
            }
        }

        if ($entry === null) {
            return $handler->handle($request);
        }

        // Store entry and query in container for templates
        $container = \app();
        $container->set('current_entry', $entry);
        $container->set('content.query', fn () => new Query($store));
        $container->set('locale', $locale);
        $container->set('current_page_number', $currentPageNumber);

        // Resolve template (honours collections.{name}.default_template from rakun.yaml).
        $templateName = (new TemplateResolver($basePath . '/templates', self::collectionDefaultTemplates()))->resolve($entry);

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

        // Modo preview: banner + no indexar + no cachear.
        $headers = ['Content-Type' => 'text/html; charset=UTF-8'];
        if ($isPreview) {
            $status = (string) ($entry->meta()['status'] ?? '');
            $html = (new DraftResolver($basePath))->injectDraftBanner($html, $status);
            $headers['Cache-Control'] = 'no-store, max-age=0';
            $headers['X-Robots-Tag'] = 'noindex, nofollow';
        }

        return new Response(200, $headers, $html);
    }

    /**
     * Mapa colección → default_template, leído de la config. Soporta los DOS layouts
     * (igual que Application::boot con site.timezone y schema()): plano (`collections`)
     * y namespaced por archivo (`rakun.collections`). Sin el fallback, en sitios con
     * config split (p.ej. fiancee) el mapa salía vacío → default_template se ignoraba →
     * entries sin `template:` en frontmatter caían al fallback `page.twig` (las revistas
     * nuevas del panel no abrían el lector revista-reader).
     *
     * @return array<string, string>
     */
    public static function collectionDefaultTemplates(): array
    {
        $collections = (array) (\config('collections') ?? []);
        if ($collections === []) {
            $collections = (array) (\config('rakun.collections') ?? []);
        }

        $defaults = [];
        foreach ($collections as $name => $cfg) {
            if (is_array($cfg) && isset($cfg['default_template']) && is_string($cfg['default_template'])) {
                $defaults[(string) $name] = $cfg['default_template'];
            }
        }

        return $defaults;
    }

    /**
     * Identidad de la entrada a partir de los segmentos de la URL (para el token
     * global legacy, que no lleva scope). 1 segmento => página; 2+ => colección/slug.
     *
     * @param  list<string>  $segments
     * @return array{0: string, 1: string, 2: string}  [collection, locale, slug]
     */
    private function previewIdentity(array $segments, string $locale): array
    {
        if (count($segments) <= 1) {
            return ['pages', $locale, $segments[0] ?? ''];
        }

        return [$segments[0], $locale, implode('/', array_slice($segments, 1))];
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
            try {
                $data = Yaml::parseFile($file);
            } catch (\Throwable $e) {
                error_log('[rakun] unparseable global ' . $file . '; using empty: ' . $e->getMessage());
                $data = [];
            }
            $globals[$name] = is_array($data) ? $data : [];
        }

        return $globals;
    }
}
