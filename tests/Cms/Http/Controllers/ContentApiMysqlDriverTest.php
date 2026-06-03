<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Stream;
use Nyholm\Psr7\Uri;
use Rkn\Cms\Http\Controllers\ContentApiController;
use Rkn\Framework\Application;

/**
 * Fase 1 end-to-end: con content.driver=mysql, el API de contenido escribe a
 * MySQL (SSoT) y regenera el caché .md — todo por la ruta real del controlador.
 */

afterEach(function () {
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

test('create through the API persists to MySQL and regenerates the .md cache', function () {
    try {
        $pdo = new PDO(
            'mysql:host=127.0.0.1;port=3306;dbname=rakuncms_test',
            'root',
            '',
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 3],
        );
    } catch (\Throwable $e) {
        $this->markTestSkipped('MySQL rakuncms_test not available');
    }
    foreach (['content_tags', 'content_revisions', 'contents'] as $t) {
        try {
            $pdo->exec("DELETE FROM {$t}");
        } catch (\Throwable) {
            // table not created yet — the first write will create it
        }
    }

    $dir = $this->makeTempDir();
    mkdir($dir . '/config', 0755, true);
    mkdir($dir . '/content/blog', 0755, true);
    file_put_contents($dir . '/.env', '');
    file_put_contents($dir . '/config/rakun.yaml', <<<'YAML'
    site:
      default_locale: es
    content:
      driver: mysql
      mysql:
        host: 127.0.0.1
        port: 3306
        database: rakuncms_test
        username: root
        password: ""
    YAML);
    new Application($dir);

    $body = (string) json_encode([
        'title'   => 'Hola MySQL',
        'slug'    => 'hola-mysql',
        'locale'  => 'es',
        'content' => 'Cuerpo del post en la BD.',
        'meta'    => ['tags' => ['boda']],
    ]);
    $request  = (new ServerRequest('POST', new Uri('/api/v1/entries/blog')))->withBody(Stream::create($body));
    $response = (new ContentApiController($dir))->create($request, 'blog');

    expect($response->getStatusCode())->toBe(201);

    // SSoT row landed in MySQL.
    $row = $pdo->query("SELECT * FROM contents WHERE slug = 'hola-mysql'")->fetch(PDO::FETCH_ASSOC);
    expect($row)->not->toBeFalse();
    expect($row['title'])->toBe('Hola MySQL');
    expect($row['locale'])->toBe('es');

    // Tag persisted.
    $tags = $pdo->query('SELECT tag FROM content_tags')->fetchAll(PDO::FETCH_COLUMN);
    expect($tags)->toContain('boda');

    // .md cache regenerated from the SSoT (render path stays flat-file).
    expect(file_exists($dir . '/content/blog/hola-mysql.es.md'))->toBeTrue();
});
