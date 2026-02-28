<?php

declare(strict_types=1);

namespace Rkn\Cms\Components;

use Clickfwd\Yoyo\Component;

/**
 * Yoyo contact form component with server-side validation.
 */
class ContactForm extends Component
{
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $message = '';
    public string $status = '';
    public string $statusType = '';

    /** @var array<string, string> */
    public array $errors = [];

    public function submit(): void
    {
        $this->errors = [];

        // Validate
        if (trim($this->name) === '') {
            $this->errors['name'] = 'El nombre es requerido.';
        }

        if (trim($this->email) === '' || !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'Ingrese un correo electronico valido.';
        }

        if (trim($this->message) === '') {
            $this->errors['message'] = 'El mensaje es requerido.';
        }

        if (!empty($this->errors)) {
            $this->statusType = 'error';
            return;
        }

        // Form is valid - queue email for sending
        try {
            $container = \Rkn\Framework\Application::getInstance()?->getContainer();
            if ($container && $container->has('queue')) {
                $queue = $container->get('queue');
                $queue->push('send-contact-email', [
                    'name' => $this->name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'message' => $this->message,
                ]);
            }

            $this->status = 'Mensaje enviado correctamente.';
            $this->statusType = 'success';

            // Reset form
            $this->name = '';
            $this->email = '';
            $this->phone = '';
            $this->message = '';
        } catch (\Throwable) {
            $this->status = 'Error al enviar el mensaje.';
            $this->statusType = 'error';
        }
    }

    public function render(): string
    {
        return $this->view('yoyo/contact-form');
    }
}
