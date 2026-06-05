<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Tools;

use Rkn\Cms\Mcp\McpException;

final class ScheduleEntryTool extends UpdateEntryTool
{
    public function name(): string
    {
        return 'schedule-entry';
    }

    public function description(): string
    {
        return 'Schedule an existing content entry by setting status=scheduled and date';
    }

    public function inputSchema(): array
    {
        $schema = parent::inputSchema();
        $schema['properties']['date'] = ['type' => 'string'];
        $schema['required'] = ['collection', 'slug', 'date'];

        return $schema;
    }

    public function execute(array $arguments): array
    {
        $date = $this->requireString($arguments, 'date');
        if (strtotime($date) === false || preg_match('/[\r\n]/', $date) === 1) {
            throw McpException::invalidParams('date is not a valid date');
        }
        $arguments['status'] = 'scheduled';
        $arguments['meta'] = array_replace_recursive(
            is_array($arguments['meta'] ?? null) ? $arguments['meta'] : [],
            ['date' => $date],
        );

        $result = parent::execute($arguments);
        $result['action'] = 'scheduled';
        $result['entry']['date'] = $date;

        return $result;
    }
}

