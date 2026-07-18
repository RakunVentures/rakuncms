<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

use Rkn\Cms\Content\Entry;
use Twig\Extension\AbstractExtension;
use Twig\Extension\GlobalsInterface;
use Twig\TwigFunction;

final class I18nExtension extends AbstractExtension implements GlobalsInterface
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('t', [$this, 'translate']),
            new TwigFunction('url_for_locale', [$this, 'urlForLocale']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getGlobals(): array
    {
        $locale = 'es';
        $alternateLocale = 'en';

        try {
            $locale = \app('locale');
            $locales = \config('site.locales', ['es', 'en']);
            foreach ($locales as $l) {
                if ($l !== $locale) {
                    $alternateLocale = $l;
                    break;
                }
            }
        } catch (\Throwable) {
        }

        return [
            'locale' => $locale,
            'alternate_locale' => $alternateLocale,
        ];
    }

    /**
     * @param array<string, string> $params
     */
    public function translate(string $key, array $params = []): string
    {
        return \t($key, $params);
    }

    /**
     * Get the URL for the current page in a different locale.
     */
    public function urlForLocale(string $targetLocale): string
    {
        try {
            $entry = \app('current_entry');
            if ($entry instanceof Entry) {
                $slug = $entry->slugForLocale($targetLocale);
                $collection = $entry->collection();

                if ($collection === 'pages') {
                    if (Entry::isHomeSlug($slug)) {
                        return '/' . $targetLocale . '/';
                    }
                    return '/' . $targetLocale . '/' . $slug;
                }

                $collectionSlug = $collection;
                if ($targetLocale === 'en') {
                    $map = ['habitaciones' => 'rooms'];
                    $collectionSlug = $map[$collectionSlug] ?? $collectionSlug;
                }

                return '/' . $targetLocale . '/' . $collectionSlug . '/' . $slug;
            }
        } catch (\Throwable) {
        }

        return $this->swapLocaleInCurrentUri($targetLocale);
    }

    /**
     * Fallback for routes without a current_entry (search, dynamic lists, 404).
     * Strips an existing locale prefix from REQUEST_URI and prepends the target.
     */
    private function swapLocaleInCurrentUri(string $targetLocale): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $qsPos = strpos($uri, '?');
        $path = $qsPos === false ? $uri : substr($uri, 0, $qsPos);
        $query = $qsPos === false ? '' : substr($uri, $qsPos);

        $locales = [];
        try {
            $configured = \config('site.locales', []);
            if (is_array($configured)) {
                $locales = array_values(array_filter($configured, 'is_string'));
            }
        } catch (\Throwable) {
        }

        $rest = '';
        if (preg_match('#^/([a-z]{2})(/.*)?$#', $path, $m)) {
            $candidate = $m[1];
            if ($locales === [] || in_array($candidate, $locales, true)) {
                $rest = $m[2] ?? '';
            } else {
                $rest = $path;
            }
        } else {
            $rest = $path;
        }

        if ($rest === '' || $rest === '/') {
            return '/' . $targetLocale . '/' . $query;
        }

        return '/' . $targetLocale . $rest . $query;
    }
}
