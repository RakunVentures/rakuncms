<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost;

interface ArchetypeInterface
{
    public function name(): string;

    public function description(): string;

    /** @return list<array{name: string, config: array<string, mixed>}> */
    public function collections(): array;

    /** @return list<array{collection: string, filename: string, frontmatter: array<string, mixed>, content: string}> */
    public function entries(SiteProfile $profile): array;

    /** @return array<string, string> path => twig content */
    public function templates(SiteProfile $profile): array;

    public function css(SiteProfile $profile): string;

    /** @return array<string, mixed> Overrides for config/rakun.yaml */
    public function config(SiteProfile $profile): array;

    /** @return array<string, mixed> Content for content/_globals/site.yaml */
    public function globals(SiteProfile $profile): array;
}
