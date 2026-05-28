<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

/**
 * Structured representation of a Plesk Git repository configuration.
 *
 * Mirrors the fields returned by `extension --call git --info -domain X -name Y`:
 *   Domain name: xyz.rkn.mx
 *   Repository name: rakuncms-production.git
 *   Deployment path: /
 *   Deployment mode: automatic
 *   Active branch: main
 *   Repository type: pull          (or "push")
 *   Remote URL: https://github.com/owner/repo.git
 *   Webhook URL: https://plesk.host:8443/modules/git/public/web-hook.php?uuid=...
 *   Skip SSL verification: disabled
 *   Run Post-Deploy Actions: enabled
 */
final readonly class GitRepoInfo
{
    public function __construct(
        public string $domain,
        public string $repoName,
        public ?string $deploymentPath,
        public ?string $deploymentMode,
        public ?string $activeBranch,
        public string $repositoryType,
        public ?string $remoteUrl,
        public ?string $webhookUrl,
        public bool $skipSslVerification,
        public bool $runPostDeployActions,
    ) {}

    public function isPullRepo(): bool
    {
        return strtolower($this->repositoryType) === 'pull';
    }

    public function isAutomatic(): bool
    {
        return strtolower((string) $this->deploymentMode) === 'automatic';
    }
}
