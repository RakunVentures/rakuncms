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

        $result = $this->converter->convert($content);

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
