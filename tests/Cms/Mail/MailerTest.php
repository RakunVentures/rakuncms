<?php

declare(strict_types=1);

use Rkn\Cms\Mail\Mailer;

test('mailer can be instantiated with config', function () {
    $mailer = new Mailer([
        'from_name' => 'Test',
        'from_email' => 'test@example.com',
        'smtp_host' => 'localhost',
        'smtp_port' => 587,
    ]);

    expect($mailer)->toBeInstanceOf(Mailer::class);
});

test('mailer can be instantiated with empty config', function () {
    $mailer = new Mailer([]);

    expect($mailer)->toBeInstanceOf(Mailer::class);
});

test('mailer accepts all smtp config options', function () {
    $config = [
        'from_name' => 'Hotel',
        'from_email' => 'info@hotel.com',
        'smtp_host' => 'smtp.hotel.com',
        'smtp_port' => 465,
        'smtp_encryption' => 'ssl',
        'smtp_username' => 'user@hotel.com',
        'smtp_password' => 'secret',
    ];

    $mailer = new Mailer($config);

    expect($mailer)->toBeInstanceOf(Mailer::class);
});
