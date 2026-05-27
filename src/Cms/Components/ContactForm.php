<?php

declare(strict_types=1);

namespace Rkn\Cms\Components;

use Clickfwd\Yoyo\Component;

/**
 * Yoyo reactive contact form. Renders fields, validates client-side state,
 * and pushes the submission to the queue for async email delivery.
 *
 * Mirrors the validation contract of FormController::handleContact() so a
 * non-JS fallback POST and the live form behave identically.
 */
class ContactForm extends Component
{
    public string $name = '';
    public string $email = '';
    public string $message = '';
    public bool $submitted = false;

    /** @var array<string, string> */
    public array $errors = [];

    public function submit(): void
    {
        $this->errors = [];

        if (trim($this->name) === '') {
            $this->errors['name'] = 'Name is required.';
        }

        if ($this->email === '' || !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'Valid email is required.';
        }

        if (trim($this->message) === '') {
            $this->errors['message'] = 'Message is required.';
        }

        if ($this->errors !== []) {
            return;
        }

        $container = \Rkn\Framework\Application::getInstance()?->container();
        if ($container !== null && $container->has('queue')) {
            $queue = $container->get('queue');
            $queue->push('send-contact-email', [
                'name'    => $this->name,
                'email'   => $this->email,
                'message' => $this->message,
            ]);
        }

        $this->submitted = true;
    }

    public function reset(): void
    {
        $this->name      = '';
        $this->email     = '';
        $this->message   = '';
        $this->errors    = [];
        $this->submitted = false;
    }

    /** @return string|\Clickfwd\Yoyo\Interfaces\ViewProviderInterface */
    public function render(): string|\Clickfwd\Yoyo\Interfaces\ViewProviderInterface
    {
        return $this->view('yoyo/contact-form');
    }
}
