<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy;

use RuntimeException;
use Symfony\Component\Yaml\Yaml;

final class DeployConfig
{
    public string $environment;
    public string $method;
    public string $strategy;
    public string $host;
    public string $domain;
    public string $path;
    public ?string $user = null;
    public ?string $pass = null;
    public ?string $identityFile = null;
    public int $port = 21;
    public bool $secure = true;
    public ?string $remote = null;
    public ?string $webhookUrl = null;
    public ?string $deploySecret = null;
    public array $exclude = [];

    // Git-specific deployment fields (Phase 2)
    public ?string $composerBin = null;
    public string $sourceBranch = 'main';
    public string $targetBranch = 'main';
    public ?string $webhookSecret = null;
    public ?string $healthUrl = null;
    public bool $allowDirty = false;
    public bool $verifySsl = true;

    /** @var array<string, mixed>|null Snapshot captured by deploy:init and compared by deploy:check */
    public ?array $discovered = null;

    /** Target release ID for rollback (set by deploy:rollback --to=<id>) */
    public ?string $rollbackTo = null;

    /** Plesk-specific config block */
    public ?string $pleskHost = null;
    public ?string $pleskApiKey = null;
    public bool $pleskVerifySsl = true;

    /** GitHub-pull pipeline fields (method=github-pull) */
    public ?string $githubOwner = null;
    public ?string $githubRepo = null;
    public ?string $githubToken = null;
    public ?string $pleskRepoName = null;
    public bool $pleskDeployAsync = false;
    public int $pleskDeployPollTimeout = 180;
    public int $pleskDeployPollInterval = 3;

    public static function load(string $basePath, string $environment): self
    {
        $configFile = $basePath . '/config/deploy.yaml';
        if (!file_exists($configFile)) {
            throw new RuntimeException("Deploy configuration not found at config/deploy.yaml. Run 'rakun deploy:init' first.");
        }

        $content = file_get_contents($configFile) ?: '';
        
        // Interpolate ENV variables like ${WEBHOOK_URL}
        $content = preg_replace_callback('/\$\{([A-Z0-9_]+)(?::-([^}]*))?\}/', function ($matches) {
            $val = $_ENV[$matches[1]] ?? $_SERVER[$matches[1]] ?? getenv($matches[1]);
            return ($val !== false && $val !== null) ? $val : ($matches[2] ?? '');
        }, $content) ?: $content;

        $yaml = Yaml::parse($content);

        if (!isset($yaml[$environment])) {
            throw new RuntimeException("Environment '$environment' not found in deploy.yaml");
        }

        $data = $yaml[$environment];
        
        $config = new self();
        $config->environment = $environment;
        $config->method = $data['method'] ?? 'git';
        $config->strategy = $data['strategy'] ?? 'lean';
        $config->host = $data['host'] ?? '';
        $config->domain = $data['domain'] ?? '';
        $config->path = $data['path'] ?? '/httpdocs';
        
        $config->user = $data['user'] ?? null;
        $config->pass = $data['password'] ?? null;
        $config->identityFile = $data['identity_file'] ?? null;
        $config->port = (int)($data['port'] ?? ($config->method === 'sftp' ? 22 : 21));
        $config->secure = (bool)($data['secure'] ?? true);
        $config->deploySecret = $data['deploy_secret'] ?? null;
        $config->exclude = is_array($data['exclude'] ?? null) ? $data['exclude'] : [];

        if ($config->method === 'git') {
            $config->remote = $data['remote'] ?? 'plesk';
            $config->webhookUrl = $data['webhook_url'] ?? null;
            $config->sourceBranch = $data['source_branch'] ?? 'main';
            $config->targetBranch = $data['target_branch'] ?? $config->sourceBranch;
            $config->webhookSecret = $data['webhook_secret'] ?? null;
            $config->healthUrl = $data['health_url'] ?? null;
            $config->composerBin = $data['composer_bin'] ?? null;
            $config->verifySsl = (bool) ($data['verify_ssl'] ?? true);
        }

        if ($config->method === 'github-pull') {
            $config->remote = $data['remote'] ?? 'origin';
            $config->sourceBranch = $data['source_branch'] ?? 'main';
            $config->targetBranch = $data['target_branch'] ?? $config->sourceBranch;
            $config->healthUrl = $data['health_url'] ?? null;
            $config->verifySsl = (bool) ($data['verify_ssl'] ?? true);

            $github = is_array($data['github'] ?? null) ? $data['github'] : [];
            $config->githubOwner = $github['owner'] ?? null;
            $config->githubRepo = $github['repo'] ?? null;
            $config->githubToken = $github['token'] ?? null;

            $plesk = is_array($data['plesk'] ?? null) ? $data['plesk'] : [];
            $config->pleskRepoName = $plesk['repo_name'] ?? null;
            $config->pleskDeployAsync = (bool) ($plesk['deploy_async'] ?? false);
            $config->pleskDeployPollTimeout = (int) ($plesk['deploy_poll_timeout'] ?? 180);
            $config->pleskDeployPollInterval = (int) ($plesk['deploy_poll_interval'] ?? 3);
        }

        // Load Plesk block if present (panel host, not deploy host)
        if (isset($data['plesk']) && is_array($data['plesk'])) {
            $config->pleskHost = $data['plesk']['host'] ?? null;
            $config->pleskApiKey = $data['plesk']['api_key'] ?? null;
            $config->pleskVerifySsl = (bool) ($data['plesk']['verify_ssl'] ?? true);
        }

        // For FTP/SFTP methods, default deploy host to the domain itself if not specified
        if (in_array($config->method, ['ftp', 'sftp'], true) && $config->host === '') {
            $config->host = $config->domain;
        }

        // Load discovery snapshot if present (used by deploy:check)
        if (isset($data['discovered']) && is_array($data['discovered'])) {
            $config->discovered = $data['discovered'];
        }

        return $config;
    }
}
