<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\Inspector;
use Rkn\Cms\Deploy\PleskApiException;
use Rkn\Cms\Deploy\PleskTransportException;
use Rkn\Cms\Deploy\Process\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * CLI command: rakun deploy:setup-git
 *
 * Wizard that:
 *  1. Reads config/deploy.yaml for the given environment.
 *  2. Uses Plesk API Inspector to discover the Git remote URL for the domain.
 *  3. Adds (or updates) the local git remote pointing at Plesk.
 *  4. Validates connectivity via `git ls-remote`.
 *  5. Updates config/deploy.yaml with remote, webhook_url, source_branch, target_branch.
 *  6. Suggests a random webhook_secret if one is not already in .env.
 */
#[AsCommand(name: 'deploy:setup-git', description: 'Configure Git remote and webhook URL for Plesk deployment')]
final class DeploySetupGitCommand extends Command
{
    /**
     * Optional pre-wired inspector (for tests).
     * When null, the command builds its own Client+Inspector from deploy.yaml config.
     */
    public function __construct(private readonly ?Inspector $injectedInspector = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'env',
            InputArgument::OPTIONAL,
            'Environment to configure (matches a top-level key in deploy.yaml)',
            'production',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('RakunCMS — Git Deploy Setup');

        $env = (string) ($input->getArgument('env') ?? 'production');
        $basePath = $this->findBasePath();

        // ---- Load config ----
        try {
            $config = DeployConfig::load($basePath, $env);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        // ---- Build Inspector ----
        if ($this->injectedInspector !== null) {
            $inspector = $this->injectedInspector;
        } else {
            $envApiKey = getenv('PLESK_API_KEY');
            $apiKey = $config->pleskApiKey ?? ($envApiKey !== false && $envApiKey !== '' ? $envApiKey : null);
            if ($apiKey === null) {
                $io->error('PLESK_API_KEY is not set. Cannot connect to Plesk.');
                return Command::FAILURE;
            }
            $client = new Client($config->host, $apiKey, $config->pleskVerifySsl);
            $inspector = new Inspector($client);
        }

        // ---- Discover Git info from Plesk ----
        $io->writeln("Discovering Git repository info for domain: {$config->domain}...");

        $gitInfo = null;
        try {
            $gitInfo = $inspector->getGitInfo($config->domain);
        } catch (PleskTransportException $e) {
            $io->warning("Could not reach Plesk API: {$e->getMessage()}");
        } catch (PleskApiException $e) {
            $io->warning("Plesk API error: {$e->getMessage()}");
        } catch (\Throwable $e) {
            $io->warning("Discovery error: {$e->getMessage()}");
        }

        // ---- Determine remote URL ----
        $webhookUrl = null;
        if ($gitInfo !== null) {
            $webhookUrl = $gitInfo['webhook_url'] ?? null;
            $io->success("Found Plesk Git repository: {$gitInfo['repo_name']}");
            if ($webhookUrl !== null) {
                $io->writeln("  Webhook URL: {$webhookUrl}");
            }
        } else {
            $io->warning("No Git repository found in Plesk for {$config->domain}. Remote URL must be set manually.");
        }

        // ---- Configure local git remote ----
        $remoteName = $config->remote ?? 'plesk';
        $runner = new Runner($basePath);

        // Check if remote already exists
        $remoteExistsResult = $runner->run(['git', 'remote', 'get-url', $remoteName])
            ->withTimeout(15)
            ->execute();

        if ($gitInfo !== null && isset($gitInfo['deploy_path']) && $gitInfo['deploy_path'] !== null) {
            // Build SSH URL from host and deploy_path
            $remoteUrl = "ssh://{$config->host}{$gitInfo['deploy_path']}";

            if ($remoteExistsResult->isSuccess()) {
                $io->writeln("Updating existing remote '{$remoteName}' to {$remoteUrl}");
                $runner->run(['git', 'remote', 'set-url', $remoteName, $remoteUrl])
                    ->withTimeout(15)
                    ->execute();
            } else {
                $io->writeln("Adding git remote '{$remoteName}' → {$remoteUrl}");
                $addResult = $runner->run(['git', 'remote', 'add', $remoteName, $remoteUrl])
                    ->withTimeout(15)
                    ->execute();
                if (!$addResult->isSuccess()) {
                    $io->error("Could not add git remote: {$addResult->stderr}");
                    return Command::FAILURE;
                }
            }

            // Validate connectivity
            $io->writeln("Validating remote connectivity (git ls-remote)...");
            $lsResult = $runner->run(['git', 'ls-remote', $remoteName])
                ->withTimeout(30)
                ->execute();
            if (!$lsResult->isSuccess()) {
                $io->warning("git ls-remote failed (SSH may require manual key setup): {$lsResult->stderr}");
            } else {
                $io->success("Remote '{$remoteName}' is reachable.");
            }
        } else {
            $io->warning("No deploy_path available from Plesk. You must add the git remote manually:");
            $io->writeln("  git remote add {$remoteName} <your-plesk-git-url>");
        }

        // ---- Update deploy.yaml ----
        $configFile = "{$basePath}/config/deploy.yaml";
        if (file_exists($configFile)) {
            $updated = $this->updateDeployYaml($configFile, $env, [
                'method' => 'git',
                'strategy' => $config->strategy ?: 'lean',
                'remote' => $remoteName,
                'source_branch' => $config->sourceBranch,
                'target_branch' => $config->targetBranch,
                'webhook_url' => $webhookUrl,
            ]);

            if ($updated) {
                $io->success("config/deploy.yaml updated with Git settings.");
            } else {
                $io->warning("Could not update config/deploy.yaml automatically.");
            }
        }

        // ---- Suggest webhook_secret ----
        $envFile = "{$basePath}/.env";
        if (!file_exists($envFile) || !str_contains((string) file_get_contents($envFile), 'DEPLOY_SECRET')) {
            $suggestedSecret = bin2hex(random_bytes(32));
            $io->section('Suggested webhook secret');
            $io->writeln("Add to your .env file:");
            $io->writeln("  DEPLOY_SECRET={$suggestedSecret}");
            $io->writeln("Then set webhook_secret: \${DEPLOY_SECRET} in config/deploy.yaml");
        }

        $io->success("Git deploy setup complete for environment '{$env}'.");
        return Command::SUCCESS;
    }

    /**
     * Update specific keys within an environment block of deploy.yaml.
     * Preserves existing keys; only adds/overwrites the provided keys.
     *
     * @param array<string, mixed> $updates
     */
    private function updateDeployYaml(string $configFile, string $env, array $updates): bool
    {
        try {
            $content = (string) file_get_contents($configFile);
            $yaml = Yaml::parse($content);

            if (!is_array($yaml)) {
                return false;
            }

            if (!isset($yaml[$env]) || !is_array($yaml[$env])) {
                $yaml[$env] = [];
            }

            foreach ($updates as $key => $value) {
                if ($value !== null) {
                    $yaml[$env][$key] = $value;
                }
            }

            // Note: Symfony Yaml::dump does not preserve comments.
            // This is a known limitation documented in command output above.
            $newContent = Yaml::dump($yaml, 6, 2);
            file_put_contents($configFile, $newContent);
            return true;
        } catch (\Throwable) {
            return false;
        }
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
