<?php

declare(strict_types=1);

namespace Rkn\Framework;

use FastRoute\Dispatcher as FastRouteDispatcher;
use FastRoute\RouteCollector;

final class Router
{
    /** @var list<array{0: string, 1: string, 2: mixed}> */
    private array $routes = [];

    public function addRoute(string $method, string $pattern, mixed $handler): void
    {
        $this->routes[] = [$method, $pattern, $handler];
    }

    public function get(string $pattern, mixed $handler): void
    {
        $this->addRoute('GET', $pattern, $handler);
    }

    public function post(string $pattern, mixed $handler): void
    {
        $this->addRoute('POST', $pattern, $handler);
    }

    /**
     * @return array{0: int, 1?: mixed, 2?: array<string, string>}
     */
    public function dispatch(string $method, string $uri): array
    {
        $dispatcher = \FastRoute\simpleDispatcher(function (RouteCollector $r) {
            foreach ($this->routes as [$method, $pattern, $handler]) {
                $r->addRoute($method, $pattern, $handler);
            }
        });

        $result = $dispatcher->dispatch($method, $uri);

        return $result;
    }

    /**
     * Check if a route matches and return handler + vars, or null.
     *
     * @return array{handler: mixed, vars: array<string, string>}|null
     */
    public function match(string $method, string $uri): ?array
    {
        $result = $this->dispatch($method, $uri);

        if ($result[0] === FastRouteDispatcher::FOUND) {
            return [
                'handler' => $result[1],
                'vars' => $result[2],
            ];
        }

        return null;
    }
}
