<?php

declare(strict_types=1);

use Rkn\Cms\Queue\FileQueue;

beforeEach(function () {
    $this->tempDir = sys_get_temp_dir() . '/rakun_queue_test_' . uniqid();
    mkdir($this->tempDir, 0755, true);
    $this->queue = new FileQueue($this->tempDir);
});

afterEach(function () {
    // Clean up
    $dirs = ['pending', 'processing', 'failed'];
    foreach ($dirs as $dir) {
        $path = $this->tempDir . '/storage/queue/' . $dir;
        if (is_dir($path)) {
            $files = glob($path . '/*');
            foreach ($files ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($path);
        }
    }
    @rmdir($this->tempDir . '/storage/queue');
    @rmdir($this->tempDir . '/storage');
    @rmdir($this->tempDir);
});

test('creates queue directories on instantiation', function () {
    expect(is_dir($this->tempDir . '/storage/queue/pending'))->toBeTrue();
    expect(is_dir($this->tempDir . '/storage/queue/processing'))->toBeTrue();
    expect(is_dir($this->tempDir . '/storage/queue/failed'))->toBeTrue();
});

test('push creates a pending job file', function () {
    $id = $this->queue->push('test-job', ['key' => 'value']);

    expect($id)->toStartWith('job_');

    $file = $this->tempDir . '/storage/queue/pending/' . $id . '.json';
    expect(is_file($file))->toBeTrue();

    $job = json_decode(file_get_contents($file), true);
    expect($job['type'])->toBe('test-job');
    expect($job['payload'])->toBe(['key' => 'value']);
    expect($job['attempts'])->toBe(0);
    expect($job['max_retries'])->toBe(3);
});

test('reserve returns the oldest pending job', function () {
    $id1 = $this->queue->push('job-1', ['order' => 1]);
    usleep(10000); // ensure different uniqid
    $id2 = $this->queue->push('job-2', ['order' => 2]);

    $job = $this->queue->reserve();

    expect($job)->not->toBeNull();
    expect($job['type'])->toBe('job-1');
    expect($job['attempts'])->toBe(1);

    // Job moved from pending to processing
    expect(is_file($this->tempDir . '/storage/queue/pending/' . $id1 . '.json'))->toBeFalse();
    expect(is_file($this->tempDir . '/storage/queue/processing/' . $id1 . '.json'))->toBeTrue();
});

test('reserve returns null when queue is empty', function () {
    $job = $this->queue->reserve();
    expect($job)->toBeNull();
});

test('complete removes job from processing', function () {
    $id = $this->queue->push('test-job', []);
    $this->queue->reserve();

    expect(is_file($this->tempDir . '/storage/queue/processing/' . $id . '.json'))->toBeTrue();

    $this->queue->complete($id);

    expect(is_file($this->tempDir . '/storage/queue/processing/' . $id . '.json'))->toBeFalse();
});

test('fail re-queues job under max retries', function () {
    $id = $this->queue->push('test-job', [], 3);
    $this->queue->reserve(); // attempt 1

    $this->queue->fail($id);

    // Should be back in pending
    expect(is_file($this->tempDir . '/storage/queue/pending/' . $id . '.json'))->toBeTrue();
    expect(is_file($this->tempDir . '/storage/queue/processing/' . $id . '.json'))->toBeFalse();
});

test('fail moves to failed directory when max retries exceeded', function () {
    $id = $this->queue->push('test-job', [], 1);

    // Attempt 1
    $this->queue->reserve();
    $this->queue->fail($id);

    // Should be in failed (1 attempt >= 1 max_retries)
    expect(is_file($this->tempDir . '/storage/queue/failed/' . $id . '.json'))->toBeTrue();
    expect(is_file($this->tempDir . '/storage/queue/pending/' . $id . '.json'))->toBeFalse();
});

test('counts returns correct job counts', function () {
    $this->queue->push('job-1', []);
    $this->queue->push('job-2', []);
    $id3 = $this->queue->push('job-3', [], 1);

    $counts = $this->queue->counts();
    expect($counts['pending'])->toBe(3);
    expect($counts['processing'])->toBe(0);
    expect($counts['failed'])->toBe(0);

    $this->queue->reserve(); // moves job-1 to processing
    $counts = $this->queue->counts();
    expect($counts['pending'])->toBe(2);
    expect($counts['processing'])->toBe(1);
});

test('clear removes all jobs from all directories', function () {
    $this->queue->push('job-1', []);
    $this->queue->push('job-2', []);
    $this->queue->reserve();

    $this->queue->clear();

    $counts = $this->queue->counts();
    expect($counts['pending'])->toBe(0);
    expect($counts['processing'])->toBe(0);
    expect($counts['failed'])->toBe(0);
});

test('push accepts custom max retries', function () {
    $id = $this->queue->push('test-job', [], 5);

    $file = $this->tempDir . '/storage/queue/pending/' . $id . '.json';
    $job = json_decode(file_get_contents($file), true);

    expect($job['max_retries'])->toBe(5);
});
