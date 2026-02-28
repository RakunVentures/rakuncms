<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp;

interface ResourceInterface
{
    public function uri(): string;

    public function name(): string;

    public function description(): string;

    public function mimeType(): string;

    /**
     * @return array<string, mixed>
     */
    public function read(): array;
}
