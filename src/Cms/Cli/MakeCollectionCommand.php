<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun make:collection {name}
 *
 * Creates a content collection directory with _collection.yaml and sample entry.
 */
#[AsCommand(name: 'make:collection', description: 'Create a new content collection')]
final class MakeCollectionCommand extends Command
{

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Collection name (e.g. "blog")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $basePath = $this->findBasePath();

        $collectionDir = $basePath . '/content/' . $name;

        if (is_dir($collectionDir)) {
            $output->writeln('<error>Collection already exists: ' . $collectionDir . '</error>');
            return Command::FAILURE;
        }

        mkdir($collectionDir, 0755, true);

        // Create _collection.yaml
        $yaml = <<<YAML
        url_pattern: "/{locale}/{$name}/{slug}"
        sort:
          field: date
          direction: desc
        templates:
          index: "{$name}/index"
          show: "{$name}/show"
        YAML;

        file_put_contents($collectionDir . '/_collection.yaml', $yaml);
        $output->writeln('<info>Created:</info> content/' . $name . '/_collection.yaml');

        // Create sample entry
        $sample = <<<MD
        ---
        title: Sample Entry
        slugs:
          es: ejemplo
          en: example
        date: {$this->currentDate()}
        template: {$name}/show
        ---

        This is a sample entry for the **{$name}** collection.
        MD;

        file_put_contents($collectionDir . '/ejemplo.md', $sample);
        $output->writeln('<info>Created:</info> content/' . $name . '/ejemplo.md');

        // Create templates directory
        $templateDir = $basePath . '/templates/' . $name;
        if (!is_dir($templateDir)) {
            mkdir($templateDir, 0755, true);

            file_put_contents($templateDir . '/index.twig', $this->indexTemplate($name));
            $output->writeln('<info>Created:</info> templates/' . $name . '/index.twig');

            file_put_contents($templateDir . '/show.twig', $this->showTemplate($name));
            $output->writeln('<info>Created:</info> templates/' . $name . '/show.twig');
        }

        return Command::SUCCESS;
    }

    private function currentDate(): string
    {
        return date('Y-m-d');
    }

    private function indexTemplate(string $name): string
    {
        return <<<TWIG
        {% extends "_layouts/page.twig" %}

        {% block content %}
        <div class="container mx-auto px-4 py-12">
          <h1 class="text-3xl font-bold mb-8">{{ t('{$name}.title') }}</h1>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-8">
            {% for entry in collection('{$name}').locale(locale).sort('date', 'desc').get() %}
              <article>
                <h2><a href="{{ entry.url(locale) }}">{{ entry.title }}</a></h2>
              </article>
            {% endfor %}
          </div>
        </div>
        {% endblock %}

        TWIG;
    }

    private function showTemplate(string $name): string
    {
        return <<<TWIG
        {% extends "_layouts/page.twig" %}

        {% block content %}
        <article class="container mx-auto px-4 py-12">
          <h1 class="text-3xl font-bold mb-4">{{ entry.title }}</h1>

          <div class="prose max-w-none">
            {{ entry.content|raw }}
          </div>
        </article>
        {% endblock %}

        TWIG;
    }

    private function findBasePath(): string
    {
        try {
            $app = \Rkn\Framework\Application::getInstance();
            if ($app !== null) {
                return $app->getBasePath();
            }
        } catch (\Throwable) {
        }

        return getcwd() ?: dirname(__DIR__, 3);
    }
}
