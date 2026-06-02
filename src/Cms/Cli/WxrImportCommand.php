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

    /** @var array<string, string> remote image URL => local public URL (deduped across posts) */
    private array $imageMap = [];
    private int $imagesDownloaded = 0;
    private int $imagesSkipped = 0;
    /** @var array<int, string> remote URLs that failed to download (left untouched in body) */
    private array $imageFailures = [];
    private int $skippedExisting = 0;

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::REQUIRED, 'The WXR XML file or directory containing XML files');
        $this->addOption('collection', 'c', InputOption::VALUE_REQUIRED, 'Target collection (e.g., blog, pages)', 'blog');
        $this->addOption('post-type', 't', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Post types to import', ['post', 'page']);
        $this->addOption('download-images', null, InputOption::VALUE_NONE, 'Download images referenced in post bodies into public/ and rewrite their URLs to local paths (idempotent: skips files already present)');
        $this->addOption('media-dir', null, InputOption::VALUE_REQUIRED, 'Public-relative directory (under public/) to store downloaded images', 'assets/images/uploads');
        $this->addOption('overwrite', null, InputOption::VALUE_NONE, 'Overwrite entries that already exist (default: skip existing for an idempotent, non-destructive import)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ini_set('memory_limit', '1024M');
        set_time_limit(0);

        $path = $input->getArgument('path');
        $targetCollection = $input->getOption('collection');
        $allowedPostTypes = $input->getOption('post-type');
        $downloadImages = (bool) $input->getOption('download-images');
        $mediaDir = trim((string) $input->getOption('media-dir'), '/');
        $overwrite = (bool) $input->getOption('overwrite');

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
            $fileCount = $this->importFileManually($file, $targetCollection, $allowedPostTypes, $downloadImages, $mediaDir, $overwrite, $output);
            $output->writeln("Finished <comment>" . basename($file) . "</comment>: <info>{$fileCount}</info> items.");
            $totalCount += $fileCount;
        }

        $output->writeln("<info>Successfully imported {$totalCount} entries total.</info>");

        if ($this->skippedExisting > 0) {
            $output->writeln(sprintf("<info>Skipped %d entries already present (use --overwrite to refresh).</info>", $this->skippedExisting));
        }

        if ($downloadImages) {
            $output->writeln(sprintf(
                "<info>Images: %d downloaded, %d already present, %d failed.</info>",
                $this->imagesDownloaded,
                $this->imagesSkipped,
                count($this->imageFailures)
            ));
            foreach ($this->imageFailures as $failed) {
                $output->writeln("  <comment>image FAILED (kept remote URL):</comment> {$failed}");
            }
        }

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

    private function importFileManually(string $file, string $collection, array $allowedPostTypes, bool $downloadImages, string $mediaDir, bool $overwrite, OutputInterface $output): int
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

                    $title = trim((string) $item->title);
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

                    // Idempotent + non-destructive: never overwrite an existing entry
                    // unless --overwrite is given. Re-running a backup is a no-op for
                    // content already present (no duplicates, and richer prior edits
                    // such as a curated featured image are left intact).
                    if (file_exists($filename) && !$overwrite) {
                        $this->skippedExisting++;
                        continue;
                    }

                    $link = (string) $item->link;
                    $parsedUrl = parse_url($link);
                    $oldUrlPath = $parsedUrl["path"] ?? "";

                    $body = preg_replace('/\[caption[^\]]*\].*?href="([^"]*)".*?src="([^"]*)".*?\[\/caption\]/is', '![]($2)', $body);
                    $body = preg_replace('/\[caption[^\]]*\].*?src="([^"]*)".*?\[\/caption\]/is', '![]($1)', $body);

                    if ($downloadImages) {
                        $body = $this->localizeImages($body, $mediaDir);
                    }

                    $body = trim($body);

                    // Featured image = first image referenced in the body. This WXR
                    // export carries _thumbnail_id but no attachment URLs, so the
                    // thumbnail can't be resolved directly; the first body image is
                    // the site's existing convention for the cover.
                    $featured = $this->firstBodyImage($body);

                    $frontmatter = ['title' => $title];
                    if ($featured !== null) {
                        $frontmatter['image'] = $featured;
                    }
                    $frontmatter['date'] = $date;
                    $frontmatter['status'] = $status;
                    $frontmatter['author'] = $authorName;
                    $frontmatter['template'] = $postType === 'page' ? 'page' : 'blog-post';
                    $frontmatter['wp_id'] = (string) $wp->post_id;
                    $frontmatter['wp_type'] = $postType;
                    $frontmatter['old_url'] = $oldUrlPath;

                    foreach ($item->category as $cat) {
                        $domain = (string) $cat['domain'];
                        $name = (string) $cat;
                        if ($domain === 'category') {
                            $frontmatter['categories'][] = $name;
                        } elseif ($domain === 'post_tag') {
                            $frontmatter['tags'][] = $name;
                        }
                    }

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

    /**
     * Download every WordPress uploads image referenced in the body and rewrite
     * its URL to a local path. A URL is rewritten ONLY if the file is present
     * locally afterwards (downloaded now, or already on disk) — on failure the
     * original remote URL is kept so the link is never silently lost. Idempotent
     * across runs: existing non-empty files are skipped, not re-downloaded.
     */
    private function localizeImages(string $body, string $mediaDir): string
    {
        if (!preg_match_all('#https?://[^\s"\x27<>()]+/uploads/[^\s"\x27<>()]+\.(?:jpe?g|png|gif|webp)#i', $body, $matches)) {
            return $body;
        }

        foreach (array_unique($matches[0]) as $url) {
            if (isset($this->imageMap[$url])) {
                $body = str_replace($url, $this->imageMap[$url], $body);
                continue;
            }

            $target = $this->mediaTargetForUrl($url, $mediaDir);
            if ($target === null) {
                continue;
            }

            $dest = getcwd() . '/public/' . trim($mediaDir, '/') . '/' . $target['rel'];

            if (is_file($dest) && filesize($dest) > 0) {
                $this->imagesSkipped++;
                $this->imageMap[$url] = $target['public_url'];
                $body = str_replace($url, $target['public_url'], $body);
                continue;
            }

            if ($this->downloadImage($url, $dest)) {
                $this->imagesDownloaded++;
                $this->imageMap[$url] = $target['public_url'];
                $body = str_replace($url, $target['public_url'], $body);
            } else {
                $this->imageFailures[] = $url;
            }
        }

        return $body;
    }

    /**
     * First image referenced in the body (HTML <img src> or markdown), used as
     * the featured image. Returns null when the body has no image.
     */
    private function firstBodyImage(string $body): ?string
    {
        if (preg_match('#<img[^>]+src="([^"]+)"#i', $body, $m)) {
            return $m[1];
        }
        if (preg_match('#!\[[^\]]*\]\(([^)]+)\)#', $body, $m)) {
            return $m[1];
        }
        return null;
    }

    /**
     * Map a WordPress uploads URL to its local relative path + public URL.
     * Pure (no I/O) so it is unit-testable. Returns null for non-uploads URLs
     * or paths that try to traverse out of the media dir.
     *
     * @return array{rel: string, public_url: string}|null
     */
    private function mediaTargetForUrl(string $url, string $mediaDir): ?array
    {
        $clean = explode('?', $url)[0];
        $pos = stripos($clean, '/uploads/');
        if ($pos === false) {
            return null;
        }
        $rel = ltrim(substr($clean, $pos + strlen('/uploads/')), '/');
        if ($rel === '' || str_contains($rel, '..')) {
            return null;
        }
        return ['rel' => $rel, 'public_url' => '/' . trim($mediaDir, '/') . '/' . $rel];
    }

    private function downloadImage(string $url, string $dest): bool
    {
        $dir = dirname($dest);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            return false;
        }

        $ctx = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 30, 'follow_location' => 1, 'max_redirects' => 5, 'user_agent' => 'RakunCMS-WXR-Importer'],
            'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $data = @file_get_contents($url, false, $ctx);
        if ($data === false || strlen($data) < 64) {
            return false;
        }

        // Reject obvious HTML error pages served with 200.
        $head = strtolower(ltrim(substr($data, 0, 32)));
        if (str_starts_with($head, '<!doctype') || str_starts_with($head, '<html')) {
            return false;
        }

        return file_put_contents($dest, $data) !== false;
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
