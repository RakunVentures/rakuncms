<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost\Archetypes;

use Rkn\Cms\Boost\AbstractArchetype;
use Rkn\Cms\Boost\SiteProfile;

final class BusinessArchetype extends AbstractArchetype
{
    public function name(): string
    {
        return 'business';
    }

    public function description(): string
    {
        return 'Business landing page with services, testimonials, and contact form';
    }

    public function collections(): array
    {
        return [
            [
                'name' => 'services',
                'config' => [
                    'url_pattern' => '/{locale}/services/{slug}',
                    'sort' => ['field' => 'order', 'direction' => 'asc'],
                    'templates' => ['index' => 'services/index', 'show' => 'services/show'],
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
                    'meta' => ['description' => "<!-- BOOST: describe your business in 150 chars -->"],
                ],
                'content' => <<<MD
<!-- BOOST: write a compelling hero headline that captures what your business does and why customers should choose you -->

We help you achieve results. Discover our services and see how we can work together.
MD,
            ],
            [
                'collection' => 'pages',
                'filename' => 'about.md',
                'frontmatter' => [
                    'title' => 'About Us',
                    'template' => 'page',
                    'meta' => ['description' => "<!-- BOOST: describe your company story -->"],
                ],
                'content' => <<<MD
## About Us

<!-- BOOST: write your company story. Include your mission, vision, values, and what makes you different. -->

We're a team dedicated to delivering excellence.

### Our Mission

<!-- BOOST: state your mission -->

### Our Values

<!-- BOOST: list your core values -->
MD,
            ],
            [
                'collection' => 'pages',
                'filename' => 'contact.md',
                'frontmatter' => [
                    'title' => 'Contact',
                    'template' => 'contact',
                    'meta' => ['description' => "<!-- BOOST: describe how to reach your business -->"],
                ],
                'content' => <<<MD
## Get In Touch

<!-- BOOST: add your business contact information: address, phone, email, business hours -->

We'd love to hear from you. Reach out using the form below or contact us directly.
MD,
            ],
            [
                'collection' => 'services',
                'filename' => '01.service-one.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: name of your first service -->',
                    'order' => 1,
                    'template' => 'services/show',
                    'icon' => 'star',
                    'meta' => ['description' => "<!-- BOOST: describe this service in 150 chars -->"],
                ],
                'content' => <<<MD
<!-- BOOST: describe this service in detail. What does it include? Who is it for? What results can clients expect? -->

This is your first service offering. Replace this content with details about what you provide.
MD,
            ],
            [
                'collection' => 'services',
                'filename' => '02.service-two.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: name of your second service -->',
                    'order' => 2,
                    'template' => 'services/show',
                    'icon' => 'zap',
                    'meta' => ['description' => "<!-- BOOST: describe this service in 150 chars -->"],
                ],
                'content' => <<<MD
<!-- BOOST: describe this service in detail -->

This is your second service offering.
MD,
            ],
            [
                'collection' => 'services',
                'filename' => '03.service-three.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: name of your third service -->',
                    'order' => 3,
                    'template' => 'services/show',
                    'icon' => 'shield',
                    'meta' => ['description' => "<!-- BOOST: describe this service in 150 chars -->"],
                ],
                'content' => <<<MD
<!-- BOOST: describe this service in detail -->

This is your third service offering.
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
    <li><a href="/services">Services</a></li>
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
    <li><a href="/services">Services</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
</ul>
{% endblock %}

{% block content %}
<section class="hero">
    <div class="hero-content">
        <h1>{{ global('site').title }}</h1>
        <div class="prose">
            {{ markdown(entry.content) }}
        </div>
        <div class="hero-actions">
            <a href="/contact" class="btn btn-primary">Get Started</a>
            <a href="/services" class="btn btn-outline">Our Services</a>
        </div>
    </div>
</section>

<section class="services-preview">
    <h2>Our Services</h2>
    <div class="services-grid">
        {% for service in collection('services').locale(locale).sort('order', 'asc').get() %}
        <div class="service-card card">
            <h3>{{ service.title }}</h3>
            <a href="{{ service.url(locale) }}" class="btn btn-primary">Learn More</a>
        </div>
        {% endfor %}
    </div>
</section>

<section class="testimonials">
    <h2>What Our Clients Say</h2>
    <div class="testimonials-grid">
        <blockquote class="testimonial card">
            <p><!-- BOOST: add a client testimonial --></p>
            <cite><!-- BOOST: client name and title --></cite>
        </blockquote>
        <blockquote class="testimonial card">
            <p><!-- BOOST: add a client testimonial --></p>
            <cite><!-- BOOST: client name and title --></cite>
        </blockquote>
    </div>
</section>

<section class="cta">
    <h2>Ready to Get Started?</h2>
    <p><!-- BOOST: write a compelling call to action --></p>
    <a href="/contact" class="btn btn-primary">Contact Us</a>
</section>
{% endblock %}
TWIG;

        $templates['templates/contact.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/services">Services</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
</ul>
{% endblock %}

{% block title %}{{ entry.title }} - {{ parent() }}{% endblock %}

{% block content %}
<div class="contact-layout">
    <div class="contact-info">
        <h1>{{ entry.title }}</h1>
        <div class="prose">
            {{ markdown(entry.content) }}
        </div>
    </div>
    <div class="contact-form card">
        <h3>Send us a message</h3>
        <form method="post" action="/api/v1/contact">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" id="name" name="name" required>
            </div>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>
            <div class="form-group">
                <label for="message">Message</label>
                <textarea id="message" name="message" rows="5" required></textarea>
            </div>
            <button type="submit" class="btn btn-primary">Send Message</button>
        </form>
    </div>
</div>
{% endblock %}
TWIG;

        $templates['templates/services/index.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/services">Services</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
</ul>
{% endblock %}

{% block title %}Services - {{ parent() }}{% endblock %}

{% block content %}
<h1>Our Services</h1>
<div class="services-grid">
    {% for service in collection('services').locale(locale).sort('order', 'asc').get() %}
    <div class="service-card card">
        <h3>{{ service.title }}</h3>
        <a href="{{ service.url(locale) }}" class="btn btn-primary">Learn More</a>
    </div>
    {% endfor %}
</div>
{% endblock %}
TWIG;

        $templates['templates/services/show.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/services">Services</a></li>
    <li><a href="/about">About</a></li>
    <li><a href="/contact">Contact</a></li>
</ul>
{% endblock %}

{% block title %}{{ entry.title }} - Services - {{ parent() }}{% endblock %}

{% block content %}
<article class="service-detail">
    <h1>{{ entry.title }}</h1>
    <div class="prose">
        {{ markdown(entry.content) }}
    </div>
    <div class="service-cta">
        <a href="/contact" class="btn btn-primary">Get Started</a>
    </div>
</article>
{% endblock %}
TWIG;

        return $templates;
    }

    public function css(SiteProfile $profile): string
    {
        return $this->baseCss() . <<<'CSS'

/* Business-specific styles */
.hero {
    padding: 4rem 0;
    text-align: center;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 3rem;
}

.hero h1 { font-size: 2.5rem; margin-bottom: 1rem; }
.hero-actions { margin-top: 2rem; display: flex; gap: 1rem; justify-content: center; }

.btn-outline {
    background: transparent;
    color: var(--primary-color);
    border: 2px solid var(--primary-color);
    padding: 0.5rem 1.25rem;
    border-radius: 6px;
    font-weight: 500;
}
.btn-outline:hover { background: var(--primary-color); color: white; }

.services-preview, .testimonials, .cta { padding: 3rem 0; }
.services-preview h2, .testimonials h2, .cta h2 { text-align: center; margin-bottom: 2rem; }

.services-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1.5rem;
}

.service-card { text-align: center; padding: 2rem; }
.service-card h3 { margin-bottom: 1rem; }

.testimonials-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.5rem;
}

.testimonial { font-style: italic; }
.testimonial cite { display: block; margin-top: 1rem; font-style: normal; font-weight: 600; color: var(--secondary-color); }

.cta {
    text-align: center;
    background: var(--card-bg);
    border-radius: 12px;
    padding: 3rem;
    border: 1px solid var(--border-color);
}
.cta p { margin: 1rem 0 1.5rem; color: var(--secondary-color); }

.contact-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 3rem;
    align-items: start;
}

.contact-form { padding: 2rem; }
.form-group { margin-bottom: 1rem; }
.form-group label { display: block; margin-bottom: 0.25rem; font-weight: 500; }
.form-group input, .form-group textarea {
    width: 100%;
    padding: 0.5rem;
    border: 1px solid var(--border-color);
    border-radius: 4px;
    font-size: 1rem;
}

.service-detail { max-width: var(--content-width); }
.service-detail h1 { margin-bottom: 1.5rem; }
.service-cta { margin-top: 2rem; }

.prose h2 { margin: 2rem 0 1rem; }
.prose h3 { margin: 1.5rem 0 0.75rem; }
.prose p { margin-bottom: 1rem; }
.prose ul, .prose ol { margin-bottom: 1rem; padding-left: 1.5rem; }

@media (max-width: 768px) {
    .hero h1 { font-size: 1.75rem; }
    .contact-layout { grid-template-columns: 1fr; }
    .services-grid { grid-template-columns: 1fr; }
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
