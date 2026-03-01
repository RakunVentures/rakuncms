<?php

declare(strict_types=1);

use Rkn\Cms\Integrations\MailchimpFormGenerator;

test('returns empty when no embed URL configured', function () {
    $gen = new MailchimpFormGenerator();
    expect($gen->render())->toBe('');
});

test('returns empty when embed URL is empty string', function () {
    $gen = new MailchimpFormGenerator(['mailchimp_embed_url' => '']);
    expect($gen->render())->toBe('');
});

test('renders form with action URL', function () {
    $gen = new MailchimpFormGenerator([
        'mailchimp_embed_url' => 'https://example.us1.list-manage.com/subscribe/post?u=XXX&id=YYY',
    ]);
    $html = $gen->render();

    expect($html)->toContain('action="https://example.us1.list-manage.com/subscribe/post?u=XXX&amp;id=YYY"');
});

test('uses POST method', function () {
    $gen = new MailchimpFormGenerator([
        'mailchimp_embed_url' => 'https://example.us1.list-manage.com/subscribe/post',
    ]);
    $html = $gen->render();

    expect($html)->toContain('method="post"');
});

test('has target blank', function () {
    $gen = new MailchimpFormGenerator([
        'mailchimp_embed_url' => 'https://example.us1.list-manage.com/subscribe/post',
    ]);
    $html = $gen->render();

    expect($html)->toContain('target="_blank"');
});

test('renders email input with name EMAIL', function () {
    $gen = new MailchimpFormGenerator([
        'mailchimp_embed_url' => 'https://example.us1.list-manage.com/subscribe/post',
    ]);
    $html = $gen->render();

    expect($html)->toContain('name="EMAIL"');
    expect($html)->toContain('type="email"');
});

test('uses custom placeholder', function () {
    $gen = new MailchimpFormGenerator([
        'mailchimp_embed_url' => 'https://example.us1.list-manage.com/subscribe/post',
        'placeholder' => 'Your email address',
    ]);
    $html = $gen->render();

    expect($html)->toContain('placeholder="Your email address"');
});

test('uses default placeholder', function () {
    $gen = new MailchimpFormGenerator([
        'mailchimp_embed_url' => 'https://example.us1.list-manage.com/subscribe/post',
    ]);
    $html = $gen->render();

    expect($html)->toContain('placeholder="Tu email"');
});

test('uses custom button text', function () {
    $gen = new MailchimpFormGenerator([
        'mailchimp_embed_url' => 'https://example.us1.list-manage.com/subscribe/post',
        'button_text' => 'Subscribe Now',
    ]);
    $html = $gen->render();

    expect($html)->toContain('Subscribe Now');
});

test('includes honeypot anti-bot field', function () {
    $gen = new MailchimpFormGenerator([
        'mailchimp_embed_url' => 'https://example.us1.list-manage.com/subscribe/post',
    ]);
    $html = $gen->render();

    expect($html)->toContain('aria-hidden="true"');
    expect($html)->toContain('b_honeypot');
    expect($html)->toContain('left:-5000px');
});
