<?php

declare(strict_types=1);

use Rkn\Cms\Http\Controllers\ContentApiController;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun-api-test-' . uniqid();
    mkdir($this->tempDir . '/content/blog', 0755, true);
    mkdir($this->tempDir . '/content/pages', 0755, true);
    mkdir($this->tempDir . '/cache', 0755, true);
    mkdir($this->tempDir . '/config', 0755, true);

    // Config file
    file_put_contents($this->tempDir . '/config/rakun.yaml', "site:\n  default_locale: en\n");

    // Create some entries
    file_put_contents($this->tempDir . '/content/blog/hello.en.md', <<<'MD'
---
title: "Hello World"
meta:
  description: "First post"
tags:
  - php
---
Hello world content.
MD);

    file_put_contents($this->tempDir . '/content/blog/second.en.md', <<<'MD'
---
title: "Second Post"
meta:
  description: "Another post"
tags:
  - javascript
---
Second post content.
MD);

    file_put_contents($this->tempDir . '/content/pages/about.en.md', <<<'MD'
---
title: "About Us"
---
About page content.
MD);

    $this->controller = new ContentApiController($this->tempDir);
});

afterEach(function () {
    $cleanup = function (string $dir) use (&$cleanup): void {
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

test('list returns paginated entries', function () {
    $request = new ServerRequest('GET', new Uri('/api/v1/entries'));
    $response = $this->controller->list($request);

    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($data['data'])->toHaveCount(3);
    expect($data['meta']['total'])->toBe(3);
    expect($data['meta']['page'])->toBe(1);
});

test('list filters by collection', function () {
    $request = new ServerRequest('GET', new Uri('/api/v1/entries?collection=blog'));
    $request = $request->withQueryParams(['collection' => 'blog']);
    $response = $this->controller->list($request);

    $data = json_decode((string) $response->getBody(), true);

    expect($data['data'])->toHaveCount(2);
    foreach ($data['data'] as $entry) {
        expect($entry['collection'])->toBe('blog');
    }
});

test('list paginates with page and per_page', function () {
    $request = (new ServerRequest('GET', new Uri('/api/v1/entries')))
        ->withQueryParams(['per_page' => '2', 'page' => '1']);
    $data = json_decode((string) $this->controller->list($request)->getBody(), true);

    expect($data['data'])->toHaveCount(2);
    expect($data['meta']['total'])->toBe(3);
    expect($data['meta']['per_page'])->toBe(2);
    expect($data['meta']['pages'])->toBe(2);

    $request2 = (new ServerRequest('GET', new Uri('/api/v1/entries')))
        ->withQueryParams(['per_page' => '2', 'page' => '2']);
    $data2 = json_decode((string) $this->controller->list($request2)->getBody(), true);

    expect($data2['data'])->toHaveCount(1);
    expect($data2['meta']['page'])->toBe(2);
});

test('list paginates within a collection', function () {
    $request = (new ServerRequest('GET', new Uri('/api/v1/entries')))
        ->withQueryParams(['collection' => 'blog', 'per_page' => '1', 'page' => '1']);
    $data = json_decode((string) $this->controller->list($request)->getBody(), true);

    expect($data['data'])->toHaveCount(1);
    expect($data['meta']['total'])->toBe(2);
    expect($data['meta']['pages'])->toBe(2);
    expect($data['data'][0]['collection'])->toBe('blog');
});

test('show returns entry with content key', function () {
    $response = $this->controller->show('blog', 'hello');

    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($data['data']['title'])->toBe('Hello World');
    expect($data['data'])->toHaveKey('content');
    expect($data['data']['slug'])->toBe('hello');
    expect($data['data']['collection'])->toBe('blog');
});

test('show returns 404 for missing entry', function () {
    $response = $this->controller->show('blog', 'nonexistent');

    expect($response->getStatusCode())->toBe(404);
});

test('show returns raw markdown body with raw=true', function () {
    $raw = json_decode((string) $this->controller->show('blog', 'hello', true)->getBody(), true);

    // Raw Markdown body (read from the .md file), not rendered HTML.
    expect($raw['data']['content'])->toContain('Hello world content.');
    expect($raw['data']['content'])->not->toContain('<p>');
});

test('entries expose a normalized status (default published)', function () {
    $data = json_decode((string) $this->controller->show('blog', 'hello')->getBody(), true);

    expect($data['data']['status'])->toBe('published');
});

test('status normalizes WordPress publish/draft vocabulary', function () {
    file_put_contents($this->tempDir . '/content/blog/wp.en.md', "---\ntitle: WP\nstatus: publish\n---\n\nbody");
    file_put_contents($this->tempDir . '/content/blog/dr.en.md', "---\ntitle: Draft One\nstatus: draft\n---\n\nbody");

    $pub = json_decode((string) $this->controller->show('blog', 'wp')->getBody(), true);
    $dra = json_decode((string) $this->controller->show('blog', 'dr')->getBody(), true);

    expect($pub['data']['status'])->toBe('published');
    expect($dra['data']['status'])->toBe('draft');
});

test('create writes new markdown file', function () {
    $body = json_encode([
        'title' => 'New Post',
        'slug' => 'new-post',
        'locale' => 'en',
        'content' => 'This is a new post.',
        'meta' => ['description' => 'A brand new post'],
    ]);

    $request = new ServerRequest('POST', new Uri('/api/v1/entries/blog'));
    $request = $request->withBody(\Nyholm\Psr7\Stream::create($body));
    $response = $this->controller->create($request, 'blog');

    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(201);
    expect($data['data']['title'])->toBe('New Post');

    // File should exist
    $filePath = $this->tempDir . '/content/blog/new-post.en.md';
    expect(file_exists($filePath))->toBeTrue();
    $content = file_get_contents($filePath);
    expect($content)->toContain('New Post');
    expect($content)->toContain('This is a new post.');
});

test('create returns 409 for duplicate entry', function () {
    $body = json_encode(['title' => 'Hello World', 'slug' => 'hello', 'locale' => 'en']);
    $request = new ServerRequest('POST', new Uri('/api/v1/entries/blog'));
    $request = $request->withBody(\Nyholm\Psr7\Stream::create($body));

    $response = $this->controller->create($request, 'blog');

    expect($response->getStatusCode())->toBe(409);
});

test('create returns 422 for missing title', function () {
    $body = json_encode(['content' => 'no title']);
    $request = new ServerRequest('POST', new Uri('/api/v1/entries/blog'));
    $request = $request->withBody(\Nyholm\Psr7\Stream::create($body));

    $response = $this->controller->create($request, 'blog');

    expect($response->getStatusCode())->toBe(422);
});

test('delete removes entry file', function () {
    $request = new ServerRequest('DELETE', new Uri('/api/v1/entries/blog/hello'));
    $response = $this->controller->delete($request, 'blog', 'hello');

    expect($response->getStatusCode())->toBe(200);
    expect(file_exists($this->tempDir . '/content/blog/hello.en.md'))->toBeFalse();
});

test('delete returns 404 for missing entry', function () {
    $request = new ServerRequest('DELETE', new Uri('/api/v1/entries/blog/nonexistent'));
    $response = $this->controller->delete($request, 'blog', 'nonexistent');

    expect($response->getStatusCode())->toBe(404);
});

test('update merges title and content, preserving untouched fields', function () {
    $body = json_encode([
        'title'   => 'Hello Updated',
        'content' => 'Updated body text.',
        'meta'    => ['description' => 'Edited desc'],
    ]);
    $request = (new ServerRequest('PUT', new Uri('/api/v1/entries/blog/hello')))
        ->withBody(\Nyholm\Psr7\Stream::create((string) $body));

    $response = $this->controller->update($request, 'blog', 'hello');
    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    expect($data['data']['title'])->toBe('Hello Updated');
    expect($data['data']['slug'])->toBe('hello');
    expect($data['data']['locale'])->toBe('en');

    $content = file_get_contents($this->tempDir . '/content/blog/hello.en.md');
    expect($content)->toContain('Hello Updated');
    expect($content)->toContain('Updated body text.');
    expect($content)->toContain('Edited desc');
    // A field not sent in the update (the original tag) is preserved.
    expect($content)->toContain('php');
});

test('update keeps existing body when only title is sent', function () {
    $body = json_encode(['title' => 'Only Title Changed']);
    $request = (new ServerRequest('PUT', new Uri('/api/v1/entries/blog/second')))
        ->withBody(\Nyholm\Psr7\Stream::create((string) $body));

    $response = $this->controller->update($request, 'blog', 'second');

    expect($response->getStatusCode())->toBe(200);
    $content = file_get_contents($this->tempDir . '/content/blog/second.en.md');
    expect($content)->toContain('Only Title Changed');
    expect($content)->toContain('Second post content.');
});

test('update returns 404 for missing entry', function () {
    $body = json_encode(['title' => 'Nope']);
    $request = (new ServerRequest('PUT', new Uri('/api/v1/entries/blog/nonexistent')))
        ->withBody(\Nyholm\Psr7\Stream::create((string) $body));

    $response = $this->controller->update($request, 'blog', 'nonexistent');

    expect($response->getStatusCode())->toBe(404);
});

test('update returns 400 for invalid JSON body', function () {
    $request = (new ServerRequest('PUT', new Uri('/api/v1/entries/blog/hello')))
        ->withBody(\Nyholm\Psr7\Stream::create('not-json'));

    $response = $this->controller->update($request, 'blog', 'hello');

    expect($response->getStatusCode())->toBe(400);
});

test('collections returns list of collections with counts', function () {
    $response = $this->controller->collections();

    $data = json_decode((string) $response->getBody(), true);

    expect($response->getStatusCode())->toBe(200);
    $counts = array_column($data['data'], 'entry_count', 'name');
    expect($counts)->toHaveKey('blog');
    expect($counts)->toHaveKey('pages');
    expect($counts['blog'])->toBe(2);
    expect($counts['pages'])->toBe(1);
});

test('collections counts entries nested in subdirectories (chronological blog)', function () {
    // A real blog organises posts under blog/YYYY/MM/. A non-recursive scan of
    // content/blog/ would miss these and report 0 — the index sees them all.
    mkdir($this->tempDir . '/content/blog/2024/03', 0755, true);
    file_put_contents($this->tempDir . '/content/blog/2024/03/nested.en.md', <<<'MD'
    ---
    title: "Nested Post"
    ---
    A post buried under year/month folders.
    MD);

    $data = json_decode((string) $this->controller->collections()->getBody(), true);
    $counts = array_column($data['data'], 'entry_count', 'name');

    // 2 flat (hello, second) + 1 nested. The buggy non-recursive count returned 2.
    expect($counts['blog'])->toBe(3);
});

// ──────────────────────────────────────────────────────────────────────────────
// ?status opt-in tests
// ──────────────────────────────────────────────────────────────────────────────

test('list ?status=published returns only published entries (default)', function () {
    file_put_contents($this->tempDir . '/content/blog/draft-a.en.md', "---\ntitle: Draft A\nstatus: draft\n---\nbody");

    // Explicit ?status=published
    $request = (new ServerRequest('GET', new Uri('/api/v1/entries')))
        ->withQueryParams(['collection' => 'blog', 'status' => 'published']);
    $data = json_decode((string) $this->controller->list($request)->getBody(), true);

    $statuses = array_column($data['data'], 'status');
    expect($statuses)->each->toBe('published');
    expect(in_array('draft', $statuses, true))->toBeFalse();
});

test('list ?status=draft returns only draft entries', function () {
    file_put_contents($this->tempDir . '/content/blog/draft-b.en.md', "---\ntitle: Draft B\nstatus: draft\n---\nbody");

    $request = (new ServerRequest('GET', new Uri('/api/v1/entries')))
        ->withQueryParams(['collection' => 'blog', 'status' => 'draft']);
    $data = json_decode((string) $this->controller->list($request)->getBody(), true);

    expect($data['data'])->not->toBeEmpty();
    $statuses = array_column($data['data'], 'status');
    expect($statuses)->each->toBe('draft');
});

test('list ?status=all returns all statuses', function () {
    file_put_contents($this->tempDir . '/content/blog/draft-c.en.md', "---\ntitle: Draft C\nstatus: draft\n---\nbody");

    $request = (new ServerRequest('GET', new Uri('/api/v1/entries')))
        ->withQueryParams(['collection' => 'blog', 'status' => 'all']);
    $data = json_decode((string) $this->controller->list($request)->getBody(), true);

    $statuses = array_column($data['data'], 'status');
    expect(in_array('published', $statuses, true))->toBeTrue();
    expect(in_array('draft', $statuses, true))->toBeTrue();
});

test('list without ?status hides drafts by default (no-collection path)', function () {
    file_put_contents($this->tempDir . '/content/blog/draft-d.en.md', "---\ntitle: Draft D\nstatus: draft\n---\nbody");

    // No collection, no status → published only
    $request = new ServerRequest('GET', new Uri('/api/v1/entries'));
    $data = json_decode((string) $this->controller->list($request)->getBody(), true);

    $statuses = array_column($data['data'], 'status');
    expect(in_array('draft', $statuses, true))->toBeFalse();
});

test('list ?status=all on no-collection path includes drafts', function () {
    file_put_contents($this->tempDir . '/content/blog/draft-e.en.md', "---\ntitle: Draft E\nstatus: draft\n---\nbody");

    $request = (new ServerRequest('GET', new Uri('/api/v1/entries')))
        ->withQueryParams(['status' => 'all']);
    $data = json_decode((string) $this->controller->list($request)->getBody(), true);

    $statuses = array_column($data['data'], 'status');
    expect(in_array('draft', $statuses, true))->toBeTrue();
});

test('show with no status param returns drafts (admin preview default)', function () {
    file_put_contents($this->tempDir . '/content/blog/my-draft.en.md', "---\ntitle: My Draft\nstatus: draft\n---\nbody");

    $response = $this->controller->show('blog', 'my-draft');
    expect($response->getStatusCode())->toBe(200);
    $data = json_decode((string) $response->getBody(), true);
    expect($data['data']['status'])->toBe('draft');
});

test('show with status=published returns 404 for draft entry', function () {
    file_put_contents($this->tempDir . '/content/blog/hidden-draft.en.md', "---\ntitle: Hidden\nstatus: draft\n---\nbody");

    $response = $this->controller->show('blog', 'hidden-draft', false, 'published');
    expect($response->getStatusCode())->toBe(404);
});

// ──────────────────────────────────────────────────────────────────────────────
// Full-page cache invalidation on write (cache/pages)
//
// A content mutation must invalidate the full-page HTML cache, otherwise every
// listing/archive page keeps serving stale HTML and "new posts don't publish".
// clear() wipes the whole page cache (correct without file-dependency tracking)
// while preserving the cache directory itself.
// ──────────────────────────────────────────────────────────────────────────────

test('create invalidates the full-page cache', function () {
    $pages = $this->tempDir . '/cache/pages';
    mkdir($pages . '/blog', 0755, true);
    file_put_contents($pages . '/index.html', '<html>stale home</html>');
    file_put_contents($pages . '/blog/listing.html', '<html>stale listing</html>');

    $body = json_encode(['title' => 'Cache Buster', 'slug' => 'cache-buster', 'locale' => 'en', 'content' => 'x']);
    $request = (new ServerRequest('POST', new Uri('/api/v1/entries/blog')))
        ->withBody(\Nyholm\Psr7\Stream::create((string) $body));

    $response = $this->controller->create($request, 'blog');

    expect($response->getStatusCode())->toBe(201)
        ->and(file_exists($pages . '/index.html'))->toBeFalse()
        ->and(file_exists($pages . '/blog/listing.html'))->toBeFalse()
        // clear() empties the cache but preserves the directory itself.
        ->and(is_dir($pages))->toBeTrue();
});

test('update invalidates the full-page cache', function () {
    $pages = $this->tempDir . '/cache/pages';
    mkdir($pages . '/blog', 0755, true);
    file_put_contents($pages . '/index.html', '<html>stale home</html>');
    file_put_contents($pages . '/blog/listing.html', '<html>stale listing</html>');

    $body = json_encode(['title' => 'Hello Updated']);
    $request = (new ServerRequest('PUT', new Uri('/api/v1/entries/blog/hello')))
        ->withBody(\Nyholm\Psr7\Stream::create((string) $body));

    $response = $this->controller->update($request, 'blog', 'hello');

    expect($response->getStatusCode())->toBe(200)
        ->and(file_exists($pages . '/index.html'))->toBeFalse()
        ->and(file_exists($pages . '/blog/listing.html'))->toBeFalse()
        ->and(is_dir($pages))->toBeTrue();
});

test('delete invalidates the full-page cache', function () {
    $pages = $this->tempDir . '/cache/pages';
    mkdir($pages . '/blog', 0755, true);
    file_put_contents($pages . '/index.html', '<html>stale home</html>');
    file_put_contents($pages . '/blog/listing.html', '<html>stale listing</html>');

    $request = new ServerRequest('DELETE', new Uri('/api/v1/entries/blog/hello'));
    $response = $this->controller->delete($request, 'blog', 'hello');

    expect($response->getStatusCode())->toBe(200)
        ->and(file_exists($pages . '/index.html'))->toBeFalse()
        ->and(file_exists($pages . '/blog/listing.html'))->toBeFalse()
        ->and(is_dir($pages))->toBeTrue();
});

test('write does not create cache/pages when no page cache exists', function () {
    // Caching off / never visited: no cache/pages directory. A write must not
    // materialise an empty cache dir as a side-effect.
    expect(is_dir($this->tempDir . '/cache/pages'))->toBeFalse();

    $body = json_encode(['title' => 'No Cache Here', 'slug' => 'no-cache-here', 'locale' => 'en']);
    $request = (new ServerRequest('POST', new Uri('/api/v1/entries/blog')))
        ->withBody(\Nyholm\Psr7\Stream::create((string) $body));

    $response = $this->controller->create($request, 'blog');

    expect($response->getStatusCode())->toBe(201)
        ->and(is_dir($this->tempDir . '/cache/pages'))->toBeFalse();
});

test('a failing page-cache invalidation is logged but never breaks the write', function () {
    if (function_exists('posix_getuid') && posix_getuid() === 0) {
        // root bypasses 0000 perms, so a clear() failure cannot be simulated.
        test()->markTestSkipped('cannot simulate a permission failure as root');
    }

    $pages = $this->tempDir . '/cache/pages';
    mkdir($pages, 0755, true);
    file_put_contents($pages . '/index.html', '<html>stale</html>');
    // Make the cache dir unreadable so PageCache::clear() throws when it iterates
    // it. is_dir() (stat via the 0755 parent) still passes, so the controller does
    // attempt the clear — exactly the silent-failure path we want made observable.
    chmod($pages, 0000);

    $logFile = sys_get_temp_dir() . '/rakun-errlog-' . uniqid() . '.log';
    $prev = ini_get('error_log');
    ini_set('error_log', $logFile);

    try {
        $body = json_encode(['title' => 'Resilient Write', 'slug' => 'resilient-write', 'locale' => 'en', 'content' => 'x']);
        $request = (new ServerRequest('POST', new Uri('/api/v1/entries/blog')))
            ->withBody(\Nyholm\Psr7\Stream::create((string) $body));

        $response = $this->controller->create($request, 'blog');
    } finally {
        ini_set('error_log', $prev === false ? '' : $prev);
        chmod($pages, 0755); // restore so afterEach cleanup can recurse
    }

    $log = file_exists($logFile) ? (string) file_get_contents($logFile) : '';
    @unlink($logFile);

    // The write itself succeeds and the file lands on disk…
    expect($response->getStatusCode())->toBe(201);
    expect(file_exists($this->tempDir . '/content/blog/resilient-write.en.md'))->toBeTrue();
    // …and the previously-swallowed cache failure is now observable in the log.
    expect($log)->toContain('[rakun]');
});
