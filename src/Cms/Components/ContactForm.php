<?php

declare(strict_types=1);

namespace Rkn\Cms\Components;

use Clickfwd\Yoyo\Component;

class ContactForm extends Component
{
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $message = '';
    public string $status = '';
    public string $statusType = '';
    public string $source = 'contacto';
    public string $theme = 'default'; // 'default' or 'dark-blue'
    public string $website = '';

    /** @var array<string, string> */
    public array $errors = [];

    protected $props = ['source', 'theme'];

    public function submit(): void
    {
        $this->errors = [];

        if (!empty($this->website)) {
            $this->status = 'Mensaje enviado correctamente.';
            $this->statusType = 'success';
            $this->resetForm();
            return;
        }

        if (trim($this->name) === '') {
            $this->errors['name'] = 'Por favor, dinos tu nombre.';
        }

        if (trim($this->email) === '' || !filter_var($this->email, FILTER_VALIDATE_EMAIL)) {
            $this->errors['email'] = 'Necesitamos un correo válido para responderte.';
        }

        if (trim($this->message) === '') {
            $this->errors['message'] = '¿En qué podemos ayudarte? Escribe tu mensaje.';
        }

        if (!empty($this->errors)) {
            $this->statusType = 'error';
            return;
        }

        if (isset($_SESSION['last_contact_submit']) && (time() - $_SESSION['last_contact_submit'] < 10)) {
            $this->status = 'Has enviado un mensaje recientemente.';
            $this->statusType = 'error';
            return;
        }

        try {
            $container = \Rkn\Framework\Application::getInstance()?->container();
            if ($container && $container->has('queue')) {
                $queue = $container->get('queue');
                $queue->push('send-contact-email', [
                    'name' => $this->name,
                    'email' => $this->email,
                    'phone' => $this->phone,
                    'message' => $this->message,
                    'source' => $this->source,
                    'submitted_at' => date('Y-m-d H:i:s')
                ]);
            }

            $_SESSION['last_contact_submit'] = time();
            $this->status = '¡Gracias! Tu mensaje ha sido enviado.';
            $this->statusType = 'success';
            $this->resetForm();

        } catch (\Throwable $e) {
            $this->status = 'Error técnico.';
            $this->statusType = 'error';
        }
    }

    private function resetForm(): void
    {
        $this->name = '';
        $this->email = '';
        $this->phone = '';
        $this->message = '';
        $this->website = '';
    }

    /** @return string|\Clickfwd\Yoyo\Interfaces\ViewProviderInterface */
    public function render(): string|\Clickfwd\Yoyo\Interfaces\ViewProviderInterface
    {
        return $this->view('yoyo/contact-form');
    }
}
