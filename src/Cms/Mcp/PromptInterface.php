<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp;

interface PromptInterface
{
    public function name(): string;

    public function description(): string;

    /**
     * @return list<array{name: string, description: string, required: bool}>
     */
    public function arguments(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function get(array $arguments): array;
}
