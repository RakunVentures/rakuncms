<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class CsrfExtension extends AbstractExtension
{
    private \Rkn\Cms\Middleware\CsrfProtection $csrf;

    public function __construct(\Rkn\Cms\Middleware\CsrfProtection $csrf)
    {
        $this->csrf = $csrf;
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('csrf_token', [$this->csrf, 'generateToken']),
            new TwigFunction('csrf_field', [$this, 'csrfField'], ['is_safe' => ['html']]),
            new TwigFunction('hp_field', [$this, 'hpField'], ['is_safe' => ['html']]),
        ];
    }

    public function csrfField(): string
    {
        $token = $this->csrf->generateToken();
        return '<input type="hidden" name="_csrf_token" value="' . $token . '">';
    }

    public function hpField(): string
    {
        // A honeypot field that is hidden from real users but visible to bots.
        // We use a div with display:none to hide it.
        return '<div style="display:none"><input type="email" name="_hp_email" autocomplete="off" tabindex="-1"></div>';
    }
}
