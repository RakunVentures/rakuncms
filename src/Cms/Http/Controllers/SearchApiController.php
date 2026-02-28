<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Rkn\Cms\Search\SearchEngine;
use Rkn\Cms\Search\SearchIndexer;

final class SearchApiController
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
    }

    public function search(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $queryText = $params['q'] ?? '';

        if ($queryText === '') {
            return $this->json(400, ['error' => 'Query parameter "q" is required']);
        }

        $locale = $params['locale'] ?? null;
        $limit = min((int) ($params['limit'] ?? 10), 50);

        $indexer = new SearchIndexer($this->basePath);
        $index = $indexer->load() ?? $indexer->build();

        $engine = new SearchEngine($index);
        $results = $engine->search($queryText, $locale, $limit);

        return $this->json(200, [
            'data' => $results,
            'meta' => [
                'query' => $queryText,
                'locale' => $locale,
                'count' => count($results),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function json(int $status, array $data): ResponseInterface
    {
        return new Response(
            $status,
            ['Content-Type' => 'application/json'],
            json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}'
        );
    }
}
