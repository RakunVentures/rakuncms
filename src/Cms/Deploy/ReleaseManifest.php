<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

use DateTimeImmutable;
use DateTimeInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Immutable DTO representing a release manifest.
 *
 * Each ZIP artifact contains a manifest.json in its root documenting
 * every file with its sha256 hash. Used for integrity verification
 * post-extract and for computing diffs between releases.
 */
final class ReleaseManifest
{
    /**
     * @param array<array{path: string, sha256: string, size: int}> $files
     */
    public function __construct(
        public readonly string $releaseId,
        public readonly ?string $gitSha,
        public readonly DateTimeImmutable $generatedAt,
        public readonly ?string $phpVersionTarget,
        public readonly string $strategy,
        public readonly int $totalSize,
        public readonly array $files,
    ) {}

    /**
     * Parse manifest.json from inside a ZIP file.
     *
     * @throws RuntimeException
     */
    public static function fromZip(string $zipPath): self
    {
        if (!file_exists($zipPath)) {
            throw new RuntimeException("ZIP file not found: {$zipPath}");
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Cannot open ZIP file: {$zipPath}");
        }

        $manifestJson = $zip->getFromName('manifest.json');
        $zip->close();

        if ($manifestJson === false) {
            throw new RuntimeException("manifest.json not found in ZIP: {$zipPath}");
        }

        return self::fromJson($manifestJson);
    }

    /**
     * Build a manifest by scanning a directory on disk.
     *
     * @param array<string> $exclude Relative paths/prefixes to skip
     * @throws RuntimeException
     */
    public static function fromDirectory(
        string $dirPath,
        string $releaseId,
        ?string $gitSha = null,
        ?string $phpVersionTarget = null,
        string $strategy = 'lean',
        array $exclude = [],
    ): self {
        if (!is_dir($dirPath)) {
            throw new RuntimeException("Directory not found: {$dirPath}");
        }

        $defaultExclude = ['.git', '.DS_Store', 'cache/pages', 'cache/templates', 'tests'];
        $allExclude = array_merge($defaultExclude, $exclude);

        $files = [];
        $totalSize = 0;

        $normalizedDirPath = rtrim((string) realpath($dirPath), '/');

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dirPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            $realPath = $file->getRealPath();
            if ($realPath === false) {
                continue;
            }

            $relativePath = ltrim(substr($realPath, strlen($normalizedDirPath)), '/');

            if (self::shouldExclude($relativePath, $allExclude)) {
                continue;
            }

            $size = $file->getSize();
            $sha256 = hash_file('sha256', $realPath);
            if ($sha256 === false) {
                throw new RuntimeException("Cannot compute sha256 for: {$realPath}");
            }

            $files[] = [
                'path' => $relativePath,
                'sha256' => $sha256,
                'size' => $size,
            ];
            $totalSize += $size;
        }

        // Sort for deterministic output
        usort($files, fn (array $a, array $b) => strcmp($a['path'], $b['path']));

        return new self(
            releaseId: $releaseId,
            gitSha: $gitSha,
            generatedAt: new DateTimeImmutable('now', new \DateTimeZone('UTC')),
            phpVersionTarget: $phpVersionTarget,
            strategy: $strategy,
            totalSize: $totalSize,
            files: $files,
        );
    }

    /**
     * Serialize to JSON string.
     */
    public function toJson(): string
    {
        $data = [
            'version' => 2,
            'release_id' => $this->releaseId,
            'git_sha' => $this->gitSha,
            'built_at' => $this->generatedAt->format(DateTimeInterface::ATOM),
            'php_version_target' => $this->phpVersionTarget,
            'strategy' => $this->strategy,
            'total_size' => $this->totalSize,
            'files' => $this->files,
        ];

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            throw new RuntimeException('Failed to encode manifest to JSON');
        }

        return $json;
    }

    /**
     * Compute diff between this manifest (local) and another (remote).
     *
     * @return array{added: array<string>, removed: array<string>, modified: array<string>}
     */
    public function diff(ReleaseManifest $other): array
    {
        $localIndex = [];
        foreach ($this->files as $f) {
            $localIndex[$f['path']] = $f['sha256'];
        }

        $remoteIndex = [];
        foreach ($other->files as $f) {
            $remoteIndex[$f['path']] = $f['sha256'];
        }

        $added = array_keys(array_diff_key($localIndex, $remoteIndex));
        $removed = array_keys(array_diff_key($remoteIndex, $localIndex));

        $modified = [];
        foreach ($localIndex as $path => $sha) {
            if (isset($remoteIndex[$path]) && $remoteIndex[$path] !== $sha) {
                $modified[] = $path;
            }
        }

        return [
            'added' => $added,
            'removed' => $removed,
            'modified' => $modified,
        ];
    }

    /**
     * Build from JSON string.
     *
     * @throws RuntimeException
     */
    public static function fromJson(string $json): self
    {
        /** @var array<string, mixed>|null $data */
        $data = json_decode($json, true);
        if (!is_array($data)) {
            throw new RuntimeException('Invalid manifest JSON');
        }

        $generatedAt = new DateTimeImmutable(
            (string) ($data['built_at'] ?? 'now'),
            new \DateTimeZone('UTC'),
        );

        /** @var array<array{path: string, sha256: string, size: int}> $files */
        $files = is_array($data['files'] ?? null) ? $data['files'] : [];

        return new self(
            releaseId: (string) ($data['release_id'] ?? ''),
            gitSha: isset($data['git_sha']) ? (string) $data['git_sha'] : null,
            generatedAt: $generatedAt,
            phpVersionTarget: isset($data['php_version_target']) ? (string) $data['php_version_target'] : null,
            strategy: (string) ($data['strategy'] ?? 'lean'),
            totalSize: (int) ($data['total_size'] ?? 0),
            files: $files,
        );
    }

    /**
     * @param array<string> $exclude
     */
    private static function shouldExclude(string $path, array $exclude): bool
    {
        foreach ($exclude as $pattern) {
            if ($pattern === $path || str_starts_with($path, rtrim($pattern, '/') . '/')) {
                return true;
            }
            if (str_contains($pattern, '*') && preg_match('#' . str_replace('*', '.*', $pattern) . '#', $path)) {
                return true;
            }
        }

        return false;
    }
}
