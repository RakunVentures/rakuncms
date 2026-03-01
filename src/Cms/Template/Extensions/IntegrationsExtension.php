<?php

declare(strict_types=1);

namespace Rkn\Cms\Template\Extensions;

use Rkn\Cms\Integrations\GumroadButtonGenerator;
use Rkn\Cms\Integrations\MailchimpFormGenerator;
use Rkn\Cms\Integrations\StripeButtonGenerator;
use Rkn\Cms\Integrations\WhatsAppButtonGenerator;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class IntegrationsExtension extends AbstractExtension
{
    private bool $gumroadScriptRendered = false;

    public function getFunctions(): array
    {
        return [
            new TwigFunction('whatsapp_button', [$this, 'whatsappButton'], ['is_safe' => ['html']]),
            new TwigFunction('newsletter_form', [$this, 'newsletterForm'], ['is_safe' => ['html']]),
            new TwigFunction('stripe_button', [$this, 'stripeButton'], ['is_safe' => ['html']]),
            new TwigFunction('stripe_buttons', [$this, 'stripeButtons'], ['is_safe' => ['html']]),
            new TwigFunction('gumroad_button', [$this, 'gumroadButton'], ['is_safe' => ['html']]),
            new TwigFunction('gumroad_buttons', [$this, 'gumroadButtons'], ['is_safe' => ['html']]),
        ];
    }

    public function whatsappButton(): string
    {
        $config = $this->getIntegrationConfig('whatsapp');
        $gen = new WhatsAppButtonGenerator($config);

        return $gen->render();
    }

    public function newsletterForm(): string
    {
        $config = $this->getIntegrationConfig('newsletter');
        $gen = new MailchimpFormGenerator($config);

        return $gen->render();
    }

    public function stripeButton(string $linkId): string
    {
        $config = $this->getIntegrationConfig('stripe');
        $gen = new StripeButtonGenerator($config);

        return $gen->render($linkId);
    }

    public function stripeButtons(): string
    {
        $config = $this->getIntegrationConfig('stripe');
        $gen = new StripeButtonGenerator($config);

        return $gen->renderAll();
    }

    public function gumroadButton(string $productId): string
    {
        $config = $this->getIntegrationConfig('gumroad');
        $gen = new GumroadButtonGenerator($config);

        $html = $gen->render($productId);

        if ($html !== '' && !$this->gumroadScriptRendered) {
            $html .= "\n" . $gen->renderScript();
            $this->gumroadScriptRendered = true;
        }

        return $html;
    }

    public function gumroadButtons(): string
    {
        $config = $this->getIntegrationConfig('gumroad');
        $gen = new GumroadButtonGenerator($config);

        $html = $gen->renderAll();

        if ($html !== '' && !$this->gumroadScriptRendered) {
            $html .= "\n" . $gen->renderScript();
            $this->gumroadScriptRendered = true;
        }

        return $html;
    }

    /**
     * @return array<string, mixed>
     */
    private function getIntegrationConfig(string $key): array
    {
        try {
            return \config('integrations.' . $key, []);
        } catch (\Throwable) {
            return [];
        }
    }
}
