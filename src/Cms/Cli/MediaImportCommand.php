<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'wxr:media', description: 'Download images referenced in content')]
final class MediaImportCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max NEW images to download', '100');
        $this->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Content path to scan', 'content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $scanPath = $input->getOption('path');
        $basePath = getcwd();
        $fullScanPath = $basePath . '/' . ltrim($scanPath, '/');
        
        $output->writeln("<info>Scanning content for images in {$fullScanPath}...</info>");

        $it = new \RecursiveDirectoryIterator($fullScanPath);
        $display = new \RecursiveIteratorIterator($it);
        
        $relativePaths = [];
        foreach ($display as $file) {
            if ($file->getExtension() === 'md') {
                $content = file_get_contents($file->getPathname());
                preg_match_all('/\/assets\/images\/uploads\/[^\s"\)\>]+/', $content, $matches);
                foreach ($matches[0] as $path) {
                    $relativePaths[str_replace('/assets/images/uploads/', '', $path)] = true;
                }
            }
        }

        $uniquePaths = array_keys($relativePaths);
        $totalReferences = count($uniquePaths);
        $output->writeln("<info>Found {$totalReferences} unique image references in content.</info>");

        $downloadPath = $basePath . '/public/assets/images/uploads';
        $remoteBase = 'http://fianceebodas.com/wp-content/uploads/';

        $downloadedCount = 0;
        $skippedCount = 0;

        foreach ($uniquePaths as $relPath) {
            if ($downloadedCount >= $limit) break;

            $localFile = $downloadPath . '/' . $relPath;
            $localDir = dirname($localFile);
            $remoteUrl = $remoteBase . $relPath;

            // Check if already exists
            if (file_exists($localFile) && filesize($localFile) > 0) {
                $skippedCount++;
                continue;
            }

            if (!is_dir($localDir)) {
                mkdir($localDir, 0755, true);
            }

            $output->write("Downloading <comment>{$remoteUrl}</comment>... ");
            
            $ch = curl_init($remoteUrl);
            $fp = fopen($localFile, 'wb');
            curl_setopt($ch, CURLOPT_FILE, $fp);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 30);
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            fclose($fp);

            if ($httpCode === 200) {
                $output->writeln("<info>OK</info>");
                $downloadedCount++;
            } else {
                $output->writeln("<error>Error {$httpCode}</error>");
                @unlink($localFile);
            }
        }

        $output->writeln("\n<info>Summary:</info>");
        $output->writeln(" - Already had: <comment>{$skippedCount}</comment>");
        $output->writeln(" - New downloads: <comment>{$downloadedCount}</comment>");

        return Command::SUCCESS;
    }
}
