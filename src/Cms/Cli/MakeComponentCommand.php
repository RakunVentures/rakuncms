<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun make:component {name}
 *
 * Creates a Yoyo component class and its Twig template.
 */
#[AsCommand(name: 'make:component', description: 'Create a new Yoyo component')]
final class MakeComponentCommand extends Command
{

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Component name (e.g. "newsletter-form")');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $basePath = $this->findBasePath();

        // Convert kebab-case to PascalCase for class name
        $className = str_replace(' ', '', ucwords(str_replace('-', ' ', $name)));

        // Component PHP file
        $componentDir = $basePath . '/vendor/rkn/cms/src/Cms/Components';
        if (!is_dir($componentDir)) {
            // When developing the CMS directly
            $componentDir = $basePath . '/src/Cms/Components';
        }

        $componentFile = $componentDir . '/' . $className . '.php';

        if (file_exists($componentFile)) {
            $output->writeln('<error>Component already exists: ' . $componentFile . '</error>');
            return Command::FAILURE;
        }

        $phpContent = <<<PHP
        <?php

        declare(strict_types=1);

        namespace Rkn\\Cms\\Components;

        use Clickfwd\\Yoyo\\Component;

        class {$className} extends Component
        {
            public function render(): string
            {
                return \$this->view('yoyo/{$name}');
            }
        }
        PHP;

        if (!is_dir(dirname($componentFile))) {
            mkdir(dirname($componentFile), 0755, true);
        }
        file_put_contents($componentFile, $phpContent);
        $output->writeln('<info>Created component:</info> ' . $componentFile);

        // Twig template
        $templateFile = $basePath . '/templates/yoyo/' . $name . '.twig';
        if (!file_exists($templateFile)) {
            if (!is_dir(dirname($templateFile))) {
                mkdir(dirname($templateFile), 0755, true);
            }
            file_put_contents($templateFile, "<div>\n  <!-- {$className} component -->\n</div>\n");
            $output->writeln('<info>Created template:</info> ' . $templateFile);
        }

        return Command::SUCCESS;
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
