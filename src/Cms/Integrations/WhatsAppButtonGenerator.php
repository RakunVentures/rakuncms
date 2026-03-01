<?php

declare(strict_types=1);

namespace Rkn\Cms\Integrations;

final class WhatsAppButtonGenerator
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
        $phone = $this->sanitizePhone((string) ($this->config['phone'] ?? ''));
        if ($phone === '') {
            return '';
        }

        $message = (string) ($this->config['message'] ?? '');
        $position = ($this->config['position'] ?? 'bottom-right') === 'bottom-left'
            ? 'bottom-left'
            : 'bottom-right';
        $color = $this->escape((string) ($this->config['color'] ?? '#25D366'));
        $size = (int) ($this->config['size'] ?? 60);

        $url = 'https://wa.me/' . $phone;
        if ($message !== '') {
            $url .= '?text=' . rawurlencode($message);
        }

        $positionCss = $position === 'bottom-left'
            ? 'left:20px;'
            : 'right:20px;';

        $iconSize = (int) ($size * 0.6);

        return '<div class="rkn-whatsapp" style="'
            . 'position:fixed;bottom:20px;' . $positionCss
            . 'z-index:9990;'
            . '">'
            . '<a href="' . $this->escape($url) . '" target="_blank" rel="noopener noreferrer" '
            . 'aria-label="Chat on WhatsApp" '
            . 'style="'
            . 'display:flex;align-items:center;justify-content:center;'
            . 'width:' . $size . 'px;height:' . $size . 'px;'
            . 'border-radius:50%;'
            . 'background-color:' . $color . ';'
            . 'box-shadow:0 4px 12px rgba(0,0,0,0.15);'
            . 'text-decoration:none;'
            . 'transition:transform 0.2s;'
            . '">'
            . $this->renderSvg($iconSize)
            . '</a>'
            . '</div>';
    }

    private function renderSvg(int $size): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" '
            . 'viewBox="0 0 24 24" fill="#ffffff">'
            . '<path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.019-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347z"/>'
            . '<path d="M12 0C5.373 0 0 5.373 0 12c0 2.625.846 5.059 2.284 7.034L.789 23.492a.5.5 0 00.611.611l4.458-1.495A11.952 11.952 0 0012 24c6.627 0 12-5.373 12-12S18.627 0 12 0zm0 22c-2.387 0-4.607-.798-6.381-2.147l-.446-.345-3.281 1.1 1.1-3.281-.345-.446A9.935 9.935 0 012 12C2 6.477 6.477 2 12 2s10 4.477 10 10-4.477 10-10 10z"/>'
            . '</svg>';
    }

    private function sanitizePhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', $phone) ?? '';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
