<?php

declare(strict_types=1);

use Rkn\Framework\Application;

if (!function_exists('app')) {
    /**
     * Get the application container instance, or resolve a service.
     */
    function app(?string $id = null): mixed
    {
        $application = Application::getInstance();

        if ($application === null) {
            throw new RuntimeException('Application not initialized.');
        }

        if ($id === null) {
            return $application->container();
        }

        return $application->container()->get($id);
    }
}

if (!function_exists('config')) {
    /**
     * Get a config value using dot-notation.
     */
    function config(string $key, mixed $default = null): mixed
    {
        $application = Application::getInstance();

        if ($application === null) {
            throw new RuntimeException('Application not initialized.');
        }

        return $application->config($key, $default);
    }
}

if (!function_exists('t')) {
    /**
     * Translate a key using the active locale.
     *
     * @param array<string, string> $params
     */
    function t(string $key, array $params = []): string
    {
        try {
            /** @var \Rkn\Cms\I18n\Translator $translator */
            $translator = app('translator');
            return $translator->get($key, $params);
        } catch (\Throwable) {
            return $key;
        }
    }
}

if (!function_exists('asset')) {
    /**
     * Generate URL for a static asset with cache busting.
     */
    function asset(string $path): string
    {
        $basePath = app('base_path');
        $publicPath = $basePath . '/public/' . ltrim($path, '/');

        if (file_exists($publicPath)) {
            $hash = substr(md5_file($publicPath) ?: '', 0, 8);
            return '/' . ltrim($path, '/') . '?v=' . $hash;
        }

        return '/' . ltrim($path, '/');
    }
}

if (!function_exists('url')) {
    /**
     * Generate an absolute URL.
     */
    function url(string $path = ''): string
    {
        $baseUrl = config('site.base_url', '');
        return rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
    }
}
