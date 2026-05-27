<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Deploy\DeployConfig;
use Rkn\Cms\Deploy\PleskApiException;
use Rkn\Cms\Deploy\PleskTransportException;
use Rkn\Cms\Deploy\PleskApi\Client;
use Rkn\Cms\Deploy\PleskApi\Inspector;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI command: rakun deploy:check
 *
 * Re-runs the Plesk discovery and compares it against the stored
 * snapshot in config/deploy.yaml's `discovered:` block.
 *
 * Output:
 *   OK     — field matches stored value
 *   DRIFT  — field has changed since last deploy:init
 *   MISSING — field was present in stored snapshot but can no longer be retrieved
 *
 * Exit codes:
 *   0 — all checks passed
 *   1 — drift detected or server unreachable
 */
#[AsCommand(name: 'deploy:check', description: 'Validate that Plesk configuration matches the stored snapshot')]
final class DeployCheckCommand extends Command
{
    /**
     * Optional pre-wired inspector — used in tests to inject FakeTransport.
     * When null, the command builds its own Client+Inspector from deploy.yaml config.
     */
    public function __construct(private readonly ?Inspector $injectedInspector = null)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption(
            'env',
            'e',
            InputOption::VALUE_OPTIONAL,
            'Environment to check (matches a top-level key in deploy.yaml)',
            'production',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('RakunCMS — Plesk Deployment Check');

        $env = (string) ($input->getOption('env') ?? 'production');
        $basePath = $this->findBasePath();

        // ---- Load config ----

        try {
            $config = DeployConfig::load($basePath, $env);
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if ($config->discovered === null) {
            $io->warning("No 'discovered:' block found in config/deploy.yaml. Run 'rakun deploy:init' first.");
            return Command::FAILURE;
        }

        // ---- Connect ----

        $io->writeln("Connecting to {$config->host} for domain {$config->domain}…");

        if ($this->injectedInspector !== null) {
            // Pre-wired inspector (e.g. from tests with FakeTransport) — skip credential check
            $inspector = $this->injectedInspector;
        } else {
            $apiKey = $config->pleskApiKey;

            if ($apiKey === null || $apiKey === '') {
                // Try reading from environment directly (supports ${PLESK_API_KEY} substitution)
                $envValue = getenv('PLESK_API_KEY');
                $apiKey = ($envValue !== false && $envValue !== '') ? $envValue : null;
            }

            if ($apiKey === null) {
                $io->error('PLESK_API_KEY is not set. Cannot connect to Plesk for drift check.');
                return Command::FAILURE;
            }

            $client = new Client($config->host, $apiKey, $config->pleskVerifySsl);
            $inspector = new Inspector($client);
        }

        // ---- Discover current state ----

        try {
            $current = $inspector->discover($config->domain);
        } catch (PleskTransportException $e) {
            $io->error("Server unreachable: {$e->getMessage()}");
            return Command::FAILURE;
        } catch (PleskApiException $e) {
            $io->error("API error during discovery: {$e->getMessage()}");
            return Command::FAILURE;
        }

        // ---- Compare snapshots ----

        $stored = $config->discovered;
        $hasDrift = false;

        $io->section("Drift report for {$config->domain} ({$env})");

        // has_shell
        if ($this->checkField($io, 'has_shell', $stored['has_shell'] ?? null, $current['has_shell'])) {
            $hasDrift = true;
        }

        // git fields
        $storedGit = $stored['git'] ?? null;
        $currentGit = $current['git'] ?? null;

        if ($storedGit !== null && $currentGit === null) {
            $io->writeln('<error>MISSING git</error>  — Git repository was present but can no longer be found.');
            $hasDrift = true;
        } elseif ($storedGit !== null && $currentGit !== null) {
            foreach (['repo_name', 'active_branch', 'deploy_path'] as $field) {
                $storedGitVal = is_array($storedGit) ? ($storedGit[$field] ?? null) : null;
                $currentGitVal = $currentGit[$field] ?? null;
                if ($this->checkField($io, "git.{$field}", $storedGitVal, $currentGitVal)) {
                    $hasDrift = true;
                }
            }
            // webhook_url: stored may be a placeholder ${PLESK_WEBHOOK_URL}; compare actual values if both are real URLs
            $storedWebhook = is_array($storedGit) ? ($storedGit['webhook_url'] ?? null) : null;
            $currentWebhook = $currentGit['webhook_url'] ?? null;
            if ($storedWebhook !== null && is_string($storedWebhook) && !str_starts_with($storedWebhook, '${')) {
                if ($this->checkField($io, 'git.webhook_url', $storedWebhook, $currentWebhook)) {
                    $hasDrift = true;
                }
            } else {
                $io->writeln('<comment>SKIP   git.webhook_url</comment>  — stored value is a placeholder; comparison skipped.');
            }
        } elseif ($storedGit === null && $currentGit !== null) {
            $io->writeln("<info>NEW    git.repo_name</info>  — new value: {$currentGit['repo_name']}");
        } else {
            $io->writeln('<info>OK     git</info>  — no Git repository (matches stored)');
        }

        // php fields
        $storedPhp = $stored['php'] ?? null;
        $currentPhp = $current['php'] ?? null;

        if ($storedPhp !== null && $currentPhp === null) {
            $io->writeln('<error>MISSING php</error>  — PHP info was present but can no longer be retrieved.');
            $hasDrift = true;
        } elseif ($storedPhp !== null && $currentPhp !== null) {
            foreach (['version', 'handler'] as $field) {
                $storedVal = is_array($storedPhp) ? ($storedPhp[$field] ?? null) : null;
                $currentVal = $currentPhp[$field];
                if ($this->checkField($io, "php.{$field}", $storedVal, $currentVal)) {
                    $hasDrift = true;
                }
            }
        }

        // doc_root
        if ($this->checkField($io, 'doc_root', $stored['doc_root'] ?? null, $current['doc_root'])) {
            $hasDrift = true;
        }

        // ---- Summary ----

        $io->newLine();
        if ($hasDrift) {
            $io->error('Drift detected. Review the report above and re-run deploy:init if necessary.');
            return Command::FAILURE;
        }

        $io->success('All checks passed. No drift detected.');
        return Command::SUCCESS;
    }

    /**
     * Compare a stored value with the current value and output a formatted result.
     * Returns true if drift was detected.
     */
    private function checkField(SymfonyStyle $io, string $name, mixed $stored, mixed $current): bool
    {
        $storedStr = $this->valueToString($stored);
        $currentStr = $this->valueToString($current);

        $namePadded = str_pad($name, 25);

        if ($stored === null && $current === null) {
            $io->writeln("<info>OK     {$namePadded}</info>  null (both unknown)");
            return false;
        }

        if ($current === null && $stored !== null) {
            $io->writeln("<error>MISSING {$namePadded}</error>  stored={$storedStr}, actual=null");
            return true;
        }

        if ($storedStr !== $currentStr) {
            $io->writeln("<error>DRIFT  {$namePadded}</error>  stored={$storedStr}, actual={$currentStr}");
            return true;
        }

        $io->writeln("<info>OK     {$namePadded}</info>  {$currentStr}");
        return false;
    }

    private function valueToString(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        return (string) $value;
    }

    private function findBasePath(): string
    {
        return getcwd() ?: dirname(__DIR__, 3);
    }
}
