<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\XmlRpcDecoder;

$fixturesDir = __DIR__ . '/../../../Fixtures/plesk-xmlrpc';

describe('XmlRpcDecoder::parse()', function () use ($fixturesDir): void {
    it('parses subscription-info-success.xml correctly', function () use ($fixturesDir): void {
        $xml = file_get_contents("{$fixturesDir}/subscription-info-success.xml");
        $data = XmlRpcDecoder::parse($xml ?: '');

        expect($data)->toHaveKey('subscription');
        expect($data['subscription'])->toHaveKey('get');

        // Status is wrapped as ['_text' => 'ok'] by the decoder
        $status = $data['subscription']['get']['result']['status'] ?? null;
        $statusText = is_array($status) ? ($status['_text'] ?? '') : (string) $status;
        expect($statusText)->toBe('ok');
    });

    it('parses subscription-info-no-shell.xml correctly', function () use ($fixturesDir): void {
        $xml = file_get_contents("{$fixturesDir}/subscription-info-no-shell.xml");
        $data = XmlRpcDecoder::parse($xml ?: '');

        expect($data)->toHaveKey('subscription');
    });

    it('parses domain-get-php-fpm.xml correctly', function () use ($fixturesDir): void {
        $xml = file_get_contents("{$fixturesDir}/domain-get-php-fpm.xml");
        $data = XmlRpcDecoder::parse($xml ?: '');

        expect($data)->toHaveKey('domain');
    });

    it('parses git-info-with-webhook.xml correctly', function () use ($fixturesDir): void {
        $xml = file_get_contents("{$fixturesDir}/git-info-with-webhook.xml");
        $data = XmlRpcDecoder::parse($xml ?: '');

        expect($data)->toHaveKey('extension');
    });

    it('parses git-list-empty.xml correctly', function () use ($fixturesDir): void {
        $xml = file_get_contents("{$fixturesDir}/git-list-empty.xml");
        $data = XmlRpcDecoder::parse($xml ?: '');

        expect($data)->toHaveKey('extension');
    });

    it('throws InvalidArgumentException on Plesk error status in XML', function () use ($fixturesDir): void {
        $xml = file_get_contents("{$fixturesDir}/error-403.xml");

        expect(fn () => XmlRpcDecoder::parse($xml ?: ''))
            ->toThrow(\InvalidArgumentException::class, 'Plesk API error');
    });

    it('throws InvalidArgumentException on empty string', function (): void {
        expect(fn () => XmlRpcDecoder::parse(''))
            ->toThrow(\InvalidArgumentException::class, 'empty');
    });

    it('throws InvalidArgumentException on malformed XML', function (): void {
        expect(fn () => XmlRpcDecoder::parse('<<NOT VALID XML>'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('throws InvalidArgumentException on server-error-500.xml', function () use ($fixturesDir): void {
        $xml = file_get_contents("{$fixturesDir}/server-error-500.xml");

        expect(fn () => XmlRpcDecoder::parse($xml ?: ''))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('parses domain-get-custom-root.xml correctly', function () use ($fixturesDir): void {
        $xml = file_get_contents("{$fixturesDir}/domain-get-custom-root.xml");
        $data = XmlRpcDecoder::parse($xml ?: '');

        expect($data)->toHaveKey('domain');
    });

    it('parses domain-get-php74-legacy.xml correctly', function () use ($fixturesDir): void {
        $xml = file_get_contents("{$fixturesDir}/domain-get-php74-legacy.xml");
        $data = XmlRpcDecoder::parse($xml ?: '');

        expect($data)->toHaveKey('domain');
    });
});

describe('XmlRpcDecoder::extractText()', function (): void {
    it('extracts text from _text-wrapped node', function (): void {
        $data = ['key' => ['_text' => 'hello']];
        expect(XmlRpcDecoder::extractText($data, 'key'))->toBe('hello');
    });

    it('extracts text from direct string value', function (): void {
        $data = ['key' => 'direct'];
        expect(XmlRpcDecoder::extractText($data, 'key'))->toBe('direct');
    });

    it('returns null when key does not exist', function (): void {
        $data = ['other' => 'value'];
        expect(XmlRpcDecoder::extractText($data, 'missing'))->toBeNull();
    });

    it('traverses nested keys', function (): void {
        $data = ['a' => ['b' => ['_text' => 'deep']]];
        expect(XmlRpcDecoder::extractText($data, 'a', 'b'))->toBe('deep');
    });

    it('returns null when intermediate key is missing', function (): void {
        $data = ['a' => ['x' => 'value']];
        expect(XmlRpcDecoder::extractText($data, 'a', 'b', 'c'))->toBeNull();
    });
});
