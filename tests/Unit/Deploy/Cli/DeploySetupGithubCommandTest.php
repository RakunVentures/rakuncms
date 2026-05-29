<?php

declare(strict_types=1);

use Rkn\Cms\Cli\DeploySetupGithubCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

function makeSetupGithubApp(): Application
{
    $app = new Application('rakun-test', '0.0.1');
    $app->setAutoExit(false);
    $app->addCommand(new DeploySetupGithubCommand());
    return $app;
}

/**
 * @param array<string, mixed>|null $deployYamlData null = do NOT create deploy.yaml
 * @param array<string, ?string>    $envOverrides   ENV vars to set/unset for this run
 * @param array<string, string>     $options        CLI options to pass
 * @return array{exitCode:int, display:string}
 */
function runSetupGithub(
    ?array $deployYamlData,
    array $envOverrides = [],
    array $options = [],
    string $environment = 'production',
): array {
    $tmpDir = sys_get_temp_dir() . '/rakun-setup-gh-' . uniqid('', true);
    mkdir("{$tmpDir}/config", 0755, true);

    if ($deployYamlData !== null) {
        file_put_contents("{$tmpDir}/config/deploy.yaml", Yaml::dump($deployYamlData, 6, 2));
    }

    // Snapshot env, apply overrides.
    $snapshot = [];
    foreach (array_keys($envOverrides) as $name) {
        $current = getenv($name);
        $snapshot[$name] = $current === false ? null : $current;
    }
    foreach ($envOverrides as $name => $value) {
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv("{$name}={$value}");
            $_ENV[$name] = $value;
        }
    }

    $originalDir = getcwd();
    chdir($tmpDir);

    try {
        $app = makeSetupGithubApp();
        $tester = new CommandTester($app->find('deploy:setup-github'));

        $exitCode = $tester->execute(
            array_merge(['environment' => $environment], $options),
            ['interactive' => false],
        );
        $display = $tester->getDisplay();
    } finally {
        chdir($originalDir ?: '/');
        foreach ($snapshot as $name => $value) {
            if ($value === null) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv("{$name}={$value}");
                $_ENV[$name] = $value;
            }
        }
        exec('rm -rf ' . escapeshellarg($tmpDir));
    }

    return ['exitCode' => $exitCode, 'display' => $display];
}

describe('DeploySetupGithubCommand — structure', function (): void {
    it('is registered with the correct name', function (): void {
        $app = makeSetupGithubApp();
        expect($app->has('deploy:setup-github'))->toBeTrue();
    });

    it('has a non-empty description', function (): void {
        $cmd = new DeploySetupGithubCommand();
        expect($cmd->getDescription())->not->toBeEmpty();
    });

    it('defaults environment argument to production', function (): void {
        $cmd = new DeploySetupGithubCommand();
        $arg = $cmd->getDefinition()->getArgument('environment');
        expect($arg->getDefault())->toBe('production');
    });

    it('exposes the documented CLI options', function (): void {
        $cmd = new DeploySetupGithubCommand();
        $def = $cmd->getDefinition();
        foreach ([
            'owner', 'repo', 'branch', 'deploy-path', 'repo-name',
            'post-deploy', 'skip-ssl-verification', 'insecure-webhook-ssl',
        ] as $name) {
            expect($def->hasOption($name))->toBeTrue();
        }
    });
});

describe('DeploySetupGithubCommand — failure scenarios (pre-HTTP)', function (): void {
    it('fails when config/deploy.yaml does not exist', function (): void {
        $result = runSetupGithub(deployYamlData: null);

        expect($result['exitCode'])->toBe(Command::FAILURE);
        expect($result['display'])->toContain('config/deploy.yaml not found');
        expect($result['display'])->toContain('deploy:init');
    });

    it('fails when deploy.yaml parses to a non-array scalar', function (): void {
        $tmpDir = sys_get_temp_dir() . '/rakun-setup-gh-badyaml-' . uniqid('', true);
        mkdir("{$tmpDir}/config", 0755, true);
        // A plain scalar — Yaml::parse() returns the string, not an array.
        file_put_contents("{$tmpDir}/config/deploy.yaml", "just-a-string\n");

        $originalDir = getcwd();
        chdir($tmpDir);

        try {
            $app = makeSetupGithubApp();
            $tester = new CommandTester($app->find('deploy:setup-github'));
            $exitCode = $tester->execute(['environment' => 'production'], ['interactive' => false]);

            expect($exitCode)->toBe(Command::FAILURE);
            expect($tester->getDisplay())->toContain('does not parse as an associative array');
        } finally {
            chdir($originalDir ?: '/');
            exec('rm -rf ' . escapeshellarg($tmpDir));
        }
    });

    it('lists every missing required field when GITHUB_TOKEN + plesk + domain are absent', function (): void {
        $config = [
            'production' => [
                // No 'github', no 'plesk', no 'domain'.
            ],
        ];
        $result = runSetupGithub(
            deployYamlData: $config,
            envOverrides: [
                'GITHUB_OWNER' => null,
                'GITHUB_REPO' => null,
                'GITHUB_TOKEN' => null,
                'PLESK_API_KEY' => null,
            ],
        );

        expect($result['exitCode'])->toBe(Command::FAILURE);
        expect($result['display'])->toContain('missing required configuration');
        foreach ([
            'GitHub owner',
            'GitHub repo',
            'GITHUB_TOKEN',
            "'domain'",
            "'plesk.host'",
            "'plesk.api_key'",
        ] as $needle) {
            expect($result['display'])->toContain($needle);
        }
    });

    it('reads owner/repo from CLI options when env vars are missing', function (): void {
        // All other required fields present so we reach the GitHub step and stop there with a
        // network error (deterministic offline failure). We only check that owner/repo were resolved.
        $config = [
            'production' => [
                'domain' => 'example.com',
                'plesk' => [
                    'host' => 'https://127.0.0.1:1', // unreachable on purpose
                    'api_key' => 'dummy',
                    'verify_ssl' => false,
                ],
            ],
        ];
        $result = runSetupGithub(
            deployYamlData: $config,
            envOverrides: [
                'GITHUB_OWNER' => null,
                'GITHUB_REPO' => null,
                'GITHUB_TOKEN' => 'ghp_dummy_token_will_fail',
                'PLESK_API_KEY' => null,
            ],
            options: ['--owner' => 'cli-owner', '--repo' => 'cli-repo'],
        );

        // Will not succeed (we have no network mock here), but it must NOT fail on missing owner/repo.
        expect($result['display'])->toContain('cli-owner/cli-repo');
        expect($result['display'])->not->toContain('GitHub owner (--owner');
        expect($result['display'])->not->toContain('GitHub repo (--repo');
    });

    it('reads GITHUB_TOKEN from env var, not deploy.yaml', function (): void {
        // Provide the token via env, all other fields present; we expect the command to PROGRESS past
        // missing-field validation (i.e. NOT fail with "missing required configuration").
        $config = [
            'production' => [
                'domain' => 'example.com',
                'github' => ['owner' => 'octocat', 'repo' => 'Hello-World'],
                'plesk' => [
                    'host' => 'https://127.0.0.1:1',
                    'api_key' => 'dummy',
                    'verify_ssl' => false,
                ],
            ],
        ];
        $result = runSetupGithub(
            deployYamlData: $config,
            envOverrides: ['GITHUB_TOKEN' => 'ghp_from_env'],
        );

        expect($result['display'])->not->toContain('missing required configuration');
        expect($result['display'])->toContain('octocat/Hello-World');
    });

    it('resolves ${VAR} interpolation in deploy.yaml fields', function (): void {
        $config = [
            'production' => [
                'domain' => '${TEST_DOMAIN}',
                'github' => ['owner' => 'octocat', 'repo' => 'Hello-World'],
                'plesk' => [
                    'host' => '${TEST_PLESK_HOST}',
                    'api_key' => '${TEST_PLESK_KEY}',
                    'verify_ssl' => false,
                ],
            ],
        ];
        $result = runSetupGithub(
            deployYamlData: $config,
            envOverrides: [
                'GITHUB_TOKEN' => 'ghp_dummy',
                'TEST_DOMAIN' => 'resolved.example.com',
                'TEST_PLESK_HOST' => 'https://127.0.0.1:1',
                'TEST_PLESK_KEY' => 'resolved-key',
            ],
        );

        // resolved domain shown in configuration summary
        expect($result['display'])->toContain('resolved.example.com');
        expect($result['display'])->not->toContain('${TEST_DOMAIN}');
        // Must not fail at the missing-field gate.
        expect($result['display'])->not->toContain('missing required configuration');
    });
});
