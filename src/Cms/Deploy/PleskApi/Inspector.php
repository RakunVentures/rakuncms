<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

use Rkn\Cms\Deploy\PleskApiException;

/**
 * Discovers Plesk server capabilities for a domain using XML-RPC.
 *
 * REST v2 does not expose Git info, PHP version, shell access, or document root.
 * All discovery is done via XML-RPC (see D1 in deploy-plesk-api.md).
 *
 * Each method is independently try/caught so that a partial failure in one
 * discovery sub-call does not abort the entire discovery operation.
 */
final class Inspector
{
    public function __construct(
        private readonly Client $client,
    ) {}

    /**
     * Check whether shell access is enabled for the subscription hosting the domain.
     *
     * Returns:
     *   true  — shell is set to a real shell (e.g. /bin/bash)
     *   false — shell is /sbin/nologin, /bin/false, or similar
     *   null  — information could not be determined (XML-RPC unavailable, domain not found, etc.)
     */
    public function hasShellAccess(string $domain): ?bool
    {
        try {
            $xml = XmlRpcEncoder::subscriptionGet($domain);
            $data = $this->client->xmlRpcCall($xml);
            $shell = $this->extractSubscriptionProperty($data, 'shell');

            if ($shell === null) {
                return null;
            }

            $disabledShells = ['/sbin/nologin', '/bin/false', 'false', 'none', ''];
            return !in_array(strtolower($shell), $disabledShells, true);
        } catch (PleskApiException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get Git repository information for the domain.
     *
     * Returns an array with keys:
     *   repo_name, webhook_url, active_branch, deploy_path
     * Or null if no Git repository exists or the extension is unavailable.
     *
     * @return array{repo_name: string, webhook_url: ?string, active_branch: ?string, deploy_path: ?string}|null
     */
    public function getGitInfo(string $domain): ?array
    {
        try {
            // Step 1: list repos to find the primary repo name
            $listXml = XmlRpcEncoder::extensionCall('git', ['cmd' => '--list', 'domain' => $domain]);
            $listData = $this->client->xmlRpcCall($listXml);
            $stdout = $this->extractExtensionStdout($listData);

            if ($stdout === null || trim($stdout) === '') {
                return null;
            }

            $repos = array_filter(array_map('trim', explode("\n", $stdout)));
            if (empty($repos)) {
                return null;
            }

            $repoName = (string) reset($repos);

            // Step 2: get detailed info for the first repo
            $infoXml = XmlRpcEncoder::extensionCall('git', [
                'cmd' => '--info',
                'domain' => $domain,
                'name' => $repoName,
            ]);
            $infoData = $this->client->xmlRpcCall($infoXml);
            $infoStdout = $this->extractExtensionStdout($infoData) ?? '';

            return [
                'repo_name' => $repoName,
                'webhook_url' => $this->parseGitInfoField($infoStdout, 'Webhook URL'),
                'active_branch' => $this->parseGitInfoField($infoStdout, 'Active branch'),
                'deploy_path' => $this->parseGitInfoField($infoStdout, 'Deploy path'),
            ];
        } catch (PleskApiException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get the document root path for a domain.
     *
     * Falls back to '/httpdocs' if the information cannot be retrieved.
     */
    public function getDocumentRoot(string $domain): string
    {
        try {
            $xml = XmlRpcEncoder::domainGet($domain);
            $data = $this->client->xmlRpcCall($xml);
            $root = $this->extractDomainProperty($data, 'www_root');

            return $root !== null && $root !== '' ? $root : '/httpdocs';
        } catch (PleskApiException) {
            return '/httpdocs';
        } catch (\Throwable) {
            return '/httpdocs';
        }
    }

    /**
     * Get PHP configuration for the domain.
     *
     * Returns an array with keys:
     *   version (e.g. "8.2"), handler (e.g. "fpm")
     * Or null if the information is unavailable.
     *
     * @return array{version: string, handler: string}|null
     */
    public function getPhpInfo(string $domain): ?array
    {
        try {
            $xml = XmlRpcEncoder::domainGet($domain);
            $data = $this->client->xmlRpcCall($xml);
            $handlerId = $this->extractDomainProperty($data, 'php_handler_id');

            if ($handlerId === null || $handlerId === '') {
                return null;
            }

            // Handler IDs follow the pattern "plesk-phpXY-handler"
            // e.g. "plesk-php82-fpm" → version="8.2", handler="fpm"
            //      "plesk-php74-fpm" → version="7.4", handler="fpm"
            if (preg_match('/plesk-php(\d)(\d+)-(\w+)/', $handlerId, $m)) {
                return [
                    'version' => "{$m[1]}.{$m[2]}",
                    'handler' => $m[3],
                ];
            }

            return ['version' => $handlerId, 'handler' => 'unknown'];
        } catch (PleskApiException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Run full discovery for a domain.
     *
     * Calls all four discovery methods independently. A failure in one
     * does NOT abort the rest — the result is degraded but structured.
     *
     * @return array{
     *   domain: string,
     *   has_shell: bool|null,
     *   git: array{repo_name: string, webhook_url: ?string, active_branch: ?string, deploy_path: ?string}|null,
     *   php: array{version: string, handler: string}|null,
     *   doc_root: string,
     *   discovered_at: string
     * }
     */
    public function discover(string $domain): array
    {
        $hasShell = null;
        try {
            $hasShell = $this->hasShellAccess($domain);
        } catch (\Throwable) {
        }

        $git = null;
        try {
            $git = $this->getGitInfo($domain);
        } catch (\Throwable) {
        }

        $phpInfo = null;
        try {
            $phpInfo = $this->getPhpInfo($domain);
        } catch (\Throwable) {
        }

        $docRoot = '/httpdocs';
        try {
            $docRoot = $this->getDocumentRoot($domain);
        } catch (\Throwable) {
        }

        return [
            'domain' => $domain,
            'has_shell' => $hasShell,
            'git' => $git,
            'php' => $phpInfo,
            'doc_root' => $docRoot,
            'discovered_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    // -------------------------------------------------------------------------
    // Private XML extraction helpers
    // -------------------------------------------------------------------------

    /**
     * Extract a hosting vrt_hst property value from a subscription/get response.
     *
     * @param array<mixed> $data
     */
    private function extractSubscriptionProperty(array $data, string $propertyName): ?string
    {
        // Path: subscription.get.result.data.hosting.vrt_hst.property[]
        $result = $data['subscription']['get']['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        // Handle multiple results (array of results)
        $resultItem = isset($result[0]) ? $result[0] : $result;
        $properties = $resultItem['data']['hosting']['vrt_hst']['property'] ?? null;

        return $this->findPropertyValue($properties, $propertyName);
    }

    /**
     * Extract a hosting vrt_hst property value from a domain/get response.
     *
     * @param array<mixed> $data
     */
    private function extractDomainProperty(array $data, string $propertyName): ?string
    {
        $result = $data['domain']['get']['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        $resultItem = isset($result[0]) ? $result[0] : $result;
        $properties = $resultItem['data']['hosting']['vrt_hst']['property'] ?? null;

        return $this->findPropertyValue($properties, $propertyName);
    }

    /**
     * Find a value in a Plesk property list.
     * Handles both single-property (associative) and multi-property (indexed) formats.
     *
     * @param mixed $properties
     */
    private function findPropertyValue(mixed $properties, string $propertyName): ?string
    {
        if (!is_array($properties)) {
            return null;
        }

        // Single property (not wrapped in numeric index)
        if (isset($properties['name'])) {
            $name = XmlRpcDecoder::extractText($properties, 'name');
            if ($name === $propertyName) {
                return XmlRpcDecoder::extractText($properties, 'value');
            }
            return null;
        }

        // Multiple properties (numerically indexed)
        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $name = XmlRpcDecoder::extractText($prop, 'name');
            if ($name === $propertyName) {
                return XmlRpcDecoder::extractText($prop, 'value');
            }
        }

        return null;
    }

    /**
     * Extract stdout from an extension/call result.
     *
     * @param array<mixed> $data
     */
    private function extractExtensionStdout(array $data): ?string
    {
        $result = $data['extension']['call']['result'] ?? null;
        if (!is_array($result)) {
            return null;
        }

        return XmlRpcDecoder::extractText($result, 'stdout');
    }

    /**
     * Parse a key-value field from Git info stdout output.
     * Format: "Field Name: value"
     */
    private function parseGitInfoField(string $stdout, string $fieldName): ?string
    {
        if (preg_match('/^' . preg_quote($fieldName, '/') . ':\s+(.+)$/m', $stdout, $matches)) {
            return trim($matches[1]);
        }
        return null;
    }
}
