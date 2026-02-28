<?php

declare(strict_types=1);

use Rkn\Cms\Events\Event;
use Rkn\Cms\Events\EventDispatcher;
use Rkn\Cms\Events\WebhookListener;
use Rkn\Cms\Queue\FileQueue;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun-webhook-test-' . uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->queue = new FileQueue($this->tempDir);
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

test('webhook listener enqueues job with URL and payload', function () {
    $config = [
        'url' => 'https://hooks.example.com/webhook',
        'event' => 'build.completed',
    ];

    $listener = new WebhookListener($config, $this->queue);
    $event = new Event('build.completed', ['output_dir' => 'dist', 'entry_count' => 42]);

    $listener($event);

    $job = $this->queue->reserve();
    expect($job)->not->toBeNull();
    expect($job['type'])->toBe('webhook');
    expect($job['payload']['url'])->toBe('https://hooks.example.com/webhook');

    $body = json_decode($job['payload']['body'], true);
    expect($body['event'])->toBe('build.completed');
    expect($body['data']['entry_count'])->toBe(42);
});

test('webhook with secret includes HMAC signature', function () {
    $config = [
        'url' => 'https://example.com/hook',
        'secret' => 'my-secret-key',
    ];

    $listener = new WebhookListener($config, $this->queue);
    $listener(new Event('test.event', ['foo' => 'bar']));

    $job = $this->queue->reserve();
    $headers = $job['payload']['headers'];

    expect($headers)->toHaveKey('X-Webhook-Signature');
    expect($headers['X-Webhook-Signature'])->not->toBeEmpty();

    // Verify HMAC
    $expectedSignature = hash_hmac('sha256', $job['payload']['body'], 'my-secret-key');
    expect($headers['X-Webhook-Signature'])->toBe($expectedSignature);
});

test('webhook includes custom headers from config', function () {
    $config = [
        'url' => 'https://example.com/hook',
        'headers' => [
            'Authorization' => 'Bearer my-token',
            'X-Custom' => 'custom-value',
        ],
    ];

    $listener = new WebhookListener($config, $this->queue);
    $listener(new Event('test'));

    $job = $this->queue->reserve();
    $headers = $job['payload']['headers'];

    expect($headers['Authorization'])->toBe('Bearer my-token');
    expect($headers['X-Custom'])->toBe('custom-value');
    expect($headers['Content-Type'])->toBe('application/json');
});

test('registerFromConfig creates listeners for each webhook', function () {
    $webhooksConfig = [
        ['event' => 'build.completed', 'url' => 'https://example.com/build'],
        ['event' => 'entry.published', 'url' => 'https://example.com/publish'],
    ];

    $dispatcher = new EventDispatcher();
    WebhookListener::registerFromConfig($webhooksConfig, $dispatcher, $this->queue);

    expect($dispatcher->hasListeners('build.completed'))->toBeTrue();
    expect($dispatcher->hasListeners('entry.published'))->toBeTrue();
});

test('registerFromConfig skips invalid configs', function () {
    $webhooksConfig = [
        ['event' => '', 'url' => 'https://example.com/build'], // empty event
        ['event' => 'test', 'url' => ''], // empty url
        ['event' => 'test'], // missing url
    ];

    $dispatcher = new EventDispatcher();
    WebhookListener::registerFromConfig($webhooksConfig, $dispatcher, $this->queue);

    expect($dispatcher->hasListeners('test'))->toBeFalse();
});

test('webhook dispatched via event system enqueues correctly', function () {
    $dispatcher = new EventDispatcher();
    WebhookListener::registerFromConfig([
        ['event' => 'cache.cleared', 'url' => 'https://hooks.example.com/cache'],
    ], $dispatcher, $this->queue);

    $dispatcher->dispatch(new Event('cache.cleared', ['reason' => 'manual']));

    $job = $this->queue->reserve();
    expect($job)->not->toBeNull();
    expect($job['type'])->toBe('webhook');

    $body = json_decode($job['payload']['body'], true);
    expect($body['event'])->toBe('cache.cleared');
    expect($body['data']['reason'])->toBe('manual');
});
