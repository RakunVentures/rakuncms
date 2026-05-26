<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Deploy\PleskApiException;
use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\Inspector;
use Rkn\Cms\Deploy\PleskApi\Provisioner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * CLI command: rakun deploy:init
 *
 * Interactive wizard that:
 *   1. Asks for Plesk host, API key, and domain.
 *   2. Runs Inspector::discover() to capture server capabilities.
 *   3. Offers opt-in provisioning (enable shell, create Git repo).
 *   4. Writes config/deploy.yaml.example (does NOT overwrite config/deploy.yaml without confirmation).
 *   5. Updates .env.example with placeholder vars.
 */
#[AsCommand(name: 'deploy:init', description: 'Interactive setup and auto-provisioning for Plesk')]
final class DeployInitCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('RakunCMS — Plesk Deployment Setup');

        // ---- Step 1: Gather credentials ----

        $host = (string) $io->ask('Plesk host (e.g. https://plesk.example.com:8443)', null, static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new \RuntimeException('Host is required.');
            }
            return rtrim(trim($value), '/');
        });

        $apiKey = (string) $io->askHidden('Plesk API key (input hidden)', static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new \RuntimeException('API key is required.');
            }
            return trim($value);
        });

        $verifySsl = $io->confirm('Verify SSL certificate? (set to false only for self-signed certs in dev)', true);

        $domain = (string) $io->ask('Domain name (e.g. mysite.com)', null, static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new \RuntimeException('Domain is required.');
            }
            return strtolower(trim($value));
        });

        // ---- Step 2: Verify connectivity ----

        $io->section('Verifying connectivity…');
        $client = new Client($host, $apiKey, $verifySsl);

        try {
            $client->restGet('domains', ['name' => $domain]);
            $io->writeln('<info>✓ REST v2 reachable</info>');
        } catch (PleskApiException $e) {
            $io->warning("REST v2 check returned: {$e->getMessage()}");
            $io->note('Continuing with XML-RPC discovery (REST connectivity issue does not block setup).');
        }

        // ---- Step 3: Discover capabilities ----

        $io->section('Discovering server capabilities…');
        $inspector = new Inspector($client);
        $discovery = $inspector->discover($domain);

        $io->table(
            ['Capability', 'Value'],
            [
                ['Shell access', $discovery['has_shell'] === null ? 'Unknown' : ($discovery['has_shell'] ? 'Enabled' : 'Disabled')],
                ['Git repository', isset($discovery['git']['repo_name']) ? $discovery['git']['repo_name'] : 'None'],
                ['PHP version', $discovery['php']['version'] ?? 'Unknown'],
                ['PHP handler', $discovery['php']['handler'] ?? 'Unknown'],
                ['Document root', $discovery['doc_root']],
            ]
        );

        // ---- Step 4: Optional provisioning ----

        $provisioner = new Provisioner($client, $inspector);

        if ($discovery['has_shell'] === false) {
            if ($io->confirm('Shell access is disabled. Enable it via API?', true)) {
                try {
                    $provisioner->enableShell($domain);
                    $io->success('Shell access enabled.');
                    $discovery['has_shell'] = true;
                } catch (PleskApiException $e) {
                    $io->warning("Could not enable shell: {$e->getMessage()}");
                }
            }
        }

        if ($discovery['git'] === null) {
            if ($io->confirm('No Git repository found. Create one automatically?', true)) {
                try {
                    $gitInfo = $provisioner->createGitRepo($domain);
                    $discovery['git'] = $gitInfo;
                    $io->success("Git repository '{$gitInfo['repo_name']}' created.");

                    if ($discovery['has_shell'] === true) {
                        if ($io->confirm("Configure 'composer install --no-dev' as deploy action?", true)) {
                            $provisioner->setDeployActions(
                                $domain,
                                $gitInfo['repo_name'],
                                'composer install --no-dev --optimize-autoloader',
                            );
                            $io->success('Deploy actions configured.');
                        }
                    }
                } catch (PleskApiException $e) {
                    $io->warning("Could not create Git repo: {$e->getMessage()}");
                }
            }
        }

        // ---- Step 5: Build and write config ----

        $method = $discovery['git'] !== null ? 'git' : null;
        $strategy = $discovery['has_shell'] === true ? 'lean' : 'fat';

        $configData = [
            'production' => [
                'plesk' => [
                    'host' => '${PLESK_HOST}',
                    'api_key' => '${PLESK_API_KEY}',
                    'verify_ssl' => $verifySsl,
                ],
                'domain' => $domain,
                'discovered' => [
                    'has_shell' => $discovery['has_shell'],
                    'git' => $discovery['git'] !== null ? [
                        'repo_name' => $discovery['git']['repo_name'],
                        'active_branch' => $discovery['git']['active_branch'],
                        'deploy_path' => $discovery['git']['deploy_path'],
                        'webhook_url' => '${PLESK_WEBHOOK_URL}',
                    ] : null,
                    'php' => $discovery['php'],
                    'doc_root' => $discovery['doc_root'],
                    'discovered_at' => $discovery['discovered_at'],
                ],
                'method' => $method,
                'strategy' => $strategy,
            ],
        ];

        $basePath = $this->findBasePath();
        $exampleFile = "{$basePath}/config/deploy.yaml.example";
        $productionFile = "{$basePath}/config/deploy.yaml";

        // Always write the .example file
        if (!is_dir("{$basePath}/config")) {
            mkdir("{$basePath}/config", 0755, true);
        }
        file_put_contents($exampleFile, Yaml::dump($configData, 6, 2));
        $io->success("Deployment blueprint saved to config/deploy.yaml.example");

        // Optionally write the production file
        if (file_exists($productionFile)) {
            if ($io->confirm('config/deploy.yaml already exists. Overwrite it?', false)) {
                file_put_contents($productionFile, Yaml::dump($configData, 6, 2));
                $io->success('config/deploy.yaml updated.');
            } else {
                $io->note('config/deploy.yaml was not changed. Copy from config/deploy.yaml.example to update it.');
            }
        } else {
            file_put_contents($productionFile, Yaml::dump($configData, 6, 2));
            $io->success('config/deploy.yaml created.');
        }

        // ---- Step 6: Update .env.example ----

        $this->updateEnvExample($basePath, $host);

        // ---- Step 7: Final instructions ----

        $io->section('Next steps');
        $io->listing([
            'Copy .env.example to .env and fill in PLESK_HOST, PLESK_API_KEY, and PLESK_WEBHOOK_URL.',
            "Run 'rakun deploy:check' to verify the configuration matches the live server.",
            'Proceed to deploy:install for Phase 2 (Git transport setup).',
        ]);

        return Command::SUCCESS;
    }

    private function updateEnvExample(string $basePath, string $host): void
    {
        $envExampleFile = "{$basePath}/.env.example";
        $vars = [
            'PLESK_HOST' => $host,
            'PLESK_API_KEY' => '',
            'PLESK_WEBHOOK_URL' => '',
            'DEPLOY_SECRET' => '',
        ];

        $existing = '';
        if (file_exists($envExampleFile)) {
            $existing = file_get_contents($envExampleFile) ?: '';
        }

        foreach ($vars as $key => $defaultValue) {
            if (!str_contains($existing, $key . '=')) {
                $existing .= "\n{$key}={$defaultValue}";
            }
        }

        file_put_contents($envExampleFile, ltrim($existing, "\n"));
    }

    private function findBasePath(): string
    {
        try {
            $app = \Rkn\Framework\Application::getInstance();
            if ($app !== null) {
                return $app->getBasePath();
            }
        } catch (\Throwable) {
        }

        return getcwd() ?: dirname(__DIR__, 3);
    }
}
