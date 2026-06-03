<?php

declare(strict_types=1);

use Rkn\Cms\Http\Controllers\ContentApiController;
use Rkn\Framework\Application;

/**
 * Fase 0: el config dump exponía api.keys (secretos vivos) — fuga de seguridad.
 * showConfig() ahora redacta; schema() expone solo estructura para forms dinámicos.
 * Assertions agnósticas al layout de config (rakun.yaml monolítico vs split).
 */

afterEach(function () {
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

function bootApiConfigFixture(string $dir): void
{
    mkdir($dir . '/config', 0755, true);
    mkdir($dir . '/content/blog', 0755, true);
    file_put_contents($dir . '/.env', '');
    file_put_contents($dir . '/config/rakun.yaml', <<<'YAML'
    site:
      default_locale: es
    api:
      enabled: true
      keys:
        - name: "Admin"
          key: "super-secret-key-123"
          permissions: ["admin"]
    mail:
      smtp_password: "mail-secret-pw"
    collections:
      blog:
        name: "Artículos"
        chronological: true
        default_template: "blog-post"
        active: true
        fields:
          - { key: excerpt, type: textarea }
          - { key: featured, type: boolean }
      pages:
        name: "Páginas"
        chronological: false
        active: true
    YAML);

    new Application($dir);
}

test('showConfig does not leak api keys or secret fields', function () {
    $dir = $this->makeTempDir();
    bootApiConfigFixture($dir);

    $response = (new ContentApiController($dir))->showConfig();
    $raw = (string) $response->getBody();

    expect($response->getStatusCode())->toBe(200);
    // The live API key value must NOT appear anywhere in the payload.
    expect($raw)->not->toContain('super-secret-key-123');
    // Password-like fields are redacted.
    expect($raw)->not->toContain('mail-secret-pw');
    // Non-secret structure survives (so the endpoint is still useful).
    expect($raw)->toContain('Artículos');
});

test('schema returns collections with their field definitions', function () {
    $dir = $this->makeTempDir();
    bootApiConfigFixture($dir);

    $response = (new ContentApiController($dir))->schema();
    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);

    $slugs = array_column($data['data'], 'slug');
    expect($slugs)->toContain('blog');
    expect($slugs)->toContain('pages');

    $blog = null;
    foreach ($data['data'] as $c) {
        if ($c['slug'] === 'blog') {
            $blog = $c;
            break;
        }
    }
    expect($blog)->not->toBeNull();
    expect($blog['name'])->toBe('Artículos');
    expect($blog['chronological'])->toBeTrue();
    expect($blog['fields'])->toHaveCount(2);
    expect($blog['fields'][0]['key'])->toBe('excerpt');
});
