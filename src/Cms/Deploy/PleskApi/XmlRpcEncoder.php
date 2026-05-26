<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

/**
 * Builds Plesk XML API request packets.
 *
 * Plesk uses a proprietary XML schema (not standard XML-RPC).
 * Envelope: <packet version="1.6.9.0"> containing one or more operator elements.
 *
 * Reference: https://docs.plesk.com/en-US/obsidian/api-rpc/introduction.79358/
 */
final class XmlRpcEncoder
{
    private const PACKET_VERSION = '1.6.9.0';

    /**
     * Build a packet to get subscription info (hosting properties) for a domain.
     */
    public static function subscriptionGet(string $domain): string
    {
        $escapedDomain = htmlspecialchars($domain, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $v = self::PACKET_VERSION;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <packet version="{$v}">
              <subscription>
                <get>
                  <filter>
                    <login>{$escapedDomain}</login>
                  </filter>
                  <dataset>
                    <hosting/>
                  </dataset>
                </get>
              </subscription>
            </packet>
            XML;
    }

    /**
     * Build a packet to get domain hosting properties (www_root, php handler, etc.).
     */
    public static function domainGet(string $domain): string
    {
        $escapedDomain = htmlspecialchars($domain, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $v = self::PACKET_VERSION;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <packet version="{$v}">
              <domain>
                <get>
                  <filter>
                    <name>{$escapedDomain}</name>
                  </filter>
                  <dataset>
                    <hosting/>
                  </dataset>
                </get>
              </domain>
            </packet>
            XML;
    }

    /**
     * Build a packet to call a Plesk extension (e.g. git).
     *
     * @param array<string, string> $params Extension-specific parameters
     */
    public static function extensionCall(string $name, array $params = []): string
    {
        $escapedName = htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $v = self::PACKET_VERSION;

        $paramLines = '';
        foreach ($params as $key => $value) {
            $escapedKey = htmlspecialchars($key, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $escapedValue = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $paramLines .= "    <param name=\"{$escapedKey}\">{$escapedValue}</param>\n";
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <packet version="{$v}">
              <extension>
                <call>
                  <name>{$escapedName}</name>
            {$paramLines}    </call>
              </extension>
            </packet>
            XML;
    }

    /**
     * Build a packet to update domain hosting properties (e.g., shell access).
     *
     * @param array<string, string> $properties vrt_hst property key => value pairs
     */
    public static function domainSetHosting(string $domain, array $properties): string
    {
        $escapedDomain = htmlspecialchars($domain, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $v = self::PACKET_VERSION;

        $propLines = '';
        foreach ($properties as $key => $value) {
            $escapedKey = htmlspecialchars($key, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $escapedValue = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $propLines .= "          <property>\n";
            $propLines .= "            <name>{$escapedKey}</name>\n";
            $propLines .= "            <value>{$escapedValue}</value>\n";
            $propLines .= "          </property>\n";
        }

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <packet version="{$v}">
              <domain>
                <set>
                  <filter>
                    <name>{$escapedDomain}</name>
                  </filter>
                  <values>
                    <hosting>
                      <vrt_hst>
            {$propLines}        </vrt_hst>
                    </hosting>
                  </values>
                </set>
              </domain>
            </packet>
            XML;
    }

    /**
     * Wrap raw XML content in the standard Plesk packet envelope.
     * Useful when building custom operator calls not covered by named helpers.
     */
    public static function packet(string $innerXml): string
    {
        $v = self::PACKET_VERSION;

        return <<<XML
            <?xml version="1.0" encoding="UTF-8"?>
            <packet version="{$v}">
            {$innerXml}
            </packet>
            XML;
    }
}
