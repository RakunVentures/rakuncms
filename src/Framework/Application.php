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

        $this->loadConfig($basePath . '/config');
        $this->registerCoreServices();
        $this->registerRoutes();
    }

    public static function getInstance(): ?self
    {
        return self::$instance;
    }

    public function container(): Container
    {
        return $this->container;
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function pipe(MiddlewareInterface $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    public function run(): void
    {
        $factory = new Psr17Factory();
        $creator = new ServerRequestCreator($factory, $factory, $factory, $factory);
        $request = $creator->fromGlobals();

        $dispatcher = new Dispatcher($this->middleware);
        $response = $dispatcher->handle($request);

        (new SapiEmitter())->emit($response);
    }

    public function getBasePath(): string
    {
        return $this->container->get('base_path');
    }

    /**
     * @return mixed
     */
    public function config(string $key, mixed $default = null): mixed
    {
        $config = $this->container->get('config');

        $keys = explode('.', $key);
        $value = $config;

        foreach ($keys as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return $default;
            }
        }

        return $value;
    }

    private function loadConfig(string $configPath): void
    {
        $config = [];

        // Load rakun.yaml (main config)
        $mainConfig = $configPath . '/rakun.yaml';
        if (file_exists($mainConfig)) {
            $config = Yaml::parseFile($mainConfig) ?? [];
        }

        // Load collections.yaml
        $collectionsConfig = $configPath . '/collections.yaml';
        if (file_exists($collectionsConfig)) {
            $config['collections'] = Yaml::parseFile($collectionsConfig) ?? [];
        }

        // Load environment-specific config (merge over main)
        $env = getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production');
        $envConfig = $configPath . '/environments/' . $env . '.yaml';
        if (file_exists($envConfig)) {
            $envData = Yaml::parseFile($envConfig) ?? [];
            $config = $this->mergeConfig($config, $envData);
        }

        $this->container->set('config', $config);
        $this->container->set('environment', $env);
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    private function mergeConfig(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key])) {
                $base[$key] = $this->mergeConfig($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }
        return $base;
    }

    private function registerCoreServices(): void
    {
        $container = $this->container;
        $basePath = $this->getBasePath();

        // Twig Environment
        $container->set(\Twig\Environment::class, function () use ($container, $basePath) {
            $templatePaths = [$basePath . '/templates'];

            // Check if rkn/cms package has default templates
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

            return $twig;
        });

        // PSR-17 Factory
        $container->set(Psr17Factory::class, new Psr17Factory());

        // File Queue
        $container->set('queue', function () use ($basePath) {
            return new \Rkn\Cms\Queue\FileQueue($basePath);
        });

        // Mailer
        $container->set(\Rkn\Cms\Mail\Mailer::class, function () use ($container) {
            $config = $container->get('config');
            return new \Rkn\Cms\Mail\Mailer($config['mail'] ?? []);
        });
    }

    private function registerRoutes(): void
    {
        // CMS routes registered automatically
        $this->router->post('/yoyo[/{action}]', 'yoyo_handler');
        $this->router->post('/api/form/{name}', 'form_controller');
        $this->router->get('/sitemap.xml', 'sitemap_controller');
        $this->router->get('/rss.xml', 'rss_controller');
        $this->router->get('/{path:.*}', 'content_router');
    }
}
