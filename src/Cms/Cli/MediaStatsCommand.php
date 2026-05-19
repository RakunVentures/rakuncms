<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'wxr:media-stats', description: 'Show status of media assets referenced in content')]
final class MediaStatsCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Content path to scan', 'content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $scanPath = $input->getOption('path');
        $basePath = getcwd();
        $fullScanPath = $basePath . '/' . ltrim($scanPath, '/');
        $downloadPath = $basePath . '/public/assets/images/uploads';
        
        if (!is_dir($fullScanPath)) {
            $output->writeln("<error>Content path not found: {$fullScanPath}</error>");
            return Command::FAILURE;
        }

        $output->writeln("<info>Analyzing media usage in content...</info>");

        $it = new \RecursiveDirectoryIterator($fullScanPath);
        $display = new \RecursiveIteratorIterator($it);
        
        $stats = [];
        $totalReferences = 0;
        
        foreach ($display as $file) {
            if ($file->getExtension() === 'md') {
                $content = file_get_contents($file->getPathname());
                preg_match_all('/\/assets\/images\/uploads\/([^\s"\)\>]+)/', $content, $matches);
                
                foreach ($matches[1] as $relPath) {
                    $totalReferences++;
                    if (!isset($stats[$relPath])) {
                        $localFile = $downloadPath . '/' . $relPath;
                        $stats[$relPath] = file_exists($localFile) && filesize($localFile) > 0;
                    }
                }
            }
        }

        $uniqueImages = count($stats);
        $downloaded = count(array_filter($stats));
        $missing = $uniqueImages - $downloaded;
        $percentage = $uniqueImages > 0 ? round(($downloaded / $uniqueImages) * 100, 2) : 0;

        $output->writeln("");
        $table = new Table($output);
        $table->setHeaders(['Metric', 'Value'])
              ->setRows([
                  ['Total references in Markdown', $totalReferences],
                  ['Unique images required', $uniqueImages],
                  ['Images already on disk', "<info>{$downloaded}</info>"],
                  ['Missing images', "<error>{$missing}</error>"],
                  ['Sync Progress', "{$percentage}%"],
              ]);
        $table->render();

        if ($missing > 0) {
            $output->writeln("");
            $output->writeln("<comment>Recommendation:</comment> Run <info>php rakun wxr:media --limit {$missing}</info> to complete the library.");
        } else if ($uniqueImages > 0) {
            $output->writeln("");
            $output->writeln("<info>🎉 All referenced media is present on local disk!</info>");
        }

        return Command::SUCCESS;
    }
}
