<?php

declare(strict_types=1);

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rkn\Cms\Middleware\DevReloadMiddleware;

beforeEach(function () {
    $this->stamp = sys_get_temp_dir() . '/rkn_dev_reload_' . uniqid() . '.stamp';
});

afterEach(function () {
    if (file_exists($this->stamp)) {
        unlink($this->stamp);
    }
});

function makeHandler(ResponseInterface $response): RequestHandlerInterface
{
    return new class($response) implements RequestHandlerInterface {
        public function __construct(private readonly ResponseInterface $response) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            return $this->response;
        }
    };
}

test('endpoint returns current stamp mtime', function () {
    touch($this->stamp);
    $mtime = filemtime($this->stamp);

    $mw = new DevReloadMiddleware($this->stamp);

    $req = new ServerRequest('GET', '/__dev/reload?since=0');
    $resp = $mw->process($req, makeHandler(new Response()));

    expect($resp->getStatusCode())->toBe(200);
    expect($resp->getHeaderLine('Content-Type'))->toBe('application/json');
    expect($resp->getHeaderLine('Cache-Control'))->toContain('no-store');

    $payload = json_decode((string) $resp->getBody(), true);
    expect($payload)->toBe(['v' => $mtime]);
});

test('endpoint returns immediately without sleeping', function () {
    touch($this->stamp);
    $mw = new DevReloadMiddleware($this->stamp);

    $start = microtime(true);
    $req = new ServerRequest('GET', '/__dev/reload?since=0');
    $mw->process($req, makeHandler(new Response()));
    $elapsed = microtime(true) - $start;

    expect($elapsed)->toBeLessThan(0.1);
});

test('endpoint returns v=0 when stamp file does not exist', function () {
    expect(file_exists($this->stamp))->toBeFalse();

    $mw = new DevReloadMiddleware($this->stamp);
    $req = new ServerRequest('GET', '/__dev/reload?since=0');
    $resp = $mw->process($req, makeHandler(new Response()));

    $payload = json_decode((string) $resp->getBody(), true);
    expect($payload)->toBe(['v' => 0]);
});

test('endpoint ignores since query param (client compares versions)', function () {
    touch($this->stamp);
    $mtime = filemtime($this->stamp);
    $mw = new DevReloadMiddleware($this->stamp);

    $req = new ServerRequest('GET', '/__dev/reload?since=' . ($mtime + 9999));
    $resp = $mw->process($req, makeHandler(new Response()));

    $payload = json_decode((string) $resp->getBody(), true);
    expect($payload)->toBe(['v' => $mtime]);
});

test('injects script before </body> on HTML responses', function () {
    touch($this->stamp);
    $mw = new DevReloadMiddleware($this->stamp);

    $factory = new Psr17Factory();
    $response = $factory->createResponse(200)
        ->withHeader('Content-Type', 'text/html; charset=UTF-8')
        ->withBody($factory->createStream('<html><body><h1>Hello</h1></body></html>'));

    $req = new ServerRequest('GET', '/');
    $out = $mw->process($req, makeHandler($response));

    $body = (string) $out->getBody();
    expect($body)->toContain('data-rakun-dev-reload');
    expect($body)->toContain('location.reload()');
    $scriptPos = strpos($body, '<script');
    $bodyClose = strpos($body, '</body>');
    expect($scriptPos)->toBeLessThan($bodyClose);
});

test('skips injection when response is not HTML', function () {
    touch($this->stamp);
    $mw = new DevReloadMiddleware($this->stamp);

    $factory = new Psr17Factory();
    $response = $factory->createResponse(200)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($factory->createStream('{"ok":true}'));

    $req = new ServerRequest('GET', '/api/foo');
    $out = $mw->process($req, makeHandler($response));

    expect((string) $out->getBody())->toBe('{"ok":true}');
});

test('appends script when HTML has no </body>', function () {
    touch($this->stamp);
    $mw = new DevReloadMiddleware($this->stamp);

    $factory = new Psr17Factory();
    $response = $factory->createResponse(200)
        ->withHeader('Content-Type', 'text/html')
        ->withBody($factory->createStream('<h1>fragment</h1>'));

    $req = new ServerRequest('GET', '/');
    $out = $mw->process($req, makeHandler($response));

    $body = (string) $out->getBody();
    expect($body)->toStartWith('<h1>fragment</h1>');
    expect($body)->toContain('data-rakun-dev-reload');
});

test('skips injection when body is empty', function () {
    touch($this->stamp);
    $mw = new DevReloadMiddleware($this->stamp);

    $factory = new Psr17Factory();
    $response = $factory->createResponse(204)
        ->withHeader('Content-Type', 'text/html');

    $req = new ServerRequest('GET', '/');
    $out = $mw->process($req, makeHandler($response));

    expect((string) $out->getBody())->toBe('');
});

test('updates Content-Length after injection', function () {
    touch($this->stamp);
    $mw = new DevReloadMiddleware($this->stamp);

    $factory = new Psr17Factory();
    $original = '<html><body>x</body></html>';
    $response = $factory->createResponse(200)
        ->withHeader('Content-Type', 'text/html')
        ->withHeader('Content-Length', (string) strlen($original))
        ->withBody($factory->createStream($original));

    $req = new ServerRequest('GET', '/');
    $out = $mw->process($req, makeHandler($response));

    $newBody = (string) $out->getBody();
    expect((int) $out->getHeaderLine('Content-Length'))->toBe(strlen($newBody));
    expect(strlen($newBody))->toBeGreaterThan(strlen($original));
});

test('passes through requests for paths other than /__dev/reload', function () {
    touch($this->stamp);
    $mw = new DevReloadMiddleware($this->stamp);

    $called = false;
    $handler = new class($called) implements RequestHandlerInterface {
        public function __construct(private bool &$called) {}
        public function handle(ServerRequestInterface $request): ResponseInterface
        {
            $this->called = true;
            return (new Response())
                ->withHeader('Content-Type', 'application/octet-stream')
                ->withBody(\Nyholm\Psr7\Stream::create('binary'));
        }
    };

    $req = new ServerRequest('GET', '/some/page');
    $mw->process($req, $handler);

    expect($called)->toBeTrue();
});
