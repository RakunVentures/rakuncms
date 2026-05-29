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
 *   Actions: composer install...
 *            rm -rf cache/*
 *
 * The Plesk `--info` output sometimes prints `Actions:` followed by one or
 * more indented lines (one per registered post-deploy action). The Inspector
 * extracts and normalizes those lines into the {@see $actions} array. When
 * Plesk does not surface the actions at all (older extension versions,
 * unreadable output), {@see $actions} is {@code null} — distinct from "no
 * actions registered" which is an empty array.
 */
final readonly class GitRepoInfo
{
    /**
     * @param array<int, string>|null $actions Registered post-deploy actions
     *        (one entry per shell command). {@code null} when the value
     *        could not be parsed from Plesk's output.
     */
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
        public ?array $actions = null,
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
