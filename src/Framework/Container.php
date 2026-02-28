<?php

declare(strict_types=1);

namespace Rkn\Framework;

use Psr\Container\ContainerInterface;

final class Container implements ContainerInterface
{
    /** @var array<string, callable> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $instances = [];

    /**
     * Register a service definition.
     * Callables are treated as factories (lazy, singleton).
     * Objects/scalars are stored directly as instances.
     */
    public function set(string $id, callable|object|string|int|float|bool|array $definition): void
    {
        if ($definition instanceof \Closure) {
            $this->factories[$id] = $definition;
            unset($this->instances[$id]);
        } else {
            $this->instances[$id] = $definition;
            unset($this->factories[$id]);
        }
    }

    /**
     * Register a factory that creates a new instance on each call.
     */
    public function factory(string $id, callable $factory): void
    {
        $this->factories[$id] = $factory;
        unset($this->instances[$id]);
    }

    public function get(string $id): mixed
    {
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        if (isset($this->factories[$id])) {
            $this->instances[$id] = ($this->factories[$id])($this);
            return $this->instances[$id];
        }

        throw new NotFoundException("Service not found: {$id}");
    }

    public function has(string $id): bool
    {
        return isset($this->instances[$id]) || isset($this->factories[$id]);
    }

    /**
     * @return array<string>
     */
    public function keys(): array
    {
        return array_unique(array_merge(
            array_keys($this->instances),
            array_keys($this->factories)
        ));
    }
}
