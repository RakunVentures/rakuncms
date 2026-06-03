<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\UploadedFile;
use Nyholm\Psr7\Uri;
use Rkn\Cms\Http\Controllers\MediaApiController;

/**
 * Fase 0: el upload de media sanea el nombre (anti path-traversal), deriva la
 * extensión del MIME verificado, garantiza unicidad (no sobrescribe) y reporta
 * metadatos (dimensiones).
 */

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun-media-test-' . uniqid();
    mkdir($this->tempDir . '/public/assets', 0755, true);
    $this->controller = new MediaApiController($this->tempDir);

    // 1x1 PNG real (finfo lo detecta image/png; getimagesize -> 1x1).
    $this->png = (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M8AAAMBAQDJ/pLvAAAAAElFTkSuQmCC'
    );
});

afterEach(function () {
    $cleanup = function (string $dir) use (&$cleanup): void {
        foreach (new DirectoryIterator($dir) as $item) {
            if ($item->isDot()) continue;
            $item->isDir() ? $cleanup($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    };
    if (is_dir($this->tempDir)) {
        $cleanup($this->tempDir);
    }
});

function uploadPng(MediaApiController $controller, string $bytes, string $clientName, ?string $directory = null): array
{
    $upload  = new UploadedFile(Stream::create($bytes), strlen($bytes), UPLOAD_ERR_OK, $clientName, 'image/png');
    $request = (new ServerRequest('POST', new Uri('/api/v1/media')))->withUploadedFiles(['file' => $upload]);
    if ($directory !== null) {
        $request = $request->withParsedBody(['directory' => $directory]);
    }

    $response = $controller->upload($request);

    return [$response->getStatusCode(), json_decode((string) $response->getBody(), true)];
}

test('upload sanitizes filename and rejects directory traversal', function () {
    [$status, $payload] = uploadPng($this->controller, $this->png, 'My Photo!! v2.png', '../../evil/../pics');

    expect($status)->toBe(201);
    // No traversal survives, lowercased + slugged stem, .png from verified MIME.
    expect($payload['data']['path'])->not->toContain('..');
    expect($payload['data']['path'])->toMatch('#^assets/[a-z0-9_/-]+/[a-z0-9-]+\.png$#');
    expect($payload['data']['mime'])->toBe('image/png');
    expect($payload['data']['width'])->toBe(1);
    expect($payload['data']['height'])->toBe(1);

    $full = $this->tempDir . '/public/' . $payload['data']['path'];
    expect(file_exists($full))->toBeTrue();
});

test('upload never overwrites: same name yields a unique file', function () {
    [$s1, $p1] = uploadPng($this->controller, $this->png, 'logo.png');
    [$s2, $p2] = uploadPng($this->controller, $this->png, 'logo.png');

    expect($s1)->toBe(201);
    expect($s2)->toBe(201);
    expect($p2['data']['path'])->not->toBe($p1['data']['path']);
    // Both files exist on disk.
    expect(file_exists($this->tempDir . '/public/' . $p1['data']['path']))->toBeTrue();
    expect(file_exists($this->tempDir . '/public/' . $p2['data']['path']))->toBeTrue();
});

test('upload rejects a disallowed MIME type', function () {
    $text    = "just some text, not an image";
    $upload  = new UploadedFile(Stream::create($text), strlen($text), UPLOAD_ERR_OK, 'note.txt', 'text/plain');
    $request = (new ServerRequest('POST', new Uri('/api/v1/media')))->withUploadedFiles(['file' => $upload]);

    $response = $this->controller->upload($request);

    expect($response->getStatusCode())->toBe(415);
});

test('list reports type by extension (not magic bytes) and counts files', function () {
    $assets = $this->tempDir . '/public/assets';
    mkdir($assets . '/images', 0755, true);

    // Bytes que NO son imagen/PDF reales: si el tipo se dedujera por magic bytes
    // (mime_content_type) saldría text/plain; al ser por extensión sale image/jpeg.
    file_put_contents($assets . '/images/photo.jpg', 'not really a jpeg');
    file_put_contents($assets . '/doc.pdf', '%PDF fake');
    file_put_contents($assets . '/data.xyz', 'unknown ext');

    $response = $this->controller->list();
    expect($response->getStatusCode())->toBe(200);

    $payload = json_decode((string) $response->getBody(), true);
    $byPath  = [];
    foreach ($payload['data'] as $row) {
        $byPath[$row['path']] = $row['type'];
    }

    expect($payload['meta']['count'])->toBe(3);
    expect($byPath['assets/images/photo.jpg'])->toBe('image/jpeg');
    expect($byPath['assets/doc.pdf'])->toBe('application/pdf');
    expect($byPath['assets/data.xyz'])->toBe('application/octet-stream');
});
