<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\Query;
use Rkn\Cms\Http\Controllers\RssController;
use Rkn\Cms\Http\Controllers\SitemapController;
use Rkn\Cms\Template\Engine;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * CLI command: rakun build
 *
 * Generates a static site in dist/ by iterating all entries,
 * rendering each with Twig, and writing HTML files.
 */
#[AsCommand(name: 'build', description: 'Generate static site in dist/')]
final class BuildCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output directory', 'dist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();
        $distDir = $basePath . '/' . $input->getOption('output');

        // Bootstrap application if not running
        if (\Rkn\Framework\Application::getInstance() === null) {
            new \Rkn\Framework\Application($basePath);
        }

        $start = microtime(true);
        $output->writeln('<info>Building static site...</info>');

        // Clean dist directory
        if (is_dir($distDir)) {
            $this->deleteDirectory($distDir);
        }
        mkdir($distDir, 0755, true);

        // Rebuild index
        $indexer = new Indexer($basePath);
        $index = $indexer->rebuild();
        $entries = $index['entries'] ?? [];

        $output->writeln(sprintf('  Found %d entries', count($entries)));

        // Render each entry
        $rendered = 0;
        foreach ($entries as $entryData) {
            $entry = Entry::fromArray($entryData);

            if ($entry->isDraft()) {
                continue;
            }

            $url = $entry->url();
            $html = $this->renderEntry($entry, $index, $basePath);

            // Write to dist
            $filePath = $this->urlToFilePath($distDir, $url);
            $dir = dirname($filePath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            file_put_contents($filePath, $html);
            $rendered++;

            if ($output->isVerbose()) {
                $output->writeln('  ' . $url . ' -> ' . str_replace($distDir, 'dist', $filePath));
            }
        }

        $output->writeln(sprintf('  Rendered %d pages', $rendered));

        // Copy static assets
        $this->copyAssets($basePath, $distDir, $output);

        // Generate sitemap.xml
        $this->generateSitemap($distDir, $output);

        // Generate RSS feed
        $this->generateRss($distDir, $output);

        // Generate robots.txt
        $this->generateRobots($basePath, $distDir, $output);

        $elapsed = round((microtime(true) - $start) * 1000);
        $output->writeln(sprintf('<info>Build complete in %dms. Output: %s</info>', $elapsed, $distDir));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, mixed> $index
     */
    private function renderEntry(Entry $entry, array $index, string $basePath): string
    {
        $locale = $entry->locale();
        $container = \app();
        $container->set('current_entry', $entry);
        $container->set('locale', $locale);

        // Set up translator for this locale
        try {
            $translator = $container->get('translator');
            if ($translator instanceof \Rkn\Cms\I18n\Translator) {
                $translator->setLocale($locale);
            }
        } catch (\Throwable) {
        }

        $templateName = $this->resolveTemplate($entry, $basePath);
        $globals = $this->loadGlobals($basePath, $locale);
        $engine = Engine::create($basePath);

        return $engine->render($templateName, [
            'entry' => $entry,
            'page' => $entry,
            'locale' => $locale,
            'site' => $globals['site'] ?? [],
            'nav' => $globals['nav'] ?? [],
            'globals' => $globals,
        ]);
    }

    private function resolveTemplate(Entry $entry, string $basePath): string
    {
        $templateDir = $basePath . '/templates';
        $collection = $entry->collection();
        $slug = $entry->slug();

        if ($entry->template() !== null) {
            return $entry->template() . '.twig';
        }

        if (file_exists($templateDir . '/' . $collection . '/' . $slug . '.twig')) {
            return $collection . '/' . $slug . '.twig';
        }

        if (file_exists($templateDir . '/' . $collection . '/show.twig')) {
            return $collection . '/show.twig';
        }

        if (file_exists($templateDir . '/_layouts/' . $collection . '.twig')) {
            return '_layouts/' . $collection . '.twig';
        }

        return '_layouts/page.twig';
    }

    /**
     * @return array<string, mixed>
     */
    private function loadGlobals(string $basePath, string $locale): array
    {
        $globalsPath = $basePath . '/content/_globals';
        $globals = [];

        if (!is_dir($globalsPath)) {
            return $globals;
        }

        $files = glob($globalsPath . '/*.yaml') ?: [];
        foreach ($files as $file) {
            $name = basename($file, '.yaml');
            $data = Yaml::parseFile($file);
            $globals[$name] = is_array($data) ? $data : [];
        }

        return $globals;
    }

    private function urlToFilePath(string $distDir, string $url): string
    {
        $url = trim($url, '/');

        if ($url === '' || str_ends_with($url, '/')) {
            return $distDir . '/' . $url . 'index.html';
        }

        return $distDir . '/' . $url . '/index.html';
    }

    private function copyAssets(string $basePath, string $distDir, OutputInterface $output): void
    {
        $publicDir = $basePath . '/public';
        $dirs = ['assets', 'images'];

        foreach ($dirs as $dir) {
            $source = $publicDir . '/' . $dir;
            $dest = $distDir . '/' . $dir;

            if (is_dir($source)) {
                $this->copyDirectory($source, $dest);
                $output->writeln('  Copied ' . $dir . '/');
            }
        }

        // Copy .htaccess if it exists
        $htaccess = $publicDir . '/.htaccess';
        if (is_file($htaccess)) {
            copy($htaccess, $distDir . '/.htaccess');
        }

        // Copy robots.txt if it exists already
        $robots = $publicDir . '/robots.txt';
        if (is_file($robots)) {
            copy($robots, $distDir . '/robots.txt');
        }
    }

    private function generateSitemap(string $distDir, OutputInterface $output): void
    {
        try {
            $controller = new SitemapController();
            $response = $controller->handle();
            file_put_contents($distDir . '/sitemap.xml', (string) $response->getBody());
            $output->writeln('  Generated sitemap.xml');
        } catch (\Throwable $e) {
            $output->writeln('<comment>  Warning: Could not generate sitemap.xml: ' . $e->getMessage() . '</comment>');
        }
    }

    private function generateRss(string $distDir, OutputInterface $output): void
    {
        try {
            $controller = new RssController();
            $response = $controller->handle();
            file_put_contents($distDir . '/rss.xml', (string) $response->getBody());
            $output->writeln('  Generated rss.xml');
        } catch (\Throwable $e) {
            $output->writeln('<comment>  Warning: Could not generate rss.xml: ' . $e->getMessage() . '</comment>');
        }
    }

    private function generateRobots(string $basePath, string $distDir, OutputInterface $output): void
    {
        $robotsFile = $distDir . '/robots.txt';
        if (is_file($robotsFile)) {
            return; // Already copied from public/
        }

        $baseUrl = \config('site.base_url', '');
        $content = "User-agent: *\nAllow: /\n\nSitemap: {$baseUrl}/sitemap.xml\n";
        file_put_contents($robotsFile, $content);
        $output->writeln('  Generated robots.txt');
    }

    private function copyDirectory(string $source, string $dest): void
    {
        if (!is_dir($dest)) {
            mkdir($dest, 0755, true);
        }

        $items = new \DirectoryIterator($source);
        foreach ($items as $item) {
            if ($item->isDot()) {
                continue;
            }

            $srcPath = $item->getPathname();
            $dstPath = $dest . '/' . $item->getFilename();

            if ($item->isDir()) {
                $this->copyDirectory($srcPath, $dstPath);
            } else {
                copy($srcPath, $dstPath);
            }
        }
    }

    private function deleteDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \DirectoryIterator($dir);
        foreach ($items as $item) {
            if ($item->isDot()) {
                continue;
            }

            if ($item->isDir()) {
                $this->deleteDirectory($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($dir);
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
