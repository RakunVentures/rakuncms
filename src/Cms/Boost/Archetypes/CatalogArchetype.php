<?php

declare(strict_types=1);

namespace Rkn\Cms\Boost\Archetypes;

use Rkn\Cms\Boost\AbstractArchetype;
use Rkn\Cms\Boost\SiteProfile;

final class CatalogArchetype extends AbstractArchetype
{
    public function name(): string
    {
        return 'catalog';
    }

    public function description(): string
    {
        return 'Product catalog with categories, product cards, and price display';
    }

    public function collections(): array
    {
        return [
            [
                'name' => 'products',
                'config' => [
                    'url_pattern' => '/{locale}/products/{slug}',
                    'sort' => ['field' => 'order', 'direction' => 'asc'],
                    'templates' => ['index' => 'products/index', 'show' => 'products/show'],
                ],
            ],
            [
                'name' => 'categories',
                'config' => [
                    'url_pattern' => '/{locale}/categories/{slug}',
                    'sort' => ['field' => 'order', 'direction' => 'asc'],
                    'templates' => ['index' => 'categories/index', 'show' => 'categories/show'],
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
                    'meta' => ['description' => "<!-- BOOST: describe your catalog in 150 chars -->"],
                ],
                'content' => <<<MD
## Welcome to {$profile->name}

<!-- BOOST: write a brief introduction to your product catalog. What do you sell? What makes your products special? -->

Browse our catalog and find exactly what you need.
MD,
            ],
            [
                'collection' => 'categories',
                'filename' => '01.category-one.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: first category name -->',
                    'order' => 1,
                    'template' => 'categories/show',
                    'meta' => ['description' => "<!-- BOOST: describe this category -->"],
                ],
                'content' => <<<MD
<!-- BOOST: describe this product category -->

Browse our selection in this category.
MD,
            ],
            [
                'collection' => 'categories',
                'filename' => '02.category-two.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: second category name -->',
                    'order' => 2,
                    'template' => 'categories/show',
                    'meta' => ['description' => "<!-- BOOST: describe this category -->"],
                ],
                'content' => <<<MD
<!-- BOOST: describe this product category -->

Explore products in this category.
MD,
            ],
            [
                'collection' => 'products',
                'filename' => '01.product-one.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: product name -->',
                    'order' => 1,
                    'template' => 'products/show',
                    'price' => '0.00',
                    'currency' => 'USD',
                    'category' => 'category-one',
                    'image' => '/assets/images/product-1.jpg',
                    'in_stock' => true,
                    'meta' => ['description' => "<!-- BOOST: describe this product -->"],
                ],
                'content' => <<<MD
<!-- BOOST: write a detailed product description. Include features, specifications, and benefits. -->

A great product that meets your needs.

### Features

- <!-- BOOST: feature 1 -->
- <!-- BOOST: feature 2 -->
- <!-- BOOST: feature 3 -->
MD,
            ],
            [
                'collection' => 'products',
                'filename' => '02.product-two.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: product name -->',
                    'order' => 2,
                    'template' => 'products/show',
                    'price' => '0.00',
                    'currency' => 'USD',
                    'category' => 'category-one',
                    'image' => '/assets/images/product-2.jpg',
                    'in_stock' => true,
                    'meta' => ['description' => "<!-- BOOST: describe this product -->"],
                ],
                'content' => <<<MD
<!-- BOOST: write a detailed product description -->

Another excellent product in our catalog.
MD,
            ],
            [
                'collection' => 'products',
                'filename' => '03.product-three.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: product name -->',
                    'order' => 3,
                    'template' => 'products/show',
                    'price' => '0.00',
                    'currency' => 'USD',
                    'category' => 'category-two',
                    'image' => '/assets/images/product-3.jpg',
                    'in_stock' => true,
                    'meta' => ['description' => "<!-- BOOST: describe this product -->"],
                ],
                'content' => <<<MD
<!-- BOOST: write a detailed product description -->

A premium product offering great value.
MD,
            ],
            [
                'collection' => 'products',
                'filename' => '04.product-four.md',
                'frontmatter' => [
                    'title' => '<!-- BOOST: product name -->',
                    'order' => 4,
                    'template' => 'products/show',
                    'price' => '0.00',
                    'currency' => 'USD',
                    'category' => 'category-two',
                    'image' => '/assets/images/product-4.jpg',
                    'in_stock' => true,
                    'meta' => ['description' => "<!-- BOOST: describe this product -->"],
                ],
                'content' => <<<MD
<!-- BOOST: write a detailed product description -->

Quality product with exceptional features.
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
    <li><a href="/products">Products</a></li>
    <li><a href="/categories">Categories</a></li>
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
    <li><a href="/products">Products</a></li>
    <li><a href="/categories">Categories</a></li>
</ul>
{% endblock %}

{% block content %}
<div class="catalog-hero">
    <div class="prose">
        {{ markdown(entry.content) }}
    </div>
</div>

<section class="categories-section">
    <h2>Categories</h2>
    <div class="categories-grid">
        {% for cat in collection('categories').locale(locale).sort('order', 'asc').get() %}
        <a href="{{ cat.url(locale) }}" class="category-card card">
            <h3>{{ cat.title }}</h3>
        </a>
        {% endfor %}
    </div>
</section>

<section class="featured-products">
    <h2>Featured Products</h2>
    <div class="product-grid">
        {% for product in collection('products').locale(locale).sort('order', 'asc').limit(4).get() %}
        <div class="product-card card">
            {% if product.getMeta('image') %}
            <div class="product-image" style="background-image: url('{{ product.getMeta('image') }}')"></div>
            {% else %}
            <div class="product-image product-placeholder"></div>
            {% endif %}
            <div class="product-info">
                <h3><a href="{{ product.url(locale) }}">{{ product.title }}</a></h3>
                {% if product.getMeta('price') %}
                <span class="product-price">{{ product.getMeta('currency')|default('$') }} {{ product.getMeta('price') }}</span>
                {% endif %}
                {% if product.getMeta('in_stock') %}
                <span class="in-stock">In Stock</span>
                {% else %}
                <span class="out-of-stock">Out of Stock</span>
                {% endif %}
            </div>
        </div>
        {% endfor %}
    </div>
</section>
{% endblock %}
TWIG;

        $templates['templates/products/index.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/products">Products</a></li>
    <li><a href="/categories">Categories</a></li>
</ul>
{% endblock %}

{% block title %}Products - {{ parent() }}{% endblock %}

{% block content %}
<h1>Products</h1>
<div class="product-grid">
    {% for product in collection('products').locale(locale).sort('order', 'asc').get() %}
    <div class="product-card card">
        {% if product.getMeta('image') %}
        <div class="product-image" style="background-image: url('{{ product.getMeta('image') }}')"></div>
        {% else %}
        <div class="product-image product-placeholder"></div>
        {% endif %}
        <div class="product-info">
            <h3><a href="{{ product.url(locale) }}">{{ product.title }}</a></h3>
            {% if product.getMeta('price') %}
            <span class="product-price">{{ product.getMeta('currency')|default('$') }} {{ product.getMeta('price') }}</span>
            {% endif %}
        </div>
    </div>
    {% endfor %}
</div>
{% endblock %}
TWIG;

        $templates['templates/products/show.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/products">Products</a></li>
    <li><a href="/categories">Categories</a></li>
</ul>
{% endblock %}

{% block title %}{{ entry.title }} - Products - {{ parent() }}{% endblock %}

{% block content %}
<div class="product-detail">
    <div class="product-detail-layout">
        <div class="product-detail-image">
            {% if entry.getMeta('image') %}
            <img src="{{ entry.getMeta('image') }}" alt="{{ entry.title }}">
            {% else %}
            <div class="product-image product-placeholder" style="height: 400px;"></div>
            {% endif %}
        </div>
        <div class="product-detail-info">
            <h1>{{ entry.title }}</h1>
            {% if entry.getMeta('price') %}
            <div class="product-price-large">{{ entry.getMeta('currency')|default('$') }} {{ entry.getMeta('price') }}</div>
            {% endif %}
            {% if entry.getMeta('in_stock') %}
            <span class="in-stock">In Stock</span>
            {% else %}
            <span class="out-of-stock">Out of Stock</span>
            {% endif %}
            {% if entry.getMeta('category') %}
            <div class="product-category">
                Category: <a href="/categories/{{ entry.getMeta('category') }}">{{ entry.getMeta('category') }}</a>
            </div>
            {% endif %}
        </div>
    </div>
    <div class="product-description prose">
        {{ markdown(entry.content) }}
    </div>
</div>
{% endblock %}
TWIG;

        $templates['templates/categories/index.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/products">Products</a></li>
    <li><a href="/categories">Categories</a></li>
</ul>
{% endblock %}

{% block title %}Categories - {{ parent() }}{% endblock %}

{% block content %}
<h1>Categories</h1>
<div class="categories-grid">
    {% for cat in collection('categories').locale(locale).sort('order', 'asc').get() %}
    <a href="{{ cat.url(locale) }}" class="category-card card">
        <h3>{{ cat.title }}</h3>
    </a>
    {% endfor %}
</div>
{% endblock %}
TWIG;

        $templates['templates/categories/show.twig'] = <<<'TWIG'
{% extends "_layouts/base.twig" %}

{% block nav %}
<ul>
    <li><a href="/">Home</a></li>
    <li><a href="/products">Products</a></li>
    <li><a href="/categories">Categories</a></li>
</ul>
{% endblock %}

{% block title %}{{ entry.title }} - Categories - {{ parent() }}{% endblock %}

{% block content %}
<h1>{{ entry.title }}</h1>
<div class="prose">
    {{ markdown(entry.content) }}
</div>

<h2>Products in this category</h2>
<div class="product-grid">
    {% for product in collection('products').locale(locale).sort('order', 'asc').get() %}
        {% if product.getMeta('category') == entry.slug %}
        <div class="product-card card">
            {% if product.getMeta('image') %}
            <div class="product-image" style="background-image: url('{{ product.getMeta('image') }}')"></div>
            {% else %}
            <div class="product-image product-placeholder"></div>
            {% endif %}
            <div class="product-info">
                <h3><a href="{{ product.url(locale) }}">{{ product.title }}</a></h3>
                {% if product.getMeta('price') %}
                <span class="product-price">{{ product.getMeta('currency')|default('$') }} {{ product.getMeta('price') }}</span>
                {% endif %}
            </div>
        </div>
        {% endif %}
    {% endfor %}
</div>
{% endblock %}
TWIG;

        return $templates;
    }

    public function css(SiteProfile $profile): string
    {
        return $this->baseCss() . <<<'CSS'

/* Catalog-specific styles */
.catalog-hero {
    padding: 3rem 0;
    border-bottom: 1px solid var(--border-color);
    margin-bottom: 2rem;
}

.categories-section, .featured-products { padding: 2rem 0; }
.categories-section h2, .featured-products h2 { margin-bottom: 1.5rem; }

.categories-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 1rem;
}

.category-card {
    display: block;
    text-align: center;
    padding: 2rem;
    color: inherit;
}

.product-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1.5rem;
}

.product-card { overflow: hidden; padding: 0; }

.product-image {
    height: 200px;
    background-size: cover;
    background-position: center;
    background-color: var(--bg-color);
}

.product-placeholder {
    background: linear-gradient(135deg, #e2e8f0 0%, #f1f5f9 100%);
}

.product-info { padding: 1rem; }
.product-info h3 { margin-bottom: 0.5rem; font-size: 1rem; }

.product-price {
    font-weight: 700;
    font-size: 1.1rem;
    color: var(--primary-color);
}

.product-price-large {
    font-size: 1.5rem;
    font-weight: 700;
    color: var(--primary-color);
    margin: 0.5rem 0;
}

.in-stock { color: var(--success-color); font-size: 0.85rem; font-weight: 500; }
.out-of-stock { color: #ef4444; font-size: 0.85rem; font-weight: 500; }

.product-detail { max-width: var(--max-width); margin: 0 auto; }

.product-detail-layout {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 2rem;
    margin-bottom: 2rem;
}

.product-detail-image img { border-radius: 8px; }
.product-detail-info h1 { margin-bottom: 0.5rem; }
.product-category { margin-top: 1rem; color: var(--secondary-color); }

.prose h2 { margin: 2rem 0 1rem; }
.prose h3 { margin: 1.5rem 0 0.75rem; }
.prose p { margin-bottom: 1rem; }
.prose ul, .prose ol { margin-bottom: 1rem; padding-left: 1.5rem; }

@media (max-width: 768px) {
    .product-detail-layout { grid-template-columns: 1fr; }
    .product-grid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
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
