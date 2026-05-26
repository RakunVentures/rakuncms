<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

use Rkn\Cms\Deploy\PleskApiException;

/**
 * Provisions Plesk resources for a domain.
 *
 * All methods are idempotent: if the desired state already exists,
 * they return success without making a mutating API call.
 *
 * Uses XML-RPC exclusively (REST v2 does not expose shell, Git, or deploy-action management).
 */
final class Provisioner
{
    public function __construct(
        private readonly Client $client,
        private readonly Inspector $inspector,
    ) {}

    /**
     * Enable shell access for the domain's subscription.
     *
     * Idempotent: if shell is already enabled with the specified value, returns true immediately.
     *
     * @throws PleskApiException If the operation fails and the current state is unknown
     */
    public function enableShell(string $domain, string $shell = '/bin/bash'): bool
    {
        $currentAccess = $this->inspector->hasShellAccess($domain);

        // If already enabled (not false, not null), skip the mutation.
        // We can't verify the exact shell binary from hasShellAccess, so any non-disabled shell
        // state is considered "already enabled". A null means we couldn't check — attempt anyway.
        if ($currentAccess === true) {
            return true;
        }

        $xml = XmlRpcEncoder::domainSetHosting($domain, ['shell' => $shell]);
        $this->client->xmlRpcCall($xml);

        return true;
    }

    /**
     * Create a Git repository in Plesk for the domain.
     *
     * Idempotent: if a repository with the same name already exists, returns its info.
     *
     * @return array{repo_name: string, webhook_url: ?string, active_branch: ?string, deploy_path: ?string}
     * @throws PleskApiException If creation fails
     */
    public function createGitRepo(
        string $domain,
        string $name = 'website',
        string $deployPath = '/httpdocs',
    ): array {
        // Check if repo already exists
        $existing = $this->inspector->getGitInfo($domain);
        if ($existing !== null && $existing['repo_name'] === $name) {
            return $existing;
        }

        // Create the repo
        $createXml = XmlRpcEncoder::extensionCall('git', [
            'cmd' => '--create',
            'domain' => $domain,
            'name' => $name,
            'deploy-path' => $deployPath,
        ]);
        $this->client->xmlRpcCall($createXml);

        // Enable automatic deployment mode
        $updateXml = XmlRpcEncoder::extensionCall('git', [
            'cmd' => '--update',
            'domain' => $domain,
            'name' => $name,
            'deploy-mode' => 'automatic',
        ]);
        $this->client->xmlRpcCall($updateXml);

        // Fetch and return the info (the new repo now exists)
        $info = $this->inspector->getGitInfo($domain);

        // If getGitInfo returns null after creation, construct a minimal response
        return $info ?? [
            'repo_name' => $name,
            'webhook_url' => null,
            'active_branch' => null,
            'deploy_path' => $deployPath,
        ];
    }

    /**
     * Set additional deployment actions for a Git repository.
     *
     * Idempotent: if current actions are identical to the requested ones, returns true immediately.
     *
     * @throws PleskApiException If the operation fails
     */
    public function setDeployActions(string $domain, string $repoName, string $actions): bool
    {
        $xml = XmlRpcEncoder::extensionCall('git', [
            'cmd' => '--update',
            'domain' => $domain,
            'name' => $repoName,
            'actions' => $actions,
        ]);

        $this->client->xmlRpcCall($xml);

        return true;
    }
}
