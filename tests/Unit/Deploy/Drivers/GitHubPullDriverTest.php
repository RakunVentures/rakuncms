<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\Drivers\GitHubPullDriver;
use Symfony\Component\Yaml\Yaml;

/**
 * Build a DeployConfig pre-populated for github-pull, then let the caller mutate
 * specific fields to provoke the desired validate() failure path.
 *
 * @param array<string, mixed> $extraEnvOverrides Merged into the 'production' yaml block before loading.
 */
function makeGithubPullConfig(string $basePath, array $extraEnvOverrides = []): DeployConfig
{
    $config = [
        'production' => array_merge([
            'method' => 'github-pull',
            'strategy' => 'lean',
            'domain' => 'example.com',
            'path' => '/httpdocs',
            'source_branch' => 'main',
            'target_branch' => 'main',
            'remote' => 'origin',
            'verify_ssl' => true,
            'github' => [
                'owner' => 'octocat',
                'repo' => 'Hello-World',
                'token' => 'ghp_dummy',
            ],
            'plesk' => [
                'host' => 'https://plesk.test:8443',
                'api_key' => 'dummy-plesk-key',
                'verify_ssl' => false,
                'repo_name' => 'rakuncms-pull',
            ],
        ], $extraEnvOverrides),
    ];

    @mkdir("{$basePath}/config", 0755, true);
    file_put_contents("{$basePath}/config/deploy.yaml", Yaml::dump($config, 6, 2));

    return DeployConfig::load($basePath, 'production');
}

/**
 * Spin up a fresh empty directory; returned path is the caller's responsibility to clean.
 */
function makeTempBasePath(string $suffix = ''): string
{
    $dir = sys_get_temp_dir() . "/rakun-ghp-{$suffix}-" . uniqid('', true);
    mkdir($dir, 0755, true);
    return $dir;
}

function cleanupTempPath(string $path): void
{
    exec('rm -rf ' . escapeshellarg($path));
}

function initGitRepo(string $path, ?string $originUrl = null): void
{
    exec('git -C ' . escapeshellarg($path) . ' init -q 2>&1');
    exec('git -C ' . escapeshellarg($path) . ' config user.email test@rakun.test 2>&1');
    exec('git -C ' . escapeshellarg($path) . ' config user.name Test 2>&1');
    if ($originUrl !== null) {
        exec('git -C ' . escapeshellarg($path) . ' remote add origin ' . escapeshellarg($originUrl) . ' 2>&1');
    }
}

function commitInitialFile(string $path, string $branch = 'main'): void
{
    file_put_contents("{$path}/README.md", 'rakuncms test');
    exec('git -C ' . escapeshellarg($path) . ' checkout -q -b ' . escapeshellarg($branch) . ' 2>&1');
    exec('git -C ' . escapeshellarg($path) . ' add README.md 2>&1');
    exec('git -C ' . escapeshellarg($path) . " commit -q -m 'initial' 2>&1");
}

describe('GitHubPullDriver::validate() — early returns (no HTTP calls reached)', function (): void {
    it('returns single error when .git directory is missing', function (): void {
        $path = makeTempBasePath('nogit');
        try {
            $config = makeGithubPullConfig($path);
            $driver = new GitHubPullDriver($path);
            $errors = $driver->validate($config, fn () => null);

            expect($errors)->toHaveCount(1);
            expect($errors[0])->toContain('No git repository');
            expect($errors[0])->toContain("git init");
        } finally {
            cleanupTempPath($path);
        }
    });

    it('reports missing remote when remote does not exist', function (): void {
        $path = makeTempBasePath('noremote');
        try {
            initGitRepo($path); // no origin
            commitInitialFile($path, 'main');
            $config = makeGithubPullConfig($path);
            $driver = new GitHubPullDriver($path);
            $errors = $driver->validate($config, fn () => null);

            $joined = implode("\n", $errors);
            expect($joined)->toContain("Git remote 'origin' does not exist");
            expect($joined)->toContain('rakun deploy:setup-github');
        } finally {
            cleanupTempPath($path);
        }
    });

    it('reports non-GitHub remote', function (): void {
        $path = makeTempBasePath('non-gh');
        try {
            initGitRepo($path, 'git@gitlab.example.com:o/r.git');
            commitInitialFile($path, 'main');
            $config = makeGithubPullConfig($path);
            $driver = new GitHubPullDriver($path);
            $errors = $driver->validate($config, fn () => null);

            $joined = implode("\n", $errors);
            expect($joined)->toContain("does not point to GitHub");
        } finally {
            cleanupTempPath($path);
        }
    });

    it('reports missing source branch when branch does not exist locally', function (): void {
        $path = makeTempBasePath('nobranch');
        try {
            initGitRepo($path, 'git@github.com:octocat/Hello-World.git');
            commitInitialFile($path, 'master'); // not 'main'
            $config = makeGithubPullConfig($path, ['source_branch' => 'ghost-branch']);
            $driver = new GitHubPullDriver($path);
            $errors = $driver->validate($config, fn () => null);

            $joined = implode("\n", $errors);
            expect($joined)->toContain("Source branch 'ghost-branch' does not exist locally");
        } finally {
            cleanupTempPath($path);
        }
    });

    it('reports dirty tree when working dir has uncommitted changes', function (): void {
        $path = makeTempBasePath('dirty');
        try {
            initGitRepo($path, 'git@github.com:octocat/Hello-World.git');
            commitInitialFile($path, 'main');
            file_put_contents("{$path}/dirty.txt", 'untracked');
            $config = makeGithubPullConfig($path);
            $driver = new GitHubPullDriver($path);
            $errors = $driver->validate($config, fn () => null);

            $joined = implode("\n", $errors);
            expect($joined)->toContain('not clean');
            expect($joined)->toContain('--allow-dirty');
        } finally {
            cleanupTempPath($path);
        }
    });

    it('skips dirty check when allow_dirty=true', function (): void {
        $path = makeTempBasePath('allowdirty');
        try {
            initGitRepo($path, 'git@github.com:octocat/Hello-World.git');
            commitInitialFile($path, 'main');
            file_put_contents("{$path}/scratch.txt", 'untracked');
            $config = makeGithubPullConfig($path);
            $config->allowDirty = true;
            $driver = new GitHubPullDriver($path);
            $errors = $driver->validate($config, fn () => null);

            $joined = implode("\n", $errors);
            // dirty error must NOT be in the list
            expect($joined)->not->toContain('not clean');
        } finally {
            cleanupTempPath($path);
        }
    });

    it('reports every missing required deploy.yaml field individually', function (): void {
        $path = makeTempBasePath('missing');
        try {
            initGitRepo($path, 'git@github.com:octocat/Hello-World.git');
            commitInitialFile($path, 'main');

            $config = makeGithubPullConfig($path);
            // Blank out all the required-by-driver fields.
            $config->githubOwner = null;
            $config->githubRepo = null;
            $config->githubToken = '';
            $config->pleskHost = '';
            $config->pleskApiKey = null;
            $config->pleskRepoName = null;
            $config->domain = '';

            $driver = new GitHubPullDriver($path);
            $errors = $driver->validate($config, fn () => null);

            $joined = implode("\n", $errors);
            foreach ([
                "'github.owner'",
                "'github.repo'",
                "'github.token'",
                "'plesk.host'",
                "'plesk.api_key'",
                "'plesk.repo_name'",
                "'domain'",
            ] as $needle) {
                expect($joined)->toContain($needle);
            }
        } finally {
            cleanupTempPath($path);
        }
    });

    it('stops before reaching HTTP calls when missing-field errors are present', function (): void {
        // Confirms validate() short-circuits the GitHub/Plesk API calls when fields are missing.
        // If it did NOT short-circuit, the test would fail on `cURL Error: Could not resolve host`
        // because the dummy token + URLs cannot reach a real GitHub.
        $path = makeTempBasePath('shortcircuit');
        try {
            initGitRepo($path, 'git@github.com:octocat/Hello-World.git');
            commitInitialFile($path, 'main');
            $config = makeGithubPullConfig($path);
            $config->githubOwner = ''; // force missing-field path

            $driver = new GitHubPullDriver($path);
            $errors = $driver->validate($config, fn () => null);

            // Errors must be entirely missing-field; no GitHub/Plesk transport messages.
            $joined = implode("\n", $errors);
            expect($joined)->toContain("'github.owner'");
            expect($joined)->not->toContain('cURL');
            expect($joined)->not->toContain('Could not resolve');
        } finally {
            cleanupTempPath($path);
        }
    });
});

describe('GitHubPullDriver::rollback()', function (): void {
    it('emits an informational message and returns true (informational no-op)', function (): void {
        $path = makeTempBasePath('rollback');
        try {
            initGitRepo($path);
            $config = makeGithubPullConfig($path);

            $captured = [];
            $logger = function (string $m) use (&$captured): void { $captured[] = $m; };

            $driver = new GitHubPullDriver($path);
            $ok = $driver->rollback($config, $logger);

            expect($ok)->toBeTrue();
            $joined = implode("\n", $captured);
            expect($joined)->toContain('rollback');
            expect($joined)->toContain('git revert');
            expect($joined)->toContain('production');
        } finally {
            cleanupTempPath($path);
        }
    });
});
