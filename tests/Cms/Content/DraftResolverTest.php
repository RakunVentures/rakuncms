<?php

declare(strict_types=1);

use Rkn\Cms\Content\DraftResolver;
use Rkn\Framework\Application;

/**
 * Vista previa: token firmado (por-entrada, expirable) + resolución de la entrada
 * desde la FUENTE DE VERDAD (ContentStorage), sin filtrar por status.
 */

beforeEach(function () {
    $this->dir = sys_get_temp_dir() . '/rkn-preview-' . uniqid();
    mkdir($this->dir . '/content/blog', 0755, true);
    mkdir($this->dir . '/config', 0755, true);
    file_put_contents(
        $this->dir . '/config/rakun.yaml',
        "site:\n  default_locale: en\npreview:\n  secret: \"s3cr3t-test\"\n  ttl: 3600\n"
    );

    file_put_contents($this->dir . '/content/blog/draft-post.en.md', "---\ntitle: \"My Draft Post\"\nstatus: \"draft\"\n---\nCuerpo draft.\n");
    file_put_contents($this->dir . '/content/blog/future-post.en.md', "---\ntitle: \"Future Post\"\nstatus: \"future\"\ndate: \"2099-01-01\"\n---\nCuerpo programado.\n");
    file_put_contents($this->dir . '/content/blog/published-post.en.md', "---\ntitle: \"Published Post\"\nstatus: \"publish\"\n---\nCuerpo publicado.\n");

    new Application($this->dir); // hace disponible config (preview.secret, content.driver)
});

afterEach(function () {
    Application::reset(); // no filtrar el singleton (config/locale) al siguiente test
    $rm = function (string $d) use (&$rm): void {
        if (!is_dir($d)) return;
        foreach (new DirectoryIterator($d) as $i) {
            if ($i->isDot()) continue;
            $i->isDir() ? $rm($i->getPathname()) : unlink($i->getPathname());
        }
        rmdir($d);
    };
    $rm($this->dir);
});

// ── Token firmado ────────────────────────────────────────────────────────────

test('signToken + verifyToken devuelve la identidad de la entrada', function () {
    $r = new DraftResolver($this->dir);
    $token = $r->signToken('blog', 'en', 'draft-post');

    expect($r->verifyToken($token))->toBe(['collection' => 'blog', 'locale' => 'en', 'slug' => 'draft-post']);
});

test('verifyToken rechaza un token expirado', function () {
    $r = new DraftResolver($this->dir);
    // Firmado "en el pasado": exp = 100 + ttl(3600) ≈ 1970 → ya expiró.
    $token = $r->signToken('blog', 'en', 'draft-post', 100);

    expect($r->verifyToken($token, time()))->toBeNull();
});

test('verifyToken rechaza una firma manipulada', function () {
    $r = new DraftResolver($this->dir);
    $token = $r->signToken('blog', 'en', 'draft-post');

    expect($r->verifyToken($token . 'x'))->toBeNull();
});

test('verifyToken rechaza vacío y basura', function () {
    $r = new DraftResolver($this->dir);
    expect($r->verifyToken(''))->toBeNull();
    expect($r->verifyToken('no-es-un-token'))->toBeNull();
});

test('un token firmado para otra entrada no sirve (scope)', function () {
    $r = new DraftResolver($this->dir);
    $token = $r->signToken('blog', 'en', 'draft-post');
    $v = $r->verifyToken($token);
    // El scope viene del payload: el caller debe usar SOLO (c,l,s) del token.
    expect($v['slug'])->toBe('draft-post');
    expect($v['slug'])->not->toBe('future-post');
});

// ── Resolución desde la fuente de verdad (cualquier status) ──────────────────

test('resolveEntry lee cualquier status del store (draft, future, publish)', function () {
    $r = new DraftResolver($this->dir);
    expect($r->resolveEntry('blog', 'en', 'draft-post')?->title())->toBe('My Draft Post');
    expect($r->resolveEntry('blog', 'en', 'future-post')?->title())->toBe('Future Post');
    expect($r->resolveEntry('blog', 'en', 'published-post')?->title())->toBe('Published Post');
});

test('resolveEntry devuelve null para inexistente', function () {
    $r = new DraftResolver($this->dir);
    expect($r->resolveEntry('blog', 'en', 'no-existe'))->toBeNull();
});

test('resolveEntry precarga el cuerpo desde el store', function () {
    $r = new DraftResolver($this->dir);
    expect($r->resolveEntry('blog', 'en', 'draft-post')->content())->toContain('Cuerpo draft');
});

// ── Banner ───────────────────────────────────────────────────────────────────

test('injectDraftBanner inserta tras <body> con la etiqueta de preview', function () {
    $r = new DraftResolver($this->dir);
    $out = $r->injectDraftBanner('<html><body><h1>Hi</h1></body></html>', 'future');

    expect($out)->toContain('VISTA PREVIA');
    expect(strpos($out, 'VISTA PREVIA'))->toBeGreaterThan(strpos($out, '<body>'));
    expect(strpos($out, 'VISTA PREVIA'))->toBeLessThan(strpos($out, '<h1>'));
});

test('injectDraftBanner sin <body> antepone el banner', function () {
    $r = new DraftResolver($this->dir);
    $out = $r->injectDraftBanner('<h1>Hi</h1>');
    expect($out)->toStartWith('<div style=');
    expect($out)->toContain('VISTA PREVIA');
});

// ── Endpoint del API (lo consume el panel) ───────────────────────────────────

test('ContentApiController::previewUrl devuelve una URL con token verificable', function () {
    $controller = new \Rkn\Cms\Http\Controllers\ContentApiController($this->dir);
    $req = (new \Nyholm\Psr7\ServerRequest('GET', '/api/v1/preview-url'))
        ->withQueryParams(['collection' => 'blog', 'slug' => 'draft-post', 'locale' => 'en']);

    $res = $controller->previewUrl($req);
    expect($res->getStatusCode())->toBe(200);

    $data = json_decode((string) $res->getBody(), true)['data'];
    expect($data['url'])->toContain('preview=');

    parse_str((string) parse_url($data['url'], PHP_URL_QUERY), $q);
    $verified = (new DraftResolver($this->dir))->verifyToken($q['preview']);
    expect($verified)->toMatchArray(['collection' => 'blog', 'slug' => 'draft-post']);
});

test('ContentApiController::previewUrl 404 si la entrada no existe', function () {
    $controller = new \Rkn\Cms\Http\Controllers\ContentApiController($this->dir);
    $req = (new \Nyholm\Psr7\ServerRequest('GET', '/api/v1/preview-url'))
        ->withQueryParams(['collection' => 'blog', 'slug' => 'no-existe']);

    expect($controller->previewUrl($req)->getStatusCode())->toBe(404);
});
