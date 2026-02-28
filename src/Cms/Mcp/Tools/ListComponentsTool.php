<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\ToolInterface;

final class ListComponentsTool implements ToolInterface
{
    public function __construct(private string $basePath)
    {
    }

    public function name(): string
    {
        return 'list-components';
    }

    public function description(): string
    {
        return 'List Yoyo reactive components with their public properties and methods';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
        ];
    }

    public function execute(array $arguments): array
    {
        $components = [];

        // Scan project-level components
        $projectDir = $this->basePath . '/src/Components';
        if (is_dir($projectDir)) {
            $this->scanDirectory($projectDir, 'App\\Components\\', $components);
        }

        // Scan CMS built-in components
        $cmsDir = dirname(__DIR__, 2) . '/Components';
        if (is_dir($cmsDir)) {
            $this->scanDirectory($cmsDir, 'Rkn\\Cms\\Components\\', $components);
        }

        return ['components' => $components];
    }

    /**
     * @param list<array<string, mixed>> &$components
     */
    private function scanDirectory(string $dir, string $namespace, array &$components): void
    {
        $files = glob($dir . '/*.php') ?: [];

        foreach ($files as $file) {
            $className = $namespace . basename($file, '.php');
            $component = [
                'name' => basename($file, '.php'),
                'file' => $file,
                'namespace' => $namespace,
            ];

            // Try to use reflection if class is loadable
            if (class_exists($className)) {
                try {
                    $ref = new \ReflectionClass($className);
                    $component['properties'] = $this->getPublicProperties($ref);
                    $component['methods'] = $this->getPublicMethods($ref);
                } catch (\ReflectionException) {
                    // Class not reflectable, just list file info
                }
            } else {
                // Parse file for public properties/methods without loading
                $content = file_get_contents($file);
                $component['properties'] = $this->parseProperties($content);
                $component['methods'] = $this->parseMethods($content);
            }

            $components[] = $component;
        }
    }

    /**
     * @param \ReflectionClass<object> $ref
     * @return list<string>
     */
    private function getPublicProperties(\ReflectionClass $ref): array
    {
        $props = [];
        foreach ($ref->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            if ($prop->getDeclaringClass()->getName() === $ref->getName()) {
                $props[] = $prop->getName();
            }
        }
        return $props;
    }

    /**
     * @param \ReflectionClass<object> $ref
     * @return list<string>
     */
    private function getPublicMethods(\ReflectionClass $ref): array
    {
        $methods = [];
        foreach ($ref->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->getDeclaringClass()->getName() === $ref->getName()
                && !str_starts_with($method->getName(), '__')) {
                $methods[] = $method->getName();
            }
        }
        return $methods;
    }

    /**
     * @return list<string>
     */
    private function parseProperties(string $content): array
    {
        $props = [];
        if (preg_match_all('/public\s+(?:\w+\s+)?\$(\w+)/', $content, $matches)) {
            $props = $matches[1];
        }
        return $props;
    }

    /**
     * @return list<string>
     */
    private function parseMethods(string $content): array
    {
        $methods = [];
        if (preg_match_all('/public\s+function\s+(\w+)\s*\(/', $content, $matches)) {
            $methods = array_filter($matches[1], fn ($m) => !str_starts_with($m, '__'));
        }
        return array_values($methods);
    }
}
