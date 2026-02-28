<?php

declare(strict_types=1);

use Rkn\Cms\Middleware\ApiAuthMiddleware;

test('hasPermission returns true for matching permission', function () {
    expect(ApiAuthMiddleware::hasPermission(['read', 'write'], 'read'))->toBeTrue();
    expect(ApiAuthMiddleware::hasPermission(['read', 'write'], 'write'))->toBeTrue();
});

test('hasPermission returns false for missing permission', function () {
    expect(ApiAuthMiddleware::hasPermission(['read'], 'write'))->toBeFalse();
    expect(ApiAuthMiddleware::hasPermission([], 'read'))->toBeFalse();
});

test('admin permission grants all permissions', function () {
    expect(ApiAuthMiddleware::hasPermission(['admin'], 'read'))->toBeTrue();
    expect(ApiAuthMiddleware::hasPermission(['admin'], 'write'))->toBeTrue();
    expect(ApiAuthMiddleware::hasPermission(['admin'], 'media'))->toBeTrue();
});

test('hasPermission is case-sensitive', function () {
    expect(ApiAuthMiddleware::hasPermission(['READ'], 'read'))->toBeFalse();
});
