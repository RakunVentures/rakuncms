<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\Drivers\GitDriver;
use Symfony\Component\Console\Command\Command;

/**
 * Build a DeployConfig for Git lean strategy using provided overrides.
 *
 * @param array<string, mixed> $overrides
 */
function makeLeanConfig(array $overrides = []): DeployConfig
{
    $config = new DeployConfig();
    $config->environment = $overrides['environment'] ?? 'production';
    $config->method = 'git';
    $config->strategy = $overrides['strategy'] ?? 'lean';
    $config->host = 'example.com';
    $config->domain = 'example.com';
    $config->path = '/httpdocs';
    $config->remote = $overrides['remote'] ?? 'local-remote';
    $config->sourceBranch = $overrides['sourceBranch'] ?? 'main';
    $config->targetBranch = $overrides['targetBranch'] ?? 'main';
    $config->allowDirty = $overrides['allowDirty'] ?? false;
    $config->verifySsl = true;
    return $config;
}

/**
 * Create a temporary bare git repo (acts as remote) and return its path.
 */
function createBareRemote(string $base): string
{
    $barePath = "{$base}/remote.git";
    mkdir($barePath, 0755, true);
    exec("git init --bare " . escapeshellarg($barePath) . " 2>&1", $out, $code);
    if ($code !== 0) {
        throw new \RuntimeException("Could not init bare remote: " . implode("\n", $out));
    }
    // Set default branch to 'main' so git log works after first push
    exec("git -C " . escapeshellarg($barePath) . " symbolic-ref HEAD refs/heads/main 2>&1");
    return $barePath;
}

/**
 * Create a working git repo with at least one commit, add the bare as remote.
 * Returns the working repo path.
 */
function createWorkingRepo(string $base, string $remotePath, string $remoteName = 'local-remote'): string
{
    $repoPath = "{$base}/working";
    mkdir($repoPath, 0755, true);

    $cmds = [
        "git -C {$repoPath} init",
        "git -C {$repoPath} config user.email 'test@rakun.test'",
        "git -C {$repoPath} config user.name 'Test'",
        "git -C {$repoPath} checkout -b main",
        "echo 'initial' > {$repoPath}/README.md",
        "git -C {$repoPath} add .",
        "git -C {$repoPath} commit -m 'initial commit'",
        "git -C {$repoPath} remote add {$remoteName} {$remotePath}",
    ];

    foreach ($cmds as $cmd) {
        exec($cmd . " 2>&1", $out, $code);
        if ($code !== 0) {
            throw new \RuntimeException("Failed: {$cmd} → " . implode("\n", $out));
        }
    }

    return $repoPath;
}

/**
 * Create and clean up a temp directory for a test.
 */
function withTempDir(callable $test): void
{
    $tmpDir = sys_get_temp_dir() . '/rakun-git-lean-' . uniqid('', true);
    mkdir($tmpDir, 0755, true);
    try {
        $test($tmpDir);
    } finally {
        exec("rm -rf " . escapeshellarg($tmpDir));
    }
}

$noop = function (string $msg): void {};


describe('GitDriver::validate()', function () use ($noop): void {
    it('returns error when .git directory does not exist', function () use ($noop): void {
        $tmpDir = sys_get_temp_dir() . '/rakun-nogt-' . uniqid('', true);
        mkdir($tmpDir, 0755, true);
        try {
            $driver = new GitDriver($tmpDir);
            $config = makeLeanConfig();
            $errors = $driver->validate($config, $noop);
            expect($errors)->not->toBeEmpty();
            expect(implode("\n", $errors))->toContain('.git');
        } finally {
            exec("rm -rf {$tmpDir}");
        }
    });

    it('returns error when remote does not exist', function () use ($noop): void {
        withTempDir(function (string $tmpDir) use ($noop): void {
            $barePath = createBareRemote($tmpDir);
            $repoPath = createWorkingRepo($tmpDir, $barePath, 'local-remote');

            $driver = new GitDriver($repoPath);
            $config = makeLeanConfig(['remote' => 'nonexistent-remote']);
            $errors = $driver->validate($config, $noop);

            expect($errors)->not->toBeEmpty();
            expect(implode("\n", $errors))->toContain('nonexistent-remote');
        });
    });

    it('returns error when source branch does not exist', function () use ($noop): void {
        withTempDir(function (string $tmpDir) use ($noop): void {
            $barePath = createBareRemote($tmpDir);
            $repoPath = createWorkingRepo($tmpDir, $barePath);

            $driver = new GitDriver($repoPath);
            $config = makeLeanConfig(['sourceBranch' => 'nonexistent-branch']);
            $errors = $driver->validate($config, $noop);

            expect($errors)->not->toBeEmpty();
            expect(implode("\n", $errors))->toContain('nonexistent-branch');
        });
    });

    it('returns error when working directory is dirty', function () use ($noop): void {
        withTempDir(function (string $tmpDir) use ($noop): void {
            $barePath = createBareRemote($tmpDir);
            $repoPath = createWorkingRepo($tmpDir, $barePath);

            // Create an uncommitted file
            file_put_contents("{$repoPath}/dirty-file.txt", 'dirty');
            exec("git -C {$repoPath} add dirty-file.txt");

            $driver = new GitDriver($repoPath);
            $config = makeLeanConfig();
            $errors = $driver->validate($config, $noop);

            expect($errors)->not->toBeEmpty();
            expect(implode("\n", $errors))->toContain('not clean');
        });
    });

    it('returns empty array (no errors) for a clean valid repo', function () use ($noop): void {
        withTempDir(function (string $tmpDir) use ($noop): void {
            $barePath = createBareRemote($tmpDir);
            $repoPath = createWorkingRepo($tmpDir, $barePath);

            $driver = new GitDriver($repoPath);
            $config = makeLeanConfig();
            $errors = $driver->validate($config, $noop);

            expect($errors)->toBeEmpty();
        });
    });

    it('bypasses dirty check when allowDirty is true', function () use ($noop): void {
        withTempDir(function (string $tmpDir) use ($noop): void {
            $barePath = createBareRemote($tmpDir);
            $repoPath = createWorkingRepo($tmpDir, $barePath);

            // Dirty working dir
            file_put_contents("{$repoPath}/dirty.txt", 'dirty');
            exec("git -C {$repoPath} add dirty.txt");

            $driver = new GitDriver($repoPath);
            $config = makeLeanConfig(['allowDirty' => true]);
            $errors = $driver->validate($config, $noop);

            // Should pass validation (dirty check bypassed)
            expect($errors)->toBeEmpty();
        });
    });
});

describe('GitDriver::deployLean()', function () use ($noop): void {
    it('pushes commits to the remote and returns true', function () use ($noop): void {
        withTempDir(function (string $tmpDir) use ($noop): void {
            $barePath = createBareRemote($tmpDir);
            $repoPath = createWorkingRepo($tmpDir, $barePath);

            $driver = new GitDriver($repoPath);
            $config = makeLeanConfig();

            $result = $driver->deploy($config, $noop);

            expect($result)->toBeTrue();

            // Verify the remote received the commit (use show-ref which works even on fresh bare repos)
            exec("git -C " . escapeshellarg($barePath) . " show-ref --heads 2>&1", $ref, $code);
            expect($code)->toBe(0);
            // refs/heads/main should exist in the bare repo after push
            expect(implode("\n", $ref))->toContain('refs/heads/main');
        });
    });

    it('returns false and logs error message when push is rejected (non-fast-forward)', function (): void {
        withTempDir(function (string $tmpDir): void {
            $barePath = createBareRemote($tmpDir);
            $repoPath = createWorkingRepo($tmpDir, $barePath);

            // First push: establish remote history
            exec("git -C " . escapeshellarg($repoPath) . " push local-remote main 2>&1");

            // Create a divergent commit on the remote via a second clone
            $clone2Path = "{$tmpDir}/clone2";
            exec("git clone " . escapeshellarg($barePath) . " " . escapeshellarg($clone2Path) . " 2>&1");
            exec("git -C " . escapeshellarg($clone2Path) . " config user.email 'x@x.com'");
            exec("git -C " . escapeshellarg($clone2Path) . " config user.name 'X'");
            file_put_contents("{$clone2Path}/diverge.txt", 'diverge');
            exec("git -C " . escapeshellarg($clone2Path) . " add diverge.txt");
            exec("git -C " . escapeshellarg($clone2Path) . " commit -m 'divergent commit' 2>&1");
            exec("git -C " . escapeshellarg($clone2Path) . " push origin main 2>&1");

            // Create a local commit that doesn't include the divergent remote commit
            file_put_contents("{$repoPath}/local.txt", 'local');
            exec("git -C " . escapeshellarg($repoPath) . " add local.txt");
            exec("git -C " . escapeshellarg($repoPath) . " commit -m 'local commit' 2>&1");

            // Now a non-force push from repoPath will be rejected (non-fast-forward)
            $logs = [];
            $logger = function (string $msg) use (&$logs): void {
                $logs[] = $msg;
            };

            $driver = new GitDriver($repoPath);
            $config = makeLeanConfig();
            $result = $driver->deploy($config, $logger);

            expect($result)->toBeFalse();
            $logOutput = implode("\n", $logs);
            // Should contain a human-friendly message about the push failure
            expect($logOutput)->toContain('Push failed');
        });
    });

    it('rollback returns true with a no-op message for lean strategy', function (): void {
        withTempDir(function (string $tmpDir): void {
            $barePath = createBareRemote($tmpDir);
            $repoPath = createWorkingRepo($tmpDir, $barePath);

            $logs = [];
            $logger = function (string $msg) use (&$logs): void {
                $logs[] = $msg;
            };

            $driver = new GitDriver($repoPath);
            $config = makeLeanConfig();
            $result = $driver->rollback($config, $logger);

            expect($result)->toBeTrue();
            expect(implode("\n", $logs))->toContain('rollback');
        });
    });
});
