<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Builds an optimized ZIP artifact for deployment.
 *
 * The artifact contains:
 *  - All source files (minus excludes)
 *  - manifest.json in the ZIP root (release metadata + per-file sha256)
 *
 * A parallel <release>.zip.hmac file is written alongside the ZIP,
 * containing HMAC-SHA256 of the entire ZIP file signed with deploy_secret.
 * This prevents the chicken-and-egg problem of signing a file that contains
 * its own signature.
 */
final class ArtifactBuilder
{
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Build the artifact and return the path to the ZIP file.
     *
     * If $deploySecret is provided, also writes a parallel <zipname>.hmac file.
     *
     * @param array<string> $exclude
     * @throws RuntimeException
     */
    public function build(
        string $releaseId,
        array $exclude = [],
        ?string $gitSha = null,
        ?string $phpVersionTarget = null,
        string $strategy = 'lean',
        ?string $deploySecret = null,
    ): string {
        $tmpDir = sys_get_temp_dir() . '/rakun-deploy';
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $zipPath = "{$tmpDir}/{$releaseId}.zip";
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create ZIP file at {$zipPath}");
        }

        // Collect file list for manifest BEFORE adding to ZIP
        $fileEntries = $this->collectFiles($this->basePath, $exclude);

        // Build manifest from collected file list
        $manifest = $this->buildManifest(
            releaseId: $releaseId,
            fileEntries: $fileEntries,
            gitSha: $gitSha,
            phpVersionTarget: $phpVersionTarget,
            strategy: $strategy,
        );

        // Add manifest.json as first entry
        $zip->addFromString('manifest.json', $manifest->toJson());

        // Add all collected files
        foreach ($fileEntries as $entry) {
            if ($entry['is_dir']) {
                $zip->addEmptyDir($entry['relative']);
            } else {
                $zip->addFile($entry['real'], $entry['relative']);
            }
        }

        $zip->close();

        // Write HMAC of ZIP alongside it (streamed, never loads full file in memory)
        if ($deploySecret !== null && $deploySecret !== '') {
            $hmac = hash_hmac_file('sha256', $zipPath, $deploySecret);
            if ($hmac === false) {
                throw new RuntimeException("Cannot compute HMAC for ZIP: {$zipPath}");
            }
            file_put_contents("{$zipPath}.hmac", $hmac);
        }

        return $zipPath;
    }

    /**
     * Collect all files (and directories) respecting excludes.
     *
     * @param array<string> $exclude
     * @return array<array{real: string, relative: string, is_dir: bool, size: int, sha256: string}>
     */
    private function collectFiles(string $rootPath, array $exclude): array
    {
        $defaultExclude = [
            '.git',
            '.DS_Store',
            'cache/pages',
            'cache/templates',
            'tests',
            'vendor/rkn/cms/.git',
            'vendor/rkn/cms/vendor',
            'vendor/rkn/cms/tests',
            'vendor/rkn/cms/docs',
            'vendor/rkn/cms/node_modules',
            'vendor/rkn/cms/.github',
        ];
        $allExclude = array_merge($defaultExclude, $exclude);

        // Normalize to real path so getRealPath() comparisons are reliable on macOS
        $normalizedRoot = rtrim((string) realpath($rootPath), '/');

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $entries = [];

        foreach ($files as $file) {
            $filePath = $file->getRealPath();
            if ($filePath === false) {
                continue;
            }

            $relativePath = ltrim(substr($filePath, strlen($normalizedRoot)), '/');

            if ($this->shouldExclude($relativePath, $allExclude)) {
                continue;
            }

            if ($file->isDir()) {
                $entries[] = [
                    'real' => $filePath,
                    'relative' => $relativePath,
                    'is_dir' => true,
                    'size' => 0,
                    'sha256' => '',
                ];
            } else {
                $sha256 = hash_file('sha256', $filePath);
                $entries[] = [
                    'real' => $filePath,
                    'relative' => $relativePath,
                    'is_dir' => false,
                    'size' => (int) filesize($filePath),
                    'sha256' => $sha256 !== false ? $sha256 : '',
                ];
            }
        }

        return $entries;
    }

    /**
     * @param array<array{real: string, relative: string, is_dir: bool, size: int, sha256: string}> $fileEntries
     */
    private function buildManifest(
        string $releaseId,
        array $fileEntries,
        ?string $gitSha,
        ?string $phpVersionTarget,
        string $strategy,
    ): ReleaseManifest {
        $files = [];
        $totalSize = 0;

        foreach ($fileEntries as $entry) {
            if ($entry['is_dir']) {
                continue;
            }
            $files[] = [
                'path' => $entry['relative'],
                'sha256' => $entry['sha256'],
                'size' => $entry['size'],
            ];
            $totalSize += $entry['size'];
        }

        // Sort for deterministic output
        usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return new ReleaseManifest(
            releaseId: $releaseId,
            gitSha: $gitSha,
            generatedAt: new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            phpVersionTarget: $phpVersionTarget,
            strategy: $strategy,
            totalSize: $totalSize,
            files: $files,
        );
    }

    /** @param array<string> $exclude */
    private function shouldExclude(string $path, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            if ($pattern === $path || str_starts_with($path, rtrim($pattern, '/') . '/')) {
                return true;
            }
            // Handle glob-like patterns simply
            if (str_contains($pattern, '*') && preg_match('#' . str_replace('*', '.*', $pattern) . '#', $path)) {
                return true;
            }
        }

        return false;
    }
}
