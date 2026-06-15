<?php

declare(strict_types=1);

namespace Rkn\Cms\Content;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

final class Parser
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            // Permite <iframe> (embeds: YouTube, Maps, etc.) en el contenido — el
            // CMS es de autores de confianza. Mantiene escapados los tags peligrosos
            // (script/style/etc.): lista por defecto de DisallowedRawHtml SIN 'iframe'.
            'disallowed_raw_html' => [
                'disallowed_tags' => ['title', 'textarea', 'style', 'xmp', 'noembed', 'noframes', 'script', 'plaintext'],
            ],
        ]);

        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new FrontMatterExtension());

        $this->converter = new MarkdownConverter($environment);
    }

    /**
     * Parse a Markdown file, returning frontmatter and rendered HTML.
     *
     * @return array{frontmatter: array<string, mixed>, html: string}
     */
    public function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);

        if ($content === false) {
            throw new \RuntimeException("Cannot read file: {$filePath}");
        }

        try {
            $result = $this->converter->convert($content);
        } catch (\Throwable $e) {
            error_log('[rakun] unparseable frontmatter in ' . $filePath . '; rendering body only: ' . $e->getMessage());
            $parts = explode('---', $content, 3);
            $body = count($parts) >= 3 ? ltrim($parts[2], "\n") : $content;

            return [
                'frontmatter' => [],
                'html' => $this->converter->convert($body)->getContent(),
            ];
        }

        $frontmatter = [];
        if ($result instanceof RenderedContentWithFrontMatter) {
            $frontmatter = $result->getFrontMatter();
        }

        return [
            'frontmatter' => is_array($frontmatter) ? $frontmatter : [],
            'html' => $result->getContent(),
        ];
    }

    /**
     * Render only the Markdown content (body) of a file.
     */
    public function renderContent(string $filePath): string
    {
        $basePath = '';
        try {
            $basePath = \app('base_path');
        } catch (\Throwable) {
        }

        $fullPath = $basePath ? $basePath . '/' . $filePath : $filePath;

        if (!file_exists($fullPath)) {
            return '';
        }

        return $this->parse($fullPath)['html'];
    }

    /**
     * Render a Markdown string to HTML.
     */
    public function renderString(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }
}
