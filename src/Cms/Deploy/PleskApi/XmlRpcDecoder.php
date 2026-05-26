<?php

declare(strict_types=1);

namespace Rkn\Cms\Deploy\PleskApi;

/**
 * Parses Plesk XML API response packets into PHP arrays.
 *
 * The response format follows Plesk's proprietary XML schema.
 * A successful response contains <status>ok</status>.
 * An error response contains <status>error</status> with <errcode> and <errtext>.
 *
 * Reference: https://docs.plesk.com/en-US/obsidian/api-rpc/introduction.79358/
 */
final class XmlRpcDecoder
{
    /**
     * Parse a Plesk XML-RPC response packet.
     *
     * @return array<string, mixed> Decoded structure
     * @throws \InvalidArgumentException If the XML is malformed or contains a Plesk API error
     */
    public static function parse(string $xml): array
    {
        if ($xml === '') {
            throw new \InvalidArgumentException('XML-RPC response is empty');
        }

        $previous = libxml_use_internal_errors(true);
        $doc = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($doc === false || !empty($errors)) {
            $errorMsg = !empty($errors) ? $errors[0]->message : 'unknown parse error';
            throw new \InvalidArgumentException("Malformed XML: {$errorMsg}");
        }

        $result = self::xmlToArray($doc);

        // Detect top-level Plesk error status across any operator
        self::assertNoError($result);

        return $result;
    }

    /**
     * Check if the decoded array contains a Plesk-level error and throw if so.
     *
     * Plesk errors appear as: <operator><method><status>error</status><errcode>N</errcode><errtext>...</errtext>
     * They can be nested arbitrarily deep, so we walk the tree.
     *
     * @param array<mixed> $data
     * @throws \InvalidArgumentException
     */
    public static function assertNoError(array $data): void
    {
        self::walkForError($data, '');
    }

    /** @param array<mixed> $data */
    private static function walkForError(array $data, string $path): void
    {
        // Status may be a direct string or a _text-wrapped node
        $status = null;
        if (isset($data['status'])) {
            $status = is_array($data['status'])
                ? ($data['status']['_text'] ?? null)
                : $data['status'];
        }

        if ($status === 'error') {
            $rawCode = $data['errcode'] ?? 'unknown';
            $rawText = $data['errtext'] ?? 'unknown error';

            $code = is_array($rawCode) ? ($rawCode['_text'] ?? 'unknown') : (string) $rawCode;
            $text = is_array($rawText) ? ($rawText['_text'] ?? 'unknown error') : (string) $rawText;

            throw new \InvalidArgumentException(
                "Plesk API error{$path}: [{$code}] {$text}"
            );
        }

        foreach ($data as $key => $value) {
            if (is_array($value) && $key !== '_text') {
                self::walkForError($value, ".{$key}");
            }
        }
    }

    /**
     * Recursively convert a SimpleXMLElement to a PHP array.
     *
     * @return array<string, mixed>
     */
    private static function xmlToArray(\SimpleXMLElement $element): array
    {
        $result = [];

        foreach ($element->attributes() as $attrName => $attrValue) {
            $result["@{$attrName}"] = (string) $attrValue;
        }

        $children = [];
        foreach ($element->children() as $childName => $child) {
            $childArray = self::xmlToArray($child);

            if (isset($children[$childName])) {
                // Multiple elements with the same tag name → convert to indexed array
                if (!isset($children[$childName][0])) {
                    $children[$childName] = [$children[$childName]];
                }
                $children[$childName][] = $childArray;
            } else {
                $children[$childName] = $childArray;
            }
        }

        if (!empty($children)) {
            $result = array_merge($result, $children);
        } else {
            $text = trim((string) $element);
            if ($text !== '') {
                return array_merge($result, ['_text' => $text]);
            }
        }

        // Unwrap single _text nodes for scalar leaf values
        if (count($result) === 1 && isset($result['_text'])) {
            return ['_text' => $result['_text']];
        }

        return $result;
    }

    /**
     * Extract the text value of a nested key from a decoded array.
     * Handles both direct string values and {'_text': 'value'} wrapped nodes.
     *
     * @param array<mixed> $data
     */
    public static function extractText(array $data, string ...$keys): ?string
    {
        $current = $data;

        foreach ($keys as $key) {
            if (!is_array($current) || !isset($current[$key])) {
                return null;
            }
            $current = $current[$key];
        }

        if (is_string($current)) {
            return $current;
        }

        if (is_array($current) && isset($current['_text']) && is_string($current['_text'])) {
            return $current['_text'];
        }

        return null;
    }
}
