<?php

declare(strict_types=1);

namespace Rkn\Cms\Integrations;

final class StripeButtonGenerator
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config = [],
    ) {
    }

    public function render(string $linkId): string
    {
        $link = $this->findLink($linkId);
        if ($link === null) {
            return '';
        }

        return $this->renderButton($link);
    }

    public function renderAll(): string
    {
        $links = $this->config['links'] ?? [];
        if (empty($links)) {
            return '';
        }

        $buttons = [];
        foreach ($links as $link) {
            $buttons[] = $this->renderButton($link);
        }

        return '<div class="rkn-stripe-buttons">'
            . implode("\n", $buttons)
            . '</div>';
    }

    /**
     * @param array<string, mixed> $link
     */
    private function renderButton(array $link): string
    {
        $url = $this->escape((string) ($link['url'] ?? ''));
        $label = $this->escape((string) ($link['label'] ?? ''));
        $description = $this->escape((string) ($link['description'] ?? ''));
        $style = $this->getButtonStyle();

        $html = '<a href="' . $url . '" target="_blank" rel="noopener noreferrer" '
            . 'class="rkn-stripe-button" '
            . 'style="' . $style . '">'
            . '<span class="rkn-stripe-label">' . $label . '</span>';

        if ($description !== '') {
            $html .= ' <span class="rkn-stripe-desc" style="'
                . 'opacity:0.8;font-size:0.85em;'
                . '">' . $description . '</span>';
        }

        $html .= '</a>';

        return $html;
    }

    private function getButtonStyle(): string
    {
        $buttonStyle = (string) ($this->config['button_style'] ?? 'primary');

        return match ($buttonStyle) {
            'outline' => 'display:inline-block;padding:0.625rem 1.25rem;'
                . 'border:2px solid #635bff;color:#635bff;background:transparent;'
                . 'border-radius:6px;text-decoration:none;font-size:0.875rem;'
                . 'font-weight:500;cursor:pointer;text-align:center;',
            'minimal' => 'display:inline-block;padding:0.375rem 0.75rem;'
                . 'border:none;color:#635bff;background:transparent;'
                . 'text-decoration:underline;font-size:0.875rem;'
                . 'cursor:pointer;text-align:center;',
            default => 'display:inline-block;padding:0.625rem 1.25rem;'
                . 'background:#635bff;color:#fff;border:none;'
                . 'border-radius:6px;text-decoration:none;font-size:0.875rem;'
                . 'font-weight:500;cursor:pointer;text-align:center;',
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findLink(string $linkId): ?array
    {
        foreach ($this->config['links'] ?? [] as $link) {
            if (($link['id'] ?? '') === $linkId) {
                return $link;
            }
        }

        return null;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
