<?php

declare(strict_types=1);

use Rkn\Cms\Seo\ConsentManager;

test('returns empty when no tracking configured', function () {
    $manager = new ConsentManager();
    expect($manager->render())->toBe('');
});

test('hasTracking detects google analytics', function () {
    $manager = new ConsentManager(['google_analytics' => 'G-TEST123']);
    expect($manager->hasTracking())->toBeTrue();
});

test('hasTracking detects facebook pixel', function () {
    $manager = new ConsentManager(['facebook_pixel' => '1234567890']);
    expect($manager->hasTracking())->toBeTrue();
});

test('hasTracking returns false when no tracking', function () {
    $manager = new ConsentManager();
    expect($manager->hasTracking())->toBeFalse();
});

test('banner contains accept and reject buttons', function () {
    $manager = new ConsentManager(['google_analytics' => 'G-TEST']);
    $html = $manager->render();

    expect($html)->toContain('rkn-consent-accept');
    expect($html)->toContain('rkn-consent-reject');
    expect($html)->toContain('Aceptar');
    expect($html)->toContain('Rechazar');
});

test('GA script includes tracking ID', function () {
    $manager = new ConsentManager(['google_analytics' => 'G-MYID123']);
    $html = $manager->render();

    expect($html)->toContain('G-MYID123');
    expect($html)->toContain('googletagmanager.com/gtag/js?id=G-MYID123');
    expect($html)->toContain("gtag('config','G-MYID123')");
});

test('Pixel script includes pixel ID', function () {
    $manager = new ConsentManager(['facebook_pixel' => '9876543210']);
    $html = $manager->render();

    expect($html)->toContain('9876543210');
    expect($html)->toContain("fbq('init','9876543210')");
    expect($html)->toContain('connect.facebook.net');
});

test('CSS inline is included in banner', function () {
    $manager = new ConsentManager(['google_analytics' => 'G-TEST']);
    $html = $manager->render();

    expect($html)->toContain('position:fixed');
    expect($html)->toContain('bottom:0');
    expect($html)->toContain('z-index:9999');
});

test('banner text is configurable', function () {
    $manager = new ConsentManager([
        'google_analytics' => 'G-TEST',
        'consent_text' => 'We use cookies for analytics.',
    ]);
    $html = $manager->render();

    expect($html)->toContain('We use cookies for analytics.');
});

test('uses default consent text', function () {
    $manager = new ConsentManager(['google_analytics' => 'G-TEST']);
    $html = $manager->render();

    expect($html)->toContain('Este sitio utiliza cookies para mejorar la experiencia.');
});

test('does not render GA when only pixel configured', function () {
    $manager = new ConsentManager(['facebook_pixel' => '123']);
    $html = $manager->render();

    expect($html)->not->toContain('googletagmanager');
    expect($html)->toContain('fbq');
});

test('does not render pixel when only GA configured', function () {
    $manager = new ConsentManager(['google_analytics' => 'G-TEST']);
    $html = $manager->render();

    expect($html)->not->toContain('fbq');
    expect($html)->toContain('googletagmanager');
});

test('analytics templates use template data-consent tags', function () {
    $manager = new ConsentManager(['google_analytics' => 'G-TEST']);
    $html = $manager->render();

    expect($html)->toContain('<template data-consent="analytics">');
    expect($html)->toContain('</template>');
});

test('consent script handles localStorage logic', function () {
    $manager = new ConsentManager(['google_analytics' => 'G-TEST']);
    $html = $manager->render();

    expect($html)->toContain("localStorage.getItem('rkn_consent')");
    expect($html)->toContain("localStorage.setItem('rkn_consent','accepted')");
    expect($html)->toContain("localStorage.setItem('rkn_consent','rejected')");
});

test('renderAnalyticsOnly returns scripts without banner', function () {
    $manager = new ConsentManager(['google_analytics' => 'G-TEST']);
    $html = $manager->renderAnalyticsOnly();

    expect($html)->toContain('googletagmanager');
    expect($html)->not->toContain('rkn-consent');
    expect($html)->not->toContain('Aceptar');
    expect($html)->not->toContain('<template');
});

test('renderAnalyticsOnly returns empty when no tracking', function () {
    $manager = new ConsentManager();
    expect($manager->renderAnalyticsOnly())->toBe('');
});
