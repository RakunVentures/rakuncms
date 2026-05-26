<?php

declare(strict_types=1);

use Rkn\Cms\Deploy\PleskApi\XmlRpcEncoder;

describe('XmlRpcEncoder::subscriptionGet()', function (): void {
    it('produces valid XML with the correct packet version', function (): void {
        $xml = XmlRpcEncoder::subscriptionGet('example.com');

        expect($xml)->toContain('version="1.6.9.0"');
        expect($xml)->toContain('<subscription>');
        expect($xml)->toContain('<get>');
        expect($xml)->toContain('example.com');
    });

    it('escapes domain names with XML special characters', function (): void {
        $xml = XmlRpcEncoder::subscriptionGet('test<domain>&co.com');

        expect($xml)->toContain('test&lt;domain&gt;&amp;co.com');
        expect($xml)->not->toContain('<domain>');
    });

    it('produces well-formed XML', function (): void {
        $xml = XmlRpcEncoder::subscriptionGet('example.com');
        $doc = simplexml_load_string($xml);
        expect($doc)->not->toBeFalse();
    });
});

describe('XmlRpcEncoder::domainGet()', function (): void {
    it('produces valid XML for domain/get operation', function (): void {
        $xml = XmlRpcEncoder::domainGet('mysite.com');

        expect($xml)->toContain('<domain>');
        expect($xml)->toContain('<get>');
        expect($xml)->toContain('mysite.com');
        expect($xml)->toContain('<hosting/>');
    });

    it('produces well-formed XML', function (): void {
        $xml = XmlRpcEncoder::domainGet('test.com');
        $doc = simplexml_load_string($xml);
        expect($doc)->not->toBeFalse();
    });
});

describe('XmlRpcEncoder::extensionCall()', function (): void {
    it('produces valid XML for extension/call with params', function (): void {
        $xml = XmlRpcEncoder::extensionCall('git', ['cmd' => '--list', 'domain' => 'example.com']);

        expect($xml)->toContain('<extension>');
        expect($xml)->toContain('<call>');
        expect($xml)->toContain('<name>git</name>');
        expect($xml)->toContain('--list');
        expect($xml)->toContain('example.com');
    });

    it('produces valid XML for extension/call with no params', function (): void {
        $xml = XmlRpcEncoder::extensionCall('git');

        expect($xml)->toContain('<name>git</name>');
        expect($xml)->toContain('<call>');
    });

    it('escapes param values with XML special characters', function (): void {
        $xml = XmlRpcEncoder::extensionCall('git', ['path' => '/var/www/test & stuff <here>']);

        expect($xml)->toContain('test &amp; stuff &lt;here&gt;');
        expect($xml)->not->toContain('<here>');
    });

    it('produces well-formed XML', function (): void {
        $xml = XmlRpcEncoder::extensionCall('git', ['cmd' => '--info']);
        $doc = simplexml_load_string($xml);
        expect($doc)->not->toBeFalse();
    });
});

describe('XmlRpcEncoder::domainSetHosting()', function (): void {
    it('produces valid XML for domain/set with shell property', function (): void {
        $xml = XmlRpcEncoder::domainSetHosting('example.com', ['shell' => '/bin/bash']);

        expect($xml)->toContain('<domain>');
        expect($xml)->toContain('<set>');
        expect($xml)->toContain('<name>shell</name>');
        expect($xml)->toContain('<value>/bin/bash</value>');
    });

    it('handles multiple properties', function (): void {
        $xml = XmlRpcEncoder::domainSetHosting('example.com', [
            'shell' => '/bin/bash',
            'php_handler_id' => 'plesk-php82-fpm',
        ]);

        expect($xml)->toContain('shell');
        expect($xml)->toContain('php_handler_id');
        expect($xml)->toContain('plesk-php82-fpm');
    });

    it('produces well-formed XML', function (): void {
        $xml = XmlRpcEncoder::domainSetHosting('test.com', ['shell' => '/bin/bash']);
        $doc = simplexml_load_string($xml);
        expect($doc)->not->toBeFalse();
    });
});

describe('XmlRpcEncoder::packet()', function (): void {
    it('wraps content in packet envelope', function (): void {
        $xml = XmlRpcEncoder::packet('<custom><element>value</element></custom>');

        expect($xml)->toContain('<packet version="1.6.9.0">');
        expect($xml)->toContain('<custom>');
        expect($xml)->toContain('<element>value</element>');
    });
});
