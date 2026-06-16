<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Http\Controllers\ContentApiController;
use Rkn\Framework\Application;

/**
 * α-fix: el panel siempre envía el slug "limpio" (basename), pero las entradas
 * importadas (WordPress) viven en content/{collection}/YYYY/MM/{slug}.md y su
 * clave canónica de storage es compuesta ('YYYY/MM/slug'). Antes del fix,
 * update() y delete() fallaban con 404 silencioso para todo lo importado.
 *
 * Estos tests garantizan que el controlador resuelve la clave de storage vía
 * el índice (`findBySlug` matchea tanto full_slug como locale_slug) y entonces
 * editar/eliminar funciona con el slug limpio del panel. Engine-agnostic: usa
 * FileContentStorage con layout en subdirectorio — la misma topología que
 * dispara el bug en MySQL.
 */

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun-compound-test-' . uniqid();
    mkdir($this->tempDir . '/content/blog/2011/01', 0755, true);
    mkdir($this->tempDir . '/cache', 0755, true);
    mkdir($this->tempDir . '/config', 0755, true);

    file_put_contents($this->tempDir . '/.env', '');
    file_put_contents($this->tempDir . '/config/rakun.yaml', <<<'YAML'
    site:
      default_locale: es
    YAML);

    // Entrada con topología de import: content/blog/2011/01/foo.es.md
    file_put_contents($this->tempDir . '/content/blog/2011/01/foo.es.md', <<<'MD'
    ---
    title: "Foo original"
    status: published
    meta:
      description: "Importado de WordPress"
    ---
    Cuerpo original.
    MD);

    new Application($this->tempDir);

    (new Indexer($this->tempDir))->rebuild();

    $this->controller = new ContentApiController($this->tempDir);
});

afterEach(function () {
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);

    $cleanup = function (string $dir) use (&$cleanup): void {
        if (!is_dir($dir)) return;
        $items = new DirectoryIterator($dir);
        foreach ($items as $item) {
            if ($item->isDot()) continue;
            if ($item->isDir()) {
                $cleanup($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($dir);
    };
    if (is_dir($this->tempDir)) {
        $cleanup($this->tempDir);
    }
});

test('update with clean slug resolves the compound storage key via the index (panel→imported article)', function () {
    $body = (string) json_encode([
        'title'  => 'Foo editado',
        'locale' => 'es',
        'meta'   => ['description' => 'Edited via panel'],
    ]);
    $request = (new ServerRequest('PUT', new Uri('/api/v1/entries/blog/foo')))
        ->withBody(Stream::create($body));

    // El panel pasa slug='foo' (limpio); el archivo real vive en 2011/01/foo.es.md.
    $response = $this->controller->update($request, 'blog', 'foo');

    expect($response->getStatusCode())->toBe(200);

    $written = file_get_contents($this->tempDir . '/content/blog/2011/01/foo.es.md');
    expect($written)->toContain('Foo editado')
        ->and($written)->toContain('Edited via panel')
        ->and($written)->toContain('Cuerpo original.'); // body preservado
});

test('delete with clean slug resolves the compound storage key via the index (panel→imported article)', function () {
    $path = $this->tempDir . '/content/blog/2011/01/foo.es.md';
    expect(file_exists($path))->toBeTrue();

    $request = new ServerRequest('DELETE', new Uri('/api/v1/entries/blog/foo?locale=es'));
    $response = $this->controller->delete($request, 'blog', 'foo');

    expect($response->getStatusCode())->toBe(200);
    expect(file_exists($path))->toBeFalse();
});

test('update with clean slug for a non-existent entry still returns 404', function () {
    $body = (string) json_encode(['title' => 'X', 'locale' => 'es']);
    $request = (new ServerRequest('PUT', new Uri('/api/v1/entries/blog/nonexistente')))
        ->withBody(Stream::create($body));

    $response = $this->controller->update($request, 'blog', 'nonexistente');

    expect($response->getStatusCode())->toBe(404);
});

test('delete with clean slug for a non-existent entry still returns 404', function () {
    $request = new ServerRequest('DELETE', new Uri('/api/v1/entries/blog/nonexistente?locale=es'));
    $response = $this->controller->delete($request, 'blog', 'nonexistente');

    expect($response->getStatusCode())->toBe(404);
});
