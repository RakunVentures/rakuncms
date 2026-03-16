<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost;

abstract class AbstractArchetype implements ArchetypeInterface
{
    protected function currentDate(): string
    {
        return date('Y-m-d');
    }

    protected function baseCss(): string
    {
        return <<<'CSS'
:root {
    --bg-color: #f8fafc;
    --text-color: #0f172a;
    --primary-color: #3b82f6;
    --primary-hover: #2563eb;
    --secondary-color: #64748b;
    --border-color: #e2e8f0;
    --card-bg: #ffffff;
    --accent-color: #8b5cf6;
    --success-color: #22c55e;
    --max-width: 1200px;
    --content-width: 800px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
    font-family: system-ui, -apple-system, sans-serif;
    background-color: var(--bg-color);
    color: var(--text-color);
    line-height: 1.6;
}

a { color: var(--primary-color); text-decoration: none; }
a:hover { color: var(--primary-hover); }

img { max-width: 100%; height: auto; }

.container {
    max-width: var(--max-width);
    margin: 0 auto;
    padding: 0 1.5rem;
}

.site-header {
    border-bottom: 1px solid var(--border-color);
    padding: 1rem 0;
}

.site-header .container {
    display: flex;
    align-items: center;
    justify-content: space-between;
}

.site-header h1 { font-size: 1.25rem; }
.site-header h1 a { color: inherit; }

.site-nav ul { list-style: none; display: flex; gap: 1.5rem; }
.site-nav a { color: var(--secondary-color); font-weight: 500; }
.site-nav a:hover { color: var(--primary-color); }

.site-content {
    padding: 2rem 0;
    min-height: 60vh;
}

.site-footer {
    border-top: 1px solid var(--border-color);
    padding: 1.5rem 0;
    font-size: 0.875rem;
    color: var(--secondary-color);
    text-align: center;
}

.btn {
    display: inline-block;
    padding: 0.5rem 1.25rem;
    border-radius: 6px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    font-size: 1rem;
    transition: background-color 0.2s;
}

.btn-primary { background-color: var(--primary-color); color: white; }
.btn-primary:hover { background-color: var(--primary-hover); color: white; }

.card {
    background: var(--card-bg);
    border: 1px solid var(--border-color);
    border-radius: 8px;
    padding: 1.5rem;
    transition: box-shadow 0.2s;
}

.card:hover { box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08); }

CSS;
    }

    protected function baseLayout(SiteProfile $profile): string
    {
        $lang = $profile->locale;
        return <<<TWIG
<!DOCTYPE html>
<html lang="{$lang}">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{% block title %}{{ global('site').title }}{% endblock %}</title>
    {% block head %}
    {{ seo_head() }}
    {% endblock %}
    <link rel="stylesheet" href="{{ asset('assets/css/style.css')|default('/assets/css/style.css') }}">
    {{ yoyo_scripts() }}
</head>
<body>
    <header class="site-header">
        <div class="container">
            <h1><a href="/">{{ global('site').title }}</a></h1>
            <nav class="site-nav">
                {% block nav %}{% endblock %}
            </nav>
        </div>
    </header>

    <main class="site-content">
        <div class="container">
            {% block content %}{% endblock %}
        </div>
    </main>

    <footer class="site-footer">
        <div class="container">
            <p>&copy; {{ "now"|date("Y") }} {{ global('site').author|default('') }}. Built with RakunCMS.</p>
        </div>
    </footer>
    {{ seo_webmcp() }}
    {{ seo_consent() }}
    {{ whatsapp_button() }}
</body>
</html>
TWIG;
    }

    /** @return array<string, mixed> */
    protected function baseConfig(SiteProfile $profile): array
    {
        return [
            'site' => [
                'url' => 'http://localhost:8080',
                'default_locale' => $profile->locale,
            ],
            'seo' => [
                'site_name' => $profile->name,
                'default_image' => '/assets/images/default-og.jpg',
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function baseGlobals(SiteProfile $profile): array
    {
        return [
            'title' => $profile->name,
            'description' => $profile->description ?: "<!-- BOOST: describe your site in 1-2 sentences -->",
            'author' => $profile->author ?: "<!-- BOOST: your name or organization -->",
        ];
    }
}
