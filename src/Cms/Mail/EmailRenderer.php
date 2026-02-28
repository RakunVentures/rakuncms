<?php

declare(strict_types=1);

namespace Rkn\Cms\Mail;

/**
 * Standalone email template renderer using plain PHP templates.
 *
 * Works in both web and CLI contexts (no Twig dependency).
 * Templates use extract() + ob_start() + include for rendering.
 */
final class EmailRenderer
{
    /** @var list<string> */
    private array $templatePaths;

    /** @var array<string, string> */
    private array $brand;

    /**
     * @param list<string>|string $templatePaths Ordered list of directories to search (first match wins), or single path for BC
     * @param array<string, string> $brand Brand variables (site_name, primary_color, etc.)
     */
    public function __construct(array|string $templatePaths, array $brand = [])
    {
        if (is_string($templatePaths)) {
            $templatePaths = [$templatePaths];
        }
        $this->templatePaths = array_map(fn(string $p) => rtrim($p, '/'), $templatePaths);
        $this->brand = $brand;
    }

    /**
     * Find a template file by searching each path in order.
     *
     * @return string Absolute path to the resolved template file
     * @throws \RuntimeException If the template is not found in any path
     */
    public function findTemplate(string $name): string
    {
        $filename = $name . '.html.php';

        foreach ($this->templatePaths as $dir) {
            $candidate = $dir . '/' . $filename;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        throw new \RuntimeException(
            'Email template not found: ' . $filename
            . ' (searched: ' . implode(', ', $this->templatePaths) . ')'
        );
    }

    /**
     * Render an email template wrapped in the layout.
     *
     * @param string $template Template name (e.g. 'contact-form')
     * @param array<string, mixed> $data Template-specific variables
     * @return string Rendered HTML
     */
    public function render(string $template, array $data): string
    {
        $contentFile = $this->findTemplate($template);

        $escapedData = $this->autoEscape($data);
        $content = $this->renderPhpTemplate($contentFile, $escapedData);

        try {
            $layoutFile = $this->findTemplate('layout');
        } catch (\RuntimeException) {
            return $content;
        }

        $brandDefaults = [
            'site_name' => 'RakunCMS',
            'primary_color' => '#2D5F2B',
            'body_bg_color' => '#f4f4f7',
            'content_bg_color' => '#FFFFFF',
            'text_color' => '#333333',
            'muted_color' => '#888888',
        ];

        $layoutVars = array_merge($brandDefaults, $this->brand, ['content' => $content]);

        return $this->renderPhpTemplate($layoutFile, $layoutVars);
    }

    /**
     * Resolve template paths: site-specific first, then CMS vendor fallback.
     *
     * @param string|null $basePath Website base path (e.g. /var/www/site)
     * @return list<string> Ordered list of template directories (site override first if it exists)
     */
    public static function resolveTemplatePaths(?string $basePath = null): array
    {
        $paths = [];

        if ($basePath !== null) {
            $siteTemplates = rtrim($basePath, '/') . '/templates/email';
            if (is_dir($siteTemplates)) {
                $paths[] = $siteTemplates;
            }
        }

        $paths[] = dirname(__DIR__, 3) . '/templates/email';

        return $paths;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function autoEscape(array $data): array
    {
        $escaped = [];
        foreach ($data as $key => $value) {
            if ($key === 'content') {
                $escaped[$key] = $value;
            } elseif (is_string($value)) {
                $escaped[$key] = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
            } else {
                $escaped[$key] = $value;
            }
        }

        return $escaped;
    }

    /**
     * @param array<string, mixed> $vars
     */
    private function renderPhpTemplate(string $file, array $vars): string
    {
        extract($vars, EXTR_SKIP);
        ob_start();

        try {
            include $file;
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return ob_get_clean() ?: '';
    }
}
