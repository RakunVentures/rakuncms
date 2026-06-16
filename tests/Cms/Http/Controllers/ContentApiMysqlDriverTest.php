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

/**
 * Helpers privados a este archivo. La firma de cada uno es tan pequeña que
 * extraerlos como helpers globales sería over-engineering — viven aquí porque
 * sólo este suite los necesita.
 */
function mysqlPdoOrSkip(object $ctx): \PDO
{
    try {
        return new \PDO(
            'mysql:host=127.0.0.1;port=3306;dbname=rakuncms_test',
            'root',
            '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3],
        );
    } catch (\Throwable $e) {
        $ctx->markTestSkipped('MySQL rakuncms_test not available: ' . $e->getMessage());
    }
}

function bootMysqlSqliteApp(string $dir): void
{
    mkdir($dir . '/config', 0755, true);
    mkdir($dir . '/content/blog', 0755, true);
    mkdir($dir . '/cache', 0755, true);
    file_put_contents($dir . '/.env', '');
    file_put_contents($dir . '/config/rakun.yaml', <<<'YAML'
    site:
      default_locale: es
    index:
      driver: sqlite
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
}

test('create through the API persists to MySQL and regenerates the .md cache', function () {
    $pdo = mysqlPdoOrSkip($this);
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

/**
 * α-fix integración (config de producción de fiancee: content=mysql + index=sqlite).
 *
 * Simula la topología de WP-import: una entrada importada vive con slug compuesto
 * en MySQL (slug='YYYY/MM/basename') y como archivo en content/blog/YYYY/MM/foo.es.md.
 * El panel SIEMPRE envía el slug limpio ('foo'). Antes del fix, update() y delete()
 * fallaban con 404 silencioso. Estos tests aseguran que el controlador resuelve la
 * clave de storage compuesta vía el índice sqlite y muta la fila correcta en MySQL.
 */
test('update with clean slug mutates the imported MySQL row IN PLACE (no duplicate)', function () {
    $pdo = mysqlPdoOrSkip($this);
    foreach (['content_tags', 'content_revisions', 'contents'] as $t) {
        try { $pdo->exec("DELETE FROM {$t}"); } catch (\Throwable) {}
    }

    $dir = $this->makeTempDir();
    bootMysqlSqliteApp($dir);

    // 1) Seed: simula la salida del importer (FS→MySQL) — slug compuesto, archivo en subdirectorio.
    mkdir($dir . '/content/blog/2011/01', 0755, true);
    file_put_contents($dir . '/content/blog/2011/01/foo.es.md', <<<'MD'
    ---
    title: "Foo importado"
    status: published
    meta:
      description: "Importado de WP"
    ---
    Cuerpo importado.
    MD);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        "INSERT INTO contents (collection, locale, slug, section, full_slug, title, status, published_at, body_markdown, body_html, meta_json, tags_json, created_at, updated_at)
         VALUES ('blog', 'es', '2011/01/foo', '2011/01', '2011/01/foo', 'Foo importado', 'published', ?, 'Cuerpo importado.', '<p>Cuerpo importado.</p>', '{}', '[]', ?, ?)"
    )->execute([$now, $now, $now]);

    $originalId = (int) $pdo->query("SELECT id FROM contents WHERE full_slug = '2011/01/foo'")->fetchColumn();
    expect($originalId)->toBeGreaterThan(0);

    // 2) Rebuild sqlite index — el resolver depende de findBySlug.
    (new Rkn\Cms\Content\Indexer($dir))->rebuild();

    // 3) PUT con slug limpio — el panel siempre envía 'foo', no '2011/01/foo'.
    $body = (string) json_encode([
        'title'  => 'Foo editado',
        'locale' => 'es',
        'meta'   => ['description' => 'Edited via panel'],
    ]);
    $request  = (new ServerRequest('PUT', new Uri('/api/v1/entries/blog/foo')))->withBody(Stream::create($body));
    $response = (new ContentApiController($dir))->update($request, 'blog', 'foo');

    expect($response->getStatusCode())->toBe(200);

    // 4) La fila se mutó IN PLACE — mismo id, no duplicado.
    $rows = $pdo->query("SELECT id, full_slug, title FROM contents WHERE collection = 'blog' AND locale = 'es'")->fetchAll(PDO::FETCH_ASSOC);
    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]['id'])->toBe($originalId);
    expect($rows[0]['full_slug'])->toBe('2011/01/foo');
    expect($rows[0]['title'])->toBe('Foo editado');

    // 5) Cache .md regenerado en el subdirectorio correcto.
    $written = file_get_contents($dir . '/content/blog/2011/01/foo.es.md');
    expect($written)->toContain('Foo editado')
        ->and($written)->toContain('Edited via panel');
});

test('delete with clean slug removes the imported MySQL row and the .md cache', function () {
    $pdo = mysqlPdoOrSkip($this);
    foreach (['content_tags', 'content_revisions', 'contents'] as $t) {
        try { $pdo->exec("DELETE FROM {$t}"); } catch (\Throwable) {}
    }

    $dir = $this->makeTempDir();
    bootMysqlSqliteApp($dir);

    mkdir($dir . '/content/blog/2011/01', 0755, true);
    $cachePath = $dir . '/content/blog/2011/01/foo.es.md';
    file_put_contents($cachePath, <<<'MD'
    ---
    title: "Foo importado"
    status: published
    ---
    Cuerpo.
    MD);
    $now = date('Y-m-d H:i:s');
    $pdo->prepare(
        "INSERT INTO contents (collection, locale, slug, section, full_slug, title, status, published_at, body_markdown, body_html, meta_json, tags_json, created_at, updated_at)
         VALUES ('blog', 'es', '2011/01/foo', '2011/01', '2011/01/foo', 'Foo importado', 'published', ?, 'Cuerpo.', '<p>Cuerpo.</p>', '{}', '[]', ?, ?)"
    )->execute([$now, $now, $now]);

    (new Rkn\Cms\Content\Indexer($dir))->rebuild();

    $request  = new ServerRequest('DELETE', new Uri('/api/v1/entries/blog/foo?locale=es'));
    $response = (new ContentApiController($dir))->delete($request, 'blog', 'foo');

    expect($response->getStatusCode())->toBe(200);

    $remaining = (int) $pdo->query("SELECT COUNT(*) FROM contents WHERE collection = 'blog' AND locale = 'es'")->fetchColumn();
    expect($remaining)->toBe(0);
    expect(file_exists($cachePath))->toBeFalse();
});
