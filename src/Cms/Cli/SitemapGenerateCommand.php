<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Http\Controllers\SitemapController;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun sitemap:generate
 *
 * Generates a static sitemap.xml file in the public directory.
 */
#[AsCommand(name: 'sitemap:generate', description: 'Generate sitemap.xml')]
final class SitemapGenerateCommand extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();

        // Bootstrap a minimal application if not already running
        if (\Rkn\Framework\Application::getInstance() === null) {
            new \Rkn\Framework\Application($basePath);
        }

        $controller = new SitemapController();
        $response = $controller->handle();

        $publicDir = $basePath . '/public';
        if (!is_dir($publicDir)) {
            mkdir($publicDir, 0755, true);
        }

        $content = (string) $response->getBody();
        file_put_contents($publicDir . '/sitemap.xml', $content);

        $output->writeln('<info>Generated sitemap.xml</info> (' . strlen($content) . ' bytes)');

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
