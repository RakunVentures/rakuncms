<?php

declare(strict_types=1);

use Rkn\Framework\Application;

/**
 * El engine fija la zona horaria editorial desde config `site.timezone` en el
 * bootstrap, para que las fechas de programación se interpreten y comparen en
 * esa TZ (no en el UTC por defecto de PHP en muchos hosts) — si no, las
 * publicaciones programadas se disparan con horas de desfase.
 */

function makeSiteWithTimezone(string $tzYaml): string
{
    $dir = sys_get_temp_dir() . '/rkn-tz-' . uniqid();
    mkdir($dir . '/config', 0755, true);
    mkdir($dir . '/content', 0755, true);
    file_put_contents($dir . '/config/rakun.yaml', "site:\n  default_locale: es\n{$tzYaml}");
    return $dir;
}

afterEach(function () {
    date_default_timezone_set('UTC');
    Application::reset(); // no filtrar el singleton al siguiente test
});

test('Application aplica site.timezone desde config', function () {
    date_default_timezone_set('UTC');
    $dir = makeSiteWithTimezone("  timezone: \"America/Mexico_City\"\n");

    new Application($dir);

    expect(date_default_timezone_get())->toBe('America/Mexico_City');
});

test('una TZ inválida no cambia el default de PHP', function () {
    date_default_timezone_set('UTC');
    $dir = makeSiteWithTimezone("  timezone: \"Not/AReal_Zone\"\n");

    new Application($dir);

    expect(date_default_timezone_get())->toBe('UTC');
});

test('sin timezone en config, el default no se toca', function () {
    date_default_timezone_set('UTC');
    $dir = makeSiteWithTimezone('');

    new Application($dir);

    expect(date_default_timezone_get())->toBe('UTC');
});
