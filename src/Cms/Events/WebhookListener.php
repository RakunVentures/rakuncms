<?php

declare(strict_types=1);

namespace Rkn\Cms\Events;

use Rkn\Cms\Queue\FileQueue;

final class WebhookListener
{
    /**
     * @param array<string, mixed> $webhookConfig Single webhook config entry
     */
    public function __construct(
        private array $webhookConfig,
        private FileQueue $queue,
    ) {
    }

    public function __invoke(Event $event): void
    {
        $payload = [
            'event' => $event->name(),
            'timestamp' => time(),
            'data' => $event->payload(),
        ];

        $jobPayload = [
            'url' => $this->webhookConfig['url'] ?? '',
            'headers' => $this->buildHeaders($payload),
            'body' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];

        $this->queue->push('webhook', $jobPayload);
    }

    /**
     * Build HTTP headers for the webhook request.
     *
     * @param array<string, mixed> $payload
     * @return array<string, string>
     */
    private function buildHeaders(array $payload): array
    {
        $headers = [
            'Content-Type' => 'application/json',
        ];

        // Add custom headers from config
        $configHeaders = $this->webhookConfig['headers'] ?? [];
        foreach ($configHeaders as $key => $value) {
            $headers[$key] = (string) $value;
        }

        // Add HMAC signature if secret is configured
        $secret = $this->webhookConfig['secret'] ?? '';
        if ($secret !== '') {
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $signature = hash_hmac('sha256', $body ?: '', $secret);
            $headers['X-Webhook-Signature'] = $signature;
        }

        return $headers;
    }

    /**
     * Register webhooks from config into the dispatcher.
     *
     * @param list<array<string, mixed>> $webhooksConfig
     */
    public static function registerFromConfig(array $webhooksConfig, EventDispatcher $dispatcher, FileQueue $queue): void
    {
        foreach ($webhooksConfig as $config) {
            $eventName = $config['event'] ?? '';
            if ($eventName === '' || empty($config['url'])) {
                continue;
            }

            $listener = new self($config, $queue);
            $dispatcher->listen($eventName, $listener);
        }
    }
}
