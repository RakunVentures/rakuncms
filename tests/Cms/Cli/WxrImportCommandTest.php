<?php

declare(strict_types=1);

use Rkn\Cms\Cli\WxrImportCommand;

function wxrInvoke(object $obj, string $method, array $args): mixed
{
    $r = new ReflectionMethod($obj, $method);
    $r->setAccessible(true);
    return $r->invokeArgs($obj, $args);
}

test('mediaTargetForUrl maps a WP uploads URL to a local rel path + public URL', function () {
    $t = wxrInvoke(new WxrImportCommand(), 'mediaTargetForUrl', [
        'http://fianceebodas.com/wp-content/uploads/2026/04/FILE.jpg',
        'assets/images/uploads',
    ]);
    expect($t)->toMatchArray([
        'rel' => '2026/04/FILE.jpg',
        'public_url' => '/assets/images/uploads/2026/04/FILE.jpg',
    ]);
});

test('mediaTargetForUrl strips the query string', function () {
    $t = wxrInvoke(new WxrImportCommand(), 'mediaTargetForUrl', [
        'https://x.com/wp-content/uploads/a/b.png?ver=2',
        'assets/images/uploads',
    ]);
    expect($t['rel'])->toBe('a/b.png');
});

test('mediaTargetForUrl returns null for non-uploads URLs', function () {
    expect(wxrInvoke(new WxrImportCommand(), 'mediaTargetForUrl', [
        'https://x.com/images/logo.png',
        'assets/images/uploads',
    ]))->toBeNull();
});

test('mediaTargetForUrl rejects path traversal', function () {
    expect(wxrInvoke(new WxrImportCommand(), 'mediaTargetForUrl', [
        'https://x.com/wp-content/uploads/../../etc/passwd',
        'assets/images/uploads',
    ]))->toBeNull();
});

test('firstBodyImage returns the first <img src> as the featured image', function () {
    $body = 'intro <a><img class="x" src="/assets/images/uploads/2026/05/COVER.jpg" /></a> more <img src="/b.jpg">';
    expect(wxrInvoke(new WxrImportCommand(), 'firstBodyImage', [$body]))
        ->toBe('/assets/images/uploads/2026/05/COVER.jpg');
});

test('firstBodyImage falls back to markdown image syntax', function () {
    expect(wxrInvoke(new WxrImportCommand(), 'firstBodyImage', ['text ![alt](/img/x.png) tail']))
        ->toBe('/img/x.png');
});

test('firstBodyImage returns null when the body has no image', function () {
    expect(wxrInvoke(new WxrImportCommand(), 'firstBodyImage', ['<p>just text, no images</p>']))
        ->toBeNull();
});

test('localizeImages rewrites to the local path when the file is already present (idempotent, no network)', function () {
    $tmp = $this->makeTempDir();
    mkdir($tmp . '/public/assets/images/uploads/2026/04', 0755, true);
    file_put_contents($tmp . '/public/assets/images/uploads/2026/04/A.jpg', 'local-jpeg-bytes');

    $orig = getcwd();
    chdir($tmp);
    try {
        $body = '<a href="x"><img src="http://fianceebodas.com/wp-content/uploads/2026/04/A.jpg"></a>';
        $out = wxrInvoke(new WxrImportCommand(), 'localizeImages', [$body, 'assets/images/uploads']);
        expect($out)->toContain('/assets/images/uploads/2026/04/A.jpg');
        expect($out)->not->toContain('fianceebodas.com');
    } finally {
        chdir($orig);
    }
});
