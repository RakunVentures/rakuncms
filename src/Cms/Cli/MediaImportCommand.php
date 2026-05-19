<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'wxr:media', description: 'Ultra-robust media downloader with multiple recovery strategies')]
final class MediaImportCommand extends Command
{
    private string $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';
    private array $mediaMap = [];

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Max NEW images to download', '100');
        $this->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Number of parallel downloads', '5');
        $this->addOption('path', 'p', InputOption::VALUE_REQUIRED, 'Content path to scan', 'content');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');
        $concurrency = (int) $input->getOption('concurrency');
        $scanPath = $input->getOption('path');
        $basePath = getcwd();
        
        $mapFile = $basePath . '/storage/media_map.json';
        if (file_exists($mapFile)) {
            $this->mediaMap = json_decode(file_get_contents($mapFile), true) ?? [];
        }

        $output->writeln("<info>Scanning content...</info>");
        $it = new \RecursiveDirectoryIterator($basePath . '/' . ltrim($scanPath, '/'));
        $display = new \RecursiveIteratorIterator($it);
        
        $relativePaths = [];
        foreach ($display as $file) {
            if ($file->getExtension() === 'md') {
                $content = file_get_contents($file->getPathname());
                preg_match_all('/\/assets\/images\/uploads\/([^\s"\)\>]+)/', $content, $matches);
                foreach ($matches[1] as $path) $relativePaths[$path] = true;
            }
        }

        $downloadPath = $basePath . '/public/assets/images/uploads';
        $pending = [];
        foreach (array_keys($relativePaths) as $relPath) {
            if (!file_exists($downloadPath . '/' . $relPath) || filesize($downloadPath . '/' . $relPath) < 100) {
                $pending[] = $relPath;
            }
        }

        $total = min(count($pending), $limit);
        $output->writeln(" - Pending: <info>{$total}</info> / " . count($relativePaths));
        if ($total === 0) return Command::SUCCESS;

        $downloaded = 0;
        $failed = 0;
        $batch = array_slice($pending, 0, $limit);

        foreach ($batch as $relPath) {
            $localFile = $downloadPath . '/' . $relPath;
            if (!is_dir(dirname($localFile))) mkdir(dirname($localFile), 0755, true);

            if ($this->tryDownload($relPath, $localFile)) {
                $downloaded++;
                $output->write('<info>.</info>');
            } else {
                $failed++;
                $output->write('<error>F</error>');
            }
            if ($downloaded + $failed >= $limit) break;
        }

        $output->writeln("\n\n<info>Summary: Success: {$downloaded}, Failed: {$failed}</info>");
        return Command::SUCCESS;
    }

    private function tryDownload(string $relPath, string $localFile): bool
    {
        $filename = basename($relPath);
        $cleanName = class_exists('\Normalizer') ? \Normalizer::normalize($filename, \Normalizer::FORM_C) : $filename;
        
        $urls = [
            // 1. Standard path
            'https://fianceebodas.com/wp-content/uploads/' . $this->encodePath($relPath),
            // 2. NFD Variant
            'https://fianceebodas.com/wp-content/uploads/' . $this->encodePath($this->normalize($relPath, 'NFD')),
            // 3. Map lookup
            $this->mediaMap[$cleanName] ?? null,
            $this->mediaMap[$filename] ?? null,
            // 4. S3 Direct Bucket lookup (bypassing local DNS redirect)
            'https://cdn.fianceebodas.com.s3.amazonaws.com/' . $this->encodePath($this->normalize($relPath, 'NFD')),
            // 5. Common size suffixes if original is missing
            str_replace('.jpg', '-620x360.jpg', 'https://fianceebodas.com/wp-content/uploads/' . $this->encodePath($relPath)),
            'https://fianceebodas.com/wp-content/uploads/articulos/' . rawurlencode($filename)
        ];

        foreach (array_filter(array_unique($urls)) as $url) {
            if ($this->executeDownload($url, $localFile)) return true;
        }
        return false;
    }

    private function encodePath(string $relPath): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $relPath)));
    }

    private function executeDownload(string $url, string $localFile): bool
    {
        if (strpos($url, '//') === 0) $url = 'https:' . $url;
        $ch = curl_init($url);
        $fp = fopen($localFile, 'wb');
        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_SSL_VERIFYPEER => false
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if ($code === 200 && filesize($localFile) > 500) return true;
        @unlink($localFile);
        return false;
    }

    private function normalize(string $path, string $form): string
    {
        if (!class_exists('\Normalizer')) return $path;
        return \Normalizer::normalize($path, $form === 'NFD' ? \Normalizer::FORM_D : \Normalizer::FORM_C);
    }
}
