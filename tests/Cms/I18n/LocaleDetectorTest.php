<?php

declare(strict_types=1);

use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Middleware\LocaleDetector;
use Rkn\Framework\Application;

test('redirects root path to default locale', function () {
    // Bootstrap a minimal app for container access
    $tmpDir = sys_get_temp_dir() . '/rkn_locale_test_' . uniqid();
    mkdir($tmpDir . '/config', 0755, true);
    mkdir($tmpDir . '/lang/es', 0755, true);
    mkdir($tmpDir . '/cache', 0755, true);
    mkdir($tmpDir . '/templates', 0755, true);
    file_put_contents($tmpDir . '/config/rakun.yaml', "site:\n  default_locale: es\n  locales: [es, en]");
    file_put_contents($tmpDir . '/lang/es/messages.php', "<?php return [];");

    $app = new Application($tmpDir);
    $detector = new LocaleDetector();

    $request = new ServerRequest('GET', '/');

    $dummyHandler = new class implements RequestHandlerInterface {
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return new \Nyholm\Psr7\Response(200, [], 'should not reach');
        }
    };

    $response = $detector->process($request, $dummyHandler);

    expect($response->getStatusCode())->toBe(302);
    expect($response->getHeaderLine('Location'))->toBe('/es/');

    // Cleanup
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($tmpDir);
});

test('passes locale from URL prefix to handler', function () {
    $tmpDir = sys_get_temp_dir() . '/rkn_locale_test2_' . uniqid();
    mkdir($tmpDir . '/config', 0755, true);
    mkdir($tmpDir . '/lang/es', 0755, true);
    mkdir($tmpDir . '/cache', 0755, true);
    mkdir($tmpDir . '/templates', 0755, true);
    file_put_contents($tmpDir . '/config/rakun.yaml', "site:\n  default_locale: es\n  locales: [es, en]");
    file_put_contents($tmpDir . '/lang/es/messages.php', "<?php return [];");

    $app = new Application($tmpDir);
    $detector = new LocaleDetector();

    $request = new ServerRequest('GET', '/en/about');

    $capturedLocale = null;
    $handler = new class ($capturedLocale) implements RequestHandlerInterface {
        private mixed $ref;
        public function __construct(mixed &$ref)
        {
            $this->ref = &$ref;
        }
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->ref = $request->getAttribute('locale');
            return new \Nyholm\Psr7\Response(200, [], 'ok');
        }
    };

    $response = $detector->process($request, $handler);

    expect($capturedLocale)->toBe('en');

    // Cleanup
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) { $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname()); }
    rmdir($tmpDir);
});
