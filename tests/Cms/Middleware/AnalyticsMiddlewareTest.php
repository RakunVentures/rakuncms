<?php

declare(strict_types=1);

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\Uri;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Middleware\AnalyticsMiddleware;

/**
 * Analytics writes share analytics.db with the template reader (ContentExtension::
 * getViews). On the default rollback journal, a concurrent read/write collides as
 * SQLITE_BUSY ("database is locked") — and because SQLite3 emits these as PHP
 * warnings (not catchable Throwables), they leak into error_log as 500-looking
 * noise. WAL + busy_timeout lets reader and writer coexist and wait instead of
 * erroring. WAL is persisted in the DB header, so a fresh connection observes it.
 */

function analyticsHandler(): RequestHandlerInterface
{
    return new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new Response(200);
        }
    };
}

beforeEach(function () {
    $this->storage = sys_get_temp_dir() . '/rkn-analytics-' . uniqid();
    mkdir($this->storage, 0755, true);
});

afterEach(function () {
    foreach (glob($this->storage . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($this->storage);
});

test('a recorded hit puts analytics.db into WAL mode', function () {
    $mw = new AnalyticsMiddleware($this->storage);
    $request = new ServerRequest('GET', new Uri('/es/blog/hello'));

    $mw->process($request, analyticsHandler());

    $dbFile = $this->storage . '/analytics.db';
    expect(file_exists($dbFile))->toBeTrue();

    // WAL is a persistent header property: a brand-new connection sees it.
    $db = new SQLite3($dbFile);
    $mode = $db->querySingle('PRAGMA journal_mode');
    $views = $db->querySingle("SELECT views FROM hits WHERE slug = 'hello'");
    $db->close();

    expect(strtolower((string) $mode))->toBe('wal');
    expect((int) $views)->toBe(1); // the hit was actually recorded
});
