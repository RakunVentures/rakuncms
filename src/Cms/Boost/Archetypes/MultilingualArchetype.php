<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost\Archetypes;

use Rkn\Cms\Boost\AbstractArchetype;
use Rkn\Cms\Boost\SiteProfile;

final class MultilingualArchetype extends AbstractArchetype
{
    public function name(): string
    {
        return 'multilingual';
    }

    public function description(): string
    {
        return 'Multilingual site with language switcher, hreflang, and content in two locales';
    }

    public function collections(): array
    {
        return [
            [
                'name' => 'blog',
                'config' => [
                    'url_pattern' => '/{locale}/blog/{slug}',
                    'sort' => ['field' => 'date', 'direction' => 'desc'],
                    'templates' => ['index' => 'blog/index', 'show' => 'blog/show'],
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
        $altLocale = $profile->locale === 'es' ? 'en' : 'es';

        return [
            // Pages in primary locale
            [
                'collection' => 'pages',
                'filename' => 'index.md',
                'frontmatter' => [
                    'title' => $profile->name,
                    'template' => 'home',
                    'slugs' => [$profile->locale => '', $altLocale => ''],
                    'meta' => ['description' => "<!-- BOOST: describe your site in {$profile->locale} -->"],
                ],
                'content' => $profile->locale === 'es'
                    ? "## Bienvenido a {$profile->name}\n\n<!-- BOOST: escribe una introducción en español -->\n\nBienvenido a nuestro sitio web multilingüe."
                    : "## Welcome to {$profile->name}\n\n<!-- BOOST: write an introduction in English -->\n\nWelcome to our multilingual website.",
            ],
            // Pages in alt locale
            [
                'collection' => 'pages',
                'filename' => "index.{$altLocale}.md",
                'frontmatter' => [
                    'title' => $profile->name,
                    'template' => 'home',
                    'slugs' => [$profile->locale => '', $altLocale => ''],
                    'meta' => ['description' => "<!-- BOOST: describe your site in {$altLocale} -->"],
                ],
                'content' => $altLocale === 'es'
                    ? "## Bienvenido a {$profile->name}\n\n<!-- BOOST: escribe una introducción en español -->\n\nBienvenido a nuestro sitio web multilingüe."
                    : "## Welcome to {$profile->name}\n\n<!-- BOOST: write an introduction in English -->\n\nWelcome to our multilingual website.",
            ],
            // About in primary locale
            [
                'collection' => 'pages',
                'filename' => 'about.md',
                'frontmatter' => [
                    'title' => $profile->locale === 'es' ? 'Acerca de' : 'About',
                    'template' => 'page',
                    'slugs' => [$profile->locale => $profile->locale === 'es' ? 'acerca' : 'about', $altLocale => $altLocale === 'es' ? 'acerca' : 'about'],
                    'meta' => ['description' => "<!-- BOOST: describe in {$profile->locale} -->"],
                ],
                'content' => $profile->locale === 'es'
                    ? "## Acerca de nosotros\n\n<!-- BOOST: escribe sobre tu proyecto en español -->"
                    : "## About Us\n\n<!-- BOOST: write about your project in English -->",
            ],
            // About in alt locale
            [
                'collection' => 'pages',
                'filename' => "about.{$altLocale}.md",
                'frontmatter' => [
                    'title' => $altLocale === 'es' ? 'Acerca de' : 'About',
                    'template' => 'page',
                    'slugs' => [$profile->locale => $profile->locale === 'es' ? 'acerca' : 'about', $altLocale => $altLocale === 'es' ? 'acerca' : 'about'],
                    'meta' => ['description' => "<!-- BOOST: describe in {$altLocale} -->"],
                ],
                'content' => $altLocale === 'es'
                    ? "## Acerca de nosotros\n\n<!-- BOOST: escribe sobre tu proyecto en español -->"
                    : "## About Us\n\n<!-- BOOST: write about your project in English -->",
            ],
            // Contact in primary locale
            [
                'collection' => 'pages',
                'filename' => 'contact.md',
                'frontmatter' => [
                    'title' => $profile->locale === 'es' ? 'Contacto' : 'Contact',
                    'template' => 'page',
                    'slugs' => [$profile->locale => $profile->locale === 'es' ? 'contacto' : 'contact', $altLocale => $altLocale === 'es' ? 'contacto' : 'contact'],
                    'meta' => ['description' => "<!-- BOOST: contact info in {$profile->locale} -->"],
                ],
                'content' => $profile->locale === 'es'
                    ? "## Contacto\n\n<!-- BOOST: información de contacto en español -->"
                    : "## Contact\n\n<!-- BOOST: contact information in English -->",
            ],
            // Contact in alt locale
            [
                'collection' => 'pages',
                'filename' => "contact.{$altLocale}.md",
                'frontmatter' => [
                    'title' => $altLocale === 'es' ? 'Contacto' : 'Contact',
                    'template' => 'page',
                    'slugs' => [$profile->locale => $profile->locale === 'es' ? 'contacto' : 'contact', $altLocale => $altLocale === 'es' ? 'contacto' : 'contact'],
                    'meta' => ['description' => "<!-- BOOST: contact info in {$altLocale} -->"],
                ],
                'content' => $altLocale === 'es'
                    ? "## Contacto\n\n<!-- BOOST: información de contacto en español -->"
                    : "## Contact\n\n<!-- BOOST: contact information in English -->",
            ],
            // Blog post 1 in primary locale
            [
                'collection' => 'blog',
                'filename' => 'first-post.md',
                'frontmatter' => [
                    'title' => $profile->locale === 'es' ? '<!-- BOOST: título del primer post -->' : '<!-- BOOST: first post title -->',
                    'date' => $date,
                    'template' => 'blog/show',
                    'slugs' => [$profile->locale => $profile->locale === 'es' ? 'primer-post' : 'first-post', $altLocale => $altLocale === 'es' ? 'primer-post' : 'first-post'],
                    'meta' => ['description' => "<!-- BOOST: summary in {$profile->locale} -->"],
                ],
                'content' => $profile->locale === 'es'
                    ? "<!-- BOOST: escribe tu primer post en español -->\n\nBienvenido al blog."
                    : "<!-- BOOST: write your first post in English -->\n\nWelcome to the blog.",
            ],
            // Blog post 1 in alt locale
            [
                'collection' => 'blog',
                'filename' => "first-post.{$altLocale}.md",
                'frontmatter' => [
                    'title' => $altLocale === 'es' ? '<!-- BOOST: título del primer post -->' : '<!-- BOOST: first post title -->',
                    'date' => $date,
                    'template' => 'blog/show',
                    'slugs' => [$profile->locale => $profile->locale === 'es' ? 'primer-post' : 'first-post', $altLocale => $altLocale === 'es' ? 'primer-post' : 'first-post'],
                    'meta' => ['description' => "<!-- BOOST: summary in {$altLocale} -->"],
                ],
                'content' => $altLocale === 'es'
                    ? "<!-- BOOST: escribe tu primer post en español -->\n\nBienvenido al blog."
                    : "<!-- BOOST: write your first post in English -->\n\nWelcome to the blog.",
            ],
            // Blog post 2 in primary locale
            [
                'collection' => 'blog',
                'filename' => 'second-post.md',
                'frontmatter' => [
                    'title' => $profile->locale === 'es' ? '<!-- BOOST: título del segundo post -->' : '<!-- BOOST: second post title -->',
                    'date' => $date,
                    'template' => 'blog/show',
                    'slugs' => [$profile->locale => $profile->locale === 'es' ? 'segundo-post' : 'second-post', $altLocale => $altLocale === 'es' ? 'segundo-post' : 'second-post'],
                    'meta' => ['description' => "<!-- BOOST: summary in {$profile->locale} -->"],
                ],
                'content' => $profile->locale === 'es'
                    ? "<!-- BOOST: escribe tu segundo post en español -->\n\nOtro artículo para el blog."
                    : "<!-- BOOST: write your second post in English -->\n\nAnother blog post.",
            ],
            // Blog post 2 in alt locale
            [
                'collection' => 'blog',
                'filename' => "second-post.{$altLocale}.md",
                'frontmatter' => [
                    'title' => $altLocale === 'es' ? '<!-- BOOST: título del segundo post -->' : '<!-- BOOST: second post title -->',
                    'date' => $date,
                    'template' => 'blog/show',
                    'slugs' => [$profile->locale => $profile->locale === 'es' ? 'segundo-post' : 'second-post', $altLocale => $altLocale === 'es' ? 'segundo-post' : 'second-post'],
                    'meta' => ['description' => "<!-- BOOST: summary in {$altLocale} -->"],
                ],
                'content' => $altLocale === 'es'
                    ? "<!-- BOOST: escribe tu segundo post en español -->\n\nOtro artículo para el blog."
                    : "<!-- BOOST: write your second post in English -->\n\nAnother blog post.",
            ],
        ];
    }

    public function templates(SiteProfile $profile): array
    {
        $templates = [];
        $altLocale = $profile->locale === 'es' ? 'en' : 'es';
        $homeLabel = $profile->locale === 'es' ? 'Inicio' : 'Home';
        $blogLabel = $profile->locale === 'es' ? 'Blog' : 'Blog';
        $aboutLabel = $profile->locale === 'es' ? 'Acerca' : 'About';
        $contactLabel = $profile->locale === 'es' ? 'Contacto' : 'Contact';

        $templates['templates/_layouts/base.twig'] = $this->baseLayout($profile);

        $templates['templates/_partials/lang-switcher.twig'] = <<<TWIG
<div class="lang-switcher">
    <a href="/{{ '{$profile->locale}' }}" class="{{ locale == '{$profile->locale}' ? 'active' : '' }}">{{ '{$profile->locale}'|upper }}</a>
    <span class="lang-separator">|</span>
    <a href="/{{ '{$altLocale}' }}" class="{{ locale == '{$altLocale}' ? 'active' : '' }}">{{ '{$altLocale}'|upper }}</a>
</div>
TWIG;

        $templates['templates/_layouts/page.twig'] = <<<TWIG
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">{$homeLabel}</a></li>
    <li><a href="/blog">{$blogLabel}</a></li>
    <li><a href="/about">{$aboutLabel}</a></li>
    <li><a href="/contact">{$contactLabel}</a></li>
</ul>
{% include "_partials/lang-switcher.twig" %}
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

        $templates['templates/home.twig'] = <<<TWIG
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">{$homeLabel}</a></li>
    <li><a href="/blog">{$blogLabel}</a></li>
    <li><a href="/about">{$aboutLabel}</a></li>
    <li><a href="/contact">{$contactLabel}</a></li>
</ul>
{% include "_partials/lang-switcher.twig" %}
{% endblock %}

{% block content %}
<div class="home-hero">
    <div class="prose">
        {{ markdown(entry.content) }}
    </div>
</div>

<section class="recent-posts">
    <h2>{{ locale == 'es' ? 'Publicaciones Recientes' : 'Recent Posts' }}</h2>
    <div class="post-grid">
        {% for post in collection('blog').locale(locale).sort('date', 'desc').limit(6).get() %}
        <article class="post-card card">
            <h3><a href="{{ post.url(locale) }}">{{ post.title }}</a></h3>
            <time class="post-date">{{ post.getMeta('date') }}</time>
        </article>
        {% endfor %}
    </div>
</section>
{% endblock %}
TWIG;

        $templates['templates/blog/index.twig'] = <<<TWIG
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">{$homeLabel}</a></li>
    <li><a href="/blog">{$blogLabel}</a></li>
    <li><a href="/about">{$aboutLabel}</a></li>
    <li><a href="/contact">{$contactLabel}</a></li>
</ul>
{% include "_partials/lang-switcher.twig" %}
{% endblock %}

{% block title %}{$blogLabel} - {{ parent() }}{% endblock %}

{% block content %}
<h1>{$blogLabel}</h1>
<div class="post-grid">
    {% for post in collection('blog').locale(locale).sort('date', 'desc').get() %}
    <article class="post-card card">
        <h3><a href="{{ post.url(locale) }}">{{ post.title }}</a></h3>
        <time class="post-date">{{ post.getMeta('date') }}</time>
    </article>
    {% endfor %}
</div>
{% endblock %}
TWIG;

        $templates['templates/blog/show.twig'] = <<<TWIG
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">{$homeLabel}</a></li>
    <li><a href="/blog">{$blogLabel}</a></li>
    <li><a href="/about">{$aboutLabel}</a></li>
    <li><a href="/contact">{$contactLabel}</a></li>
</ul>
{% include "_partials/lang-switcher.twig" %}
{% endblock %}

{% block title %}{{ entry.title }} - {{ parent() }}{% endblock %}

{% block content %}
<article class="blog-post">
    <header class="post-header">
        <h1>{{ entry.title }}</h1>
        <time class="post-date">{{ entry.getMeta('date') }}</time>
    </header>
    <div class="prose">
        {{ markdown(entry.content) }}
    </div>
</article>
{% endblock %}
TWIG;

        return $templates;
    }

    public function css(SiteProfile $profile): string
    {
        return $this->baseCss() . <<<'CSS'

/* Multilingual-specific styles */
.lang-switcher {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-left: 1.5rem;
}

.lang-switcher a {
    color: var(--secondary-color);
    font-weight: 500;
    font-size: 0.875rem;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
}

.lang-switcher a.active {
    color: var(--primary-color);
    background: #eff6ff;
    font-weight: 700;
}

.lang-separator { color: var(--border-color); }

.home-hero {
    padding: 3rem 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 2rem;
}

.recent-posts h2 { margin-bottom: 1.5rem; }

.post-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
}

.post-card h3 { margin-bottom: 0.5rem; }
.post-date { color: var(--secondary-color); font-size: 0.875rem; }

.blog-post .post-header { margin-bottom: 2rem; }
.blog-post .post-header h1 { margin-bottom: 0.5rem; }

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

@media (max-width: 768px) {
    .post-grid { grid-template-columns: 1fr; }
    .site-header .container { flex-wrap: wrap; }
    .lang-switcher { margin-left: 0; margin-top: 0.5rem; }
}
CSS;
    }

    public function config(SiteProfile $profile): array
    {
        $altLocale = $profile->locale === 'es' ? 'en' : 'es';
        return [
            'site' => [
                'url' => 'http://localhost:8080',
                'default_locale' => $profile->locale,
                'locales' => [$profile->locale, $altLocale],
            ],
            'seo' => [
                'site_name' => $profile->name,
                'default_image' => '/assets/images/default-og.jpg',
            ],
        ];
    }

    public function globals(SiteProfile $profile): array
    {
        return $this->baseGlobals($profile);
    }
}
