<?php

declare(strict_types=1);

use Rkn\Cms\I18n\Translator;

beforeEach(function () {
    $this->langDir = sys_get_temp_dir() . '/rkn_lang_' . uniqid();
    mkdir($this->langDir . '/es', 0755, true);
    mkdir($this->langDir . '/en', 0755, true);

    file_put_contents($this->langDir . '/es/messages.php', "<?php return [\n"
        . "    'nav.home' => 'Inicio',\n"
        . "    'nav.about' => 'Nosotros',\n"
        . "    'greeting' => 'Hola :name',\n"
        . "];\n");

    file_put_contents($this->langDir . '/en/messages.php', "<?php return [\n"
        . "    'nav.home' => 'Home',\n"
        . "    'nav.about' => 'About',\n"
        . "    'greeting' => 'Hello :name',\n"
        . "];\n");
});

afterEach(function () {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->langDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($this->langDir);
});

test('translates key in current locale', function () {
    $translator = new Translator($this->langDir, 'es');

    expect($translator->get('nav.home'))->toBe('Inicio');
});

test('translates key in english locale', function () {
    $translator = new Translator($this->langDir, 'en');

    expect($translator->get('nav.home'))->toBe('Home');
});

test('replaces params in translation', function () {
    $translator = new Translator($this->langDir, 'es');

    expect($translator->get('greeting', ['name' => 'Juan']))->toBe('Hola Juan');
});

test('falls back to fallback locale', function () {
    $translator = new Translator($this->langDir, 'fr', 'es');

    expect($translator->get('nav.home'))->toBe('Inicio');
});

test('returns key when no translation exists', function () {
    $translator = new Translator($this->langDir, 'es');

    expect($translator->get('missing.key'))->toBe('missing.key');
});

test('has checks translation existence', function () {
    $translator = new Translator($this->langDir, 'es');

    expect($translator->has('nav.home'))->toBeTrue();
    expect($translator->has('missing'))->toBeFalse();
});

test('can change locale', function () {
    $translator = new Translator($this->langDir, 'es');
    expect($translator->get('nav.home'))->toBe('Inicio');

    $translator->setLocale('en');
    expect($translator->get('nav.home'))->toBe('Home');
});
