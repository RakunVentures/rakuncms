<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\CliResult;

describe('CliResult', function (): void {
    it('isSuccess returns true when code is 0', function (): void {
        $result = new CliResult(0, 'ok', '');
        expect($result->isSuccess())->toBeTrue();
    });

    it('isSuccess returns false when code is non-zero', function (): void {
        $result = new CliResult(1, '', 'error');
        expect($result->isSuccess())->toBeFalse();
    });

    it('exposes readonly code, stdout, stderr', function (): void {
        $result = new CliResult(0, 'hello', 'warn');
        expect($result->code)->toBe(0);
        expect($result->stdout)->toBe('hello');
        expect($result->stderr)->toBe('warn');
    });
});
