<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Boost\ArchetypeInterface;
use Rkn\Cms\Boost\ArchetypeRegistry;
use Rkn\Cms\Boost\SiteProfile;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'boost', description: 'Create a complete site from an archetype with personalized content')]
final class BoostCommand extends Command
{
    private ArchetypeRegistry $registry;

    public function __construct(?ArchetypeRegistry $registry = null)
    {
        $this->registry = $registry ?? ArchetypeRegistry::withDefaults();
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Target directory for the site', getcwd());
        $this->addOption('archetype', 'a', InputOption::VALUE_REQUIRED, 'Archetype to use (blog, docs, business, portfolio, catalog, multilingual)');
        $this->addOption('name', null, InputOption::VALUE_REQUIRED, 'Site name');
        $this->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Site description');
        $this->addOption('locale', 'l', InputOption::VALUE_REQUIRED, 'Default locale', 'es');
        $this->addOption('author', null, InputOption::VALUE_REQUIRED, 'Author name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        /** @var string $pathArg */
        $pathArg = $input->getArgument('path');
        $basePath = rtrim($pathArg, '/');

        // Resolve archetype
        $archetypeOption = $input->getOption('archetype');
        $archetypeName = is_string($archetypeOption) ? $archetypeOption : $this->askArchetype($input, $output);

        $archetype = $this->registry->get($archetypeName);
        if ($archetype === null) {
            $output->writeln("<error>Unknown archetype: {$archetypeName}</error>");
            $output->writeln("Available: " . implode(', ', $this->registry->names()));
            return Command::FAILURE;
        }

        // Resolve site profile
        $nameOption = $input->getOption('name');
        $name = is_string($nameOption) ? $nameOption : $this->askQuestion($input, $output, 'Site name', 'My Site');
        $descOption = $input->getOption('description');
        $description = is_string($descOption) ? $descOption : '';
        $localeOption = $input->getOption('locale');
        $locale = is_string($localeOption) ? $localeOption : 'es';
        $authorOption = $input->getOption('author');
        $author = is_string($authorOption) ? $authorOption : '';

        $profile = new SiteProfile(
            name: $name,
            description: $description,
            locale: $locale,
            author: $author,
            archetype: $archetypeName,
        );

        $output->writeln("<info>Boosting site with archetype: {$archetype->name()}</info>");
        $output->writeln("  Name: {$profile->name}");
        $output->writeln("  Locale: {$profile->locale}");
        $output->writeln('');

        // Step 1: Run InitCommand for base structure
        $output->writeln('Step 1/6: Scaffolding base structure...');
        $this->runInit($basePath, $output);

        // Step 2: Create collections
        $output->writeln('Step 2/6: Creating collections...');
        $this->writeCollections($basePath, $archetype, $output);

        // Step 3: Write templates (overrides defaults)
        $output->writeln('Step 3/6: Writing templates...');
        $this->writeTemplates($basePath, $archetype, $profile, $output);

        // Step 4: Write entries
        $output->writeln('Step 4/6: Writing content entries...');
        $this->writeEntries($basePath, $archetype, $profile, $output);

        // Step 5: Write CSS
        $output->writeln('Step 5/6: Writing styles...');
        $this->writeCss($basePath, $archetype, $profile);

        // Step 6: Write config and globals
        $output->writeln('Step 6/6: Writing configuration...');
        $this->writeConfig($basePath, $archetype, $profile);
        $this->writeGlobals($basePath, $archetype, $profile);

        $output->writeln('');
        $output->writeln("<info>Site boosted successfully in {$basePath}!</info>");
        $output->writeln('');
        $output->writeln("Archetype: <comment>{$archetype->name()}</comment> — {$archetype->description()}");
        $output->writeln("Start the server: <comment>php rakun serve</comment>");
        $output->writeln("Rebuild index:    <comment>php rakun index:rebuild</comment>");

        return Command::SUCCESS;
    }

    private function runInit(string $basePath, OutputInterface $output): void
    {
        $app = $this->getApplication();
        if ($app === null) {
            return;
        }

        try {
            $initCommand = $app->find('init');
            $initInput = new ArrayInput(['path' => $basePath]);
            $initCommand->run($initInput, $output);
        } catch (\Throwable) {
            // InitCommand might not be registered; create dirs manually
            $this->ensureDirectories($basePath);
        }
    }

    private function ensureDirectories(string $basePath): void
    {
        $dirs = [
            'cache/assets', 'cache/pages', 'cache/templates',
            'config', 'content/_globals', 'content/pages',
            'public/assets/css', 'public/assets/js', 'public/assets/images',
            'templates/_layouts', 'templates/_partials',
            'storage/logs',
        ];

        foreach ($dirs as $dir) {
            $path = "{$basePath}/{$dir}";
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
            }
        }
    }

    private function writeCollections(string $basePath, ArchetypeInterface $archetype, OutputInterface $output): void
    {
        foreach ($archetype->collections() as $collection) {
            $dir = "{$basePath}/content/{$collection['name']}";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $yamlContent = Yaml::dump($collection['config'], 4);
            file_put_contents("{$dir}/_collection.yaml", $yamlContent);
            $output->writeln("  Created: content/{$collection['name']}/_collection.yaml");

            // Also create template directory
            $templateDir = "{$basePath}/templates/{$collection['name']}";
            if (!is_dir($templateDir)) {
                mkdir($templateDir, 0755, true);
            }
        }
    }

    private function writeTemplates(string $basePath, ArchetypeInterface $archetype, SiteProfile $profile, OutputInterface $output): void
    {
        foreach ($archetype->templates($profile) as $path => $content) {
            $fullPath = "{$basePath}/{$path}";
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($fullPath, $content);
            $output->writeln("  Written: {$path}");
        }
    }

    private function writeEntries(string $basePath, ArchetypeInterface $archetype, SiteProfile $profile, OutputInterface $output): void
    {
        foreach ($archetype->entries($profile) as $entry) {
            $dir = "{$basePath}/content/{$entry['collection']}";
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            $frontmatter = Yaml::dump($entry['frontmatter'], 4);
            $markdown = "---\n{$frontmatter}---\n\n{$entry['content']}\n";

            file_put_contents("{$dir}/{$entry['filename']}", $markdown);
            $output->writeln("  Created: content/{$entry['collection']}/{$entry['filename']}");
        }
    }

    private function writeCss(string $basePath, ArchetypeInterface $archetype, SiteProfile $profile): void
    {
        $cssDir = "{$basePath}/public/assets/css";
        if (!is_dir($cssDir)) {
            mkdir($cssDir, 0755, true);
        }
        file_put_contents("{$cssDir}/style.css", $archetype->css($profile));
    }

    private function writeConfig(string $basePath, ArchetypeInterface $archetype, SiteProfile $profile): void
    {
        $configDir = "{$basePath}/config";
        if (!is_dir($configDir)) {
            mkdir($configDir, 0755, true);
        }
        $config = $archetype->config($profile);
        file_put_contents("{$configDir}/rakun.yaml", Yaml::dump($config, 4));
    }

    private function writeGlobals(string $basePath, ArchetypeInterface $archetype, SiteProfile $profile): void
    {
        $globalsDir = "{$basePath}/content/_globals";
        if (!is_dir($globalsDir)) {
            mkdir($globalsDir, 0755, true);
        }
        $globals = $archetype->globals($profile);
        file_put_contents("{$globalsDir}/site.yaml", Yaml::dump($globals, 4));
    }

    private function askArchetype(InputInterface $input, OutputInterface $output): string
    {
        if (!$input->isInteractive()) {
            return 'blog';
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $choices = [];
        foreach ($this->registry->all() as $archetype) {
            $choices[$archetype->name()] = "{$archetype->name()} — {$archetype->description()}";
        }

        $question = new ChoiceQuestion('Select an archetype:', $choices, 'blog');
        /** @var string $answer */
        $answer = $helper->ask($input, $output, $question);
        return $answer;
    }

    private function askQuestion(InputInterface $input, OutputInterface $output, string $label, string $default): string
    {
        if (!$input->isInteractive()) {
            return $default;
        }

        /** @var QuestionHelper $helper */
        $helper = $this->getHelper('question');
        $question = new Question("{$label} [{$default}]: ", $default);
        /** @var string $answer */
        $answer = $helper->ask($input, $output, $question);
        return $answer;
    }
}
