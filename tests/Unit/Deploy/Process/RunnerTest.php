<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\Process\ProcessResult;
use Rkn\Cms\Deploy\Process\Runner;

describe('ProcessResult', function (): void {
    it('is a success when exitCode is 0', function (): void {
        $result = new ProcessResult(
            exitCode: 0,
            stdout: 'hello',
            stderr: '',
            command: ['echo', 'hello'],
            duration: 0.01,
        );
        expect($result->isSuccess())->toBeTrue();
        expect($result->exitCode)->toBe(0);
        expect($result->stdout)->toBe('hello');
    });

    it('is a failure when exitCode is non-zero', function (): void {
        $result = new ProcessResult(
            exitCode: 1,
            stdout: '',
            stderr: 'error',
            command: ['false'],
            duration: 0.01,
        );
        expect($result->isSuccess())->toBeFalse();
    });
});

describe('Runner — real process execution', function (): void {
    $basePath = sys_get_temp_dir();

    it('runs echo and captures stdout', function () use ($basePath): void {
        $result = (new Runner($basePath))
            ->run(['echo', 'hello world'])
            ->execute();

        expect($result->isSuccess())->toBeTrue();
        expect(trim($result->stdout))->toBe('hello world');
        expect($result->stderr)->toBe('');
        expect($result->duration)->toBeGreaterThan(0.0);
        expect($result->command)->toBe(['echo', 'hello world']);
    });

    it('captures stderr separately from stdout', function () use ($basePath): void {
        // Use sh -c to write to both stdout and stderr
        $result = (new Runner($basePath))
            ->run(['sh', '-c', 'echo out; echo err >&2'])
            ->execute();

        expect($result->isSuccess())->toBeTrue();
        expect(trim($result->stdout))->toBe('out');
        expect(trim($result->stderr))->toBe('err');
    });

    it('returns non-zero exit code for failing command', function () use ($basePath): void {
        $result = (new Runner($basePath))
            ->run(['false'])
            ->execute();

        expect($result->isSuccess())->toBeFalse();
        expect($result->exitCode)->not->toBe(0);
    });

    it('returns exitCode 124 on timeout', function () use ($basePath): void {
        $result = (new Runner($basePath))
            ->run(['sleep', '10'])
            ->withTimeout(1)
            ->execute();

        expect($result->isSuccess())->toBeFalse();
        // 124 is our sentinel for timeout
        expect($result->exitCode)->toBe(124);
    });

    it('passes env vars to the process', function () use ($basePath): void {
        $result = (new Runner($basePath))
            ->run(['sh', '-c', 'echo $RAKUN_TEST_VAR'])
            ->withEnv(['RAKUN_TEST_VAR' => 'hello_env'])
            ->execute();

        expect($result->isSuccess())->toBeTrue();
        expect(trim($result->stdout))->toBe('hello_env');
    });

    it('uses withWorkingDir as the cwd', function (): void {
        $tmpDir = sys_get_temp_dir();
        $result = (new Runner('/some/other/path'))
            ->run(['pwd'])
            ->withWorkingDir($tmpDir)
            ->execute();

        expect($result->isSuccess())->toBeTrue();
        // Realpath to handle macOS /private/var symlink
        expect(rtrim($result->stdout))->toBe(realpath($tmpDir));
    });

    it('passes stdin via withInput', function () use ($basePath): void {
        $result = (new Runner($basePath))
            ->run(['cat'])
            ->withInput('piped input')
            ->execute();

        expect($result->isSuccess())->toBeTrue();
        expect($result->stdout)->toBe('piped input');
    });

    it('invokes logger callback for output lines', function () use ($basePath): void {
        $lines = [];
        $logger = function (string $line) use (&$lines): void {
            $lines[] = $line;
        };

        (new Runner($basePath, $logger))
            ->run(['echo', 'logged line'])
            ->execute();

        expect(implode('', $lines))->toContain('logged line');
    });

    it('mustExecute throws RuntimeException on failure', function () use ($basePath): void {
        expect(fn() =>
            (new Runner($basePath))
                ->run(['false'])
                ->mustExecute()
        )->toThrow(\RuntimeException::class);
    });

    it('mustExecute returns ProcessResult on success', function () use ($basePath): void {
        $result = (new Runner($basePath))
            ->run(['echo', 'ok'])
            ->mustExecute();

        expect($result)->toBeInstanceOf(ProcessResult::class);
        expect($result->isSuccess())->toBeTrue();
    });
});

describe('Runner::resolveComposer()', function (): void {
    $basePath = sys_get_temp_dir();

    it('returns configured binary when explicitly set', function () use ($basePath): void {
        $cmd = Runner::resolveComposer($basePath, '/usr/local/bin/composer');
        expect($cmd)->toBe(['/usr/local/bin/composer']);
    });

    it('returns COMPOSER env var when set', function () use ($basePath): void {
        $saved = getenv('COMPOSER');
        putenv('COMPOSER=/tmp/composer.phar');
        try {
            $cmd = Runner::resolveComposer($basePath);
            expect($cmd)->toBe(['/tmp/composer.phar']);
        } finally {
            if ($saved !== false) {
                putenv("COMPOSER={$saved}");
            } else {
                putenv('COMPOSER');
            }
        }
    });

    it('prefers configured over COMPOSER env var', function () use ($basePath): void {
        $saved = getenv('COMPOSER');
        putenv('COMPOSER=/tmp/env-composer.phar');
        try {
            $cmd = Runner::resolveComposer($basePath, '/explicit/composer');
            expect($cmd)->toBe(['/explicit/composer']);
        } finally {
            if ($saved !== false) {
                putenv("COMPOSER={$saved}");
            } else {
                putenv('COMPOSER');
            }
        }
    });

    it('returns composer from PATH when no env override', function () use ($basePath): void {
        // Ensure no COMPOSER env override
        $saved = getenv('COMPOSER');
        putenv('COMPOSER');

        try {
            // `composer` or `herd composer` should be available in the dev environment
            $cmd = Runner::resolveComposer($basePath);
            // Either ['composer'] or ['herd', 'composer'] is valid
            expect($cmd)->toBeArray();
            expect($cmd)->not->toBeEmpty();
        } finally {
            if ($saved !== false) {
                putenv("COMPOSER={$saved}");
            }
        }
    });
});
