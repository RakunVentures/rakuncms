<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Boost\ArchetypeRegistry;
use Rkn\Cms\Mcp\ToolInterface;

final class BoostArchetypesTool implements ToolInterface
{
    private ArchetypeRegistry $registry;

    public function __construct(?ArchetypeRegistry $registry = null)
    {
        $this->registry = $registry ?? ArchetypeRegistry::withDefaults();
    }

    public function name(): string
    {
        return 'boost-archetypes';
    }

    public function description(): string
    {
        return 'List all available site archetypes for the boost wizard with their descriptions and structure';
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

        return ['archetypes' => $archetypes];
    }
}
