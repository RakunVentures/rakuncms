<?php

declare(strict_types=1);

namespace Rkn\Cms\Integrations;

final class GumroadButtonGenerator
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private array $config = [],
    ) {
    }

    public function render(string $productId): string
    {
        $product = $this->findProduct($productId);
        if ($product === null) {
            return '';
        }

        return $this->renderButton($product);
    }

    public function renderAll(): string
    {
        $products = $this->config['products'] ?? [];
        if (empty($products)) {
            return '';
        }

        $buttons = [];
        foreach ($products as $product) {
            $buttons[] = $this->renderButton($product);
        }

        return '<div class="rkn-gumroad-buttons">'
            . implode("\n", $buttons)
            . '</div>';
    }

    public function renderScript(): string
    {
        $overlay = (bool) ($this->config['overlay'] ?? true);
        if (!$overlay) {
            return '';
        }

        $products = $this->config['products'] ?? [];
        if (empty($products)) {
            return '';
        }

        return '<script src="https://gumroad.com/js/gumroad.js"></script>';
    }

    /**
     * @param array<string, mixed> $product
     */
    private function renderButton(array $product): string
    {
        $id = $this->escape((string) ($product['id'] ?? ''));
        $label = $this->escape((string) ($product['label'] ?? ''));
        $description = $this->escape((string) ($product['description'] ?? ''));

        $url = 'https://gumroad.com/l/' . $id;

        $html = '<a class="gumroad-button" href="' . $this->escape($url) . '" '
            . 'target="_blank" rel="noopener noreferrer" '
            . 'style="'
            . 'display:inline-block;padding:0.625rem 1.25rem;'
            . 'background:#ff90e8;color:#000;border:none;'
            . 'border-radius:6px;text-decoration:none;font-size:0.875rem;'
            . 'font-weight:500;cursor:pointer;text-align:center;'
            . '">'
            . '<span class="rkn-gumroad-label">' . $label . '</span>';

        if ($description !== '') {
            $html .= ' <span class="rkn-gumroad-desc" style="'
                . 'opacity:0.8;font-size:0.85em;'
                . '">' . $description . '</span>';
        }

        $html .= '</a>';

        return $html;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findProduct(string $productId): ?array
    {
        foreach ($this->config['products'] ?? [] as $product) {
            if (($product['id'] ?? '') === $productId) {
                return $product;
            }
        }

        return null;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
