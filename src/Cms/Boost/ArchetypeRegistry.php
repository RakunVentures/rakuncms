<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost;

final class ArchetypeRegistry
{
    /** @var array<string, ArchetypeInterface> */
    private array $archetypes = [];

    public function register(ArchetypeInterface $archetype): void
    {
        $this->archetypes[$archetype->name()] = $archetype;
    }

    public function get(string $name): ?ArchetypeInterface
    {
        return $this->archetypes[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return isset($this->archetypes[$name]);
    }

    /** @return list<ArchetypeInterface> */
    public function all(): array
    {
        return array_values($this->archetypes);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->archetypes);
    }

    public static function withDefaults(): self
    {
        $registry = new self();
        $registry->register(new Archetypes\BlogArchetype());
        $registry->register(new Archetypes\DocsArchetype());
        $registry->register(new Archetypes\BusinessArchetype());
        $registry->register(new Archetypes\PortfolioArchetype());
        $registry->register(new Archetypes\CatalogArchetype());
        $registry->register(new Archetypes\MultilingualArchetype());
        return $registry;
    }
}
