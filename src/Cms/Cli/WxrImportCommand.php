<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'wxr:import', description: 'Import content from a WordPress WXR backup')]
final class WxrImportCommand extends Command
{
    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'The WXR XML file or directory containing XML files');
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'Target collection (e.g., blog, pages)', 'blog');
        $this->addOption('post-type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Post types to import', ['post', 'page']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $path = $input->getArgument('path');
        $targetCollection = $input->getOption('collection');
        $allowedPostTypes = $input->getOption('post-type');

        $files = [];
        if (is_dir($path)) {
            $files = glob(rtrim($path, '/') . '/*.xml');
        } elseif (file_exists($path)) {
            $files = [$path];
        }

        if (empty($files)) {
            $output->writeln("<error>No WXR files found at: {$path}</error>");
            return Command::FAILURE;
        }

        $output->writeln(sprintf("Importing from <info>%d</info> file(s) to <info>%s</info>...", count($files), $targetCollection));

        $totalCount = 0;
        foreach ($files as $file) {
            $output->writeln("Processing <comment>{$file}</comment>...");
            $totalCount += $this->importFile($file, $targetCollection, $allowedPostTypes, $output);
        }

        $output->writeln("<info>Successfully imported {$totalCount} entries total.</info>");

        return Command::SUCCESS;
    }

    private function importFile(string $file, string $collection, array $allowedPostTypes, OutputInterface $output): int
    {
        // Use LIBXML_PARSEHUGE to handle large XML files
        $xml = @simplexml_load_file($file, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_PARSEHUGE);
        if ($xml === false) {
            $output->writeln("<error>Failed to parse XML file: {$file}</error>");
            return 0;
        }

        $namespaces = $xml->getDocNamespaces(true);
        $wpNs = $namespaces['wp'] ?? 'http://wordpress.org/export/1.2/';
        $contentNs = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
        $dcNs = $namespaces['dc'] ?? 'http://purl.org/dc/elements/1.1/';

        $count = 0;
        foreach ($xml->channel->item as $item) {
            $wp = $item->children($wpNs);
            $content = $item->children($contentNs);
            $dc = $item->children($dcNs);

            $postType = (string) $wp->post_type;
            
            if (!in_array($postType, $allowedPostTypes)) {
                continue;
            }

            $title = (string) $item->title;
            $slug = (string) $wp->post_name;
            $status = (string) $wp->status;
            $date = (string) $wp->post_date;
            $body = (string) $content->encoded;
            $author = (string) $dc->creator;

            // Handle empty slug
            if (empty($slug)) {
                $slug = $this->slugify($title) ?: uniqid();
            }

                        $basePath = getcwd();
            $timestamp = strtotime($date) ?: time();
            $folder = "{$basePath}/content/{$collection}/" . date('Y', $timestamp) . "/" . date('m', $timestamp);
            if (!is_dir($folder)) {
                mkdir($folder, 0o755, true);
            }

            $filename = "{$folder}/{$slug}.md";
            
            $link = (string) $item->link;
            $parsedUrl = parse_url($link);
            $oldUrlPath = $parsedUrl["path"] ?? "";

            $frontmatter = [
                'title' => $title,
                'date' => $date,
                'status' => $status,
                'author' => $author,
                'template' => $postType === 'page' ? 'page' : 'blog-post',
                'wp_id' => (string) $wp->post_id,
                'wp_type' => $postType,
                'old_url' => $oldUrlPath,
            ];

            // Categories and Tags
            foreach ($item->category as $cat) {
                $domain = (string) $cat['domain'];
                $nicename = (string) $cat['nicename'];
                $name = (string) $cat;
                if ($domain === 'category') {
                    $frontmatter['categories'][] = $name;
                } elseif ($domain === 'post_tag') {
                    $frontmatter['tags'][] = $name;
                }
            }

            // Simple conversion of WordPress [caption] shortcodes
            $body = preg_replace('/\[caption[^\]]*\].*?href="([^"]*)".*?src="([^"]*)".*?\[\/caption\]/is', '![]($2)', $body);
            $body = preg_replace('/\[caption[^\]]*\].*?src="([^"]*)".*?\[\/caption\]/is', '![]($1)', $body);

            $contentMd = "---\n";
            foreach ($frontmatter as $key => $value) {
                if (is_array($value)) {
                    if (is_array($value)) {
                    $contentMd .= "{$key}: " . json_encode(array_values(array_unique($value)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                } else {
                    $contentMd .= "{$key}: " . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                }
            }
            }
            $contentMd .= "---\n\n{$body}";

            file_put_contents($filename, $contentMd);
            $count++;
        }

        return $count;
    }

    private function slugify(string $text): string
    {
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
        $text = preg_replace('~[^-\w]+~', '', $text);
        $text = trim($text, '-');
        $text = preg_replace('~-+~', '-', $text);
        $text = strtolower($text);
        return $text;
    }
}
