<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** @var string[] */
    private array $tempDirsToCleanup = [];

    /** @var string[] */
    private array $tempFilesToCleanup = [];

    protected function makeTempDir(string $prefix = 'rkn-test-'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6));
        if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create temp dir: {$dir}");
        }
        $this->tempDirsToCleanup[] = $dir;
        return $dir;
    }

    protected function makeTempFile(string $prefix = 'rkn-test-', string $suffix = ''): string
    {
        $file = sys_get_temp_dir() . '/' . $prefix . bin2hex(random_bytes(6)) . $suffix;
        $this->tempFilesToCleanup[] = $file;
        return $file;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFilesToCleanup as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFilesToCleanup = [];

        foreach ($this->tempDirsToCleanup as $dir) {
            $this->rmrf($dir);
        }
        $this->tempDirsToCleanup = [];

        parent::tearDown();
    }

    protected function rmrf(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path) || is_link($path)) {
                @unlink($path);
            }
            return;
        }
        $items = scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $p = "{$path}/{$item}";
            if (is_dir($p) && !is_link($p)) {
                $this->rmrf($p);
            } else {
                @unlink($p);
            }
        }
        @rmdir($path);
    }
}
