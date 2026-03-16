<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost\Archetypes;

use Rkn\Cms\Boost\AbstractArchetype;
use Rkn\Cms\Boost\SiteProfile;

final class BlogArchetype extends AbstractArchetype
{
    public function name(): string
    {
        return 'blog';
    }

    public function description(): string
    {
        return 'Personal or professional blog with posts, pagination, and sidebar';
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
                    'per_page' => 10,
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
                    'meta' => ['description' => "<!-- BOOST: describe your blog homepage in 150 chars -->"],
                ],
                'content' => <<<MD
## Welcome to {$profile->name}

<!-- BOOST: write a compelling introduction for your blog. What topics do you cover? Who is your audience? -->

This is your blog's homepage. Recent posts appear below automatically.
MD,
            ],
            [
                'collection' => 'pages',
                'filename' => 'about.md',
                'frontmatter' => [
                    'title' => 'About',
                    'template' => 'page',
                    'meta' => ['description' => "<!-- BOOST: describe who you are in 150 chars -->"],
                ],
                'content' => <<<MD
## About

<!-- BOOST: write your personal or professional bio. What's your story? What drives you to write? -->

This is the about page. Tell your readers who you are and why they should follow your blog.
MD,
            ],
            [
                'collection' => 'pages',
                'filename' => 'contact.md',
                'frontmatter' => [
                    'title' => 'Contact',
                    'template' => 'page',
                    'meta' => ['description' => "<!-- BOOST: describe how to reach you -->"],
                ],
                'content' => <<<MD
## Contact

<!-- BOOST: add your preferred contact methods, social links, or a contact form -->

Get in touch — we'd love to hear from you.
MD,
            ],
            [
                'collection' => 'blog',
                'filename' => 'first-post.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: write a catchy title for your first post -->',
                    'date' => $date,
                    'template' => 'blog/show',
                    'tags' => ['getting-started'],
                    'meta' => ['description' => "<!-- BOOST: summarize this post in 150 chars -->"],
                ],
                'content' => <<<MD
<!-- BOOST: write your inaugural blog post. Introduce yourself, explain what readers can expect, and set the tone for future content. Aim for 300-500 words. -->

Welcome to the first post on this blog. Stay tuned for more content.
MD,
            ],
            [
                'collection' => 'blog',
                'filename' => 'second-post.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: title for a topic-focused post -->',
                    'date' => $date,
                    'template' => 'blog/show',
                    'tags' => ['tutorial'],
                    'meta' => ['description' => "<!-- BOOST: summarize this post in 150 chars -->"],
                ],
                'content' => <<<MD
<!-- BOOST: write a tutorial or how-to post related to your blog's topic. Include practical examples and clear steps. Aim for 400-600 words. -->

This is a sample tutorial post. Replace this content with your own.
MD,
            ],
            [
                'collection' => 'blog',
                'filename' => 'third-post.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: title for an opinion or insight post -->',
                    'date' => $date,
                    'template' => 'blog/show',
                    'tags' => ['insights'],
                    'meta' => ['description' => "<!-- BOOST: summarize this post in 150 chars -->"],
                ],
                'content' => <<<MD
<!-- BOOST: write a thought piece or opinion post. Share your perspective on a trend or topic in your field. Aim for 300-500 words. -->

This is a sample insights post. Share your unique perspective here.
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
    <li><a href="/blog">Blog</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
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
    <li><a href="/blog">Blog</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
</ul>
{% endblock %}

{% block content %}
<div class="home-hero">
    <div class="prose">
        {{ markdown(entry.content) }}
    </div>
</div>

<section class="recent-posts">
    <h2>Recent Posts</h2>
    <div class="post-grid">
        {% for post in collection('blog').locale(locale).sort('date', 'desc').limit(6).get() %}
        <article class="post-card card">
            <h3><a href="{{ post.url(locale) }}">{{ post.title }}</a></h3>
            <time class="post-date">{{ post.getMeta('date') }}</time>
            {% if post.getMeta('tags') %}
            <div class="post-tags">
                {% for tag in post.getMeta('tags') %}
                <span class="tag">{{ tag }}</span>
                {% endfor %}
            </div>
            {% endif %}
        </article>
        {% endfor %}
    </div>
</section>
{% endblock %}
TWIG;

        $templates['templates/blog/index.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/blog">Blog</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
</ul>
{% endblock %}

{% block title %}Blog - {{ parent() }}{% endblock %}

{% block content %}
<div class="blog-layout">
    <div class="blog-main">
        <h2>Blog</h2>
        {% for post in collection('blog').locale(locale).sort('date', 'desc').get() %}
        <article class="post-card card">
            <h3><a href="{{ post.url(locale) }}">{{ post.title }}</a></h3>
            <time class="post-date">{{ post.getMeta('date') }}</time>
            {% if post.getMeta('tags') %}
            <div class="post-tags">
                {% for tag in post.getMeta('tags') %}
                <span class="tag">{{ tag }}</span>
                {% endfor %}
            </div>
            {% endif %}
        </article>
        {% endfor %}
    </div>

    <aside class="blog-sidebar">
        <div class="sidebar-section card">
            <h4>Tags</h4>
            <div class="tag-cloud">
                {% for tag in ['getting-started', 'tutorial', 'insights'] %}
                <a href="/tag/{{ tag }}" class="tag">{{ tag }}</a>
                {% endfor %}
            </div>
        </div>
    </aside>
</div>
{% endblock %}
TWIG;

        $templates['templates/blog/show.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/blog">Blog</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
</ul>
{% endblock %}

{% block title %}{{ entry.title }} - {{ parent() }}{% endblock %}

{% block content %}
<article class="blog-post">
    <header class="post-header">
        <h1>{{ entry.title }}</h1>
        <div class="post-meta">
            <time>{{ entry.getMeta('date') }}</time>
            {% if entry.getMeta('tags') %}
            <div class="post-tags">
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
</article>
{% endblock %}
TWIG;

        return $templates;
    }

    public function css(SiteProfile $profile): string
    {
        return $this->baseCss() . <<<'CSS'

/* Blog-specific styles */
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

.post-card { margin-bottom: 1rem; }
.post-card h3 { margin-bottom: 0.5rem; }
.post-date { color: var(--secondary-color); font-size: 0.875rem; }

.post-tags { display: flex; gap: 0.5rem; margin-top: 0.75rem; flex-wrap: wrap; }
.tag {
    background: var(--bg-color);
    padding: 0.2rem 0.6rem;
    border-radius: 4px;
    font-size: 0.8rem;
    color: var(--secondary-color);
}

.blog-layout {
    display: grid;
    grid-template-columns: 1fr 280px;
    gap: 2rem;
}

.blog-main h2 { margin-bottom: 1.5rem; }

.sidebar-section { margin-bottom: 1.5rem; }
.sidebar-section h4 { margin-bottom: 0.75rem; }
.tag-cloud { display: flex; flex-wrap: wrap; gap: 0.5rem; }

.blog-post .post-header { margin-bottom: 2rem; }
.blog-post .post-header h1 { margin-bottom: 0.5rem; }
.blog-post .post-meta {
    display: flex;
    align-items: center;
    gap: 1rem;
    color: var(--secondary-color);
    font-size: 0.9rem;
}

.prose { max-width: var(--content-width); }
.prose h2 { margin: 2rem 0 1rem; }
.prose h3 { margin: 1.5rem 0 0.75rem; }
.prose p { margin-bottom: 1rem; }
.prose ul, .prose ol { margin-bottom: 1rem; padding-left: 1.5rem; }
.prose blockquote {
    border-left: 3px solid var(--primary-color);
    padding-left: 1rem;
    margin: 1rem 0;
    color: var(--secondary-color);
}
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

@media (max-width: 768px) {
    .blog-layout { grid-template-columns: 1fr; }
    .post-grid { grid-template-columns: 1fr; }
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
