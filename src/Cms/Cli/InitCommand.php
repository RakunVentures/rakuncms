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
        $this->createFile($basePath . '/content/_globals/site.yaml', $this->getSiteYamlContent());
        $this->createFile($basePath . '/content/pages/index.md', $this->getIndexMdContent());
        $this->createFile($basePath . '/templates/_layouts/base.twig', $this->getBaseTwigContent());
        $this->createFile($basePath . '/templates/_layouts/page.twig', $this->getPageTwigContent());
        $this->createFile($basePath . '/src/Components/Counter.php', $this->getCounterComponentContent());
        $this->createFile($basePath . '/templates/yoyo/counter.twig', $this->getCounterTwigContent());
        $this->createFile($basePath . '/public/assets/css/style.css', $this->getCssContent());

        $output->writeln("<info>¡Proyecto RakunCMS inicializado correctamente en {$basePath}!</info>");
        $output->writeln("Puedes iniciar el servidor con: <comment>php vendor/bin/rakun serve</comment>");

        return Command::SUCCESS;
    }

    private function createFile(string $path, string $content): void
    {
        if (!file_exists($path)) {
            file_put_contents($path, $content);
        }
    }

    private function getIndexPhpContent(): string
    {
        return <<<'PHP'
<?php

require __DIR__ . '/../vendor/autoload.php';

$app = new \Rkn\Framework\Application(dirname(__DIR__));

// PSR-15 Middleware Pipeline
$app->pipe(new \Rkn\Cms\Middleware\ErrorHandler());
$app->pipe(new \Rkn\Cms\Middleware\PageCacheReader());
$app->pipe(new \Rkn\Cms\Middleware\LocaleDetector());
$app->pipe(new \Rkn\Cms\Middleware\ContentRouter());
$app->pipe(new \Rkn\Cms\Middleware\YoyoHandler());
$app->pipe(new \Rkn\Cms\Middleware\PageCacheWriter());

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
site:
  url: "http://localhost:8080"
  default_locale: "es"
YAML;
    }

    private function getSiteYamlContent(): string
    {
        return <<<'YAML'
title: "RakunCMS Starter"
description: "Un sitio web súper rápido impulsado por Markdown."
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
}
