<?php

declare(strict_types=1);

namespace Rkn\Cms\Components;

use Clickfwd\Yoyo\Component;

class NewsletterSubscription extends Component
{
    public string $email = '';
    public string $tier = 'monthly'; // 'monthly' or 'weekly'
    public string $status = '';
    public string $statusType = '';
    public bool $subscribed = false;

    /** @var array<string, string> */
    public array $errors = [];

    protected $props = ['tier'];

    public function subscribe(): void
    {
        $this->errors = [];

        if (empty($this->email) || !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'Por favor, ingresa un correo válido.';
            return;
        }

        // Logic for Substack redirection or lead capture
        // For now, we simulate a successful capture and provide the link
        try {
            $container = \Rkn\Framework\Application::getInstance()?->container();
            if ($container && $container->has('queue')) {
                $queue = $container->get('queue');
                $queue->push('newsletter-lead', [
                    'email' => $this->email,
                    'tier' => $this->tier,
                    'source' => 'newsletter_page',
                    'timestamp' => date('Y-m-d H:i:s')
                ]);
            }

            $this->subscribed = true;
            $this->status = ($this->tier === 'weekly') 
                ? '¡Excelente elección! Redirigiéndote a Substack para completar tu suscripción premium...' 
                : '¡Bienvenido al Atelier! Revisa tu bandeja para confirmar tu suscripción gratuita.';
            $this->statusType = 'success';

        } catch (\Throwable) {
            $this->status = 'Hubo un problema. Inténtalo de nuevo.';
            $this->statusType = 'error';
        }
    }

    /** @return string|\Clickfwd\Yoyo\Interfaces\ViewProviderInterface */
    public function render(): string|\Clickfwd\Yoyo\Interfaces\ViewProviderInterface
    {
        return $this->view('yoyo/newsletter-subscription');
    }
}
