<?php

declare(strict_types=1);

namespace Rkn\Cms\Integrations;

final class MailchimpFormGenerator
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config = [],
    ) {
    }

    public function render(): string
    {
        $embedUrl = trim((string) ($this->config['mailchimp_embed_url'] ?? ''));
        if ($embedUrl === '') {
            return '';
        }

        $buttonText = $this->escape((string) ($this->config['button_text'] ?? 'Suscribirse'));
        $placeholder = $this->escape((string) ($this->config['placeholder'] ?? 'Tu email'));

        return '<div class="rkn-newsletter">'
            . '<form action="' . $this->escape($embedUrl) . '" method="post" target="_blank" rel="noopener">'
            . '<div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">'
            . '<input type="email" name="EMAIL" placeholder="' . $placeholder . '" required '
            . 'style="'
            . 'padding:0.5rem 0.75rem;border:1px solid #cbd5e1;border-radius:4px;'
            . 'font-size:0.875rem;flex:1;min-width:200px;'
            . '">'
            . '<button type="submit" style="'
            . 'background:#3b82f6;color:#fff;border:none;padding:0.5rem 1rem;'
            . 'border-radius:4px;cursor:pointer;font-size:0.875rem;white-space:nowrap;'
            . '">' . $buttonText . '</button>'
            . '</div>'
            . $this->renderHoneypot()
            . '</form>'
            . '</div>';
    }

    private function renderHoneypot(): string
    {
        return '<div style="position:absolute;left:-5000px;" aria-hidden="true">'
            . '<input type="text" name="b_honeypot" tabindex="-1" value="">'
            . '</div>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
