<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'wxr:magazine-process', description: 'Extract pages from PDF magazines as JPG images')]
final class MagazineProcessCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::OPTIONAL, 'The magazine slug to process (empty for all)');
        $this->addOption('limit-pages', null, InputOption::VALUE_REQUIRED, 'Max pages to extract per PDF', '0');
        $this->addOption('quality', null, InputOption::VALUE_REQUIRED, 'JPG quality', '80');
        $this->addOption('width', null, InputOption::VALUE_REQUIRED, 'Target width', '1200');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('slug');
        $quality = $input->getOption('quality');
        $width = $input->getOption('width');
        $limitPages = (int) $input->getOption('limit-pages');
        
        $basePath = getcwd();
        $contentDir = $basePath . '/content/revista';
        $pdfDir = $basePath . '/public/assets/pdfs/revista';
        $pagesBaseDir = $basePath . '/public/assets/images/revista/pages';

        if (!is_dir($pagesBaseDir)) {
            mkdir($pagesBaseDir, 0755, true);
        }

        $mdFiles = $slug ? ["$contentDir/$slug.md"] : glob("$contentDir/*.md");

        foreach ($mdFiles as $mdFile) {
            if (!file_exists($mdFile)) continue;
            
            $slug = basename($mdFile, '.md');
            $content = file_get_contents($mdFile);
            
            if (!preg_match('/pdf_url: "(.*?)"/', $content, $matches)) {
                $output->writeln("<error>No pdf_url found in $slug.md</error>");
                continue;
            }
            
            $pdfRelPath = $matches[1];
            $pdfPath = $basePath . '/public' . $pdfRelPath;
            
            if (!file_exists($pdfPath)) {
                $output->writeln("<error>PDF file not found: $pdfPath</error>");
                continue;
            }

            $output->writeln("<info>Processing magazine: $slug</info>");

            exec("pdfinfo " . escapeshellarg($pdfPath) . " | grep Pages | awk '{print $2}'", $pageCountOutput);
            $pageCount = (int) ($pageCountOutput[0] ?? 0);
            unset($pageCountOutput);

            if ($pageCount === 0) {
                $output->writeln("<error>Could not determine page count for $slug</error>");
                continue;
            }

            $output->writeln(" - Total pages: $pageCount");

            $magazinePagesDir = "$pagesBaseDir/$slug";
            if (!is_dir($magazinePagesDir)) {
                mkdir($magazinePagesDir, 0755, true);
            }

            $processLimit = $limitPages > 0 ? min($pageCount, $limitPages) : $pageCount;

            for ($i = 0; $i < $processLimit; $i++) {
                $targetImg = "$magazinePagesDir/page-" . sprintf('%03d', $i + 1) . ".jpg";
                
                if (file_exists($targetImg)) {
                    continue;
                }

                $output->write("   Extracing page " . ($i + 1) . "/$processLimit... ");
                
                $pdfArg = $pdfPath . '[' . $i . ']';
                $cmd = sprintf("magick -density 150 %s -flatten -resize %dx -quality %s %s 2>&1", 
                    escapeshellarg($pdfArg), 
                    $width,
                    $quality,
                    escapeshellarg($targetImg)
                );
                
                exec($cmd, $cmdOutput, $returnVar);
                
                if ($returnVar === 0) {
                    $output->writeln("<info>OK</info>");
                } else {
                    $output->writeln("<error>Error</error>");
                    $output->writeln(implode("\n", $cmdOutput));
                }
                unset($cmdOutput);
            }

            $newContent = preg_replace('/pages_count: \d+/', "pages_count: $pageCount", $content);
            file_put_contents($mdFile, $newContent);
            
            $output->writeln("<info>Finished $slug</info>\n");
        }

        return Command::SUCCESS;
    }
}
