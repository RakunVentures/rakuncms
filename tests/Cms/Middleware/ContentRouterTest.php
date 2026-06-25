<?php

declare(strict_types=1);

use Rkn\Cms\Middleware\ContentRouter;
use Rkn\Framework\Application;

/**
 * default_template debe resolverse en AMBOS layouts de config: plano (`collections`)
 * y namespaced por archivo (`rakun.collections`, el de fiancee). El bug: ContentRouter
 * leía solo `config('collections')` → en el layout split el mapa salía vacío y las
 * entries sin `template:` en frontmatter (revistas nuevas del panel) caían al fallback
 * page.twig en vez del revista-reader → el lector no abría.
 */

afterEach(function () {
    $prop = new ReflectionProperty(Application::class, 'instance');
    $prop->setAccessible(true);
    $prop->setValue(null, null);
});

test('collectionDefaultTemplates lee default_template del layout rakun.collections (split)', function () {
    $dir = $this->makeTempDir();
    mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/.env', '');
    file_put_contents($dir . '/config/rakun.yaml', <<<'YAML'
    site:
      default_locale: es
    collections:
      revista:
        name: "Revistas"
        default_template: revista-reader
      banners:
        name: "Banners"
        default_template: null
      blog:
        name: "Blog"
    YAML);
    new Application($dir);

    // En este layout (config/rakun.yaml) config('collections') está vacío...
    expect((array) (config('collections') ?? []))->toBe([]);

    // ...pero el mapa se construye igual desde rakun.collections.
    $map = ContentRouter::collectionDefaultTemplates();
    expect($map)->toBe(['revista' => 'revista-reader']); // banners (null) y blog (sin clave) se omiten
});

test('collectionDefaultTemplates lee del layout plano collections', function () {
    $dir = $this->makeTempDir();
    mkdir($dir . '/config', 0755, true);
    file_put_contents($dir . '/.env', '');
    // config/collections.yaml → config('collections') directo (layout plano).
    file_put_contents($dir . '/config/site.yaml', "default_locale: es\n");
    file_put_contents($dir . '/config/collections.yaml', <<<'YAML'
    revista:
      name: "Revistas"
      default_template: revista-reader
    YAML);
    new Application($dir);

    $map = ContentRouter::collectionDefaultTemplates();
    expect($map)->toBe(['revista' => 'revista-reader']);
});
