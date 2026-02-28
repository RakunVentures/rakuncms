<?php

declare(strict_types=1);

namespace Rkn\Cms\Seo;

final class ConsentManager
{
    /**
     * @param array<string, mixed> $seoConfig
     */
    public function __construct(
        private array $seoConfig = [],
    ) {
    }

    public function hasTracking(): bool
    {
        return ($this->seoConfig['google_analytics'] ?? '') !== ''
            || ($this->seoConfig['facebook_pixel'] ?? '') !== '';
    }

    public function render(): string
    {
        if (!$this->hasTracking()) {
            return '';
        }

        $parts = [
            $this->renderAnalyticsTemplates(),
            $this->renderBannerHtml(),
            $this->renderScript(),
        ];

        return implode("\n", $parts);
    }

    public function renderAnalyticsOnly(): string
    {
        if (!$this->hasTracking()) {
            return '';
        }

        return $this->renderAnalyticsScriptsDirect();
    }

    private function renderAnalyticsTemplates(): string
    {
        $templates = [];

        $gaId = $this->seoConfig['google_analytics'] ?? '';
        if ($gaId !== '') {
            $templates[] = '<template data-consent="analytics">'
                . '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $this->escape($gaId) . '"></script>'
                . '</template>';
            $templates[] = '<template data-consent="analytics">'
                . '<script>'
                . 'window.dataLayer=window.dataLayer||[];'
                . 'function gtag(){dataLayer.push(arguments);}'
                . "gtag('js',new Date());"
                . "gtag('config','" . $this->escape($gaId) . "');"
                . '</script>'
                . '</template>';
        }

        $pixelId = $this->seoConfig['facebook_pixel'] ?? '';
        if ($pixelId !== '') {
            $templates[] = '<template data-consent="analytics">'
                . '<script>'
                . "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?"
                . "n.callMethod.apply(n,arguments):n.queue.push(arguments)};"
                . "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';"
                . "n.queue=[];t=b.createElement(e);t.async=!0;"
                . "t.src=v;s=b.getElementsByTagName(e)[0];"
                . "s.parentNode.insertBefore(t,s)}(window,document,'script',"
                . "'https://connect.facebook.net/en_US/fbevents.js');"
                . "fbq('init','" . $this->escape($pixelId) . "');"
                . "fbq('track','PageView');"
                . '</script>'
                . '</template>';
        }

        return implode("\n", $templates);
    }

    private function renderAnalyticsScriptsDirect(): string
    {
        $scripts = [];

        $gaId = $this->seoConfig['google_analytics'] ?? '';
        if ($gaId !== '') {
            $scripts[] = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $this->escape($gaId) . '"></script>';
            $scripts[] = '<script>'
                . 'window.dataLayer=window.dataLayer||[];'
                . 'function gtag(){dataLayer.push(arguments);}'
                . "gtag('js',new Date());"
                . "gtag('config','" . $this->escape($gaId) . "');"
                . '</script>';
        }

        $pixelId = $this->seoConfig['facebook_pixel'] ?? '';
        if ($pixelId !== '') {
            $scripts[] = '<script>'
                . "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?"
                . "n.callMethod.apply(n,arguments):n.queue.push(arguments)};"
                . "if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';"
                . "n.queue=[];t=b.createElement(e);t.async=!0;"
                . "t.src=v;s=b.getElementsByTagName(e)[0];"
                . "s.parentNode.insertBefore(t,s)}(window,document,'script',"
                . "'https://connect.facebook.net/en_US/fbevents.js');"
                . "fbq('init','" . $this->escape($pixelId) . "');"
                . "fbq('track','PageView');"
                . '</script>';
        }

        return implode("\n", $scripts);
    }

    private function renderBannerHtml(): string
    {
        $text = $this->seoConfig['consent_text']
            ?? 'Este sitio utiliza cookies para mejorar la experiencia.';

        return '<div id="rkn-consent" style="'
            . 'display:none;position:fixed;bottom:0;left:0;right:0;z-index:9999;'
            . 'background:#1f2937;color:#f9fafb;padding:1rem;'
            . 'font-family:system-ui,-apple-system,sans-serif;font-size:0.875rem;'
            . 'text-align:center;box-shadow:0 -2px 10px rgba(0,0,0,0.1);'
            . '">'
            . '<span>' . $this->escape($text) . '</span> '
            . '<button id="rkn-consent-accept" style="'
            . 'background:#3b82f6;color:#fff;border:none;padding:0.375rem 1rem;'
            . 'border-radius:4px;cursor:pointer;margin-left:0.5rem;font-size:0.875rem;'
            . '">Aceptar</button> '
            . '<button id="rkn-consent-reject" style="'
            . 'background:transparent;color:#9ca3af;border:1px solid #4b5563;'
            . 'padding:0.375rem 1rem;border-radius:4px;cursor:pointer;'
            . 'margin-left:0.25rem;font-size:0.875rem;'
            . '">Rechazar</button>'
            . '</div>';
    }

    private function renderScript(): string
    {
        return '<script>'
            . '(function(){'
            . "var c=localStorage.getItem('rkn_consent');"
            . "var banner=document.getElementById('rkn-consent');"
            . "function loadConsented(){"
            . "document.querySelectorAll('template[data-consent]').forEach(function(t){"
            . "var clone=t.content.cloneNode(true);"
            . "document.head.appendChild(clone);"
            . "});"
            . "}"
            . "if(c==='accepted'){loadConsented();}"
            . "else if(c!=='rejected'){banner.style.display='block';}"
            . "document.getElementById('rkn-consent-accept').addEventListener('click',function(){"
            . "localStorage.setItem('rkn_consent','accepted');"
            . "banner.style.display='none';"
            . "loadConsented();"
            . "});"
            . "document.getElementById('rkn-consent-reject').addEventListener('click',function(){"
            . "localStorage.setItem('rkn_consent','rejected');"
            . "banner.style.display='none';"
            . "});"
            . '})();'
            . '</script>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
