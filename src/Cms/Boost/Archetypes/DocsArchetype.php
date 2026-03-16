<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost\Archetypes;

use Rkn\Cms\Boost\AbstractArchetype;
use Rkn\Cms\Boost\SiteProfile;

final class DocsArchetype extends AbstractArchetype
{
    public function name(): string
    {
        return 'docs';
    }

    public function description(): string
    {
        return 'Documentation site with sidebar TOC, breadcrumbs, and prev/next navigation';
    }

    public function collections(): array
    {
        return [
            [
                'name' => 'docs',
                'config' => [
                    'url_pattern' => '/{locale}/docs/{slug}',
                    'sort' => ['field' => 'order', 'direction' => 'asc'],
                    'templates' => ['index' => 'docs/index', 'show' => 'docs/show'],
                ],
            ],
            [
                'name' => 'pages',
                'config' => [
                    'url_pattern' => '/{locale}/{slug}',
                    'templates' => ['show' => 'page'],
                ],
            ],
        ];
    }

    public function entries(SiteProfile $profile): array
    {
        return [
            [
                'collection' => 'pages',
                'filename' => 'index.md',
                'frontmatter' => [
                    'title' => $profile->name,
                    'template' => 'home',
                    'meta' => ['description' => "<!-- BOOST: describe your documentation site -->"],
                ],
                'content' => <<<MD
## {$profile->name}

<!-- BOOST: write an introduction for your documentation. What does this project do? Who is it for? -->

Welcome to the documentation. Use the sidebar to navigate through the sections.

### Quick Start

1. <!-- BOOST: first step to get started -->
2. <!-- BOOST: second step -->
3. <!-- BOOST: third step -->
MD,
            ],
            [
                'collection' => 'docs',
                'filename' => '01.getting-started.md',
                'frontmatter' => [
                    'title' => 'Getting Started',
                    'order' => 1,
                    'template' => 'docs/show',
                    'meta' => ['description' => "<!-- BOOST: summarize the getting started guide -->"],
                ],
                'content' => <<<MD
## Getting Started

<!-- BOOST: write installation and setup instructions. Include prerequisites, installation steps, and initial configuration. -->

### Prerequisites

- <!-- BOOST: list required software, versions, and dependencies -->

### Installation

```bash
# BOOST: add installation commands
```

### Configuration

<!-- BOOST: explain basic configuration options -->
MD,
            ],
            [
                'collection' => 'docs',
                'filename' => '02.core-concepts.md',
                'frontmatter' => [
                    'title' => 'Core Concepts',
                    'order' => 2,
                    'template' => 'docs/show',
                    'meta' => ['description' => "<!-- BOOST: summarize core concepts -->"],
                ],
                'content' => <<<MD
## Core Concepts

<!-- BOOST: explain the fundamental concepts and architecture. Use diagrams or examples where helpful. -->

### Concept 1

<!-- BOOST: explain the first key concept -->

### Concept 2

<!-- BOOST: explain the second key concept -->
MD,
            ],
            [
                'collection' => 'docs',
                'filename' => '03.guides.md',
                'frontmatter' => [
                    'title' => 'Guides',
                    'order' => 3,
                    'template' => 'docs/show',
                    'meta' => ['description' => "<!-- BOOST: summarize the guides section -->"],
                ],
                'content' => <<<MD
## Guides

<!-- BOOST: write practical how-to guides for common tasks -->

### Guide 1

<!-- BOOST: step-by-step guide for a common task -->

### Guide 2

<!-- BOOST: another practical guide -->
MD,
            ],
            [
                'collection' => 'docs',
                'filename' => '04.api-reference.md',
                'frontmatter' => [
                    'title' => 'API Reference',
                    'order' => 4,
                    'template' => 'docs/show',
                    'meta' => ['description' => "<!-- BOOST: summarize the API reference -->"],
                ],
                'content' => <<<MD
## API Reference

<!-- BOOST: document your API endpoints, functions, or classes. Use tables and code examples. -->

### Endpoints

| Method | Path | Description |
|--------|------|-------------|
| <!-- BOOST: add API endpoints --> | | |

### Parameters

<!-- BOOST: document parameters, types, and defaults -->
MD,
            ],
            [
                'collection' => 'docs',
                'filename' => '05.faq.md',
                'frontmatter' => [
                    'title' => 'FAQ',
                    'order' => 5,
                    'template' => 'docs/show',
                    'meta' => ['description' => "<!-- BOOST: summarize the FAQ -->"],
                ],
                'content' => <<<MD
## Frequently Asked Questions

<!-- BOOST: answer common questions about your project -->

### Question 1?

<!-- BOOST: answer -->

### Question 2?

<!-- BOOST: answer -->
MD,
            ],
        ];
    }

    public function templates(SiteProfile $profile): array
    {
        $templates = [];

        $templates['templates/_layouts/base.twig'] = $this->baseLayout($profile);

        $templates['templates/_layouts/page.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/docs/getting-started">Docs</a></li>
</ul>
{% endblock %}

{% block title %}{{ entry.title }} - {{ parent() }}{% endblock %}

{% block content %}
<article class="page-content">
    <h2>{{ entry.title }}</h2>
    <div class="prose">
        {{ markdown(entry.content) }}
    </div>
</article>
{% endblock %}
TWIG;

        $templates['templates/home.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/docs/getting-started">Docs</a></li>
</ul>
{% endblock %}

{% block content %}
<div class="docs-hero">
    <div class="prose">
        {{ markdown(entry.content) }}
    </div>
    <div class="hero-actions">
        <a href="/docs/getting-started" class="btn btn-primary">Get Started</a>
    </div>
</div>

<section class="docs-overview">
    <h2>Documentation</h2>
    <div class="docs-grid">
        {% for doc in collection('docs').locale(locale).sort('order', 'asc').get() %}
        <a href="{{ doc.url(locale) }}" class="doc-card card">
            <h3>{{ doc.title }}</h3>
        </a>
        {% endfor %}
    </div>
</section>
{% endblock %}
TWIG;

        $templates['templates/docs/index.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/docs/getting-started">Docs</a></li>
</ul>
{% endblock %}

{% block title %}Documentation - {{ parent() }}{% endblock %}

{% block content %}
<div class="docs-layout">
    <aside class="docs-sidebar">
        <nav class="sidebar-nav">
            <h3>Documentation</h3>
            <ul>
                {% for doc in collection('docs').locale(locale).sort('order', 'asc').get() %}
                <li><a href="{{ doc.url(locale) }}">{{ doc.title }}</a></li>
                {% endfor %}
            </ul>
        </nav>
    </aside>
    <div class="docs-main">
        <h1>Documentation</h1>
        <div class="docs-grid">
            {% for doc in collection('docs').locale(locale).sort('order', 'asc').get() %}
            <a href="{{ doc.url(locale) }}" class="doc-card card">
                <h3>{{ doc.title }}</h3>
            </a>
            {% endfor %}
        </div>
    </div>
</div>
{% endblock %}
TWIG;

        $templates['templates/docs/show.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/docs/getting-started">Docs</a></li>
</ul>
{% endblock %}

{% block title %}{{ entry.title }} - Docs - {{ parent() }}{% endblock %}

{% block content %}
<div class="docs-layout">
    <aside class="docs-sidebar">
        <nav class="sidebar-nav">
            <h3>Documentation</h3>
            <ul>
                {% set docs = collection('docs').locale(locale).sort('order', 'asc').get() %}
                {% for doc in docs %}
                <li class="{{ doc.slug == entry.slug ? 'active' : '' }}">
                    <a href="{{ doc.url(locale) }}">{{ doc.title }}</a>
                </li>
                {% endfor %}
            </ul>
        </nav>
    </aside>
    <div class="docs-main">
        <nav class="breadcrumbs">
            <a href="/">Home</a> / <a href="/docs/getting-started">Docs</a> / <span>{{ entry.title }}</span>
        </nav>
        <article class="docs-content">
            <h1>{{ entry.title }}</h1>
            <div class="prose">
                {{ markdown(entry.content) }}
            </div>
        </article>
        <nav class="docs-nav-prev-next">
            {% set docs = collection('docs').locale(locale).sort('order', 'asc').get() %}
            {% set prev = null %}
            {% set next = null %}
            {% set found = false %}
            {% for doc in docs %}
                {% if found and next is null %}
                    {% set next = doc %}
                {% endif %}
                {% if doc.slug == entry.slug %}
                    {% set found = true %}
                {% endif %}
                {% if not found %}
                    {% set prev = doc %}
                {% endif %}
            {% endfor %}
            {% if prev %}
            <a href="{{ prev.url(locale) }}" class="nav-prev">&larr; {{ prev.title }}</a>
            {% endif %}
            {% if next %}
            <a href="{{ next.url(locale) }}" class="nav-next">{{ next.title }} &rarr;</a>
            {% endif %}
        </nav>
    </div>
</div>
{% endblock %}
TWIG;

        return $templates;
    }

    public function css(SiteProfile $profile): string
    {
        return $this->baseCss() . <<<'CSS'

/* Docs-specific styles */
.docs-hero {
    padding: 3rem 0;
    text-align: center;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 2rem;
}

.hero-actions { margin-top: 1.5rem; }

.docs-overview h2 { margin-bottom: 1.5rem; }

.docs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1rem;
}

.doc-card { display: block; color: inherit; }
.doc-card h3 { font-size: 1.1rem; }

.docs-layout {
    display: grid;
    grid-template-columns: 260px 1fr;
    gap: 2rem;
}

.docs-sidebar {
    position: sticky;
    top: 1rem;
    align-self: start;
}

.sidebar-nav h3 { margin-bottom: 1rem; font-size: 1rem; }
.sidebar-nav ul { list-style: none; }
.sidebar-nav li { margin-bottom: 0.5rem; }
.sidebar-nav a {
    color: var(--secondary-color);
    display: block;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
}
.sidebar-nav a:hover { color: var(--primary-color); background: var(--bg-color); }
.sidebar-nav li.active a {
    color: var(--primary-color);
    background: #eff6ff;
    font-weight: 500;
}

.breadcrumbs {
    font-size: 0.875rem;
    color: var(--secondary-color);
    margin-bottom: 1.5rem;
}
.breadcrumbs a { color: var(--secondary-color); }
.breadcrumbs a:hover { color: var(--primary-color); }

.docs-content h1 { margin-bottom: 1.5rem; }

.docs-nav-prev-next {
    display: flex;
    justify-content: space-between;
    margin-top: 3rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color);
}

.nav-prev, .nav-next {
    padding: 0.5rem 1rem;
    border: 1px solid var(--border-color);
    border-radius: 6px;
}

.prose { max-width: var(--content-width); }
.prose h2 { margin: 2rem 0 1rem; }
.prose h3 { margin: 1.5rem 0 0.75rem; }
.prose p { margin-bottom: 1rem; }
.prose ul, .prose ol { margin-bottom: 1rem; padding-left: 1.5rem; }
.prose code {
    background: var(--bg-color);
    padding: 0.15rem 0.4rem;
    border-radius: 3px;
    font-size: 0.9rem;
}
.prose pre {
    background: #1e293b;
    color: #e2e8f0;
    padding: 1rem;
    border-radius: 6px;
    overflow-x: auto;
    margin: 1rem 0;
}
.prose pre code { background: none; padding: 0; color: inherit; }
.prose table { width: 100%; border-collapse: collapse; margin: 1rem 0; }
.prose th, .prose td {
    border: 1px solid var(--border-color);
    padding: 0.5rem 0.75rem;
    text-align: left;
}
.prose th { background: var(--bg-color); font-weight: 600; }

@media (max-width: 768px) {
    .docs-layout { grid-template-columns: 1fr; }
    .docs-sidebar { position: static; }
}
CSS;
    }

    public function config(SiteProfile $profile): array
    {
        return $this->baseConfig($profile);
    }

    public function globals(SiteProfile $profile): array
    {
        return $this->baseGlobals($profile);
    }
}
