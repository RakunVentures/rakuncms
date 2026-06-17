<?php

declare(strict_types=1);

namespace Rkn\Cms\Template;

use Rkn\Cms\Content\Entry;

/**
 * Resolves which Twig template renders a given content Entry.
 *
 * Resolution order (highest specificity wins; first match returns):
 *   1. Frontmatter `template:` field of the .md file (per-entry override).
 *   2. `default_template` declared in `collections.{collection}` of `rakun.yaml`
 *      — explicit collection-wide choice. If the file does not exist, fails
 *      loud with a `TemplateNotFoundException` (no silent fallback).
 *   3. templates/{collection}/{slug}.twig (per-entry override by path).
 *   4. templates/{collection}/show.twig (collection-wide path convention).
 *   5. templates/_layouts/{collection}.twig (layout convention).
 *   6. templates/_layouts/page.twig (final hardcoded fallback).
 *
 * Steps 3-5 are filesystem conventions: existence of a file is the only signal.
 * Step 6 always wins as last resort (Twig itself raises a loader error if even
 * that file is missing — the right failure mode).
 *
 * @param array<string, string> $defaultTemplates Map of collection name → template path
 *                                                (without the `.twig` suffix), sourced from
 *                                                `config('collections')` upstream.
 */
final class TemplateResolver
{
    /**
     * @param array<string, string> $defaultTemplates
     */
    public function __construct(
        private string $templateDir,
        private array $defaultTemplates = [],
    ) {
    }

    public function resolve(Entry $entry): string
    {
        $collection = $entry->collection();
        $slug = $entry->slug();

        // 1. Frontmatter template field (per-entry override)
        if ($entry->template() !== null) {
            return $entry->template() . '.twig';
        }

        // 2. collections.{collection}.default_template from rakun.yaml
        $default = $this->defaultTemplates[$collection] ?? null;
        if ($default !== null && $default !== '') {
            $relative = $default . '.twig';
            if (!is_file($this->templateDir . '/' . $relative)) {
                throw new TemplateNotFoundException(
                    "Collection '{$collection}' declares default_template '{$default}' "
                    . "but '{$this->templateDir}/{$relative}' does not exist. "
                    . "Fix the path or remove default_template from rakun.yaml."
                );
            }
            return $relative;
        }

        // 3. templates/{collection}/{slug}.twig
        if (is_file($this->templateDir . '/' . $collection . '/' . $slug . '.twig')) {
            return $collection . '/' . $slug . '.twig';
        }

        // 4. templates/{collection}/show.twig
        if (is_file($this->templateDir . '/' . $collection . '/show.twig')) {
            return $collection . '/show.twig';
        }

        // 5. templates/_layouts/{collection}.twig
        if (is_file($this->templateDir . '/_layouts/' . $collection . '.twig')) {
            return '_layouts/' . $collection . '.twig';
        }

        // 6. templates/_layouts/page.twig
        return '_layouts/page.twig';
    }
}
