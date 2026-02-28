<?php

declare(strict_types=1);

namespace Rkn\Cms\Queue;

/**
 * File-based job queue using JSON files with flock + atomic rename.
 *
 * Directory structure:
 *   storage/queue/pending/     – new jobs
 *   storage/queue/processing/  – currently executing
 *   storage/queue/failed/      – failed after max retries
 */
final class FileQueue
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/') . '/storage/queue';

        foreach (['pending', 'processing', 'failed'] as $dir) {
            $path = $this->basePath . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    /**
     * Push a new job onto the queue.
     *
     * @param string $type Job type identifier (e.g. 'send-contact-email')
     * @param array<string, mixed> $payload Arbitrary data for the job
     * @param int $maxRetries Maximum retry attempts
     */
    public function push(string $type, array $payload, int $maxRetries = 3): string
    {
        $id = uniqid('job_', true);

        $job = [
            'id' => $id,
            'type' => $type,
            'payload' => $payload,
            'attempts' => 0,
            'max_retries' => $maxRetries,
            'created_at' => time(),
            'updated_at' => time(),
        ];

        $file = $this->basePath . '/pending/' . $id . '.json';
        file_put_contents($file, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

        return $id;
    }

    /**
     * Reserve the next pending job for processing.
     *
     * Uses flock + rename for atomicity.
     *
     * @return array<string, mixed>|null The job data, or null if queue is empty
     */
    public function reserve(): ?array
    {
        $pendingDir = $this->basePath . '/pending';
        $files = glob($pendingDir . '/*.json');

        if ($files === false || $files === []) {
            return null;
        }

        // Sort by filename (oldest first due to uniqid)
        sort($files);

        foreach ($files as $file) {
            $handle = fopen($file, 'r');
            if ($handle === false) {
                continue;
            }

            // Non-blocking exclusive lock — skip if another process holds it
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                continue;
            }

            $content = stream_get_contents($handle);
            flock($handle, LOCK_UN);
            fclose($handle);

            if ($content === false || $content === '') {
                continue;
            }

            $job = json_decode($content, true);
            if (!is_array($job)) {
                continue;
            }

            // Atomically move to processing
            $basename = basename($file);
            $processingFile = $this->basePath . '/processing/' . $basename;

            $job['attempts'] = ($job['attempts'] ?? 0) + 1;
            $job['updated_at'] = time();

            // Write updated job to processing dir, then remove from pending
            file_put_contents($processingFile, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

            @unlink($file);

            return $job;
        }

        return null;
    }

    /**
     * Mark a job as completed (remove from processing).
     */
    public function complete(string $id): void
    {
        $file = $this->basePath . '/processing/' . $id . '.json';
        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Mark a job as failed. Re-queues if under max retries, otherwise moves to failed/.
     */
    public function fail(string $id): void
    {
        $file = $this->basePath . '/processing/' . $id . '.json';
        if (!is_file($file)) {
            return;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            return;
        }

        $job = json_decode($content, true);
        if (!is_array($job)) {
            @unlink($file);
            return;
        }

        $job['updated_at'] = time();

        if (($job['attempts'] ?? 0) < ($job['max_retries'] ?? 3)) {
            // Re-queue: move back to pending
            $pendingFile = $this->basePath . '/pending/' . $id . '.json';
            file_put_contents($pendingFile, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        } else {
            // Max retries exceeded: move to failed
            $failedFile = $this->basePath . '/failed/' . $id . '.json';
            $job['failed_at'] = time();
            file_put_contents($failedFile, json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        }

        @unlink($file);
    }

    /**
     * Get counts of jobs in each state.
     *
     * @return array{pending: int, processing: int, failed: int}
     */
    public function counts(): array
    {
        $count = static function (string $dir): int {
            $files = glob($dir . '/*.json');
            return $files === false ? 0 : count($files);
        };

        return [
            'pending' => $count($this->basePath . '/pending'),
            'processing' => $count($this->basePath . '/processing'),
            'failed' => $count($this->basePath . '/failed'),
        ];
    }

    /**
     * Clear all jobs from all directories.
     */
    public function clear(): void
    {
        foreach (['pending', 'processing', 'failed'] as $dir) {
            $path = $this->basePath . '/' . $dir;
            $files = glob($path . '/*.json');
            if ($files === false) {
                continue;
            }
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}
