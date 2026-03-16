<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost\Archetypes;

use Rkn\Cms\Boost\AbstractArchetype;
use Rkn\Cms\Boost\SiteProfile;

final class PortfolioArchetype extends AbstractArchetype
{
    public function name(): string
    {
        return 'portfolio';
    }

    public function description(): string
    {
        return 'Creative portfolio with project gallery, filterable tags, and detail pages';
    }

    public function collections(): array
    {
        return [
            [
                'name' => 'projects',
                'config' => [
                    'url_pattern' => '/{locale}/projects/{slug}',
                    'sort' => ['field' => 'date', 'direction' => 'desc'],
                    'templates' => ['index' => 'projects/index', 'show' => 'projects/show'],
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
        $date = $this->currentDate();
        return [
            [
                'collection' => 'pages',
                'filename' => 'index.md',
                'frontmatter' => [
                    'title' => $profile->name,
                    'template' => 'home',
                    'meta' => ['description' => "<!-- BOOST: describe your portfolio in 150 chars -->"],
                ],
                'content' => <<<MD
<!-- BOOST: write a brief, impactful introduction. Who are you? What do you create? -->

Welcome to my portfolio. Browse my latest projects below.
MD,
            ],
            [
                'collection' => 'pages',
                'filename' => 'about.md',
                'frontmatter' => [
                    'title' => 'About',
                    'template' => 'page',
                    'meta' => ['description' => "<!-- BOOST: describe yourself as a creative professional -->"],
                ],
                'content' => <<<MD
## About Me

<!-- BOOST: write your professional bio. What's your background? What are your skills and specialties? What drives your creative work? -->

I'm a creative professional passionate about delivering exceptional work.

### Skills

<!-- BOOST: list your key skills and tools -->

### Experience

<!-- BOOST: highlight key career milestones or notable clients -->
MD,
            ],
            [
                'collection' => 'projects',
                'filename' => 'project-alpha.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: project name -->',
                    'date' => $date,
                    'template' => 'projects/show',
                    'tags' => ['design'],
                    'client' => '<!-- BOOST: client name -->',
                    'image' => '/assets/images/project-alpha.jpg',
                    'meta' => ['description' => "<!-- BOOST: describe this project in 150 chars -->"],
                ],
                'content' => <<<MD
<!-- BOOST: describe this project. What was the challenge? What was your approach? What was the result? Include specifics. -->

A showcase project demonstrating creative problem-solving and attention to detail.

### The Challenge

<!-- BOOST: what problem did you solve? -->

### The Approach

<!-- BOOST: how did you approach it? -->

### The Result

<!-- BOOST: what was the outcome? -->
MD,
            ],
            [
                'collection' => 'projects',
                'filename' => 'project-beta.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: project name -->',
                    'date' => $date,
                    'template' => 'projects/show',
                    'tags' => ['development'],
                    'client' => '<!-- BOOST: client name -->',
                    'image' => '/assets/images/project-beta.jpg',
                    'meta' => ['description' => "<!-- BOOST: describe this project -->"],
                ],
                'content' => <<<MD
<!-- BOOST: describe this project in detail -->

Another great project that showcases technical expertise and creative thinking.
MD,
            ],
            [
                'collection' => 'projects',
                'filename' => 'project-gamma.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: project name -->',
                    'date' => $date,
                    'template' => 'projects/show',
                    'tags' => ['branding'],
                    'client' => '<!-- BOOST: client name -->',
                    'image' => '/assets/images/project-gamma.jpg',
                    'meta' => ['description' => "<!-- BOOST: describe this project -->"],
                ],
                'content' => <<<MD
<!-- BOOST: describe this project in detail -->

A branding project that transformed a client's visual identity.
MD,
            ],
            [
                'collection' => 'projects',
                'filename' => 'project-delta.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: project name -->',
                    'date' => $date,
                    'template' => 'projects/show',
                    'tags' => ['design', 'development'],
                    'client' => '<!-- BOOST: client name -->',
                    'image' => '/assets/images/project-delta.jpg',
                    'meta' => ['description' => "<!-- BOOST: describe this project -->"],
                ],
                'content' => <<<MD
<!-- BOOST: describe this project in detail -->

A full-scope project combining design and development.
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
    <li><a href="/projects">Projects</a></li>
    <li><a href="/about">About</a></li>
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
    <li><a href="/projects">Projects</a></li>
    <li><a href="/about">About</a></li>
</ul>
{% endblock %}

{% block content %}
<section class="portfolio-hero">
    <h1>{{ global('site').title }}</h1>
    <div class="prose">
        {{ markdown(entry.content) }}
    </div>
</section>

<section class="featured-projects">
    <h2>Featured Work</h2>
    <div class="project-grid">
        {% for project in collection('projects').locale(locale).sort('date', 'desc').limit(4).get() %}
        <a href="{{ project.url(locale) }}" class="project-card">
            {% if project.getMeta('image') %}
            <div class="project-image" style="background-image: url('{{ project.getMeta('image') }}')"></div>
            {% else %}
            <div class="project-image project-placeholder"></div>
            {% endif %}
            <div class="project-info">
                <h3>{{ project.title }}</h3>
                {% if project.getMeta('client') %}
                <span class="project-client">{{ project.getMeta('client') }}</span>
                {% endif %}
                {% if project.getMeta('tags') %}
                <div class="project-tags">
                    {% for tag in project.getMeta('tags') %}
                    <span class="tag">{{ tag }}</span>
                    {% endfor %}
                </div>
                {% endif %}
            </div>
        </a>
        {% endfor %}
    </div>
    <div class="view-all">
        <a href="/projects" class="btn btn-outline">View All Projects</a>
    </div>
</section>
{% endblock %}
TWIG;

        $templates['templates/projects/index.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/projects">Projects</a></li>
    <li><a href="/about">About</a></li>
</ul>
{% endblock %}

{% block title %}Projects - {{ parent() }}{% endblock %}

{% block content %}
<h1>Projects</h1>
<div class="project-grid">
    {% for project in collection('projects').locale(locale).sort('date', 'desc').get() %}
    <a href="{{ project.url(locale) }}" class="project-card">
        {% if project.getMeta('image') %}
        <div class="project-image" style="background-image: url('{{ project.getMeta('image') }}')"></div>
        {% else %}
        <div class="project-image project-placeholder"></div>
        {% endif %}
        <div class="project-info">
            <h3>{{ project.title }}</h3>
            {% if project.getMeta('client') %}
            <span class="project-client">{{ project.getMeta('client') }}</span>
            {% endif %}
            {% if project.getMeta('tags') %}
            <div class="project-tags">
                {% for tag in project.getMeta('tags') %}
                <span class="tag">{{ tag }}</span>
                {% endfor %}
            </div>
            {% endif %}
        </div>
    </a>
    {% endfor %}
</div>
{% endblock %}
TWIG;

        $templates['templates/projects/show.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/projects">Projects</a></li>
    <li><a href="/about">About</a></li>
</ul>
{% endblock %}

{% block title %}{{ entry.title }} - Projects - {{ parent() }}{% endblock %}

{% block content %}
<article class="project-detail">
    {% if entry.getMeta('image') %}
    <div class="project-hero-image" style="background-image: url('{{ entry.getMeta('image') }}')"></div>
    {% endif %}
    <header class="project-header">
        <h1>{{ entry.title }}</h1>
        <div class="project-meta">
            {% if entry.getMeta('client') %}
            <span class="project-client">Client: {{ entry.getMeta('client') }}</span>
            {% endif %}
            <time>{{ entry.getMeta('date') }}</time>
            {% if entry.getMeta('tags') %}
            <div class="project-tags">
                {% for tag in entry.getMeta('tags') %}
                <span class="tag">{{ tag }}</span>
                {% endfor %}
            </div>
            {% endif %}
        </div>
    </header>
    <div class="prose">
        {{ markdown(entry.content) }}
    </div>
    <nav class="project-nav">
        <a href="/projects" class="btn btn-outline">&larr; All Projects</a>
    </nav>
</article>
{% endblock %}
TWIG;

        return $templates;
    }

    public function css(SiteProfile $profile): string
    {
        return $this->baseCss() . <<<'CSS'

/* Portfolio-specific styles */
.portfolio-hero {
    padding: 4rem 0;
    text-align: center;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 3rem;
}

.portfolio-hero h1 { font-size: 2.5rem; margin-bottom: 1rem; }

.featured-projects { padding: 2rem 0; }
.featured-projects h2 { margin-bottom: 2rem; }

.project-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
}

.project-card {
    display: block;
    color: inherit;
    border-radius: 8px;
    overflow: hidden;
    border: 1px solid var(--border-color);
    transition: transform 0.2s, box-shadow 0.2s;
}

.project-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
}

.project-image {
    height: 200px;
    background-size: cover;
    background-position: center;
    background-color: var(--bg-color);
}

.project-placeholder {
    background: linear-gradient(135deg, var(--primary-color) 0%, var(--accent-color) 100%);
    opacity: 0.3;
}

.project-info { padding: 1.25rem; }
.project-info h3 { margin-bottom: 0.5rem; }
.project-client { color: var(--secondary-color); font-size: 0.875rem; }

.project-tags { display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap; }
.tag {
    background: var(--bg-color);
    padding: 0.2rem 0.6rem;
    border-radius: 4px;
    font-size: 0.8rem;
    color: var(--secondary-color);
}

.view-all { text-align: center; margin-top: 2rem; }

.btn-outline {
    background: transparent;
    color: var(--primary-color);
    border: 2px solid var(--primary-color);
    padding: 0.5rem 1.25rem;
    border-radius: 6px;
    font-weight: 500;
}
.btn-outline:hover { background: var(--primary-color); color: white; }

.project-detail { max-width: var(--content-width); margin: 0 auto; }
.project-hero-image {
    height: 400px;
    background-size: cover;
    background-position: center;
    border-radius: 8px;
    margin-bottom: 2rem;
}

.project-header { margin-bottom: 2rem; }
.project-header h1 { margin-bottom: 0.75rem; }
.project-meta {
    display: flex;
    align-items: center;
    gap: 1rem;
    color: var(--secondary-color);
    font-size: 0.9rem;
    flex-wrap: wrap;
}

.project-nav {
    margin-top: 3rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--border-color);
}

.prose h2 { margin: 2rem 0 1rem; }
.prose h3 { margin: 1.5rem 0 0.75rem; }
.prose p { margin-bottom: 1rem; }
.prose ul, .prose ol { margin-bottom: 1rem; padding-left: 1.5rem; }

@media (max-width: 768px) {
    .portfolio-hero h1 { font-size: 1.75rem; }
    .project-grid { grid-template-columns: 1fr; }
    .project-hero-image { height: 250px; }
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
