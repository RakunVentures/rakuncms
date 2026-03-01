<?php

declare(strict_types=1);

use Rkn\Cms\Integrations\WhatsAppButtonGenerator;

test('returns empty when no phone configured', function () {
    $gen = new WhatsAppButtonGenerator();
    expect($gen->render())->toBe('');
});

test('returns empty when phone is empty string', function () {
    $gen = new WhatsAppButtonGenerator(['phone' => '']);
    expect($gen->render())->toBe('');
});

test('generates wa.me URL with phone number', function () {
    $gen = new WhatsAppButtonGenerator(['phone' => '+521234567890']);
    $html = $gen->render();

    expect($html)->toContain('https://wa.me/+521234567890');
});

test('sanitizes phone number removing spaces dashes and parentheses', function () {
    $gen = new WhatsAppButtonGenerator(['phone' => '+52 (123) 456-7890']);
    $html = $gen->render();

    expect($html)->toContain('https://wa.me/+521234567890');
});

test('includes pre-filled message URL-encoded', function () {
    $gen = new WhatsAppButtonGenerator([
        'phone' => '+521234567890',
        'message' => 'Hola, me interesa info',
    ]);
    $html = $gen->render();

    expect($html)->toContain('?text=Hola%2C%20me%20interesa%20info');
});

test('does not include text param when message is empty', function () {
    $gen = new WhatsAppButtonGenerator(['phone' => '+521234567890']);
    $html = $gen->render();

    expect($html)->not->toContain('?text=');
});

test('renders with bottom-right position by default', function () {
    $gen = new WhatsAppButtonGenerator(['phone' => '+521234567890']);
    $html = $gen->render();

    expect($html)->toContain('right:20px;');
    expect($html)->not->toContain('left:20px;');
});

test('renders with bottom-left position when configured', function () {
    $gen = new WhatsAppButtonGenerator([
        'phone' => '+521234567890',
        'position' => 'bottom-left',
    ]);
    $html = $gen->render();

    expect($html)->toContain('left:20px;');
    expect($html)->not->toContain('right:20px;');
});

test('uses custom color', function () {
    $gen = new WhatsAppButtonGenerator([
        'phone' => '+521234567890',
        'color' => '#FF0000',
    ]);
    $html = $gen->render();

    expect($html)->toContain('background-color:#FF0000;');
});

test('uses default WhatsApp green color', function () {
    $gen = new WhatsAppButtonGenerator(['phone' => '+521234567890']);
    $html = $gen->render();

    expect($html)->toContain('background-color:#25D366;');
});

test('renders SVG icon', function () {
    $gen = new WhatsAppButtonGenerator(['phone' => '+521234567890']);
    $html = $gen->render();

    expect($html)->toContain('<svg');
    expect($html)->toContain('</svg>');
    expect($html)->toContain('viewBox="0 0 24 24"');
});

test('escapes XSS in phone and message', function () {
    $gen = new WhatsAppButtonGenerator([
        'phone' => '+521234567890',
        'message' => '<script>alert("xss")</script>',
    ]);
    $html = $gen->render();

    expect($html)->not->toContain('<script>alert');
    expect($html)->toContain('noopener noreferrer');
});
