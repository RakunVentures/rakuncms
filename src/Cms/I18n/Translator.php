<?php

declare(strict_types=1);

namespace Rkn\Cms\I18n;

final class Translator
{
    private string $locale;
    private string $fallbackLocale;
    private string $langPath;

    /** @var array<string, array<string, string>> */
    private array $loaded = [];

    public function __construct(string $langPath, string $locale = 'es', string $fallbackLocale = 'es')
    {
        $this->langPath = $langPath;
        $this->locale = $locale;
        $this->fallbackLocale = $fallbackLocale;
    }

    public function setLocale(string $locale): void
    {
        $this->locale = $locale;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * Get a translated string for the given key.
     *
     * @param array<string, string> $params  Replacements for :param placeholders
     */
    public function get(string $key, array $params = []): string
    {
        $messages = $this->loadMessages($this->locale);

        $text = $messages[$key] ?? null;

        // Fallback to fallback locale
        if ($text === null && $this->locale !== $this->fallbackLocale) {
            $fallbackMessages = $this->loadMessages($this->fallbackLocale);
            $text = $fallbackMessages[$key] ?? null;
        }

        // Return key itself if no translation found
        if ($text === null) {
            return $key;
        }

        // Replace :param placeholders
        foreach ($params as $param => $value) {
            $text = str_replace(':' . $param, $value, $text);
        }

        return $text;
    }

    /**
     * Check if a translation exists.
     */
    public function has(string $key): bool
    {
        $messages = $this->loadMessages($this->locale);
        return isset($messages[$key]);
    }

    /**
     * Load messages for a locale (cached per request).
     *
     * @return array<string, string>
     */
    private function loadMessages(string $locale): array
    {
        if (isset($this->loaded[$locale])) {
            return $this->loaded[$locale];
        }

        $file = $this->langPath . '/' . $locale . '/messages.php';

        if (file_exists($file)) {
            $messages = require $file;
            $this->loaded[$locale] = is_array($messages) ? $messages : [];
        } else {
            $this->loaded[$locale] = [];
        }

        return $this->loaded[$locale];
    }
}
