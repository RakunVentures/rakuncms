<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

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
            if ($entry instanceof \Rkn\Cms\Content\Entry) {
                $slug = $entry->slugForLocale($targetLocale);
                $collection = $entry->collection();

                if ($collection === 'pages') {
                    if ($slug === 'home' || $slug === 'inicio') {
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

        // Fallback: swap locale in current URL
        return '/' . $targetLocale . '/';
    }
}
