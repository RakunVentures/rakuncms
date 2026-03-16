<?php

declare(strict_types=1);

use Rkn\Cms\Http\Controllers\BoostApiController;
use Nyholm\Psr7\ServerRequest;

beforeEach(function () {
    $this->tmpDir = sys_get_temp_dir() . '/rkn_boost_api_' . uniqid();
    mkdir($this->tmpDir, 0755, true);
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmpDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->tmpDir);
});

test('archetypes returns all 6 archetypes', function () {
    $controller = new BoostApiController($this->tmpDir);
    $response = $controller->archetypes();

    expect($response->getStatusCode())->toBe(200);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['data'])->toHaveCount(6);

    $names = array_column($body['data'], 'name');
    expect($names)->toContain('blog');
    expect($names)->toContain('docs');
    expect($names)->toContain('business');
    expect($names)->toContain('portfolio');
    expect($names)->toContain('catalog');
    expect($names)->toContain('multilingual');
});

test('archetypes have description and collections', function () {
    $controller = new BoostApiController($this->tmpDir);
    $response = $controller->archetypes();

    $body = json_decode((string) $response->getBody(), true);
    foreach ($body['data'] as $archetype) {
        expect($archetype)->toHaveKeys(['name', 'description', 'collections']);
        expect($archetype['description'])->toBeString()->not->toBeEmpty();
        expect($archetype['collections'])->toBeArray()->not->toBeEmpty();
    }
});

test('apply creates blog site', function () {
    $controller = new BoostApiController($this->tmpDir);
    $request = new ServerRequest('POST', '/api/v1/boost/apply');
    $request = $request->withParsedBody([
        'archetype' => 'blog',
        'name' => 'Test Blog API',
        'locale' => 'es',
    ]);

    $response = $controller->apply($request);
    expect($response->getStatusCode())->toBe(200);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['success'])->toBeTrue();
    expect($body['archetype'])->toBe('blog');
    expect($body['profile']['name'])->toBe('Test Blog API');
    expect($body['files_created'])->toBeGreaterThan(0);

    // Verify files
    expect(file_exists("{$this->tmpDir}/content/blog/_collection.yaml"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/content/blog/first-post.md"))->toBeTrue();
    expect(file_exists("{$this->tmpDir}/config/rakun.yaml"))->toBeTrue();
});

test('apply fails with missing archetype', function () {
    $controller = new BoostApiController($this->tmpDir);
    $request = new ServerRequest('POST', '/api/v1/boost/apply');
    $request = $request->withParsedBody(['name' => 'Test']);

    $response = $controller->apply($request);
    expect($response->getStatusCode())->toBe(400);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['error'])->toContain('archetype');
});

test('apply fails with missing name', function () {
    $controller = new BoostApiController($this->tmpDir);
    $request = new ServerRequest('POST', '/api/v1/boost/apply');
    $request = $request->withParsedBody(['archetype' => 'blog']);

    $response = $controller->apply($request);
    expect($response->getStatusCode())->toBe(400);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['error'])->toContain('name');
});

test('apply fails with unknown archetype', function () {
    $controller = new BoostApiController($this->tmpDir);
    $request = new ServerRequest('POST', '/api/v1/boost/apply');
    $request = $request->withParsedBody([
        'archetype' => 'nonexistent',
        'name' => 'Test',
    ]);

    $response = $controller->apply($request);
    expect($response->getStatusCode())->toBe(400);

    $body = json_decode((string) $response->getBody(), true);
    expect($body['error'])->toContain('nonexistent');
    expect($body['available'])->toBeArray();
});

test('apply fails with invalid body', function () {
    $controller = new BoostApiController($this->tmpDir);
    $request = new ServerRequest('POST', '/api/v1/boost/apply');

    $response = $controller->apply($request);
    expect($response->getStatusCode())->toBe(400);
});
