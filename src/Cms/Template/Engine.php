<?php

declare(strict_types=1);

namespace Rkn\Cms\Template;

use Clickfwd\Yoyo\Services\Configuration as YoyoConfiguration;
use Clickfwd\Yoyo\Twig\YoyoTwigExtension;
use Rkn\Cms\Template\Extensions\AssetExtension;
use Rkn\Cms\Template\Extensions\ContentExtension;
use Rkn\Cms\Template\Extensions\I18nExtension;
use Rkn\Cms\Template\Extensions\MarkdownExtension;
use Rkn\Cms\Template\Extensions\SeoExtension;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class Engine
{
    private Environment $twig;

    public function __construct(Environment $twig)
    {
        $this->twig = $twig;
    }

    public static function create(string $basePath): self
    {
        $templatePaths = [$basePath . '/templates'];

        $loader = new FilesystemLoader($templatePaths);

        $debug = false;
        try {
            $debug = (bool) \config('debug', false);
        } catch (\Throwable) {
        }

        $twig = new Environment($loader, [
            'cache' => $basePath . '/cache/templates',
            'debug' => $debug,
            'auto_reload' => $debug,
            'strict_variables' => false,
        ]);

        if ($debug) {
            $twig->addExtension(new \Twig\Extension\DebugExtension());
        }

        // Register CMS extensions
        $twig->addExtension(new AssetExtension());
        $twig->addExtension(new ContentExtension());
        $twig->addExtension(new MarkdownExtension());
        $twig->addExtension(new I18nExtension());
        $twig->addExtension(new SeoExtension());

        // Bootstrap Yoyo configuration and Twig extension
        $siteUrl = '';
        try {
            $siteUrl = \config('site.url', '');
        } catch (\Throwable) {
        }
        new YoyoConfiguration([
            'url' => rtrim($siteUrl, '/') . '/yoyo',
            'scriptsPath' => rtrim($siteUrl, '/'),
            'namespace' => 'Rkn\\Cms\\Components\\',
        ]);
        $twig->addExtension(new YoyoTwigExtension());

        return new self($twig);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function render(string $template, array $context = []): string
    {
        return $this->twig->render($template, $context);
    }

    public function twig(): Environment
    {
        return $this->twig;
    }
}
