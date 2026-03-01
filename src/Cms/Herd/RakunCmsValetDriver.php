<?php

declare(strict_types=1);

/**
 * RakunCMS Valet/Herd Driver.
 *
 * This file serves dual purpose:
 * 1. Source template stored within the RakunCMS package
 * 2. The actual driver file installed into Herd's Drivers directory
 *
 * When installed globally, Herd requires namespace Valet\Drivers\Custom.
 * When installed locally as LocalValetDriver.php, no namespace is used.
 */

namespace Valet\Drivers\Custom;

use Valet\Drivers\ValetDriver;

class RakunCmsValetDriver extends ValetDriver
{
    public function serves(string $sitePath, string $siteName, string $uri): bool
    {
        return file_exists($sitePath . '/config/rakun.yaml');
    }

    public function isStaticFile(string $sitePath, string $siteName, string $uri): string|false
    {
        $staticPath = $sitePath . '/public' . $uri;

        if (is_file($staticPath)) {
            return $staticPath;
        }

        return false;
    }

    public function frontControllerPath(string $sitePath, string $siteName, string $uri): string
    {
        return $sitePath . '/public/index.php';
    }

    /**
     * @return array<int, string>
     */
    public function logFilesPaths(): array
    {
        return ['cache/logs'];
    }

    /**
     * @return array<string, string>
     */
    public function siteInformation(string $sitePath, string $phpBinary): array
    {
        $version = '0.1.0';
        $name = 'RakunCMS';

        $configFile = $sitePath . '/config/rakun.yaml';
        if (file_exists($configFile)) {
            $content = file_get_contents($configFile);
            if ($content !== false && preg_match('/^\s*name:\s*["\']?(.+?)["\']?\s*$/m', $content, $matches)) {
                $name = $matches[1];
            }
        }

        return [
            'Framework' => 'RakunCMS',
            'Site Name' => $name,
            'Version' => $version,
            'Type' => 'Flat-file CMS',
        ];
    }
}
