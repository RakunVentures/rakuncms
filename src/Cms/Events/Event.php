<?php

declare(strict_types=1);

namespace Rkn\Cms\Events;

final class Event
{
    /** @var array<string, mixed> */
    private array $payload;
    private bool $propagationStopped = false;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $name,
        array $payload = [],
    ) {
        $this->payload = $payload;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->payload[$key] ?? $default;
    }

    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }
}
