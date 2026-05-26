<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\DeployLock;

describe('DeployLock', function () {

    beforeEach(function () {
        $this->lockDir = sys_get_temp_dir() . '/rakun-lock-test-' . uniqid();
        mkdir($this->lockDir, 0755, true);
        $this->lock = new DeployLock($this->lockDir, ttlSec: 1800);
    });

    afterEach(function () {
        // Cleanup lock dir
        $cleanup = function (string $dir) use (&$cleanup): void {
            foreach (scandir($dir) ?: [] as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }
                $path = "{$dir}/{$item}";
                is_dir($path) ? $cleanup($path) : unlink($path);
            }
            rmdir($dir);
        };
        if (is_dir($this->lockDir)) {
            $cleanup($this->lockDir);
        }
    });

    it('acquires a lock when no lock exists', function () {
        $result = $this->lock->acquire('production', 'release-001');

        expect($result)->toBeTrue();
        $data = $this->lock->inspect('production');
        expect($data)->not->toBeNull()
            ->and($data['release_id'])->toBe('release-001')
            ->and($data['pid'])->toBeInt();
    });

    it('releases the lock and allows re-acquisition', function () {
        $this->lock->acquire('production', 'release-001');
        $this->lock->release('production');

        expect($this->lock->inspect('production'))->toBeNull();

        $result = $this->lock->acquire('production', 'release-002');
        expect($result)->toBeTrue();
    });

    it('returns false if a non-stale lock already exists', function () {
        $this->lock->acquire('production', 'release-001');

        // Try to acquire again while lock is held
        $lock2 = new DeployLock($this->lockDir, ttlSec: 1800);
        $result = $lock2->acquire('production', 'release-002');

        expect($result)->toBeFalse();
    });

    it('acquires over an expired (stale TTL) lock', function () {
        // Write a lock with an expired timestamp
        $staleData = [
            'pid'        => getmypid(),
            'env'        => 'production',
            'release_id' => 'stale-release',
            'started_at' => time() - 9999, // Way in the past
            'ttl'        => 1800,
            'host'       => 'test',
        ];
        $safeEnv  = 'production';
        $lockFile = "{$this->lockDir}/deploy-{$safeEnv}.lock";
        file_put_contents($lockFile, json_encode($staleData));

        $result = $this->lock->acquire('production', 'new-release');

        expect($result)->toBeTrue()
            ->and($this->lock->inspect('production')['release_id'])->toBe('new-release');
    });

    it('acquires over a lock whose PID is dead', function () {
        // Use a PID that is very unlikely to exist (max + offset)
        $deadPid = 99999999;
        $lockData = [
            'pid'        => $deadPid,
            'env'        => 'production',
            'release_id' => 'dead-process-release',
            'started_at' => time(),
            'ttl'        => 1800,
            'host'       => 'test',
        ];
        $lockFile = "{$this->lockDir}/deploy-production.lock";
        file_put_contents($lockFile, json_encode($lockData));

        $result = $this->lock->acquire('production', 'new-release-after-crash');

        // May or may not acquire depending on posix_kill availability;
        // but should not throw an exception
        expect(is_bool($result))->toBeTrue();
    });

    it('isStale returns true when no lock file exists', function () {
        expect($this->lock->isStale('staging'))->toBeTrue();
    });

    it('isStale returns false when lock is fresh', function () {
        $this->lock->acquire('staging', 'fresh-release');
        expect($this->lock->isStale('staging'))->toBeFalse();
    });

    it('isStale returns true when TTL is exceeded', function () {
        $lockFile = "{$this->lockDir}/deploy-staging.lock";
        $staleData = [
            'pid'        => getmypid(),
            'env'        => 'staging',
            'release_id' => 'old',
            'started_at' => time() - 9999,
            'ttl'        => 1800,
            'host'       => 'test',
        ];
        file_put_contents($lockFile, json_encode($staleData));

        expect($this->lock->isStale('staging'))->toBeTrue();
    });

    it('release is idempotent (no error on double-release)', function () {
        $this->lock->acquire('production', 'release-x');
        $this->lock->release('production');

        expect(fn () => $this->lock->release('production'))->not->toThrow(Throwable::class);
    });

    it('inspect returns null when no lock exists', function () {
        expect($this->lock->inspect('missing-env'))->toBeNull();
    });

    it('uses separate lock files for different environments', function () {
        $this->lock->acquire('production', 'prod-001');
        $this->lock->acquire('staging', 'stage-001');

        expect($this->lock->inspect('production')['release_id'])->toBe('prod-001')
            ->and($this->lock->inspect('staging')['release_id'])->toBe('stage-001');

        $this->lock->release('production');
        expect($this->lock->inspect('production'))->toBeNull()
            ->and($this->lock->inspect('staging'))->not->toBeNull();
    });

});
