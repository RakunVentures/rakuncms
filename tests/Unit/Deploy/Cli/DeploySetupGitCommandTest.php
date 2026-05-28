<?php

declare(strict_types=1);

use Rkn\Cms\Cli\DeploySetupGitCommand;
use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskApi\Inspector;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

if (!function_exists('cliBody')) {
    function cliBody(string $stdout, int $code = 0, string $stderr = ''): string
    {
        return (string) json_encode([
            'code' => $code,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ]);
    }
}

/**
 * Build a test Application with an optional injected Inspector.
 */
function makeSetupGitApp(?Inspector $inspector = null): Application
{
    $app = new Application('test', '0.0.1');
    $app->setAutoExit(false);
    $app->addCommand(new DeploySetupGitCommand($inspector));
    return $app;
}

/**
 * Run deploy:setup-git in a temp working directory.
 *
 * @param array<mixed> $deployYamlData
 * @param Inspector|null $inspector Pre-wired inspector
 * @param bool $initGit Whether to create a .git directory in the temp dir
 * @param string $env Environment argument
 * @return array{exitCode: int, display: string, dir: string}
 */
function runSetupGit(
    array $deployYamlData,
    ?Inspector $inspector = null,
    bool $initGit = true,
    string $env = 'production',
): array {
    $tmpDir = sys_get_temp_dir() . '/rakun-setup-git-' . uniqid('', true);
    mkdir("{$tmpDir}/config", 0755, true);

    file_put_contents("{$tmpDir}/config/deploy.yaml", Yaml::dump($deployYamlData, 6, 2));

    if ($initGit) {
        exec("git -C " . escapeshellarg($tmpDir) . " init 2>&1");
        exec("git -C " . escapeshellarg($tmpDir) . " config user.email 'test@rakun.test'");
        exec("git -C " . escapeshellarg($tmpDir) . " config user.name 'Test'");
    }

    $originalDir = getcwd();
    chdir($tmpDir);

    $app = makeSetupGitApp($inspector);
    $tester = new CommandTester($app->find('deploy:setup-git'));

    $exitCode = $tester->execute(['env' => $env], ['interactive' => false]);
    $display = $tester->getDisplay();

    chdir($originalDir ?: '/');

    // Cleanup
    exec("rm -rf " . escapeshellarg($tmpDir));

    return ['exitCode' => $exitCode, 'display' => $display, 'dir' => $tmpDir];
}

describe('DeploySetupGitCommand', function (): void {
    it('is registered with the correct command name', function (): void {
        $app = makeSetupGitApp();
        expect($app->has('deploy:setup-git'))->toBeTrue();
    });

    it('has a description', function (): void {
        $cmd = new DeploySetupGitCommand();
        expect($cmd->getDescription())->not->toBeEmpty();
    });

    it('has an [env] argument defaulting to production', function (): void {
        $cmd = new DeploySetupGitCommand();
        $arg = $cmd->getDefinition()->getArgument('env');
        expect($arg->getDefault())->toBe('production');
    });
});

describe('DeploySetupGitCommand — failure scenarios', function (): void {
    it('fails when deploy.yaml does not exist', function (): void {
        $tmpDir = sys_get_temp_dir() . '/rakun-no-yaml-' . uniqid('', true);
        mkdir($tmpDir, 0755, true);
        $originalDir = getcwd();
        chdir($tmpDir);

        try {
            $app = makeSetupGitApp();
            $tester = new CommandTester($app->find('deploy:setup-git'));
            $exitCode = $tester->execute([], ['interactive' => false]);
            $display = $tester->getDisplay();

            expect($exitCode)->toBe(Command::FAILURE);
            expect($display)->toContain('deploy:init');
        } finally {
            chdir($originalDir ?: '/');
            @rmdir($tmpDir);
        }
    });

    it('fails without PLESK_API_KEY when no injected inspector', function (): void {
        $savedKey = getenv('PLESK_API_KEY');
        putenv('PLESK_API_KEY');
        unset($_ENV['PLESK_API_KEY'], $_SERVER['PLESK_API_KEY']);

        $config = [
            'production' => [
                'plesk' => [
                    'host' => 'https://plesk.test:8443',
                    'verify_ssl' => false,
                ],
                'domain' => 'example.com',
            ],
        ];

        $result = runSetupGit($config);

        if ($savedKey !== false) {
            putenv("PLESK_API_KEY={$savedKey}");
        }

        expect($result['exitCode'])->toBe(Command::FAILURE);
        expect($result['display'])->toContain('PLESK_API_KEY');
    });
});

describe('DeploySetupGitCommand — with FakeTransport', function (): void {
    it('completes successfully when Plesk returns no git repositories (graceful degradation)', function (): void {
        // CLI gateway returns empty git list → getGitInfo() returns null.
        // Command should still succeed (with a warning about no repo found).
        $transport = new FakeTransport();
        $transport->queueResponse(200, cliBody('No Git repositories were found for domain example.com.'));

        $client = new Client('https://plesk.test:8443', 'test-key', false, 30, $transport);
        $inspector = new Inspector($client);

        $config = [
            'production' => [
                'method' => 'git',
                'strategy' => 'lean',
                'source_branch' => 'main',
                'target_branch' => 'main',
                'remote' => 'plesk',
                'plesk' => [
                    'host' => 'https://plesk.test:8443',
                    'api_key' => 'test-key',
                    'verify_ssl' => false,
                ],
                'domain' => 'example.com',
            ],
        ];

        $result = runSetupGit($config, $inspector);

        expect($result['exitCode'])->toBe(Command::SUCCESS);
        expect($result['display'])->toContain('No Git repository found');
    });

    it('persists webhook_url in deploy.yaml when Inspector returns git info', function (): void {
        // getGitInfo() makes TWO CLI gateway calls:
        //   1. cliCall('extension', ['--call','git','--list',...]) → stdout with repo name
        //   2. cliCall('extension', ['--call','git','--info',...])  → stdout with webhook URL
        $transport = new FakeTransport();
        $transport->queueResponse(200, cliBody("website.git\n"));
        $transport->queueResponse(200, cliBody(
            "Repository name:   website.git\n"
            . "Active branch:     main\n"
            . "Webhook URL:       https://plesk.example.com:8443/modules/git/webhook/abc123token\n"
            . "Deploy mode:       automatic\n"
        ));

        $client = new Client('https://plesk.test:8443', 'test-key', false, 30, $transport);
        $inspector = new Inspector($client);

        $tmpDir = sys_get_temp_dir() . '/rakun-setup-git-persist-' . uniqid('', true);
        mkdir("{$tmpDir}/config", 0755, true);
        exec("git -C " . escapeshellarg($tmpDir) . " init 2>&1");

        $configData = [
            'production' => [
                'method' => 'git',
                'strategy' => 'lean',
                'source_branch' => 'main',
                'target_branch' => 'main',
                'remote' => 'plesk',
                'plesk' => [
                    'host' => 'https://plesk.test:8443',
                    'api_key' => 'test-key',
                    'verify_ssl' => false,
                ],
                'domain' => 'example.com',
            ],
        ];
        file_put_contents("{$tmpDir}/config/deploy.yaml", Yaml::dump($configData, 6, 2));

        $originalDir = getcwd();
        chdir($tmpDir);

        $app = makeSetupGitApp($inspector);
        $tester = new CommandTester($app->find('deploy:setup-git'));
        $exitCode = $tester->execute(['env' => 'production'], ['interactive' => false]);

        // Check what was written to deploy.yaml
        $updatedYaml = Yaml::parse((string) file_get_contents("{$tmpDir}/config/deploy.yaml"));

        chdir($originalDir ?: '/');
        exec("rm -rf " . escapeshellarg($tmpDir));

        // The method and remote should be preserved/set in deploy.yaml
        expect($updatedYaml['production']['method'])->toBe('git');
        expect($updatedYaml['production']['remote'])->toBe('plesk');
        // webhook_url from git-info-with-webhook.xml must be persisted in deploy.yaml
        expect($updatedYaml['production']['webhook_url'])->toBe(
            'https://plesk.example.com:8443/modules/git/webhook/abc123token'
        );
        expect($exitCode)->toBe(Command::SUCCESS);
    });
});

describe('DeploySetupGitCommand — structure validation', function (): void {
    it('returns expected command name from attribute', function (): void {
        $cmd = new DeploySetupGitCommand();
        expect($cmd->getName())->toBe('deploy:setup-git');
    });
});
