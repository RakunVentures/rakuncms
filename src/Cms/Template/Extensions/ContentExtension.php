<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Query;
use Symfony\Component\Yaml\Yaml;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class ContentExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('collection', [$this, 'collection']),
            new TwigFunction('entry', [$this, 'entry']),
            new TwigFunction('global', [$this, 'global']),
            new TwigFunction('config', [$this, 'config']),
        ];
    }

    public function collection(string $name): Query
    {
        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $index = $indexer->load();

        return (new Query($index))->collection($name);
    }

    public function entry(string $path): ?Entry
    {
        $basePath = \app('base_path');
        $indexer = new Indexer($basePath);
        $index = $indexer->load();

        // Path can be "collection/slug" or "collection/slug.locale"
        $entries = $index['entries'];

        // Direct key match
        if (isset($entries[$path])) {
            return Entry::fromArray($entries[$path]);
        }

        // Try with locale suffix
        $locale = 'es';
        try {
            $locale = \app('locale');
        } catch (\Throwable) {
        }

        foreach ($entries as $key => $data) {
            if (str_starts_with($key, $path) && $data['locale'] === $locale) {
                return Entry::fromArray($data);
            }
        }

        return null;
    }

    /**
     * Load a global YAML file from content/_globals/.
     *
     * @return array<string, mixed>
     */
    public function global(string $name): array
    {
        $basePath = \app('base_path');
        $file = $basePath . '/content/_globals/' . $name . '.yaml';

        if (!file_exists($file)) {
            return [];
        }

        $data = Yaml::parseFile($file);
        return is_array($data) ? $data : [];
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return \config($key, $default);
    }
}
