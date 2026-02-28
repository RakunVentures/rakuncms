<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Prompts;

use Rkn\Cms\Mcp\PromptInterface;

final class CreateComponentPrompt implements PromptInterface
{
    public function name(): string
    {
        return 'create-component';
    }

    public function description(): string
    {
        return 'Generate structured instructions for creating a new Yoyo reactive component';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'name', 'description' => 'Component name in PascalCase (e.g. "ContactForm")', 'required' => true],
        ];
    }

    public function get(array $arguments): array
    {
        $name = $arguments['name'] ?? 'MyComponent';
        $viewName = $this->toKebabCase($name);

        $instructions = "# Create a new Yoyo component: {$name}\n\n";

        $instructions .= "## PHP class\n\n";
        $instructions .= "Create `src/Components/{$name}.php`:\n\n";
        $instructions .= "```php\n";
        $instructions .= "<?php\n\n";
        $instructions .= "declare(strict_types=1);\n\n";
        $instructions .= "namespace App\\Components;\n\n";
        $instructions .= "use Clickfwd\\Yoyo\\Component;\n\n";
        $instructions .= "class {$name} extends Component\n";
        $instructions .= "{\n";
        $instructions .= "    // Public properties become reactive state\n";
        $instructions .= "    public string \$message = '';\n\n";
        $instructions .= "    // Methods are actions callable from the template\n";
        $instructions .= "    public function submit(): void\n";
        $instructions .= "    {\n";
        $instructions .= "        // Handle the action\n";
        $instructions .= "    }\n\n";
        $instructions .= "    public function render(): string|\\Clickfwd\\Yoyo\\Interfaces\\ViewProviderInterface\n";
        $instructions .= "    {\n";
        $instructions .= "        return \$this->view('yoyo/{$viewName}');\n";
        $instructions .= "    }\n";
        $instructions .= "}\n";
        $instructions .= "```\n\n";

        $instructions .= "## Twig template\n\n";
        $instructions .= "Create `templates/yoyo/{$viewName}.twig`:\n\n";
        $instructions .= "```twig\n";
        $instructions .= "<div>\n";
        $instructions .= "    <form yoyo:on=\"submit\" yoyo:method=\"submit\">\n";
        $instructions .= "        <input type=\"text\" yoyo name=\"message\" value=\"{{ message }}\">\n";
        $instructions .= "        <button type=\"submit\">Submit</button>\n";
        $instructions .= "    </form>\n";
        $instructions .= "</div>\n";
        $instructions .= "```\n\n";

        $instructions .= "## Usage in templates\n\n";
        $instructions .= "```twig\n";
        $instructions .= "{{ yoyo_render('{$name}') }}\n";
        $instructions .= "```\n\n";

        $instructions .= "## Yoyo conventions\n\n";
        $instructions .= "- **Reactive props**: Public properties auto-sync between server and client\n";
        $instructions .= "- **Actions**: Public methods are callable via `yoyo:method=\"methodName\"`\n";
        $instructions .= "- **Updated hooks**: `updatedPropertyName()` runs when a property changes\n";
        $instructions .= "- **Transport**: htmx handles all AJAX — no JavaScript needed\n";
        $instructions .= "- **POST route**: All Yoyo requests go through `POST /yoyo/{action}`\n";
        $instructions .= "- **Template name**: Must match view path in `render()` method\n";

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

    private function toKebabCase(string $name): string
    {
        $snake = preg_replace('/([a-z])([A-Z])/', '$1-$2', $name) ?? $name;
        return mb_strtolower($snake);
    }
}
