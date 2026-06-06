<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'wxr:magazine-process', description: 'Update page count for PDF magazines')]
final class MagazineProcessCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('slug', InputArgument::OPTIONAL, 'The magazine slug to process (empty for all)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = $input->getArgument('slug');
        
        $basePath = getcwd();
        $contentDir = $basePath . '/content/revista';

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

            $newContent = preg_replace('/pages_count: \d+/', "pages_count: $pageCount", $content);
            file_put_contents($mdFile, $newContent);
            
            $output->writeln("<info>Finished $slug</info>\n");
        }

        return Command::SUCCESS;
    }
}
