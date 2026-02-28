<?php

declare(strict_types=1);

namespace Rkn\Cms\Mail;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

/**
 * Lightweight wrapper around PHPMailer with SMTP config from rakun.yaml.
 */
final class Mailer
{
    /** @var array<string, mixed> */
    private array $config;

    private ?EmailRenderer $renderer;

    /**
     * @param array<string, mixed> $config Mail config section from rakun.yaml
     * @param EmailRenderer|null $renderer Optional email template renderer
     */
    public function __construct(array $config, ?EmailRenderer $renderer = null)
    {
        $this->config = $config;
        $this->renderer = $renderer;
    }

    /**
     * Send an email.
     *
     * @param string $to Recipient email
     * @param string $subject Email subject
     * @param string $body HTML body
     * @param string|null $replyTo Optional reply-to address
     * @throws PHPMailerException
     */
    public function send(string $to, string $subject, string $body, ?string $replyTo = null): void
    {
        $mail = $this->createMailer();

        $mail->addAddress($to);

        if ($replyTo !== null) {
            $mail->addReplyTo($replyTo);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;
        $mail->AltBody = $this->htmlToText($body);

        $mail->send();
    }

    /**
     * Send a contact form email.
     *
     * @param array{name: string, email: string, phone?: string, message: string} $data
     * @throws PHPMailerException
     */
    public function sendContactForm(array $data): void
    {
        $to = $this->config['from_email'] ?? '';
        $subject = 'Nuevo mensaje de contacto de ' . $data['name'];

        if ($this->renderer !== null) {
            $body = $this->renderer->render('contact-form', $data);
        } else {
            $body = '<h2>Nuevo mensaje de contacto</h2>';
            $body .= '<p><strong>Nombre:</strong> ' . htmlspecialchars($data['name']) . '</p>';
            $body .= '<p><strong>Email:</strong> ' . htmlspecialchars($data['email']) . '</p>';

            if (!empty($data['phone'])) {
                $body .= '<p><strong>Teléfono:</strong> ' . htmlspecialchars($data['phone']) . '</p>';
            }

            $body .= '<p><strong>Mensaje:</strong></p>';
            $body .= '<p>' . nl2br(htmlspecialchars($data['message'])) . '</p>';
        }

        $this->send($to, $subject, $body, $data['email']);
    }

    private function htmlToText(string $html): string
    {
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/si', '', $html) ?? $html;
        $text = preg_replace('/<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        $lines = explode("\n", $text);
        $lines = array_map('trim', $lines);
        $text = implode("\n", $lines);
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);

        $host = $this->config['smtp_host'] ?? '';

        if ($host !== '' && $host !== 'localhost') {
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = (int) ($this->config['smtp_port'] ?? 587);

            $encryption = $this->config['smtp_encryption'] ?? 'tls';
            if ($encryption === 'tls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } elseif ($encryption === 'ssl') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            }

            $username = $this->config['smtp_username'] ?? '';
            if ($username !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $username;
                $mail->Password = $this->config['smtp_password'] ?? '';
            }
        }

        $mail->setFrom(
            $this->config['from_email'] ?? 'noreply@example.com',
            $this->config['from_name'] ?? 'RakunCMS'
        );

        $mail->CharSet = PHPMailer::CHARSET_UTF8;

        return $mail;
    }
}
