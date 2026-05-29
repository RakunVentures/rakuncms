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
use Symfony\Component\Console\Input\InputOption;
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
    protected function configure(): void
    {
        $this
            ->addOption('environment', null, InputOption::VALUE_REQUIRED, 'Environment name (used in the auto-provisioned API key description)', 'production')
            ->addOption('auto-key', null, InputOption::VALUE_NONE, 'Force auto-provisioning of a REST API key (skip the prompt)')
            ->addOption('manual-key', null, InputOption::VALUE_NONE, 'Force manual API-key input (skip auto-provisioning prompt)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('RakunCMS — Plesk Deployment Setup');

        $env = (string) $input->getOption('environment');

        // ---- Step 1: Gather host + SSL preference ----

        $host = (string) $io->ask('Plesk host (e.g. https://plesk.example.com:8443)', null, static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new \RuntimeException('Host is required.');
            }
            return rtrim(trim($value), '/');
        });

        $verifySsl = $io->confirm('Verify SSL certificate? (set to false only for self-signed certs in dev)', true);

        // ---- Step 2: Get an API key — auto-provision via Basic Auth, or accept an existing one ----

        $autoFlag = (bool) $input->getOption('auto-key');
        $manualFlag = (bool) $input->getOption('manual-key');

        if ($autoFlag && $manualFlag) {
            $io->error('--auto-key and --manual-key are mutually exclusive.');
            return Command::FAILURE;
        }

        $shouldAutoProvision = $autoFlag
            ? true
            : ($manualFlag
                ? false
                : $io->confirm("Auto-provision a REST API key for '{$env}' (recommended)? You'll be asked for admin credentials, used once and never persisted.", true));

        if ($shouldAutoProvision) {
            $apiKey = $this->provisionApiKey($io, $host, $verifySsl, $env);
            if ($apiKey === null) {
                return Command::FAILURE;
            }
        } else {
            $apiKey = (string) $io->askHidden('Plesk API key (input hidden)', static function (?string $value): string {
                if ($value === null || trim($value) === '') {
                    throw new \RuntimeException('API key is required.');
                }
                return trim($value);
            });
        }

        $domain = (string) $io->ask('Domain name (e.g. mysite.com)', null, static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new \RuntimeException('Domain is required.');
            }
            return strtolower(trim($value));
        });

        // ---- Step 3: Verify connectivity ----

        $io->section('Verifying connectivity…');
        $client = new Client($host, $apiKey, $verifySsl);

        try {
            $client->restGet('domains', ['name' => $domain]);
            $io->writeln('<info>✓ REST v2 reachable</info>');
        } catch (PleskApiException $e) {
            $io->error("Cannot reach Plesk REST v2 with the configured key: {$e->getMessage()}");
            $io->note('REST-only client cannot fall back to XML-RPC (since #24). Verify the API key, host, and that Plesk Obsidian 18.0.78+ is reachable.');
            return Command::FAILURE;
        }

        // ---- Step 4: Discover capabilities ----

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

        // ---- Step 5: Optional provisioning ----

        $provisioner = new Provisioner($client, $inspector);

        if ($discovery['has_shell'] === false) {
            if ($io->confirm('Shell access is disabled. Enable it via API?', true)) {
                try {
                    $provisioner->enableShellAccess($domain);
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
                } catch (PleskApiException $e) {
                    $io->warning("Could not create Git repo: {$e->getMessage()}");
                }
            }
        }

        // ---- Step 6: Build and write config ----

        $method = $discovery['git'] !== null ? 'git' : null;
        $strategy = $discovery['has_shell'] === true ? 'lean' : 'fat';

        $configData = [
            $env => [
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

        // ---- Step 7: Update .env / .env.example ----

        $this->updateEnvExample($basePath, $host);

        if ($shouldAutoProvision) {
            $this->writeApiKeyToEnv($basePath, $apiKey, $io);
        }

        // ---- Step 8: Final instructions ----

        $io->section('Next steps');
        $io->listing([
            'Copy .env.example to .env and fill in PLESK_HOST, PLESK_API_KEY, and PLESK_WEBHOOK_URL.',
            "Run 'rakun deploy:check' to verify the configuration matches the live server.",
            'Proceed to deploy:install for Phase 2 (Git transport setup).',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Auto-provision a REST API key by hitting POST /api/v2/auth/keys with Basic Auth.
     *
     * The admin password is asked twice (input hidden), used to bootstrap the key, then
     * dropped from memory. The minted key is bound to the egress IP of this machine
     * (auto-detected via api.ipify.org, overridable by user input) so it cannot be reused
     * from anywhere else.
     *
     * On any failure (transport error, bad credentials, Plesk rejection) the method
     * prints a clear error and returns null — the caller must abort the wizard so the
     * operator never leaves with a half-configured project.
     *
     * REST-only: we never fall back to XML-RPC (removed in #24).
     */
    private function provisionApiKey(SymfonyStyle $io, string $host, bool $verifySsl, string $env): ?string
    {
        $io->section('Auto-provisioning REST API key');

        $adminUser = (string) $io->ask('Plesk admin username', 'admin', static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new \RuntimeException('Admin username is required.');
            }
            return trim($value);
        });

        $adminPassword = (string) $io->askHidden('Plesk admin password (input hidden — used once, never persisted)', static function (?string $value): string {
            if ($value === null || trim($value) === '') {
                throw new \RuntimeException('Admin password is required.');
            }
            return $value;
        });

        $detectedIp = $this->detectEgressIp();
        $promptDefault = $detectedIp ?? '0.0.0.0';
        $hint = $detectedIp !== null
            ? "Egress IP (detected via api.ipify.org)"
            : "Egress IP (auto-detect failed — pass your public IP, or '0.0.0.0' to skip binding)";
        $ipAddress = (string) $io->ask($hint, $promptDefault, static function (?string $value): string {
            $value = trim((string) $value);
            if ($value === '' || $value === '0.0.0.0') {
                return '';
            }
            if (filter_var($value, FILTER_VALIDATE_IP) === false) {
                throw new \RuntimeException('Not a valid IP address.');
            }
            return $value;
        });

        $description = "rakuncms-deploy-{$env}";

        $payload = ['description' => $description];
        if ($ipAddress !== '') {
            $payload['ip_address'] = $ipAddress;
        }

        try {
            $bootstrap = Client::withBasicAuth(
                host: $host,
                user: $adminUser,
                password: $adminPassword,
                verifySsl: $verifySsl,
            );
            $response = $bootstrap->restPost('auth/keys', $payload);
        } catch (PleskApiException $e) {
            $io->error("Could not mint API key: {$e->getMessage()}");
            $io->note('Check that the admin credentials are correct and that the egress IP is allowed by Plesk firewall.');
            return null;
        }

        $apiKey = null;
        foreach (['key', 'apiKey', 'api_key'] as $candidate) {
            if (isset($response[$candidate]) && is_string($response[$candidate]) && $response[$candidate] !== '') {
                $apiKey = $response[$candidate];
                break;
            }
        }

        if ($apiKey === null) {
            $io->error('Plesk accepted the request but did not return a key in the response body.');
            return null;
        }

        $io->writeln("  <info>OK</info> — minted key (length: <comment>" . strlen($apiKey) . "</comment>, ip_address: <comment>" . ($ipAddress !== '' ? $ipAddress : '(unbound)') . "</comment>, description: <comment>{$description}</comment>)");

        // Round-trip verification before we hand the key back to the wizard.
        $verifyClient = new Client($host, $apiKey, $verifySsl);
        try {
            $verifyClient->restGet('server');
        } catch (PleskApiException $e) {
            $io->warning("New key returned by Plesk failed verification on GET /server: {$e->getMessage()}");
            $io->note('This is sometimes the 5-8s key-propagation window. Retrying once after 6 seconds…');
            sleep(6);
            try {
                $verifyClient->restGet('server');
            } catch (PleskApiException $e2) {
                $io->error("Key verification still fails: {$e2->getMessage()}. Aborting so we never persist an unusable key.");
                return null;
            }
        }
        $io->writeln('  <info>OK</info> — key works against GET /api/v2/server.');

        return $apiKey;
    }

    /**
     * Best-effort discovery of the egress IP this machine presents to Plesk.
     *
     * Returns null on any timeout / parse failure — caller falls back to asking the user.
     */
    private function detectEgressIp(): ?string
    {
        $ctx = stream_context_create([
            'http' => ['timeout' => 5, 'ignore_errors' => true, 'user_agent' => 'rakuncms/deploy-init'],
            'https' => ['timeout' => 5, 'ignore_errors' => true],
        ]);

        $body = @file_get_contents('https://api.ipify.org', false, $ctx);
        if (!is_string($body)) {
            return null;
        }

        $ip = trim($body);
        return filter_var($ip, FILTER_VALIDATE_IP) === false ? null : $ip;
    }

    /**
     * Persist the auto-minted key into .env (NEVER into deploy.yaml).
     *
     * If PLESK_API_KEY is already declared in .env the existing line is preserved and
     * the operator is told to update it manually — we never silently rewrite an existing
     * secret. .env is created with 0600 perms if it didn't exist.
     */
    private function writeApiKeyToEnv(string $basePath, string $apiKey, SymfonyStyle $io): void
    {
        $envFile = "{$basePath}/.env";
        $existing = is_file($envFile) ? (string) file_get_contents($envFile) : '';

        if (preg_match('/^PLESK_API_KEY=.*$/m', $existing) === 1) {
            $io->note("PLESK_API_KEY already present in .env — not overwriting. New key (copy it manually if you want to switch): {$apiKey}");
            return;
        }

        $prefix = ($existing === '' || str_ends_with($existing, "\n")) ? '' : "\n";
        $appended = $existing . $prefix . "PLESK_API_KEY={$apiKey}\n";
        file_put_contents($envFile, $appended);

        if (!is_file("{$envFile}.lock")) {
            @chmod($envFile, 0600);
        }

        $io->writeln("  <info>OK</info> — wrote PLESK_API_KEY into <comment>{$envFile}</comment>");
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
