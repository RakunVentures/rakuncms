<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(
    name: 'init',
    description: 'Inicializa una nueva estructura de proyecto RakunCMS en el directorio indicado'
)]
class InitCommand extends Command
{

    protected function configure(): void
    {
        $this->addArgument('path', InputArgument::OPTIONAL, 'Ruta donde inicializar el proyecto', getcwd());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = rtrim($input->getArgument('path'), '/');

        $directories = [
            'bin',
            'cache/assets',
            'cache/pages',
            'cache/templates',
            'config/environments',
            'content/_globals',
            'content/blog',
            'content/pages',
            'lang/en',
            'lang/es',
            'public/assets/css',
            'public/assets/js',
            'public/assets/images',
            'src/Components',
            'storage/logs',
            'storage/queue/pending',
            'storage/queue/processing',
            'storage/queue/failed',
            'storage/rates',
            'templates/_layouts',
            'templates/_partials',
            'templates/_components',
            'templates/yoyo',
            'templates/blog',
            'templates/errors',
        ];

        foreach ($directories as $dir) {
            $path = $basePath . '/' . $dir;
            if (!is_dir($path)) {
                mkdir($path, 0755, true);
                file_put_contents($path . '/.gitkeep', '');
            }
        }

        $this->createFile($basePath . '/public/index.php', $this->getIndexPhpContent());
        $this->createFile($basePath . '/public/.htaccess', $this->getHtaccessContent());
        $this->createFile($basePath . '/config/rakun.yaml', $this->getConfigContent());
        $this->createFile($basePath . '/.env.example', $this->getDotenvExampleContent());
        $this->createFile($basePath . '/.env', $this->getDotenvExampleContent());
        $this->appendIfMissing($basePath . '/.gitignore', "/.env\n");
        $this->createFile($basePath . '/content/_globals/site.yaml', $this->getSiteYamlContent());
        $this->createFile($basePath . '/content/pages/index.md', $this->getIndexMdContent());
        $this->createFile($basePath . '/templates/_layouts/base.twig', $this->getBaseTwigContent());
        $this->createFile($basePath . '/templates/_layouts/page.twig', $this->getPageTwigContent());
        $this->createFile($basePath . '/src/Components/Counter.php', $this->getCounterComponentContent());
        $this->createFile($basePath . '/templates/yoyo/counter.twig', $this->getCounterTwigContent());
        $this->createFile($basePath . '/public/assets/css/style.css', $this->getCssContent());
        $this->createFile($basePath . '/templates/_partials/seo.twig', $this->getSeoPartialContent());

        $rakunCliPath = $basePath . '/rakun';
        $this->createFile($rakunCliPath, $this->getRakunCliContent());
        chmod($rakunCliPath, 0755);

        $output->writeln("<info>¡Proyecto RakunCMS inicializado correctamente en {$basePath}!</info>");
        $output->writeln("Puedes iniciar el servidor con: <comment>php rakun serve</comment>");

        return Command::SUCCESS;
    }

    private function createFile(string $path, string $content): void
    {
        if (!file_exists($path)) {
            file_put_contents($path, $content);
        }
    }

    private function appendIfMissing(string $path, string $line): void
    {
        $existing = is_file($path) ? (string) file_get_contents($path) : '';
        if (str_contains($existing, trim($line))) {
            return;
        }
        $prefix = ($existing === '' || str_ends_with($existing, "\n")) ? '' : "\n";
        file_put_contents($path, $prefix . $line, FILE_APPEND);
    }

    private function getDotenvExampleContent(): string
    {
        return <<<'ENV'
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8080

# SMTP (PHPMailer).
# Leave MAIL_HOST empty for no-op (mail() fallback) or set to an SMTP host.
# Mailtrap (dev sandbox): smtp.mailtrap.io:2525
# Amazon SES us-east-1:   email-smtp.us-east-1.amazonaws.com:587
MAIL_HOST=
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_FROM_EMAIL=noreply@example.com
MAIL_FROM_NAME="RakunCMS"
MAIL_TO_EMAIL=
ENV;
    }

    private function getIndexPhpContent(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = new \Rkn\Framework\Application(dirname(__DIR__));

$basePath = $app->getBasePath();
$pageCache = new \Rkn\Cms\Cache\PageCache($basePath . '/cache/pages');
$cacheEnabled = $app->config('cache.page_cache', true);

// PSR-15 Middleware Pipeline
$app->pipe(new \Rkn\Cms\Middleware\ErrorHandler());
$app->pipe(new \Rkn\Cms\Middleware\PageCacheReader($pageCache, $cacheEnabled));
$app->pipe(new \Rkn\Cms\Middleware\ApiDispatcher());
$app->pipe(new \Rkn\Cms\Middleware\LocaleDetector());
$app->pipe(new \Rkn\Cms\Middleware\ContentRouter());
$app->pipe(new \Rkn\Cms\Middleware\YoyoHandler());
$app->pipe(new \Rkn\Cms\Middleware\PageCacheWriter($pageCache, $cacheEnabled));

$app->run();
PHP;
    }

    private function getHtaccessContent(): string
    {
        return <<<'HTACCESS'
RewriteEngine On

# Evitar lectura directa a archivos y carpetas ocultas
RewriteRule "(^|/)\." - [F]

# Full-page Cache Rewrites
RewriteCond %{REQUEST_URI} ^/?$
RewriteCond %{DOCUMENT_ROOT}/../cache/pages/index.html -f
RewriteRule .? ../cache/pages/index.html [L]

RewriteCond %{DOCUMENT_ROOT}/../cache/pages%{REQUEST_URI}.html -f
RewriteRule . ../cache/pages%{REQUEST_URI}.html [L]

# Fallback al front-controller
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^ index.php [QSA,L]
HTACCESS;
    }

    private function getConfigContent(): string
    {
        return <<<'YAML'
debug: "${APP_DEBUG:-false}"

site:
  url: "${APP_URL:-http://localhost:8080}"
  default_locale: "es"

mail:
  smtp_host: "${MAIL_HOST:-localhost}"
  smtp_port: "${MAIL_PORT:-587}"
  smtp_encryption: "${MAIL_ENCRYPTION:-tls}"
  smtp_username: "${MAIL_USERNAME:-}"
  smtp_password: "${MAIL_PASSWORD:-}"
  from_email: "${MAIL_FROM_EMAIL:-noreply@example.com}"
  from_name: "${MAIL_FROM_NAME:-RakunCMS}"
  to_email: "${MAIL_TO_EMAIL:-}"

seo:
  site_name: "Mi Sitio"
  default_image: "/assets/images/default-og.jpg"
  # twitter_handle: "@misitio"
  # google_analytics: "G-XXXXXXXXXX"
  # facebook_pixel: "1234567890"
  # google_verification: ""
  # bing_verification: ""
  # organization:
  #   name: "Mi Empresa"
  #   url: "https://ejemplo.com"
  #   logo: "/assets/images/logo.png"
  # local_business:
  #   type: "Hotel"
  #   address:
  #     street: "Calle 123"
  #     city: "Ciudad"
  #     region: "Estado"
  #     postal_code: "12345"
  #     country: "MX"
  #   phone: "+52 123 456 7890"
  #   price_range: "$$"

# integrations:
#   whatsapp:
#     phone: "+521234567890"
#     message: "Hola, me interesa..."
#     position: "bottom-right"
#     color: "#25D366"
#     size: "60"
#   newsletter:
#     mailchimp_embed_url: "https://example.us1.list-manage.com/subscribe/post?u=XXXXX&id=YYYYY"
#     button_text: "Suscribirse"
#     placeholder: "Tu email"
#   stripe:
#     links:
#       - id: "basic"
#         label: "Plan Basico"
#         url: "https://buy.stripe.com/XXXXX"
#         description: "$9/mes"
#     button_style: "primary"
#   gumroad:
#     products:
#       - id: "my-ebook"
#         label: "Comprar eBook"
#         description: "$19"
#     overlay: true
YAML;
    }

    private function getSiteYamlContent(): string
    {
        return <<<'YAML'
title: "RakunCMS Starter"
description: "Un sitio web súper rápido impulsado por Markdown."
author: "RakunCMS"
YAML;
    }

    private function getIndexMdContent(): string
    {
        return <<<'MD'
---
title: "Bienvenido a RakunCMS"
template: page
---

## Tu nuevo sitio web está listo.

Edita este archivo en `content/pages/index.md` para cambiar el contenido. La magia ocurre al combinar la simplicidad de Markdown con el poder de Yoyo.

### Componente Reactivo Server-Side (Yoyo)
A continuación verás un contador interactivo. Está escrito enteramente en PHP, reacciona sin recargar la página y no requiere escribir una sola línea de JavaScript:

{{ yoyo('counter') }}

Puedes ver cómo funciona esto editando:
* `src/Components/Counter.php`
* `templates/yoyo/counter.twig`
MD;
    }

    private function getBaseTwigContent(): string
    {
        return <<<'TWIG'
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}{{ global('site').title }}{% endblock %}</title>
    {% block head %}
    {{ seo_head() }}
    {% endblock %}
    <link rel="stylesheet" href="{{ asset('assets/css/style.css')|default('/assets/css/style.css') }}">
    <!-- El helper de yoyo inyectará htmx y yoyo.js -->
    {{ yoyo_scripts() }}
</head>
<body>
    <header class="site-header">
        <h1><a href="/">{{ global('site').title }}</a></h1>
        <p>{{ global('site').description }}</p>
    </header>

    <main class="site-content">
        {% block content %}{% endblock %}
    </main>

    <footer class="site-footer">
        <p>&copy; {{ "now"|date("Y") }} Construido con RakunCMS.</p>
    </footer>
    {{ seo_webmcp() }}
    {{ seo_consent() }}
    {{ whatsapp_button() }}
</body>
</html>
TWIG;
    }

    private function getPageTwigContent(): string
    {
        return <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block title %}{{ entry.title }} - {{ parent() }}{% endblock %}

{% block content %}
    <article>
        <header>
            <h2>{{ entry.title }}</h2>
        </header>
        <div class="content">
            {# Transforma el markdown a HTML #}
            {{ markdown(entry.content) }}
        </div>
    </article>
{% endblock %}
TWIG;
    }

    private function getCounterComponentContent(): string
    {
        return <<<'PHP'
<?php

namespace Rkn\Cms\Components;

use Clickfwd\Yoyo\Component;

class Counter extends Component
{
    public int $count = 0;

    protected $props = ['count'];

    public function increment(): void
    {
        $this->count++;
    }

    public function render()
    {
        return $this->view('counter', ['count' => $this->count]);
    }
}
PHP;
    }

    private function getCounterTwigContent(): string
    {
        return <<<'TWIG'
<div id="counter-component" class="yoyo-box">
    <h3>Contador interactivo: {{ count }}</h3>
    <button yoyo:click="increment" class="btn">Incrementar +1</button>
</div>
TWIG;
    }

    private function getCssContent(): string
    {
        return <<<'CSS'
:root {
    --bg-color: #f8fafc;
    --text-color: #0f172a;
    --primary-color: #3b82f6;
    --border-color: #cbd5e1;
}

body {
    font-family: system-ui, -apple-system, sans-serif;
    background-color: var(--bg-color);
    color: var(--text-color);
    line-height: 1.6;
    max-width: 800px;
    margin: 0 auto;
    padding: 2rem;
}

.site-header { margin-bottom: 3rem; border-bottom: 1px solid var(--border-color); padding-bottom: 1rem; }
.site-header a { text-decoration: none; color: inherit; }
.site-footer { margin-top: 3rem; border-top: 1px solid var(--border-color); padding-top: 1rem; font-size: 0.875rem; color: #64748b; }

.yoyo-box {
    border: 1px dashed var(--primary-color);
    background: #eff6ff;
    padding: 1.5rem;
    border-radius: 8px;
    margin: 2rem 0;
    text-align: center;
}

.btn {
    background-color: var(--primary-color);
    color: white;
    border: none;
    padding: 0.5rem 1rem;
    border-radius: 4px;
    cursor: pointer;
    font-size: 1rem;
}
.btn:hover { background-color: #2563eb; }
CSS;
    }

    private function getSeoPartialContent(): string
    {
        return <<<'TWIG'
{#
  SEO Partial — Reference template for manual SEO customization.
  The seo_head() function generates these tags automatically from config and frontmatter.
  Use this partial if you prefer explicit control over the SEO tags in your templates.

  Usage: {% include "_partials/seo.twig" %}
#}

{# Meta description from frontmatter or site globals #}
{% if entry is defined and entry.getMeta('description') %}
<meta name="description" content="{{ entry.getMeta('description') }}">
{% elseif global('site').description is defined %}
<meta name="description" content="{{ global('site').description }}">
{% endif %}

{# Canonical URL #}
{% if entry is defined %}
<link rel="canonical" href="{{ config('site.url') }}{{ entry.url() }}">
{% endif %}

{# Open Graph #}
{% if entry is defined %}
<meta property="og:title" content="{{ entry.title }}">
<meta property="og:description" content="{{ entry.getMeta('description')|default(global('site').description) }}">
<meta property="og:url" content="{{ config('site.url') }}{{ entry.url() }}">
<meta property="og:type" content="{{ entry.getMeta('type')|default('website') }}">
{% if entry.getMeta('image') %}
<meta property="og:image" content="{{ config('site.url') }}{{ entry.getMeta('image') }}">
{% endif %}
<meta property="og:locale" content="{{ locale }}">
<meta property="og:site_name" content="{{ config('seo.site_name')|default(global('site').title) }}">
{% endif %}

{# Twitter Card #}
{% if entry is defined %}
<meta name="twitter:card" content="{{ entry.getMeta('image') ? 'summary_large_image' : 'summary' }}">
<meta name="twitter:title" content="{{ entry.title }}">
<meta name="twitter:description" content="{{ entry.getMeta('description')|default(global('site').description) }}">
{% endif %}

{# Hreflang alternate links #}
<link rel="alternate" hreflang="{{ locale }}" href="{{ url_for_locale(locale) }}">
<link rel="alternate" hreflang="{{ alternate_locale }}" href="{{ url_for_locale(alternate_locale) }}">
<link rel="alternate" hreflang="x-default" href="{{ url_for_locale(locale) }}">
TWIG;
    }

    private function getRakunCliContent(): string
    {
        return <<<'PHP'
#!/usr/bin/env php
<?php

declare(strict_types=1);

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require __DIR__ . '/vendor/autoload.php';
} else {
    fwrite(STDERR, "Could not find autoload.php. Run 'composer install' first.\n");
    exit(1);
}

use Symfony\Component\Console\Application;

$application = new Application('RakunCMS', '0.1.0');

// Register commands
$application->add(new \Rkn\Cms\Cli\InitCommand());
$application->add(new \Rkn\Cms\Cli\IndexRebuildCommand());
$application->add(new \Rkn\Cms\Cli\CacheClearCommand());
$application->add(new \Rkn\Cms\Cli\CacheWarmupCommand());
$application->add(new \Rkn\Cms\Cli\TemplateWarmupCommand());
$application->add(new \Rkn\Cms\Cli\QueueProcessCommand());
$application->add(new \Rkn\Cms\Cli\ServeCommand());
$application->add(new \Rkn\Cms\Cli\MakeComponentCommand());
$application->add(new \Rkn\Cms\Cli\MakeCollectionCommand());
$application->add(new \Rkn\Cms\Cli\SitemapGenerateCommand());
$application->add(new \Rkn\Cms\Cli\BuildCommand());

$application->run();
PHP;
    }
}
