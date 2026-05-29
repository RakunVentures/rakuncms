<?php

declare(strict_types=1);

use Rkn\Cms\Cli\DeployInitCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

$fixturesDir = __DIR__ . '/../../../Fixtures/plesk-xmlrpc';

function makeDeployInitApp(): Application
{
    $app = new Application('rakun-test', '0.0.1');
    $app->setAutoExit(false);
    $app->addCommand(new DeployInitCommand());
    return $app;
}

/**
 * Build a CommandTester for DeployInitCommand with the given inputs.
 * The command itself constructs its own Client from user input, so we test
 * via CommandTester's input simulation.
 */
function runDeployInit(array $inputs): array
{
    $app = makeDeployInitApp();
    $tester = new CommandTester($app->find('deploy:init'));
    $tester->setInputs($inputs);

    // Run in a temp directory so config files don't pollute the project
    $tmpDir = sys_get_temp_dir() . '/rakun-test-' . uniqid('', true);
    mkdir("{$tmpDir}/config", 0755, true);

    $originalDir = getcwd();
    chdir($tmpDir);

    // Tests cover the legacy manual-key flow; the auto-provision flow needs a
    // live Plesk (POST /auth/keys + IP discovery) and lives in the sandbox suite.
    $exitCode = $tester->execute(['--manual-key' => true], ['interactive' => true]);

    chdir($originalDir ?: '/');

    $exampleContent = '';
    $configContent = '';
    $envContent = '';

    if (file_exists("{$tmpDir}/config/deploy.yaml.example")) {
        $exampleContent = (string) file_get_contents("{$tmpDir}/config/deploy.yaml.example");
    }
    if (file_exists("{$tmpDir}/config/deploy.yaml")) {
        $configContent = (string) file_get_contents("{$tmpDir}/config/deploy.yaml");
    }
    if (file_exists("{$tmpDir}/.env.example")) {
        $envContent = (string) file_get_contents("{$tmpDir}/.env.example");
    }

    // Recursive cleanup
    array_map('unlink', glob("{$tmpDir}/config/*") ?: []);
    @rmdir("{$tmpDir}/config");
    if (file_exists("{$tmpDir}/.env.example")) {
        unlink("{$tmpDir}/.env.example");
    }
    @rmdir($tmpDir);

    return [
        'exitCode' => $exitCode,
        'display' => $tester->getDisplay(),
        'exampleContent' => $exampleContent,
        'configContent' => $configContent,
        'envContent' => $envContent,
    ];
}

describe('DeployInitCommand', function () use ($fixturesDir): void {
    it('is registered with the correct command name', function (): void {
        $app = makeDeployInitApp();
        expect($app->has('deploy:init'))->toBeTrue();
    });

    it('has a description', function (): void {
        $cmd = new DeployInitCommand();
        expect($cmd->getDescription())->not->toBeEmpty();
    });

    it('displays Plesk-related output when run interactively', function () use ($fixturesDir): void {
        // Simulate inputs: host, api_key, verify_ssl (yes), domain, then any remaining prompts
        $inputs = [
            'https://plesk.example.com:8443',  // host
            'test-api-key',                     // api_key
            'yes',                              // verify_ssl
            'example.com',                      // domain
            'no',                               // confirm shell provisioning (if prompted)
            'no',                               // confirm git provisioning (if prompted)
            'no',                               // overwrite deploy.yaml (if prompted)
        ];

        $result = runDeployInit($inputs);

        // The command outputs the title regardless of whether connectivity succeeds
        expect($result['display'])->toContain('Plesk');
    });

    it('outputs the RakunCMS title on startup', function (): void {
        // Verify the command title is rendered regardless of connectivity outcome
        $inputs = [
            'https://fallback.test:8443',
            'key',
            'yes',
            'domain.com',
            'no',
            'no',
            'no',
        ];
        $result = runDeployInit($inputs);

        expect($result['display'])->toContain('RakunCMS');
    });
});

describe('DeployInitCommand — structure', function (): void {
    it('has expected command name attribute', function (): void {
        $cmd = new DeployInitCommand();
        expect($cmd->getName())->toBe('deploy:init');
    });

    it('has a non-empty description', function (): void {
        $cmd = new DeployInitCommand();
        expect($cmd->getDescription())->toBeString()->not->toBeEmpty();
    });
});
