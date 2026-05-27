<?php

declare(strict_types=1);

use Tests\Helpers\ContainerHelper;

describe('ContainerHelper', function () {

    it('isAvailable() returns a bool without throwing', function () {
        // Must not throw regardless of whether container is installed or running
        $result = ContainerHelper::isAvailable();
        expect($result)->toBeBool();
    });

    it('pickFreePort() returns an integer greater than 1024', function () {
        $helper = new ContainerHelper();
        $port   = $helper->pickFreePort();

        expect($port)->toBeInt()
            ->and($port)->toBeGreaterThan(1024);
    });

    it('pickFreePort() returns a port that can immediately be rebound with stream_socket_server', function () {
        $helper = new ContainerHelper();
        $port   = $helper->pickFreePort();

        // The port should be free right after pickFreePort() released it.
        // Bind again to verify it is usable (not in TIME_WAIT, etc.).
        $socket = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
        expect($socket)->not->toBeFalse(
            "Expected port {$port} to be bindable, but got [{$errno}] {$errstr}"
        );
        if ($socket !== false) {
            fclose($socket);
        }
    });

    it('pull(alpine:3.20) succeeds when container is available', function () {
        if (!ContainerHelper::isAvailable()) {
            $this->markTestSkipped(
                'apple/container system is not running. '
                . 'Start it with: container system start'
            );
        }

        $helper = new ContainerHelper();
        // Must not throw
        $helper->pull('alpine:3.20');
        expect(true)->toBeTrue(); // Pull completed without exception
    });

});
