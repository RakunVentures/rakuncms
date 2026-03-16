<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Boost\ArchetypeInterface;
use Rkn\Cms\Boost\ArchetypeRegistry;
use Rkn\Cms\Boost\SiteProfile;
use Rkn\Cms\Mcp\ToolInterface;
use Symfony\Component\Yaml\Yaml;

final class BoostApplyTool implements ToolInterface
{
    private ArchetypeRegistry $registry;

    public function __construct(
        private string $basePath,
        ?ArchetypeRegistry $registry = null,
    ) {
        $this->registry = $registry ?? ArchetypeRegistry::withDefaults();
    }

    public function name(): string
    {
        return 'boost-apply';
    }

    public function description(): string
    {
        return 'Apply a site archetype to create a complete site structure with collections, templates, entries, and styles';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'archetype' => [
                    'type' => 'string',
                    'description' => 'Archetype name: blog, docs, business, portfolio, catalog, multilingual',
                    'enum' => $this->registry->names(),
                ],
                'name' => [
                    'type' => 'string',
                    'description' => 'Site name',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Site description',
                ],
                'locale' => [
                    'type' => 'string',
                    'description' => 'Default locale (e.g., es, en)',
                    'default' => 'es',
                ],
                'author' => [
                    'type' => 'string',
                    'description' => 'Author name',
                ],
            ],
            'required' => ['archetype', 'name'],
        ];
    }

    public function execute(array $arguments): array
    {
        $archetypeName = is_string($arguments['archetype'] ?? null) ? $arguments['archetype'] : 'blog';
        $archetype = $this->registry->get($archetypeName);

        if ($archetype === null) {
            return [
                'success' => false,
                'error' => "Unknown archetype: {$archetypeName}",
                'available' => $this->registry->names(),
            ];
        }

        $name = is_string($arguments['name'] ?? null) ? $arguments['name'] : 'My Site';
        $description = is_string($arguments['description'] ?? null) ? $arguments['description'] : '';
        $locale = is_string($arguments['locale'] ?? null) ? $arguments['locale'] : 'es';
        $author = is_string($arguments['author'] ?? null) ? $arguments['author'] : '';

        $profile = new SiteProfile(
            name: $name,
            description: $description,
            locale: $locale,
            author: $author,
            archetype: $archetypeName,
        );

        $created = [];

        // Create collections
        foreach ($archetype->collections() as $collection) {
            $dir = "{$this->basePath}/content/{$collection['name']}";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $yamlContent = Yaml::dump($collection['config'], 4);
            file_put_contents("{$dir}/_collection.yaml", $yamlContent);
            $created[] = "content/{$collection['name']}/_collection.yaml";

            $templateDir = "{$this->basePath}/templates/{$collection['name']}";
            if (!is_dir($templateDir)) {
                mkdir($templateDir, 0755, true);
            }
        }

        // Write templates
        foreach ($archetype->templates($profile) as $path => $content) {
            $fullPath = "{$this->basePath}/{$path}";
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($fullPath, $content);
            $created[] = $path;
        }

        // Write entries
        foreach ($archetype->entries($profile) as $entry) {
            $dir = "{$this->basePath}/content/{$entry['collection']}";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $frontmatter = Yaml::dump($entry['frontmatter'], 4);
            $markdown = "---\n{$frontmatter}---\n\n{$entry['content']}\n";
            file_put_contents("{$dir}/{$entry['filename']}", $markdown);
            $created[] = "content/{$entry['collection']}/{$entry['filename']}";
        }

        // Write CSS
        $cssDir = "{$this->basePath}/public/assets/css";
        if (!is_dir($cssDir)) {
            mkdir($cssDir, 0755, true);
        }
        file_put_contents("{$cssDir}/style.css", $archetype->css($profile));
        $created[] = 'public/assets/css/style.css';

        // Write config
        $configDir = "{$this->basePath}/config";
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        file_put_contents("{$configDir}/rakun.yaml", Yaml::dump($archetype->config($profile), 4));
        $created[] = 'config/rakun.yaml';

        // Write globals
        $globalsDir = "{$this->basePath}/content/_globals";
        if (!is_dir($globalsDir)) {
            mkdir($globalsDir, 0755, true);
        }
        file_put_contents("{$globalsDir}/site.yaml", Yaml::dump($archetype->globals($profile), 4));
        $created[] = 'content/_globals/site.yaml';

        return [
            'success' => true,
            'archetype' => $archetypeName,
            'profile' => $profile->toArray(),
            'files_created' => count($created),
            'created' => $created,
        ];
    }
}
