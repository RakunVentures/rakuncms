<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rkn\Cms\Boost\ArchetypeRegistry;
use Rkn\Cms\Boost\SiteProfile;
use Symfony\Component\Yaml\Yaml;

final class BoostApiController
{
    private ArchetypeRegistry $registry;

    public function __construct(
        private string $basePath,
        ?ArchetypeRegistry $registry = null,
    ) {
        $this->registry = $registry ?? ArchetypeRegistry::withDefaults();
    }

    public function archetypes(): ResponseInterface
    {
        $archetypes = [];
        foreach ($this->registry->all() as $archetype) {
            $collections = array_map(
                fn(array $c): string => $c['name'],
                $archetype->collections()
            );
            $archetypes[] = [
                'name' => $archetype->name(),
                'description' => $archetype->description(),
                'collections' => $collections,
            ];
        }

        return $this->jsonResponse(200, ['data' => $archetypes]);
    }

    public function apply(ServerRequestInterface $request): ResponseInterface
    {
        $body = $request->getParsedBody();
        if (!is_array($body)) {
            return $this->jsonResponse(400, ['error' => 'Invalid request body']);
        }

        $archetypeName = is_string($body['archetype'] ?? null) ? $body['archetype'] : null;
        if ($archetypeName === null) {
            return $this->jsonResponse(400, ['error' => 'Missing required field: archetype']);
        }

        $archetype = $this->registry->get($archetypeName);
        if ($archetype === null) {
            return $this->jsonResponse(400, [
                'error' => "Unknown archetype: {$archetypeName}",
                'available' => $this->registry->names(),
            ]);
        }

        $name = is_string($body['name'] ?? null) ? $body['name'] : null;
        if ($name === null) {
            return $this->jsonResponse(400, ['error' => 'Missing required field: name']);
        }

        $profile = new SiteProfile(
            name: $name,
            description: is_string($body['description'] ?? null) ? $body['description'] : '',
            locale: is_string($body['locale'] ?? null) ? $body['locale'] : 'es',
            author: is_string($body['author'] ?? null) ? $body['author'] : '',
            archetype: $archetypeName,
        );

        $created = [];

        // Create collections
        foreach ($archetype->collections() as $collection) {
            $dir = "{$this->basePath}/content/{$collection['name']}";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents("{$dir}/_collection.yaml", Yaml::dump($collection['config'], 4));
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

        return $this->jsonResponse(200, [
            'success' => true,
            'archetype' => $archetypeName,
            'profile' => $profile->toArray(),
            'files_created' => count($created),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(int $status, array $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}'
        );
    }
}
