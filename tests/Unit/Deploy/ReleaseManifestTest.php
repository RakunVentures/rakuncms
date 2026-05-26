<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\ArtifactBuilder;
use Rkn\Cms\Deploy\ReleaseManifest;

describe('ReleaseManifest', function () {

    beforeEach(function () {
        $this->tmpDir = sys_get_temp_dir() . '/rakun-manifest-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        mkdir("{$this->tmpDir}/subdir", 0755, true);

        file_put_contents("{$this->tmpDir}/index.php", '<?php echo "hello";');
        file_put_contents("{$this->tmpDir}/subdir/page.php", '<?php echo "page";');
    });

    afterEach(function () {
        $cleanup = function (string $dir) use (&$cleanup): void {
            foreach (scandir($dir) as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = "{$dir}/{$item}";
                is_dir($path) ? $cleanup($path) : unlink($path);
            }
            rmdir($dir);
        };
        if (is_dir($this->tmpDir)) {
            $cleanup($this->tmpDir);
        }
    });

    it('fromDirectory builds a manifest with correct sha256 per file', function () {
        $manifest = ReleaseManifest::fromDirectory($this->tmpDir, 'release-abc', 'deadbeef', '8.2', 'lean');

        expect($manifest->releaseId)->toBe('release-abc')
            ->and($manifest->gitSha)->toBe('deadbeef')
            ->and($manifest->phpVersionTarget)->toBe('8.2')
            ->and($manifest->strategy)->toBe('lean')
            ->and($manifest->files)->toBeArray()
            ->and(count($manifest->files))->toBeGreaterThan(0);

        foreach ($manifest->files as $entry) {
            $fullPath = "{$this->tmpDir}/{$entry['path']}";
            $actual = hash_file('sha256', $fullPath);
            expect($actual)->toBe($entry['sha256']);
        }
    });

    it('fromZip reads manifest.json from inside the ZIP', function () {
        $builder = new ArtifactBuilder($this->tmpDir);
        $zipPath = $builder->build('release-zip-test', [], 'abc123', '8.2', 'lean');

        $manifest = ReleaseManifest::fromZip($zipPath);

        expect($manifest->releaseId)->toBe('release-zip-test')
            ->and($manifest->gitSha)->toBe('abc123')
            ->and($manifest->files)->toBeArray()
            ->and(count($manifest->files))->toBeGreaterThan(0);

        unlink($zipPath);
    });

    it('fromZip throws RuntimeException if manifest.json is absent', function () {
        // Create a ZIP without manifest.json
        $zipPath = sys_get_temp_dir() . '/no-manifest-' . uniqid() . '.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);
        $zip->addFromString('some-file.txt', 'hello');
        $zip->close();

        expect(fn () => ReleaseManifest::fromZip($zipPath))->toThrow(RuntimeException::class);

        unlink($zipPath);
    });

    it('toJson → fromJson round-trip preserves all fields', function () {
        $original = ReleaseManifest::fromDirectory($this->tmpDir, 'round-trip-001', 'git123', '8.2', 'fat');
        $json = $original->toJson();

        $restored = ReleaseManifest::fromJson($json);

        expect($restored->releaseId)->toBe($original->releaseId)
            ->and($restored->gitSha)->toBe($original->gitSha)
            ->and($restored->strategy)->toBe($original->strategy)
            ->and($restored->totalSize)->toBe($original->totalSize)
            ->and(count($restored->files))->toBe(count($original->files));

        foreach ($original->files as $i => $entry) {
            expect($restored->files[$i]['path'])->toBe($entry['path'])
                ->and($restored->files[$i]['sha256'])->toBe($entry['sha256'])
                ->and($restored->files[$i]['size'])->toBe($entry['size']);
        }
    });

    it('diff detects added files', function () {
        $local = ReleaseManifest::fromDirectory($this->tmpDir, 'local-001', null, null, 'lean');

        // Remote is missing subdir/page.php
        $remoteFiles = array_filter(
            $local->files,
            fn (array $f) => $f['path'] !== 'subdir/page.php'
        );

        $remote = new ReleaseManifest(
            releaseId: 'remote-001',
            gitSha: null,
            generatedAt: new DateTimeImmutable(),
            phpVersionTarget: null,
            strategy: 'lean',
            totalSize: 0,
            files: array_values($remoteFiles),
        );

        $diff = $local->diff($remote);

        expect($diff['added'])->toContain('subdir/page.php')
            ->and($diff['removed'])->toBeEmpty()
            ->and($diff['modified'])->toBeEmpty();
    });

    it('diff detects removed files', function () {
        $local = ReleaseManifest::fromDirectory($this->tmpDir, 'local-002', null, null, 'lean');

        // Remote has an extra file not in local
        $remoteFiles = $local->files;
        $remoteFiles[] = ['path' => 'extra-old-file.php', 'sha256' => 'abc123', 'size' => 10];

        $remote = new ReleaseManifest(
            releaseId: 'remote-002',
            gitSha: null,
            generatedAt: new DateTimeImmutable(),
            phpVersionTarget: null,
            strategy: 'lean',
            totalSize: 0,
            files: $remoteFiles,
        );

        $diff = $local->diff($remote);

        expect($diff['removed'])->toContain('extra-old-file.php')
            ->and($diff['added'])->toBeEmpty();
    });

    it('diff detects modified files', function () {
        $local = ReleaseManifest::fromDirectory($this->tmpDir, 'local-003', null, null, 'lean');

        // Tamper sha256 of one file in remote
        $remoteFiles = $local->files;
        $remoteFiles[0]['sha256'] = 'aaaaaaaaaa';

        $remote = new ReleaseManifest(
            releaseId: 'remote-003',
            gitSha: null,
            generatedAt: new DateTimeImmutable(),
            phpVersionTarget: null,
            strategy: 'lean',
            totalSize: 0,
            files: $remoteFiles,
        );

        $diff = $local->diff($remote);

        expect($diff['modified'])->toContain($local->files[0]['path'])
            ->and($diff['added'])->toBeEmpty()
            ->and($diff['removed'])->toBeEmpty();
    });

    it('diff returns empty arrays for identical manifests', function () {
        $manifest = ReleaseManifest::fromDirectory($this->tmpDir, 'same-001', null, null, 'lean');
        $diff = $manifest->diff($manifest);

        expect($diff['added'])->toBeEmpty()
            ->and($diff['removed'])->toBeEmpty()
            ->and($diff['modified'])->toBeEmpty();
    });

});
