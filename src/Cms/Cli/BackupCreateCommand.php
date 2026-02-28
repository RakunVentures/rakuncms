<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'backup:create', description: 'Create a backup ZIP of the site')]
final class BackupCreateCommand extends Command
{
    /** @var list<string> Directories to include */
    private const INCLUDE_DIRS = ['content', 'config', 'templates', 'public/assets', 'lang'];

    /** @var list<string> Patterns to exclude */
    private const EXCLUDE_PATTERNS = ['cache/', 'vendor/', 'storage/queue/', 'node_modules/', '.git/'];

    protected function configure(): void
    {
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output file path');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();

        // Determine output path
        $outputPath = $input->getOption('output');
        if ($outputPath === null) {
            $backupDir = $basePath . '/storage/backups';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0755, true);
            }
            $outputPath = $backupDir . '/backup-' . date('Y-m-d-His') . '.zip';
        }

        $output->writeln('<info>Creating backup...</info>');

        $zip = new \ZipArchive();
        $result = $zip->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        if ($result !== true) {
            $output->writeln('<error>Failed to create ZIP file: ' . $outputPath . '</error>');
            return Command::FAILURE;
        }

        $fileCount = 0;

        foreach (self::INCLUDE_DIRS as $dir) {
            $dirPath = $basePath . '/' . $dir;
            if (!is_dir($dirPath)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dirPath, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filePath = $file->getPathname();
                $relativePath = ltrim(str_replace($basePath, '', $filePath), '/');

                // Skip excluded patterns
                if ($this->isExcluded($relativePath)) {
                    continue;
                }

                // Sanitize config files
                if ($dir === 'config' && str_ends_with($filePath, '.yaml')) {
                    $content = $this->sanitizeConfig($filePath);
                    $zip->addFromString($relativePath, $content);
                } else {
                    $zip->addFile($filePath, $relativePath);
                }

                $fileCount++;
            }
        }

        // Add manifest
        $manifest = $this->buildManifest($basePath, $fileCount);
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        $zip->close();

        $size = filesize($outputPath);
        $sizeStr = $size > 1048576
            ? round($size / 1048576, 1) . 'MB'
            : round($size / 1024, 1) . 'KB';

        $output->writeln(sprintf('<info>Backup created: %s (%s, %d files)</info>', $outputPath, $sizeStr, $fileCount));

        return Command::SUCCESS;
    }

    private function isExcluded(string $path): bool
    {
        foreach (self::EXCLUDE_PATTERNS as $pattern) {
            if (str_contains($path, $pattern)) {
                return true;
            }
        }
        return false;
    }

    private function sanitizeConfig(string $filePath): string
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            return '';
        }

        // Replace known secret patterns with placeholders (handles both `key:` and `- key:`)
        $content = preg_replace('/^(\s*-?\s*key:\s*)".+"/m', '$1"REDACTED"', $content) ?? $content;
        $content = preg_replace('/^(\s*-?\s*token:\s*)".+"/m', '$1"REDACTED"', $content) ?? $content;
        $content = preg_replace('/^(\s*-?\s*secret:\s*)".+"/m', '$1"REDACTED"', $content) ?? $content;
        $content = preg_replace('/^(\s*-?\s*password:\s*)".+"/m', '$1"REDACTED"', $content) ?? $content;

        return $content;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildManifest(string $basePath, int $fileCount): array
    {
        $locales = [];
        try {
            $locales = \config('site.locales', []);
        } catch (\Throwable) {
        }

        return [
            'version' => '1.0',
            'cms' => 'RakunCMS',
            'created_at' => date('c'),
            'file_count' => $fileCount,
            'locales' => $locales,
            'base_path' => basename($basePath),
        ];
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
