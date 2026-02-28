<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Prompts;

use Rkn\Cms\Mcp\PromptInterface;

final class CreateCollectionPrompt implements PromptInterface
{
    public function name(): string
    {
        return 'create-collection';
    }

    public function description(): string
    {
        return 'Generate structured instructions for creating a new content collection';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'name', 'description' => 'Collection name (lowercase, no spaces)', 'required' => true],
        ];
    }

    public function get(array $arguments): array
    {
        $name = $arguments['name'] ?? 'new-collection';

        $instructions = "# Create a new \"{$name}\" collection\n\n";

        $instructions .= "## Directory structure\n\n";
        $instructions .= "```\n";
        $instructions .= "content/{$name}/\n";
        $instructions .= "  _collection.yaml      # Collection configuration\n";
        $instructions .= "  01.first-entry.md      # First entry (ordered)\n";
        $instructions .= "  02.second-entry.md     # Second entry\n";
        $instructions .= "\n";
        $instructions .= "templates/{$name}/\n";
        $instructions .= "  index.twig             # List view\n";
        $instructions .= "  show.twig              # Single entry view\n";
        $instructions .= "```\n\n";

        $instructions .= "## _collection.yaml format\n\n";
        $instructions .= "```yaml\n";
        $instructions .= "sort: order              # Sort field: order, date, title\n";
        $instructions .= "sort_direction: asc      # asc or desc\n";
        $instructions .= "template: {$name}/show   # Default template for entries\n";
        $instructions .= "per_page: 10             # Pagination (0 = no pagination)\n";
        $instructions .= "url_pattern: /{locale}/{$name}/{slug}\n";
        $instructions .= "```\n\n";

        $instructions .= "## Index template (templates/{$name}/index.twig)\n\n";
        $instructions .= "```twig\n";
        $instructions .= "{" . "% extends \"_layouts/page.twig\" %}\n\n";
        $instructions .= "{" . "% block content %}\n";
        $instructions .= "<h1>{{ page.title }}</h1>\n";
        $instructions .= "{" . "% for entry in collection('{$name}') %}\n";
        $instructions .= "  <article>\n";
        $instructions .= "    <h2><a href=\"{{ entry.url }}\">{{ entry.title }}</a></h2>\n";
        $instructions .= "    <p>{{ entry.meta.description }}</p>\n";
        $instructions .= "  </article>\n";
        $instructions .= "{" . "% endfor %}\n";
        $instructions .= "{" . "% endblock %}\n";
        $instructions .= "```\n\n";

        $instructions .= "## Show template (templates/{$name}/show.twig)\n\n";
        $instructions .= "```twig\n";
        $instructions .= "{" . "% extends \"_layouts/page.twig\" %}\n\n";
        $instructions .= "{" . "% block content %}\n";
        $instructions .= "<article>\n";
        $instructions .= "  <h1>{{ page.title }}</h1>\n";
        $instructions .= "  {{ page.content|raw }}\n";
        $instructions .= "</article>\n";
        $instructions .= "{" . "% endblock %}\n";
        $instructions .= "```\n\n";

        $instructions .= "## After creating\n\n";
        $instructions .= "1. Create the directory: `content/{$name}/`\n";
        $instructions .= "2. Add `_collection.yaml` with configuration\n";
        $instructions .= "3. Create templates in `templates/{$name}/`\n";
        $instructions .= "4. Add entries as `.md` files\n";
        $instructions .= "5. Run `php rakun index:rebuild` to index the new collection\n";

        return [
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        'type' => 'text',
                        'text' => $instructions,
                    ],
                ],
            ],
        ];
    }
}
