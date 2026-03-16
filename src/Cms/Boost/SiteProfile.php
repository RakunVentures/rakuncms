<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost;

final class SiteProfile
{
    public function __construct(
        public readonly string $name,
        public readonly string $description = '',
        public readonly string $locale = 'es',
        public readonly string $author = '',
        public readonly string $archetype = 'blog',
    ) {
    }

    /** @return array<string, string> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'locale' => $this->locale,
            'author' => $this->author,
            'archetype' => $this->archetype,
        ];
    }
}
