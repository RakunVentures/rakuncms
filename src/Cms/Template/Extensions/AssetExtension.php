<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig extension providing asset() function with cache busting.
 */
final class AssetExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('asset', [$this, 'asset']),
        ];
    }

    /**
     * Generate URL for a static asset with cache-busting query param.
     */
    public function asset(string $path): string
    {
        return \asset($path);
    }
}
