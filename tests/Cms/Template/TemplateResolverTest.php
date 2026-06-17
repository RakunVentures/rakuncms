<?php

declare(strict_types=1);

use Rkn\Cms\Content\Entry;
use Rkn\Cms\Template\TemplateNotFoundException;
use Rkn\Cms\Template\TemplateResolver;

beforeEach(function () {
    $this->templateDir = sys_get_temp_dir() . '/rkn-template-resolver-' . uniqid();
    mkdir($this->templateDir . '/_layouts', 0755, true);
    mkdir($this->templateDir . '/blog', 0755, true);

    $this->resolver = new TemplateResolver($this->templateDir);

    $this->makeEntry = function (array $overrides = []) {
        return new Entry(
            title: $overrides['title'] ?? 'Test',
            slug: $overrides['slug'] ?? 'test-slug',
            collection: $overrides['collection'] ?? 'blog',
            locale: $overrides['locale'] ?? 'es',
            file: $overrides['file'] ?? 'blog/test-slug.es.md',
            template: $overrides['template'] ?? null,
        );
    };

    $this->resolverWithDefaults = fn (array $defaults) => new TemplateResolver($this->templateDir, $defaults);
});

afterEach(function () {
    if (!is_dir($this->templateDir)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->templateDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($this->templateDir);
});

it('step 1: returns frontmatter template field when set', function () {
    $entry = ($this->makeEntry)(['template' => 'custom/special']);
    expect($this->resolver->resolve($entry))->toBe('custom/special.twig');
});

it('step 1: frontmatter template wins even when collection-specific templates exist', function () {
    file_put_contents($this->templateDir . '/blog/test-slug.twig', '');
    file_put_contents($this->templateDir . '/blog/show.twig', '');
    file_put_contents($this->templateDir . '/_layouts/blog.twig', '');

    $entry = ($this->makeEntry)(['template' => 'override']);
    expect($this->resolver->resolve($entry))->toBe('override.twig');
});

it('step 3: returns templates/{collection}/{slug}.twig when present', function () {
    file_put_contents($this->templateDir . '/blog/test-slug.twig', '');

    expect($this->resolver->resolve(($this->makeEntry)()))
        ->toBe('blog/test-slug.twig');
});

it('step 3: slug-specific template wins over collection show and layouts', function () {
    file_put_contents($this->templateDir . '/blog/test-slug.twig', '');
    file_put_contents($this->templateDir . '/blog/show.twig', '');
    file_put_contents($this->templateDir . '/_layouts/blog.twig', '');

    expect($this->resolver->resolve(($this->makeEntry)()))
        ->toBe('blog/test-slug.twig');
});

it('step 4: falls back to templates/{collection}/show.twig', function () {
    file_put_contents($this->templateDir . '/blog/show.twig', '');

    expect($this->resolver->resolve(($this->makeEntry)()))
        ->toBe('blog/show.twig');
});

it('step 4: show.twig wins over layouts but loses to slug.twig', function () {
    file_put_contents($this->templateDir . '/blog/show.twig', '');
    file_put_contents($this->templateDir . '/_layouts/blog.twig', '');

    expect($this->resolver->resolve(($this->makeEntry)()))
        ->toBe('blog/show.twig');
});

it('step 5: falls back to templates/_layouts/{collection}.twig', function () {
    file_put_contents($this->templateDir . '/_layouts/blog.twig', '');

    expect($this->resolver->resolve(($this->makeEntry)()))
        ->toBe('_layouts/blog.twig');
});

it('step 6: falls back to templates/_layouts/page.twig when nothing else matches', function () {
    expect($this->resolver->resolve(($this->makeEntry)()))
        ->toBe('_layouts/page.twig');
});

it('does not match files outside the configured templateDir', function () {
    $entry = ($this->makeEntry)(['collection' => 'pages']);
    expect($this->resolver->resolve($entry))->toBe('_layouts/page.twig');
});

it('handles entries from arbitrary collection names', function () {
    mkdir($this->templateDir . '/products', 0755, true);
    file_put_contents($this->templateDir . '/products/show.twig', '');

    $entry = ($this->makeEntry)(['collection' => 'products']);
    expect($this->resolver->resolve($entry))->toBe('products/show.twig');
});

// --- step 2: default_template from rakun.yaml -------------------------------

it('step 2: uses default_template from collection config when set', function () {
    file_put_contents($this->templateDir . '/_layouts/blog.twig', '');
    file_put_contents($this->templateDir . '/blog-post.twig', '');

    $resolver = ($this->resolverWithDefaults)(['blog' => 'blog-post']);
    expect($resolver->resolve(($this->makeEntry)()))->toBe('blog-post.twig');
});

it('step 2: default_template wins over slug, show, and layout conventions', function () {
    file_put_contents($this->templateDir . '/custom-blog.twig', '');
    file_put_contents($this->templateDir . '/blog/test-slug.twig', '');
    file_put_contents($this->templateDir . '/blog/show.twig', '');
    file_put_contents($this->templateDir . '/_layouts/blog.twig', '');

    $resolver = ($this->resolverWithDefaults)(['blog' => 'custom-blog']);
    expect($resolver->resolve(($this->makeEntry)()))->toBe('custom-blog.twig');
});

it('step 1: frontmatter template still wins over default_template', function () {
    file_put_contents($this->templateDir . '/from-config.twig', '');
    file_put_contents($this->templateDir . '/from-frontmatter.twig', '');

    $resolver = ($this->resolverWithDefaults)(['blog' => 'from-config']);
    $entry = ($this->makeEntry)(['template' => 'from-frontmatter']);

    expect($resolver->resolve($entry))->toBe('from-frontmatter.twig');
});

it('throws TemplateNotFoundException when default_template points to missing file', function () {
    $resolver = ($this->resolverWithDefaults)(['blog' => 'does-not-exist']);

    expect(fn () => $resolver->resolve(($this->makeEntry)()))
        ->toThrow(
            TemplateNotFoundException::class,
            "Collection 'blog' declares default_template 'does-not-exist'"
        );
});

it('default_template applies only to its declared collection', function () {
    file_put_contents($this->templateDir . '/blog-default.twig', '');
    file_put_contents($this->templateDir . '/_layouts/page.twig', '');

    $resolver = ($this->resolverWithDefaults)(['blog' => 'blog-default']);
    // pages collection has no default → falls back to _layouts/page.twig
    $pagesEntry = ($this->makeEntry)(['collection' => 'pages']);

    expect($resolver->resolve($pagesEntry))->toBe('_layouts/page.twig');
});

it('empty string default_template is treated as not configured', function () {
    file_put_contents($this->templateDir . '/_layouts/blog.twig', '');

    $resolver = ($this->resolverWithDefaults)(['blog' => '']);

    expect($resolver->resolve(($this->makeEntry)()))->toBe('_layouts/blog.twig');
});
