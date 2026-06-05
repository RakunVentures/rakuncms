<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

final class PublishEntryTool extends UpdateEntryTool
{
    public function name(): string
    {
        return 'publish-entry';
    }

    public function description(): string
    {
        return 'Publish an existing content entry by setting status=published';
    }

    public function execute(array $arguments): array
    {
        $arguments['status'] = 'published';
        $result = parent::execute($arguments);
        $result['action'] = 'published';

        return $result;
    }
}

