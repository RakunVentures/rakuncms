<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\ArtifactBuilder;
use Rkn\Cms\Deploy\ReleaseManifest;

describe('ArtifactBuilder — manifest + HMAC', function () {

    beforeEach(function () {
        // Create a temp dir with sample files
        $this->tmpSrc = sys_get_temp_dir() . '/rakun-test-src-' . uniqid();
        mkdir($this->tmpSrc, 0755, true);
        mkdir("{$this->tmpSrc}/subdir", 0755, true);

        file_put_contents("{$this->tmpSrc}/index.php", '<?php echo "hello";');
        file_put_contents("{$this->tmpSrc}/subdir/page.php", '<?php echo "page";');
        file_put_contents("{$this->tmpSrc}/.DS_Store", 'ignored');
        mkdir("{$this->tmpSrc}/.git", 0755, true);
        file_put_contents("{$this->tmpSrc}/.git/HEAD", 'ref: refs/heads/main');
    });

    afterEach(function () {
        // Cleanup
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
        if (is_dir($this->tmpSrc)) {
            $cleanup($this->tmpSrc);
        }
    });

    it('builds a ZIP that contains manifest.json', function () {
        $builder = new ArtifactBuilder($this->tmpSrc);
        $zipPath = $builder->build('test-release-001', [], null, '8.2', 'lean');

        expect($zipPath)->toBeString()
            ->and(file_exists($zipPath))->toBeTrue();

        $zip = new ZipArchive();
        expect($zip->open($zipPath))->toBe(true);

        $manifestJson = $zip->getFromName('manifest.json');
        $zip->close();

        expect($manifestJson)->not->toBeFalse();

        $data = json_decode((string) $manifestJson, true);
        expect($data)->toBeArray()
            ->and($data['version'])->toBe(2)
            ->and($data['release_id'])->toBe('test-release-001')
            ->and($data['strategy'])->toBe('lean')
            ->and($data['files'])->toBeArray();

        unlink($zipPath);
    });

    it('manifest sha256 for each file matches actual extracted content', function () {
        $builder = new ArtifactBuilder($this->tmpSrc);
        $zipPath = $builder->build('test-release-002');

        // Extract to temp dir
        $extractDir = sys_get_temp_dir() . '/rakun-test-extract-' . uniqid();
        mkdir($extractDir, 0755, true);

        $zip = new ZipArchive();
        $zip->open($zipPath);
        $zip->extractTo($extractDir);
        $zip->close();

        // Parse manifest from the extracted dir
        $manifestJson = file_get_contents("{$extractDir}/manifest.json");
        expect($manifestJson)->not->toBeFalse();

        $data = json_decode((string) $manifestJson, true);
        expect($data['files'])->toBeArray();

        foreach ($data['files'] as $entry) {
            $extractedFile = "{$extractDir}/{$entry['path']}";
            expect(file_exists($extractedFile))->toBeTrue(
                "File {$entry['path']} listed in manifest but not found after extraction"
            );

            $actualSha256 = hash_file('sha256', $extractedFile);
            expect($actualSha256)->toBe($entry['sha256'],
                "sha256 mismatch for {$entry['path']}: manifest says {$entry['sha256']}, actual is {$actualSha256}"
            );
        }

        unlink($zipPath);

        // Cleanup extract dir
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
        $cleanup($extractDir);
    });

    it('excludes .git and .DS_Store from the artifact and manifest', function () {
        $builder = new ArtifactBuilder($this->tmpSrc);
        $zipPath = $builder->build('test-release-003');

        $zip = new ZipArchive();
        $zip->open($zipPath);
        $manifestJson = (string) $zip->getFromName('manifest.json');

        // Check no .git or .DS_Store entries in zip
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();

        $gitFiles = array_filter($names, fn (string $n) => str_starts_with($n, '.git'));
        $dsFiles = array_filter($names, fn (string $n) => str_contains($n, '.DS_Store'));

        expect($gitFiles)->toBeEmpty()
            ->and($dsFiles)->toBeEmpty();

        // Manifest also must not contain them
        $data = json_decode($manifestJson, true);
        $manifestPaths = array_column($data['files'], 'path');
        foreach ($manifestPaths as $p) {
            expect(str_starts_with($p, '.git'))->toBeFalse();
            expect(str_contains($p, '.DS_Store'))->toBeFalse();
        }

        unlink($zipPath);
    });

    it('writes a parallel .hmac file when deploySecret is provided', function () {
        $builder = new ArtifactBuilder($this->tmpSrc);
        $secret = 'super-secret-key-123';
        $zipPath = $builder->build('test-release-004', [], null, null, 'lean', $secret);
        $hmacPath = "{$zipPath}.hmac";

        expect(file_exists($hmacPath))->toBeTrue();

        $hmacValue = trim((string) file_get_contents($hmacPath));
        $expectedHmac = hash_hmac('sha256', (string) file_get_contents($zipPath), $secret);

        expect($hmacValue)->toBe($expectedHmac);

        unlink($zipPath);
        unlink($hmacPath);
    });

    it('does NOT write a .hmac file when deploySecret is null', function () {
        $builder = new ArtifactBuilder($this->tmpSrc);
        $zipPath = $builder->build('test-release-005');
        $hmacPath = "{$zipPath}.hmac";

        expect(file_exists($hmacPath))->toBeFalse();

        unlink($zipPath);
    });

    it('manifest includes git_sha and php_version_target when provided', function () {
        $builder = new ArtifactBuilder($this->tmpSrc);
        $zipPath = $builder->build('test-release-006', [], 'abc1234', '8.2', 'fat');

        $zip = new ZipArchive();
        $zip->open($zipPath);
        $manifestJson = (string) $zip->getFromName('manifest.json');
        $zip->close();

        $data = json_decode($manifestJson, true);
        expect($data['git_sha'])->toBe('abc1234')
            ->and($data['php_version_target'])->toBe('8.2')
            ->and($data['strategy'])->toBe('fat');

        unlink($zipPath);
    });

});
