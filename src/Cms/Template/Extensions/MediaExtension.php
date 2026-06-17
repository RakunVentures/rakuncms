<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Resolves article cover image URLs with a fallback chain over frontmatter keys.
 *
 * Variants and their fallback chains (designed so legacy articles with only
 * `image` keep rendering on every surface):
 *   - wide     → image
 *   - portrait → image_portrait, image
 *   - square   → image_square, image_portrait, image
 */
final class MediaExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('article_image', [$this, 'articleImage']),
        ];
    }

    public function articleImage(mixed $entry, string $variant = 'wide'): string
    {
        $meta = $this->extractMeta($entry);

        return match ($variant) {
            'wide'     => $this->stringOrEmpty($meta['image'] ?? null),
            'portrait' => $this->stringOrEmpty($meta['image_portrait'] ?? $meta['image'] ?? null),
            'square'   => $this->stringOrEmpty($meta['image_square'] ?? $meta['image_portrait'] ?? $meta['image'] ?? null),
            default    => $this->stringOrEmpty($meta['image'] ?? null),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function extractMeta(mixed $entry): array
    {
        if (is_object($entry) && method_exists($entry, 'meta')) {
            $meta = $entry->meta();
            return is_array($meta) ? $meta : [];
        }
        if (is_array($entry)) {
            $meta = $entry['meta'] ?? $entry;
            return is_array($meta) ? $meta : [];
        }
        return [];
    }

    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
