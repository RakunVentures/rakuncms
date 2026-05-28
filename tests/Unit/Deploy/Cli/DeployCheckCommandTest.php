<?php

declare(strict_types=1);

use Rkn\Cms\Cli\DeployCheckCommand;
use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\FakeTransport;
use Rkn\Cms\Deploy\PleskApi\Inspector;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Yaml\Yaml;

function makeDeployCheckApp(?Inspector $inspector = null): Application
{
    $app = new Application('test', '0.0.1');
    $app->setAutoExit(false);
    $app->addCommand(new DeployCheckCommand($inspector));
    return $app;
}

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
 * Build the JSON body for a REST GET /domains list with a single matching domain.
 */
function fakeDomainsListBody(string $name, int $id): string
{
    return (string) json_encode([
        ['id' => $id, 'name' => $name],
    ]);
}

function fakeDomainDetailBody(string $wwwRoot): string
{
    return (string) json_encode(['www_root' => $wwwRoot]);
}

function fakeDomainInfoStdout(string $shell, string $phpVersion, string $phpHandler, string $docRoot): string
{
    return <<<EOT
Domain name:        example.com
SSH access:         {$shell}
Document root:      {$docRoot}
PHP version:        {$phpVersion}
PHP handler:        {$phpHandler}

EOT;
}

/**
 * Set up a temporary deploy.yaml and run deploy:check.
 *
 * @param array<mixed> $deployYamlData The full deploy.yaml data structure
 * @param array<string, string> $envVars Environment variables to set
 * @param Inspector|null $inspector Pre-wired inspector to inject (bypasses credential check)
 */
function runDeployCheck(array $deployYamlData, array $envVars = [], ?Inspector $inspector = null): array
{
    $tmpDir = sys_get_temp_dir() . '/rakun-check-' . uniqid('', true);
    mkdir("{$tmpDir}/config", 0755, true);

    file_put_contents("{$tmpDir}/config/deploy.yaml", Yaml::dump($deployYamlData, 6, 2));

    // Set env vars (in all lookup mechanisms used by DeployConfig)
    foreach ($envVars as $key => $value) {
        putenv("{$key}={$value}");
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    $originalDir = getcwd();
    chdir($tmpDir);

    $app = makeDeployCheckApp($inspector);
    $tester = new CommandTester($app->find('deploy:check'));

    $exitCode = $tester->execute([], ['interactive' => false]);
    $display = $tester->getDisplay();

    chdir($originalDir ?: '/');

    // Cleanup env vars from all lookup mechanisms
    foreach ($envVars as $key => $value) {
        putenv("{$key}");
        unset($_ENV[$key], $_SERVER[$key]);
    }

    // Cleanup files
    array_map('unlink', glob("{$tmpDir}/config/*") ?: []);
    @rmdir("{$tmpDir}/config");
    @rmdir($tmpDir);

    return compact('exitCode', 'display');
}

describe('DeployCheckCommand', function (): void {
    it('is registered with the correct command name', function (): void {
        $app = makeDeployCheckApp();
        expect($app->has('deploy:check'))->toBeTrue();
    });

    it('has a description', function (): void {
        $cmd = new DeployCheckCommand();
        expect($cmd->getDescription())->not->toBeEmpty();
    });

    it('has --env option', function (): void {
        $cmd = new DeployCheckCommand();
        expect($cmd->getDefinition()->hasOption('env'))->toBeTrue();
    });
});

describe('DeployCheckCommand — failure scenarios', function (): void {
    it('fails with exit code 1 when config/deploy.yaml does not exist', function (): void {
        $tmpDir = sys_get_temp_dir() . '/rakun-noconfig-' . uniqid('', true);
        mkdir($tmpDir, 0755, true);
        $originalDir = getcwd();
        chdir($tmpDir);

        $app = makeDeployCheckApp();
        $tester = new CommandTester($app->find('deploy:check'));
        $exitCode = $tester->execute([]);
        $display = $tester->getDisplay();

        chdir($originalDir ?: '/');
        @rmdir($tmpDir);

        expect($exitCode)->toBe(Command::FAILURE);
        expect($display)->toContain('deploy:init');
    });

    it('fails when there is no discovered block in config', function (): void {
        $config = [
            'production' => [
                'plesk' => [
                    'host' => 'https://plesk.test:8443',
                    'api_key' => 'testkey',
                    'verify_ssl' => false,
                ],
                'domain' => 'example.com',
                // No 'discovered' key
            ],
        ];

        $result = runDeployCheck($config);

        expect($result['exitCode'])->toBe(Command::FAILURE);
        expect($result['display'])->toContain('deploy:init');
    });

    it('fails without credentials (no api_key, no env var)', function (): void {
        // This test verifies that without a PLESK_API_KEY (either in config or env),
        // the command fails. We unset all possible sources of the key.
        $savedEnv = getenv('PLESK_API_KEY');
        $savedEnvArr = $_ENV['PLESK_API_KEY'] ?? null;
        $savedServer = $_SERVER['PLESK_API_KEY'] ?? null;

        putenv('PLESK_API_KEY');
        unset($_ENV['PLESK_API_KEY'], $_SERVER['PLESK_API_KEY']);

        $config = [
            'production' => [
                'plesk' => [
                    'host' => 'https://plesk.test:8443',
                    // No api_key — forces the check on env var
                    'verify_ssl' => false,
                ],
                'domain' => 'example.com',
                'discovered' => [
                    'has_shell' => true,
                    'doc_root' => '/httpdocs',
                ],
            ],
        ];

        $result = runDeployCheck($config);

        // Restore original env state
        if ($savedEnv !== false) {
            putenv("PLESK_API_KEY={$savedEnv}");
        }
        if ($savedEnvArr !== null) {
            $_ENV['PLESK_API_KEY'] = $savedEnvArr;
        }
        if ($savedServer !== null) {
            $_SERVER['PLESK_API_KEY'] = $savedServer;
        }

        // The command fails either because no key (PLESK_API_KEY error) or server unreachable
        expect($result['exitCode'])->toBe(Command::FAILURE);
    });
});

describe('DeployCheckCommand — drift detection', function (): void {
    it('reports DRIFT when has_shell changes from true to false', function (): void {
        $config = [
            'production' => [
                'plesk' => [
                    'host' => 'https://plesk.test:8443',
                    'api_key' => 'test-key',
                    'verify_ssl' => false,
                ],
                'domain' => 'example.com',
                'discovered' => [
                    'has_shell' => true,
                    'doc_root' => '/var/www/vhosts/example.com/httpdocs',
                    'git' => null,
                    'php' => ['version' => '8.2', 'handler' => 'fpm'],
                ],
            ],
        ];

        // discover() flow against the new REST-only Inspector:
        //   1. getDocumentRoot → restGet('domains')        (find id)
        //   2. getDocumentRoot → restGet('domains/{id}')   (www_root)
        //   3. hasShellAccess  → cliCall('domain --info')  (cached for getPhpInfo)
        //   4. getGitInfo      → cliCall('extension --list')
        $transport = new FakeTransport();
        $transport->queueResponse(200, fakeDomainsListBody('example.com', 13));
        $transport->queueResponse(200, fakeDomainDetailBody('/var/www/vhosts/example.com/httpdocs'));
        $transport->queueResponse(200, cliBody(fakeDomainInfoStdout(
            '/sbin/nologin',
            '8.2.15',
            'FPM',
            '/var/www/vhosts/example.com/httpdocs',
        )));
        $transport->queueResponse(200, cliBody('No Git repositories were found for domain example.com.'));

        $client = new Client('https://plesk.test:8443', 'test-key', false, 30, $transport);
        $inspector = new Inspector($client);

        $result = runDeployCheck($config, [], $inspector);

        expect($result['exitCode'])->toBe(Command::FAILURE);
        expect($result['display'])->toContain('DRIFT');
        expect($result['display'])->toContain('has_shell');
    });

    it('reports OK when all fields match the stored snapshot', function (): void {
        $config = [
            'production' => [
                'plesk' => [
                    'host' => 'https://plesk.test:8443',
                    'api_key' => 'test-key',
                    'verify_ssl' => false,
                ],
                'domain' => 'example.com',
                'discovered' => [
                    'has_shell' => true,
                    'doc_root' => '/var/www/vhosts/example.com/httpdocs',
                    'git' => null,
                    'php' => ['version' => '8.2.15', 'handler' => 'fpm'],
                ],
            ],
        ];

        $transport = new FakeTransport();
        $transport->queueResponse(200, fakeDomainsListBody('example.com', 13));
        $transport->queueResponse(200, fakeDomainDetailBody('/var/www/vhosts/example.com/httpdocs'));
        $transport->queueResponse(200, cliBody(fakeDomainInfoStdout(
            '/bin/bash',
            '8.2.15',
            'FPM',
            '/var/www/vhosts/example.com/httpdocs',
        )));
        $transport->queueResponse(200, cliBody('No Git repositories were found for domain example.com.'));

        $client = new Client('https://plesk.test:8443', 'test-key', false, 30, $transport);
        $inspector = new Inspector($client);

        $result = runDeployCheck($config, [], $inspector);

        expect($result['exitCode'])->toBe(Command::SUCCESS);
        expect($result['display'])->toContain('No drift detected');
    });
});

describe('DeployCheckCommand — structure validation', function (): void {
    it('returns expected command name attribute', function (): void {
        $cmd = new DeployCheckCommand();
        expect($cmd->getName())->toBe('deploy:check');
    });

    it('option --env defaults to production', function (): void {
        $cmd = new DeployCheckCommand();
        $option = $cmd->getDefinition()->getOption('env');
        expect($option->getDefault())->toBe('production');
    });
});
