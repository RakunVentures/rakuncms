<?php

declare(strict_types=1);

namespace Rkn\Cms\Mcp\Prompts;

use Rkn\Cms\Boost\ArchetypeRegistry;
use Rkn\Cms\Mcp\PromptInterface;

final class BoostWizardPrompt implements PromptInterface
{
    private ArchetypeRegistry $registry;

    public function __construct(?ArchetypeRegistry $registry = null)
    {
        $this->registry = $registry ?? ArchetypeRegistry::withDefaults();
    }

    public function name(): string
    {
        return 'boost-wizard';
    }

    public function description(): string
    {
        return 'Guided conversation to create a complete RakunCMS site from an archetype';
    }

    public function arguments(): array
    {
        return [
            ['name' => 'archetype', 'description' => 'Pre-selected archetype (optional)', 'required' => false],
            ['name' => 'name', 'description' => 'Pre-set site name (optional)', 'required' => false],
            ['name' => 'locale', 'description' => 'Pre-set locale (optional)', 'required' => false],
        ];
    }

    public function get(array $arguments): array
    {
        $archetype = is_string($arguments['archetype'] ?? null) ? $arguments['archetype'] : null;
        $name = is_string($arguments['name'] ?? null) ? $arguments['name'] : null;
        $locale = is_string($arguments['locale'] ?? null) ? $arguments['locale'] : null;

        $archetypeList = '';
        foreach ($this->registry->all() as $at) {
            $collections = array_map(fn(array $c): string => $c['name'], $at->collections());
            $archetypeList .= "- **{$at->name()}**: {$at->description()} (collections: " . implode(', ', $collections) . ")\n";
        }

        $instructions = "# Boost Wizard — Create a new RakunCMS site\n\n";
        $instructions .= "You are helping the user create a complete website using RakunCMS.\n\n";

        $instructions .= "## Available Archetypes\n\n{$archetypeList}\n";

        if ($archetype !== null) {
            $instructions .= "The user has pre-selected the **{$archetype}** archetype.\n\n";
        }

        if ($name !== null) {
            $instructions .= "The site name is: **{$name}**\n\n";
        }

        $instructions .= "## Your Task\n\n";
        $instructions .= "1. **Gather information**: Ask about the site name, type (archetype), locale, description, and author\n";
        $instructions .= "2. **Apply the archetype**: Use the `boost-apply` tool with the collected information\n";
        $instructions .= "3. **Personalize content**: Read each generated `.md` file and replace `<!-- BOOST: ... -->` placeholders with real content based on the user's answers\n";
        $instructions .= "4. **Verify**: Check that all files were created and content is personalized\n\n";

        $instructions .= "## Important Notes\n\n";
        $instructions .= "- Always ask before generating — don't assume the archetype or site name\n";
        $instructions .= "- Generate real, relevant content — never leave BOOST placeholders\n";
        $instructions .= "- SEO descriptions should be exactly 150 characters\n";
        $instructions .= "- Run `index:rebuild` after creating content\n";

        if ($locale !== null) {
            $instructions .= "- The site locale is pre-set to: **{$locale}**\n";
        }

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
