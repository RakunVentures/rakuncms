<?php

declare(strict_types=1);

namespace Rkn\Framework;

use Laminas\HttpHandlerRunner\Emitter\SapiEmitter;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7Server\ServerRequestCreator;
use Psr\Http\Server\MiddlewareInterface;
use Symfony\Component\Yaml\Yaml;

final class Application
{
    private Container $container;
    private Router $router;
    /** @var list<MiddlewareInterface> */
    private array $middleware = [];

    private static ?self $instance = null;

    public function __construct(string $basePath)
    {
        self::$instance = $this;

        $this->container = new Container();
        $this->router = new Router();

        $this->container->set('base_path', $basePath);
        $this->container->set(self::class, $this);
        $this->container->set(Container::class, $this->container);
        $this->container->set(Router::class, $this->router);

        $this->loadDotenv($basePath);
        $this->loadConfig($basePath . '/config');
        $this->registerCoreServices();
        $this->registerRoutes();
    }

    private function loadDotenv(string $basePath): void
    {
        if (!class_exists(\Dotenv\Dotenv::class)) {
            return;
        }
        try {
            $dotenv = \Dotenv\Dotenv::createImmutable($basePath);
            $dotenv->safeLoad();
        } catch (\Throwable) {
        }
    }

    private function loadConfig(string $configDir): void
    {
        $config = [];
        if (is_dir($configDir)) {
            foreach (glob($configDir . '/*.yaml') as $file) {
                $content = file_get_contents($file) ?: '';
                $name = pathinfo($file, PATHINFO_FILENAME);
                
                // Allow ${VAR} in YAML
                $content = preg_replace_callback('/\$\{([A-Z0-9_]+)(?::-([^}]*))?\}/', function ($matches) {
                    $val = $_ENV[$matches[1]] ?? $_SERVER[$matches[1]] ?? getenv($matches[1]);
                    return ($val !== false && $val !== null) ? $val : ($matches[2] ?? '');
                }, $content) ?: $content;

                $config[$name] = Yaml::parse($content);
            }
        }
        $this->container->set('config', $config);
    }

    private function registerCoreServices(): void
    {
        $container = $this->container;
        $basePath = $this->getBasePath();

        $container->set(\Twig\Environment::class, function () use ($container, $basePath) {
            $templatePaths = [$basePath . '/templates'];
            $cmsTemplates = $basePath . '/vendor/rkn/cms/templates';
            if (is_dir($cmsTemplates)) {
                $templatePaths[] = $cmsTemplates;
            }

            $loader = new \Twig\Loader\FilesystemLoader($templatePaths);
            $config = $container->get('config');
            $debug = $config['debug'] ?? false;

            $twig = new \Twig\Environment($loader, [
                'cache' => $basePath . '/cache/templates',
                'debug' => $debug,
                'auto_reload' => $config['cache']['twig_auto_reload'] ?? $debug,
                'strict_variables' => $debug,
            ]);

            if ($debug) {
                $twig->addExtension(new \Twig\Extension\DebugExtension());
            }

            // Register Banner Slot Function (Task 2.4)
            $twig->addFunction(new \Twig\TwigFunction('banner_slot', function (string $slot) use ($basePath) {
                $indexer = new \Rkn\Cms\Content\Indexer($basePath);
                $query = new \Rkn\Cms\Content\Query($indexer->load());
                $banners = $query->collection('banners')
                    ->where('slot', '=', $slot)
                    ->where('status', '=', 'publish')
                    ->get();
                
                if (empty($banners)) return '';
                
                $banner = $banners[0];
                $meta = $banner->meta();
                
                $img = $meta['image'] ?? '';
                $url = $meta['url'] ?? '#';
                
                return sprintf(
                    '<div class="banner-slot banner-slot-%s"><a href="%s" target="_blank"><img src="%s" alt="Ad" class="w-full h-auto"></a></div>',
                    htmlspecialchars($slot),
                    htmlspecialchars($url),
                    htmlspecialchars($img)
                );
            }, ['is_safe' => ['html']]));

            return $twig;
        });

        $container->set(Psr17Factory::class, new Psr17Factory());

        $container->set(\Rkn\Cms\Middleware\CsrfProtection::class, function () use ($container) {
            $secret = (string) env('APP_KEY', 'change-me-at-least-32-chars-long');
            return new \Rkn\Cms\Middleware\CsrfProtection($secret);
        });

        $container->set('queue', function () use ($basePath) {
            return new \Rkn\Cms\Queue\FileQueue($basePath);
        });

        // Active content index backend (php array | sqlite), memoised per request.
        $container->set('index_store', function () use ($basePath) {
            return \Rkn\Cms\Content\IndexStoreFactory::make($basePath);
        });

        $container->set(\Rkn\Cms\Mail\Mailer::class, function () use ($container) {
            $config = $container->get('config');
            return new \Rkn\Cms\Mail\Mailer($config['mail'] ?? []);
        });

        $container->set(\Rkn\Cms\Events\EventDispatcher::class, function () use ($container, $basePath) {
            $dispatcher = new \Rkn\Cms\Events\EventDispatcher();
            $config = $container->get('config');
            $webhooks = $config['webhooks'] ?? [];
            if (!empty($webhooks)) {
                $queue = new \Rkn\Cms\Queue\FileQueue($basePath);
                \Rkn\Cms\Events\WebhookListener::registerFromConfig($webhooks, $dispatcher, $queue);
            }
            return $dispatcher;
        });
        $container->set('events', fn () => $container->get(\Rkn\Cms\Events\EventDispatcher::class));
    }

    private function registerRoutes(): void
    {
        $this->router->post('/yoyo[/{action}]', 'yoyo_handler');
        $this->router->post('/api/form/{name}', 'form_controller');
        $this->router->get('/sitemap.xml', 'sitemap_controller');
        $this->router->get('/rss.xml', 'rss_controller');
        $this->router->get('/{path:.*}', 'content_router');
    }

    public function pipe(MiddlewareInterface $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function run(): void
    {
        $psr17Factory = new Psr17Factory();
        $creator = new ServerRequestCreator($psr17Factory, $psr17Factory, $psr17Factory, $psr17Factory);
        $request = $creator->fromGlobals();

        $pipeline = $this->middleware;
        if (getenv('RAKUN_DEV_RELOAD') === '1') {
            $stamp = getenv('RAKUN_DEV_RELOAD_STAMP');
            if (!is_string($stamp) || $stamp === '') {
                $stamp = $this->getBasePath() . '/cache/.dev-reload-stamp';
            }
            array_unshift($pipeline, new \Rkn\Cms\Middleware\DevReloadMiddleware($stamp));
        }

        $handler = new Dispatcher($pipeline);
        $response = $handler->handle($request);

        $emitter = new SapiEmitter();
        $emitter->emit($response);
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function getContainer(): Container
    {
        return $this->container;
    }

    public function getBasePath(): string
    {
        return $this->container->get('base_path');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        $config = $this->container->get('config');
        $parts = explode('.', $key);
        foreach ($parts as $part) {
            if (!isset($config[$part])) {
                return $default;
            }
            $config = $config[$part];
        }
        return $config;
    }

    public function container(): Container
    {
        return $this->container;
    }
}
