<?php

declare(strict_types=1);

namespace Rkn\Cms\Template;

use Clickfwd\Yoyo\Services\Configuration as YoyoConfiguration;
use Clickfwd\Yoyo\Twig\YoyoTwigExtension;
use Clickfwd\Yoyo\Yoyo;
use Clickfwd\Yoyo\ViewProviders\TwigViewProvider;
use Rkn\Cms\Template\Extensions\AssetExtension;
use Rkn\Cms\Template\Extensions\ContentExtension;
use Rkn\Cms\Template\Extensions\I18nExtension;
use Rkn\Cms\Template\Extensions\MarkdownExtension;
use Rkn\Cms\Template\Extensions\IntegrationsExtension;
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
        $twig->addExtension(new IntegrationsExtension());
        $twig->addExtension(new \Rkn\Cms\Template\Extensions\CsrfExtension(
            \app(\Rkn\Cms\Middleware\CsrfProtection::class)
        ));

        // Bootstrap Yoyo configuration and Twig extension
        // Use relative URLs to support any port/domain
        new YoyoConfiguration([
            'url' => '/yoyo',
            'scriptsPath' => '/',
            'namespace' => 'Rkn\\Cms\\Components\\',
        ]);

        $yoyo = Yoyo::getInstance();
        $twigProvider = new TwigViewProvider($twig);
        
        // Register Twig as the view provider for Yoyo
        $yoyo->registerViewProvider('default', $twigProvider);
        $yoyo->registerViewProvider('twig', $twigProvider);
        
        // Register known components
        $yoyo->registerComponents([
            'category-grid' => \Rkn\Cms\Components\CategoryGrid::class,
            'trend-grid' => \Rkn\Cms\Components\TrendGrid::class,
            'search' => \Rkn\Cms\Components\Search::class,
            'contact-form' => \Rkn\Cms\Components\ContactForm::class,
            'newsletter-subscription' => \Rkn\Cms\Components\NewsletterSubscription::class,
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
