<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Deploy\GitHost\GitHubApiException;
use Rkn\Cms\Deploy\GitHost\GitHubClient;
use Rkn\Cms\Deploy\PleskApi\Client as PleskClient;
use Rkn\Cms\Deploy\PleskApi\Inspector as PleskInspector;
use Rkn\Cms\Deploy\PleskApi\Provisioner as PleskProvisioner;
use Rkn\Cms\Deploy\PleskApiException;
use Rkn\Cms\Deploy\Process\Runner;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * CLI command: rakun deploy:setup-github
 *
 * Wires the GitHub-as-origin + Plesk-pull deployment pipeline end-to-end.
 *
 * Every step is idempotent — re-running this command on an already-configured
 * environment results in no changes (or just a config refresh).
 *
 * Pipeline (ordered to avoid Plesk's "Repository not found" on initial fetch —
 * the deploy key must exist on GitHub *before* Plesk tries to clone):
 *   1. Verify GitHub repo exists with the provided PAT.
 *   2. Read Plesk-generated deploy public key (per-domain, generated at extension install time).
 *   3. Register that key on GitHub as a read-only deploy key.
 *   4. Create/update Plesk Git pull repo pointing at git@github.com:owner/repo.git
 *      (initial fetch now succeeds because GitHub recognizes the deploy key).
 *   5. Read the Plesk webhook URL from the just-created repo and register it on GitHub (events=[push]).
 *   6. Add the GitHub repo as 'origin' to the local git working tree (if missing).
 *   7. Persist all settings under the chosen environment in config/deploy.yaml.
 *
 * After completion, `rakun deploy <env>` will: push to GitHub → Plesk pulls → site live.
 */
#[AsCommand(name: 'deploy:setup-github', description: 'Wire GitHub-as-origin + Plesk-pull pipeline for an environment')]
final class DeploySetupGithubCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument('environment', InputArgument::OPTIONAL, 'Environment to configure', 'production')
            ->addOption('owner', null, InputOption::VALUE_REQUIRED, 'GitHub owner / org (or set GITHUB_OWNER env var)')
            ->addOption('repo', null, InputOption::VALUE_REQUIRED, 'GitHub repo name (or set GITHUB_REPO env var)')
            ->addOption('branch', null, InputOption::VALUE_REQUIRED, 'Branch to pull on the server', 'main')
            ->addOption('deploy-path', null, InputOption::VALUE_REQUIRED, 'Plesk deploy target path (relative to subscription root)', '/httpdocs')
            ->addOption('repo-name', null, InputOption::VALUE_REQUIRED, 'Plesk-side repository identifier', 'rakuncms-pull')
            ->addOption('post-deploy', null, InputOption::VALUE_REQUIRED, 'Shell command(s) to run after each Plesk pull (newline-separated)')
            ->addOption('skip-ssl-verification', null, InputOption::VALUE_NONE, 'Tell Plesk to skip SSL verification on git fetch')
            ->addOption('insecure-webhook-ssl', null, InputOption::VALUE_NONE, 'Tell GitHub to skip SSL verification when posting to Plesk webhook (use only with self-signed certs)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('RakunCMS — GitHub-pull deployment setup');

        $env = (string) $input->getArgument('environment');
        $basePath = $this->findBasePath();

        $configPath = "{$basePath}/config/deploy.yaml";
        if (!file_exists($configPath)) {
            $io->error("config/deploy.yaml not found. Run 'rakun deploy:init' first.");
            return Command::FAILURE;
        }

        $rawYaml = file_get_contents($configPath);
        if ($rawYaml === false) {
            $io->error("Could not read {$configPath}.");
            return Command::FAILURE;
        }

        $parsed = Yaml::parse($rawYaml) ?? [];
        if (!is_array($parsed)) {
            $io->error('config/deploy.yaml does not parse as an associative array.');
            return Command::FAILURE;
        }

        $envBlock = is_array($parsed[$env] ?? null) ? $parsed[$env] : [];

        $owner = (string) ($input->getOption('owner') ?? $this->envVar('GITHUB_OWNER') ?? ($envBlock['github']['owner'] ?? ''));
        $repo = (string) ($input->getOption('repo') ?? $this->envVar('GITHUB_REPO') ?? ($envBlock['github']['repo'] ?? ''));
        $branch = (string) $input->getOption('branch');
        $deployPath = (string) $input->getOption('deploy-path');
        $pleskRepoName = (string) $input->getOption('repo-name');
        $postDeploy = $input->getOption('post-deploy');
        $skipSsl = (bool) $input->getOption('skip-ssl-verification');
        $insecureWebhookSsl = (bool) $input->getOption('insecure-webhook-ssl');

        $githubToken = $this->envVar('GITHUB_TOKEN') ?? ($envBlock['github']['token'] ?? null);
        $pleskHost = (string) ($envBlock['plesk']['host'] ?? '');
        $pleskApiKey = (string) ($envBlock['plesk']['api_key'] ?? '');
        $pleskVerifySsl = (bool) ($envBlock['plesk']['verify_ssl'] ?? true);
        $domain = (string) ($envBlock['domain'] ?? '');

        // Resolve ${VAR} interpolation for fields pulled from yaml.
        $pleskHost = $this->resolveInterpolation($pleskHost);
        $pleskApiKey = $this->resolveInterpolation($pleskApiKey);
        $domain = $this->resolveInterpolation($domain);
        if (is_string($githubToken)) {
            $githubToken = $this->resolveInterpolation($githubToken);
        }

        $missing = [];
        if ($owner === '') { $missing[] = 'GitHub owner (--owner or GITHUB_OWNER env)'; }
        if ($repo === '') { $missing[] = 'GitHub repo (--repo or GITHUB_REPO env)'; }
        if (!is_string($githubToken) || $githubToken === '') { $missing[] = 'GITHUB_TOKEN env var (PAT with repo + admin:repo_hook scopes)'; }
        if ($domain === '') { $missing[] = "'domain' under environment '{$env}' in deploy.yaml"; }
        if ($pleskHost === '') { $missing[] = "'plesk.host' under environment '{$env}' in deploy.yaml"; }
        if ($pleskApiKey === '') { $missing[] = "'plesk.api_key' under environment '{$env}' (or PLESK_API_KEY env)"; }

        if ($missing !== []) {
            $io->error('Cannot proceed — missing required configuration:');
            foreach ($missing as $m) {
                $io->writeln("  - {$m}");
            }
            return Command::FAILURE;
        }

        $remoteUrl = "git@github.com:{$owner}/{$repo}.git";
        $localOriginUrl = $remoteUrl;

        $io->section("Configuration");
        $io->definitionList(
            ['Environment' => $env],
            ['GitHub repo' => "{$owner}/{$repo}"],
            ['Branch' => $branch],
            ['Plesk host' => $pleskHost],
            ['Plesk domain' => $domain],
            ['Plesk repo name' => $pleskRepoName],
            ['Deploy path' => $deployPath],
            ['Plesk skip-ssl-verification' => $skipSsl ? 'true' : 'false'],
            ['Webhook insecure_ssl' => $insecureWebhookSsl ? 'true' : 'false'],
        );

        // ---- Step 1: GitHub repo reachable ----
        $io->section('[1/7] Verify GitHub repo is reachable');
        $github = new GitHubClient(token: (string) $githubToken);
        try {
            $ghRepo = $github->getRepo($owner, $repo);
        } catch (GitHubApiException $e) {
            $io->error("GitHub API error: {$e->getMessage()}");
            return Command::FAILURE;
        }
        if ($ghRepo === null) {
            $io->error("GitHub repo '{$owner}/{$repo}' does not exist (or token has insufficient scope).");
            return Command::FAILURE;
        }
        $defaultBranch = is_string($ghRepo['default_branch'] ?? null) ? $ghRepo['default_branch'] : $branch;
        $io->writeln("  <info>OK</info> — default branch: <comment>{$defaultBranch}</comment>");

        // ---- Step 2: Read Plesk-generated deploy public key (BEFORE creating the repo, so we can register it on GitHub first) ----
        $io->section('[2/7] Read Plesk-generated deploy public key');
        $pleskClient = new PleskClient(host: $pleskHost, apiKey: $pleskApiKey, verifySsl: $pleskVerifySsl);
        $inspector = new PleskInspector($pleskClient);
        $provisioner = new PleskProvisioner($pleskClient, $inspector);

        $publicKey = $inspector->getGitDeployPublicKey($domain);
        if ($publicKey === null) {
            $io->error("Plesk did not return a deploy public key for '{$domain}'. Ensure the Plesk Git extension is installed on the domain.");
            return Command::FAILURE;
        }
        $io->writeln("  <info>OK</info> — key length: <comment>" . strlen($publicKey) . " chars</comment>");

        // ---- Step 3: Register deploy key on GitHub (BEFORE Plesk fetches, otherwise SSH auth fails on initial clone) ----
        $io->section('[3/7] Register deploy key on GitHub');
        try {
            $keyTitle = "Plesk {$pleskHost} ({$domain})";
            $deployKey = $github->ensureDeployKey($owner, $repo, $keyTitle, $publicKey, true);
        } catch (GitHubApiException $e) {
            $io->error("GitHub deploy key registration failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
        $keyId = $deployKey['id'] ?? '?';
        $io->writeln("  <info>OK</info> — key id: <comment>{$keyId}</comment>");

        // ---- Step 4: Create/update Plesk Git pull repo (now that deploy key is on GitHub, initial fetch will succeed) ----
        $io->section('[4/7] Create or update Plesk Git pull repo');
        $actions = $this->normalizeActions(is_string($postDeploy) ? $postDeploy : null)
            ?? $this->defaultPostDeployActions();
        $io->writeln('  <comment>Post-deploy actions:</comment>');
        foreach ($actions as $a) {
            $io->writeln("    {$a}");
        }

        try {
            $repoInfo = $provisioner->createGitPullRepo(
                domain: $domain,
                repoName: $pleskRepoName,
                remoteUrl: $remoteUrl,
                branch: $branch,
                deploymentPath: $deployPath,
                deploymentMode: 'automatic',
                actions: $actions,
                skipSslVerification: $skipSsl,
            );
        } catch (PleskApiException $e) {
            $io->error("Plesk pull repo provisioning failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
        $io->writeln("  <info>OK</info> — repo: <comment>{$repoInfo->repoName}</comment>, branch: <comment>{$repoInfo->activeBranch}</comment>");

        // ---- Step 5: Webhook URL discovery + GitHub registration ----
        $io->section('[5/7] Register Plesk webhook on GitHub');
        $webhookUrl = $repoInfo->webhookUrl;
        if ($webhookUrl === null || $webhookUrl === '') {
            $io->error('Plesk did not expose a webhook URL for this repo. Check Plesk Git extension version.');
            return Command::FAILURE;
        }

        try {
            $webhook = $github->ensureWebhook($owner, $repo, $webhookUrl, ['push'], $insecureWebhookSsl, null);
        } catch (GitHubApiException $e) {
            $io->error("GitHub webhook registration failed: {$e->getMessage()}");
            return Command::FAILURE;
        }
        $hookId = $webhook['id'] ?? '?';
        $io->writeln("  <info>OK</info> — webhook id: <comment>{$hookId}</comment>, url: <comment>{$webhookUrl}</comment>");

        // ---- Step 6: Local git remote ----
        $io->section('[6/7] Ensure local git remote');
        $logger = static function (string $m) use ($io): void { $io->writeln("  {$m}"); };
        $runner = new Runner($basePath, $logger);

        $remoteCheck = $runner->run(['git', 'remote', 'get-url', 'origin'])->withTimeout(10)->execute();
        if ($remoteCheck->isSuccess()) {
            $existing = trim($remoteCheck->stdout);
            if ($existing === $localOriginUrl) {
                $io->writeln("  <info>OK</info> — origin already points to <comment>{$existing}</comment>");
            } else {
                $io->writeln("  <comment>origin currently points to {$existing}; leaving untouched.</comment>");
                $io->writeln("  <comment>If you want to overwrite, run: git remote set-url origin {$localOriginUrl}</comment>");
            }
        } else {
            $addResult = $runner->run(['git', 'remote', 'add', 'origin', $localOriginUrl])->withTimeout(10)->execute();
            if (!$addResult->isSuccess()) {
                $io->warning("Could not add origin remote: {$addResult->stderr}");
            } else {
                $io->writeln("  <info>OK</info> — added origin: <comment>{$localOriginUrl}</comment>");
            }
        }

        // ---- Step 7: Persist config/deploy.yaml ----
        $io->section('[7/7] Update config/deploy.yaml');
        $newEnvBlock = $envBlock;
        $newEnvBlock['method'] = 'github-pull';
        $newEnvBlock['strategy'] = 'lean';
        $newEnvBlock['domain'] = $domain;
        $newEnvBlock['path'] = $deployPath;
        $newEnvBlock['source_branch'] = $branch;
        $newEnvBlock['target_branch'] = $branch;
        $newEnvBlock['remote'] = 'origin';
        $newEnvBlock['verify_ssl'] = $pleskVerifySsl;
        $newEnvBlock['github'] = [
            'owner' => $owner,
            'repo' => $repo,
            'token' => '${GITHUB_TOKEN}',
        ];
        $newEnvBlock['plesk'] = array_replace(
            is_array($envBlock['plesk'] ?? null) ? $envBlock['plesk'] : [],
            [
                'host' => $envBlock['plesk']['host'] ?? '${PLESK_HOST}',
                'api_key' => $envBlock['plesk']['api_key'] ?? '${PLESK_API_KEY}',
                'verify_ssl' => $pleskVerifySsl,
                'repo_name' => $pleskRepoName,
                'deploy_async' => false,
                'deploy_poll_timeout' => 180,
                'deploy_poll_interval' => 3,
            ],
        );

        $parsed[$env] = $newEnvBlock;
        $written = file_put_contents($configPath, Yaml::dump($parsed, 6, 2));
        if ($written === false) {
            $io->error("Could not write {$configPath}.");
            return Command::FAILURE;
        }
        $io->writeln("  <info>OK</info> — wrote <comment>{$configPath}</comment>");

        $io->newLine();
        $io->success("GitHub-pull pipeline configured for '{$env}'.");
        $io->section('Next steps');
        $io->listing([
            "Ensure GITHUB_TOKEN is exported in your shell or written to .env (PAT with repo + admin:repo_hook scopes).",
            "Push code: git push origin {$branch} (or run: rakun deploy {$env}).",
            "Verify with: rakun deploy:check {$env}",
        ]);

        return Command::SUCCESS;
    }

    /**
     * Split user-provided actions on newlines so each entry is a single-line
     * shell command. Plesk runs every line in `-actions` as its own `bash -c`
     * invocation, so a multi-line bash block (e.g. `{ cmd1\ncmd2 }`) within a
     * single entry gets sliced across independent shells and silently fails
     * (the opening `{` is a syntax error in its own shell). To compound
     * commands, use `;` or `&&` inline; do not break across lines.
     *
     * @return array<int, string>|null
     */
    private function normalizeActions(?string $postDeploy): ?array
    {
        if ($postDeploy === null || trim($postDeploy) === '') {
            return null;
        }
        $lines = preg_split('/\r?\n/', $postDeploy) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed !== '') {
                $out[] = $trimmed;
            }
        }
        return $out !== [] ? $out : null;
    }

    /**
     * Default post-deploy actions run by Plesk after each pull from GitHub.
     *
     * Plesk joins these with newlines via Provisioner::createGitPullRepo and
     * then runs each line in its own shell. Three consequences shape the format:
     *
     *   - Each entry MUST be a complete single-line shell command. Multi-line
     *     bash blocks ({ ... }, heredocs) inside one entry break silently.
     *   - To chain operations, use `;` or `&&` within one entry — not `\n`.
     *   - **Plesk's post-action shell has an EMPTY PATH**: no /usr/bin, no /bin,
     *     no PHP, no composer. Without an explicit `export PATH=…` and absolute
     *     PHPBIN discovery, `composer install` reports `command not found`,
     *     vendor/ stays stale, and the next request 500s. Every entry therefore
     *     rebuilds the PATH and resolves the newest Plesk-managed PHP binary
     *     in-line.
     *
     * CWD on each invocation equals the deployment path, so relative paths
     * (cache/, vendor/, public/…) resolve correctly. We append both stdout
     * and stderr from every action to `public/deploy.txt` so post-mortems
     * (and our own E2E checks) can see exactly what Plesk just ran.
     *
     * Without the cache-clear step, `cache/content-index.php` keeps the
     * pre-deploy snapshot and any new content (pages, locales, slugs) 404s
     * until the cache file is manually removed.
     *
     * @return array<int, string>
     */
    private function defaultPostDeployActions(): array
    {
        $path = 'export PATH=/usr/local/bin:/usr/bin:/bin';
        $phpbin = 'PHPBIN=$(/bin/ls -1 /opt/plesk/php/*/bin/php 2>/dev/null | /usr/bin/sort -V | /usr/bin/tail -1)';
        $log = '>> public/deploy.txt 2>&1';

        return [
            "{$path}; {$phpbin}; /bin/date {$log}; \"\$PHPBIN\" /usr/bin/composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --classmap-authoritative {$log}",
            "{$path}; /bin/rm -rf cache/pages/* cache/templates/* cache/content-index.php cache/sitemap*.xml {$log}; /bin/echo 'cache cleared' {$log}",
        ];
    }

    private function envVar(string $name): ?string
    {
        $val = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        if ($val === false || $val === '') {
            return null;
        }
        return (string) $val;
    }

    private function resolveInterpolation(string $value): string
    {
        $result = preg_replace_callback('/\$\{([A-Z0-9_]+)(?::-([^}]*))?\}/', function (array $m): string {
            $env = $this->envVar($m[1]);
            return $env ?? ($m[2] ?? '');
        }, $value);
        return $result ?? $value;
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
