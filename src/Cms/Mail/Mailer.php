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
     * Canonical fields (name, email, message) are required; any additional
     * keys in $data (source, phone, company, monthly_volume, model, ...) are
     * forwarded to the template and rendered as an extra-fields table.
     *
     * @param array<string, mixed> $data
     * @throws PHPMailerException
     */
    public function sendContactForm(array $data): void
    {
        // Destination: to_email wins, from_email is the fallback (back-compat).
        // Treat empty strings as "not set" so ${MAIL_TO_EMAIL:-} falls through.
        $to = !empty($this->config['to_email'])
            ? $this->config['to_email']
            : ($this->config['from_email'] ?? '');

        $subjectBase = 'Nuevo mensaje de contacto de ' . (string) ($data['name'] ?? '');
        $source = (string) ($data['source'] ?? '');
        $subject = $source !== ''
            ? sprintf('[%s] %s', $source, $subjectBase)
            : $subjectBase;

        // Split canonical vs extra fields for template rendering.
        $canonical = ['name', 'email', 'phone', 'message'];
        $extras = [];
        foreach ($data as $k => $v) {
            if (in_array($k, $canonical, true)) {
                continue;
            }
            if ($v === null || $v === '') {
                continue;
            }
            $extras[$k] = is_scalar($v) ? (string) $v : json_encode($v);
        }

        $templateData = [
            'name'    => (string) ($data['name'] ?? ''),
            'email'   => (string) ($data['email'] ?? ''),
            'phone'   => (string) ($data['phone'] ?? ''),
            'message' => (string) ($data['message'] ?? ''),
            'extras'  => $extras,
        ];

        if ($this->renderer !== null) {
            $body = $this->renderer->render('contact-form', $templateData);
        } else {
            $body = $this->renderPlainBody($templateData);
        }

        $this->send($to, $subject, $body, (string) ($data['email'] ?? ''));
    }

    /**
     * Fallback plain-HTML renderer when no EmailRenderer is injected.
     *
     * @param array{name:string,email:string,phone:string,message:string,extras:array<string,string>} $d
     */
    private function renderPlainBody(array $d): string
    {
        $h = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

        $body  = '<h2>Nuevo mensaje de contacto</h2>';
        $body .= '<p><strong>Nombre:</strong> ' . $h($d['name']) . '</p>';
        $body .= '<p><strong>Email:</strong> ' . $h($d['email']) . '</p>';
        if ($d['phone'] !== '') {
            $body .= '<p><strong>Teléfono:</strong> ' . $h($d['phone']) . '</p>';
        }
        if (!empty($d['extras'])) {
            $body .= '<h3>Información adicional</h3><ul>';
            foreach ($d['extras'] as $k => $v) {
                $body .= '<li><strong>' . $h($this->humanizeKey($k)) . ':</strong> ' . $h($v) . '</li>';
            }
            $body .= '</ul>';
        }
        $body .= '<p><strong>Mensaje:</strong></p>';
        $body .= '<p>' . nl2br($h($d['message'])) . '</p>';

        return $body;
    }

    private function humanizeKey(string $k): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $k));
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
