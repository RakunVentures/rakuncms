<?php

declare(strict_types=1);

use Tests\TestCase;

if (class_exists(\Dotenv\Dotenv::class) && file_exists(dirname(__DIR__) . '/.env')) {
    \Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

uses(TestCase::class)->in('Unit', 'Cms', 'Framework', 'Integration');
