<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'wxr:media', description: 'Download images referenced in content using parallel requests')]
final class MediaImportCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max NEW images to download', '100');
        $this->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Number of parallel downloads', '10');
        $this->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Content path to scan', 'content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $concurrency = (int) $input->getOption('concurrency');
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
                preg_match_all('/\/assets\/images\/uploads\/([^\s"\)\>]+)/', $content, $matches);
                foreach ($matches[1] as $path) {
                    $relativePaths[$path] = true;
                }
            }
        }

        $uniquePaths = array_keys($relativePaths);
        $totalReferences = count($uniquePaths);
        $output->writeln("<info>Found {$totalReferences} unique image references in content.</info>");

        $downloadPath = $basePath . '/public/assets/images/uploads';
        $remoteBase = 'http://fianceebodas.com/wp-content/uploads/';

        // Filter out existing files
        $pending = [];
        $skippedCount = 0;
        foreach ($uniquePaths as $relPath) {
            $localFile = $downloadPath . '/' . $relPath;
            if (file_exists($localFile) && filesize($localFile) > 0) {
                $skippedCount++;
                continue;
            }
            $pending[] = $relPath;
        }

        $totalToDownload = min(count($pending), $limit);
        $output->writeln(" - Already had: <comment>{$skippedCount}</comment>");
        $output->writeln(" - Pending to download in this batch: <info>{$totalToDownload}</info> (Concurrency: {$concurrency})");

        if ($totalToDownload === 0) {
            $output->writeln("<info>All images already present.</info>");
            return Command::SUCCESS;
        }

        $downloadedCount = 0;
        $failedCount = 0;
        
        // Process in chunks of concurrency
        $chunks = array_chunk(array_slice($pending, 0, $limit), $concurrency);

        foreach ($chunks as $chunk) {
            $mh = curl_multi_init();
            $handles = [];
            $files = [];

            foreach ($chunk as $relPath) {
                $remoteUrl = $remoteBase . $relPath;
                $localFile = $downloadPath . '/' . $relPath;
                $localDir = dirname($localFile);
                if (!is_dir($localDir)) mkdir($localDir, 0755, true);

                $ch = curl_init($remoteUrl);
                $fp = fopen($localFile, 'wb');
                
                curl_setopt($ch, CURLOPT_FILE, $fp);
                curl_setopt($ch, CURLOPT_HEADER, 0);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($ch, CURLOPT_TIMEOUT, 60);

                curl_multi_add_handle($mh, $ch);
                $handles[] = ['ch' => $ch, 'fp' => $fp, 'url' => $remoteUrl, 'path' => $localFile];
            }

            // Execute the handles
            $running = null;
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh);
            } while ($running > 0);

            // Check results and close
            foreach ($handles as $h) {
                $httpCode = curl_getinfo($h['ch'], CURLINFO_HTTP_CODE);
                curl_multi_remove_handle($mh, $h['ch']);
                curl_close($h['ch']);
                fclose($h['fp']);

                if ($httpCode === 200) {
                    $downloadedCount++;
                } else {
                    $failedCount++;
                    @unlink($h['path']);
                }
            }
            curl_multi_close($mh);
            $output->write('.'); // Progress indicator
        }

        $output->writeln("\n\n<info>Batch Summary:</info>");
        $output->writeln(" - Successfully downloaded: <info>{$downloadedCount}</info>");
        if ($failedCount > 0) {
            $output->writeln(" - Failed: <error>{$failedCount}</error>");
        }

        return Command::SUCCESS;
    }
}
