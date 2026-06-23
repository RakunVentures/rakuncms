<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Rkn\Cms\Http\Controllers\ContentApiController;
use Rkn\Framework\Application;

/**
 * Al borrar una entrada de una colección con `cleanup_media: true`, los archivos de
 * media que referencia (campos image/pdf/file/media) se borran de assets/ — para no
 * dejar residuos (p.ej. PDF + portada de una revista). Opt-in: colecciones sin la
 * bandera conservan sus archivos.
 */

afterEach(function () {
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);

    $rm = function (string $d) use (&$rm): void {
        if (!is_dir($d)) return;
        foreach (new DirectoryIterator($d) as $i) {
            if ($i->isDot()) continue;
            $i->isDir() ? $rm($i->getPathname()) : @unlink($i->getPathname());
        }
        @rmdir($d);
    };
    if (isset($this->dir)) $rm($this->dir);
});

function bootCleanupFixture(string $dir): void
{
    mkdir($dir . '/content/revista', 0755, true);
    mkdir($dir . '/content/noticias', 0755, true);
    mkdir($dir . '/public/assets/pdfs/revista', 0755, true);
    mkdir($dir . '/public/assets/uploads', 0755, true);
    mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/.env', '');
    file_put_contents($dir . '/config/rakun.yaml', <<<'YAML'
    site:
      default_locale: en
    collections:
      revista:
        name: "Revistas"
        active: true
        cleanup_media: true
        fields:
          - { key: cover, type: image, label: "Portada" }
          - { key: pdf_url, type: pdf, label: "PDF" }
      noticias:
        name: "Noticias"
        active: true
        fields:
          - { key: img, type: image, label: "Imagen" }
    YAML);

    new Application($dir);
}

test('delete borra los archivos de media referenciados (cleanup_media: true)', function () {
    $this->dir = sys_get_temp_dir() . '/rakun-cleanup-' . uniqid();
    bootCleanupFixture($this->dir);

    file_put_contents($this->dir . '/public/assets/pdfs/revista/edicion.pdf', '%PDF-1.4 x');
    file_put_contents($this->dir . '/public/assets/uploads/edicion-portada.webp', 'WEBPDATA');
    file_put_contents($this->dir . '/content/revista/2025-edicion.en.md', <<<'MD'
    ---
    title: "Revista X"
    status: publish
    pdf_url: /assets/pdfs/revista/edicion.pdf
    cover: /assets/uploads/edicion-portada.webp
    ---

    cuerpo
    MD);

    $controller = new ContentApiController($this->dir);
    $response = $controller->delete(new ServerRequest('DELETE', new Uri('/api/v1/entries/revista/2025-edicion')), 'revista', '2025-edicion');

    expect($response->getStatusCode())->toBe(200);
    expect(file_exists($this->dir . '/content/revista/2025-edicion.en.md'))->toBeFalse();
    // Los archivos referenciados se borraron (sin residuos).
    expect(file_exists($this->dir . '/public/assets/pdfs/revista/edicion.pdf'))->toBeFalse();
    expect(file_exists($this->dir . '/public/assets/uploads/edicion-portada.webp'))->toBeFalse();
});

test('delete conserva los archivos cuando la colección NO declara cleanup_media', function () {
    $this->dir = sys_get_temp_dir() . '/rakun-cleanup-' . uniqid();
    bootCleanupFixture($this->dir);

    file_put_contents($this->dir . '/public/assets/uploads/foto.webp', 'WEBPDATA');
    file_put_contents($this->dir . '/content/noticias/nota.en.md', <<<'MD'
    ---
    title: "Nota"
    status: publish
    img: /assets/uploads/foto.webp
    ---

    cuerpo
    MD);

    $controller = new ContentApiController($this->dir);
    $controller->delete(new ServerRequest('DELETE', new Uri('/api/v1/entries/noticias/nota')), 'noticias', 'nota');

    expect(file_exists($this->dir . '/content/noticias/nota.en.md'))->toBeFalse();
    // El archivo se conserva (no opt-in).
    expect(file_exists($this->dir . '/public/assets/uploads/foto.webp'))->toBeTrue();
});

test('cleanup ignora URLs externas y no truena', function () {
    $this->dir = sys_get_temp_dir() . '/rakun-cleanup-' . uniqid();
    bootCleanupFixture($this->dir);

    file_put_contents($this->dir . '/content/revista/2026-ext.en.md', <<<'MD'
    ---
    title: "Externa"
    status: publish
    cover: https://cdn.externo.com/img.jpg
    pdf_url: /assets/pdfs/revista/inexistente.pdf
    ---

    cuerpo
    MD);

    $controller = new ContentApiController($this->dir);
    $response = $controller->delete(new ServerRequest('DELETE', new Uri('/api/v1/entries/revista/2026-ext')), 'revista', '2026-ext');

    expect($response->getStatusCode())->toBe(200);
    expect(file_exists($this->dir . '/content/revista/2026-ext.en.md'))->toBeFalse();
});
