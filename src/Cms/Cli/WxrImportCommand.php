<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use SimpleXMLElement;

#[AsCommand(name: 'wxr:import', description: 'Import content from a WordPress WXR backup')]
final class WxrImportCommand extends Command
{
    /** @var array<string, string> */
    private array $authorMap = [];

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'The WXR XML file or directory containing XML files');
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'Target collection (e.g., blog, pages)', 'blog');
        $this->addOption('post-type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Post types to import', ['post', 'page']);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(0);

        $path = $input->getArgument('path');
        $targetCollection = $input->getOption('collection');
        $allowedPostTypes = $input->getOption('post-type');

        $files = [];
        if (is_dir($path)) {
            $files = glob(rtrim($path, '/') . '/*.xml');
            sort($files);
        } elseif (file_exists($path)) {
            $files = [$path];
        }

        if (empty($files)) {
            $output->writeln("<error>No WXR files found at: {$path}</error>");
            return Command::FAILURE;
        }

        libxml_use_internal_errors(true);

        $output->writeln("<info>Scanning for author data...</info>");
        foreach ($files as $file) {
            $this->scanAuthors($file);
        }
        $output->writeln(sprintf("Found <info>%d</info> unique authors.", count($this->authorMap)));

        $output->writeln(sprintf("Importing from <info>%d</info> file(s) to <info>%s</info>...", count($files), $targetCollection));

        $totalCount = 0;
        foreach ($files as $file) {
            $output->writeln("Processing <comment>" . basename($file) . "</comment>...");
            $fileCount = $this->importFileManually($file, $targetCollection, $allowedPostTypes, $output);
            $output->writeln("Finished <comment>" . basename($file) . "</comment>: <info>{$fileCount}</info> items.");
            $totalCount += $fileCount;
        }

        $output->writeln("<info>Successfully imported {$totalCount} entries total.</info>");

        return Command::SUCCESS;
    }

    private function scanAuthors(string $file): void
    {
        $content = file_get_contents($file);
        if ($content === false) return;

        if (preg_match_all('/<wp:author>(.*?)<\/wp:author>/s', $content, $matches)) {
            foreach ($matches[1] as $authorXml) {
                if (preg_match('/<wp:author_login><!\[CDATA\[(.*?)\]\]><\/wp:author_login>/', $authorXml, $loginMatch) ||
                    preg_match('/<wp:author_login>(.*?)<\/wp:author_login>/', $authorXml, $loginMatch)) {
                    
                    $login = trim($loginMatch[1]);
                    
                    if (preg_match('/<wp:author_display_name><!\[CDATA\[(.*?)\]\]><\/wp:author_display_name>/', $authorXml, $nameMatch) ||
                        preg_match('/<wp:author_display_name>(.*?)<\/wp:author_display_name>/', $authorXml, $nameMatch)) {
                        
                        $this->authorMap[$login] = trim($nameMatch[1]);
                    }
                }
            }
        }
    }

    private function importFileManually(string $file, string $collection, array $allowedPostTypes, OutputInterface $output): int
    {
        $content = file_get_contents($file);
        if ($content === false) return 0;

        preg_match('/<rss[^>]*>/i', $content, $matches);
        $rssTag = $matches[0] ?? '<rss version="2.0" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wfw="http://wellformedweb.org/CommentAPI/" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">';

        $parts = explode('<item>', $content);
        array_shift($parts);

        $count = 0;
        foreach ($parts as $part) {
            $itemContent = explode('</item>', $part)[0];
            $itemXml = '<item>' . $itemContent . '</item>';
            $fragment = '<?xml version="1.0" encoding="UTF-8" ?>' . $rssTag . $itemXml . '</rss>';

            try {
                $xml = @simplexml_load_string($fragment, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_PARSEHUGE | LIBXML_RECOVER);
                
                if (!$xml || !isset($xml->item)) {
                    libxml_clear_errors();
                    continue;
                }

                $item = $xml->item;
                $namespaces = $item->getDocNamespaces(true);
                $wpNs = $namespaces['wp'] ?? 'http://wordpress.org/export/1.2/';
                $contentNs = $namespaces['content'] ?? 'http://purl.org/rss/1.0/modules/content/';
                $dcNs = $namespaces['dc'] ?? 'http://purl.org/dc/elements/1.1/';

                $wp = $item->children($wpNs);
                $postType = (string) $wp->post_type;
                
                if (in_array($postType, $allowedPostTypes)) {
                    $contentChild = $item->children($contentNs);
                    $dc = $item->children($dcNs);

                    $title = (string) $item->title;
                    $slug = (string) $wp->post_name;
                    $status = (string) $wp->status;
                    $date = (string) $wp->post_date;
                    $body = (string) $contentChild->encoded;
                    
                    $authorLogin = (string) $dc->creator;
                    $authorName = $this->authorMap[$authorLogin] ?? $authorLogin;

                    if (empty($slug)) {
                        $slug = $this->slugify($title) ?: uniqid();
                    }

                    $timestamp = strtotime($date) ?: time();
                    $folder = getcwd() . "/content/{$collection}/" . date('Y', $timestamp) . "/" . date('m', $timestamp);
                    if (!is_dir($folder)) mkdir($folder, 0755, true);

                    $filename = "{$folder}/{$slug}.md";

                    $link = (string) $item->link;
                    $parsedUrl = parse_url($link);
                    $oldUrlPath = $parsedUrl["path"] ?? "";

                    $frontmatter = [
                        'title' => $title,
                        'date' => $date,
                        'status' => $status,
                        'author' => $authorName,
                        'template' => $postType === 'page' ? 'page' : 'blog-post',
                        'wp_id' => (string) $wp->post_id,
                        'wp_type' => $postType,
                        'old_url' => $oldUrlPath,
                    ];

                    foreach ($item->category as $cat) {
                        $domain = (string) $cat['domain'];
                        $name = (string) $cat;
                        if ($domain === 'category') {
                            $frontmatter['categories'][] = $name;
                        } elseif ($domain === 'post_tag') {
                            $frontmatter['tags'][] = $name;
                        }
                    }

                    $body = preg_replace('/\[caption[^\]]*\].*?href="([^"]*)".*?src="([^"]*)".*?\[\/caption\]/is', '![]($2)', $body);
                    $body = preg_replace('/\[caption[^\]]*\].*?src="([^"]*)".*?\[\/caption\]/is', '![]($1)', $body);

                    $contentMd = "---\n";
                    foreach ($frontmatter as $key => $value) {
                        $val = is_array($value) ? array_values(array_unique($value)) : $value;
                        $contentMd .= "{$key}: " . json_encode($val, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                    }
                    $contentMd .= "---\n\n{$body}";

                    file_put_contents($filename, $contentMd);
                    $count++;
                    
                    if ($count % 50 === 0) $output->write('.');
                }
            } catch (\Throwable) {
                libxml_clear_errors();
            }
        }
        $output->writeln('');
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
