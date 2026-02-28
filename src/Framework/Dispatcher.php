<?php

declare(strict_types=1);

namespace Rkn\Framework;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Dispatcher implements RequestHandlerInterface
{
    /** @var list<MiddlewareInterface> */
    private array $middleware;
    private int $index = 0;

    /** @param list<MiddlewareInterface> $middleware */
    public function __construct(array $middleware)
    {
        $this->middleware = $middleware;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (!isset($this->middleware[$this->index])) {
            throw new \RuntimeException('Unhandled request — no middleware produced a response.');
        }

        $current = $this->middleware[$this->index];
        $this->index++;

        return $current->process($request, $this);
    }
}
