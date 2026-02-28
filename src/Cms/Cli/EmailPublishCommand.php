<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun email:publish
 *
 * Copies email templates from the CMS package to the site's templates directory
 * so they can be customized per-site.
 */
#[AsCommand(name: 'email:publish', description: 'Publish email templates for customization')]
final class EmailPublishCommand extends Command
{

    protected function configure(): void
    {
        $this->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing templates');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();
        $force = (bool) $input->getOption('force');

        $sourcePath = dirname(__DIR__, 3) . '/templates/email';
        $destPath = $basePath . '/templates/email';

        if (!is_dir($sourcePath)) {
            $output->writeln('<error>Source templates not found: ' . $sourcePath . '</error>');
            return Command::FAILURE;
        }

        if (is_dir($destPath) && !$force) {
            $output->writeln('<comment>Email templates directory already exists: ' . $destPath . '</comment>');
            $output->writeln('Use --force to overwrite existing templates.');
            return Command::FAILURE;
        }

        if (!is_dir($destPath)) {
            mkdir($destPath, 0755, true);
        }

        $files = glob($sourcePath . '/*.html.php');
        if ($files === false || $files === []) {
            $output->writeln('<comment>No email templates found to publish.</comment>');
            return Command::SUCCESS;
        }

        $copied = 0;
        foreach ($files as $file) {
            $filename = basename($file);
            $dest = $destPath . '/' . $filename;

            if (is_file($dest) && !$force) {
                $output->writeln('<comment>Skipped:</comment> templates/email/' . $filename . ' (already exists)');
                continue;
            }

            copy($file, $dest);
            $output->writeln('<info>Published:</info> templates/email/' . $filename);
            $copied++;
        }

        if ($copied === 0) {
            $output->writeln('<comment>No templates were published (all already exist).</comment>');
        } else {
            $output->writeln(sprintf('<info>Published %d email template(s).</info>', $copied));
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
