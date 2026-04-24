<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Mail\EmailRenderer;
use Rkn\Cms\Mail\Mailer;
use Rkn\Cms\Queue\FileQueue;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun queue:process
 *
 * Processes pending jobs from the file queue.
 * Designed to run via cron:
 *   * * * * * /usr/bin/flock -n /tmp/rakun-queue.lock /usr/bin/php bin/rakun queue:process
 */
#[AsCommand(name: 'queue:process', description: 'Process pending queue jobs')]
final class QueueProcessCommand extends Command
{

    protected function configure(): void
    {
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of jobs to process', '10');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();
        $this->bootstrapFramework($basePath);
        $queue = new FileQueue($basePath);
        $limit = (int) $input->getOption('limit');
        $processed = 0;

        $output->writeln('Processing queue...');

        while ($processed < $limit) {
            $job = $queue->reserve();
            if ($job === null) {
                break;
            }

            $id = $job['id'] ?? 'unknown';
            $type = $job['type'] ?? 'unknown';
            $attempt = $job['attempts'] ?? 1;

            $output->writeln(sprintf('  [%s] Processing "%s" (attempt %d)...', $id, $type, $attempt));

            try {
                $this->processJob($job, $basePath);
                $queue->complete($id);
                $output->writeln(sprintf('  [%s] <info>Completed.</info>', $id));
                $processed++;
            } catch (\Throwable $e) {
                $queue->fail($id);
                $output->writeln(sprintf('  [%s] <error>Failed: %s</error>', $id, $e->getMessage()));
            }
        }

        if ($processed === 0) {
            $output->writeln('No pending jobs.');
        } else {
            $output->writeln(sprintf('<info>Processed %d job(s).</info>', $processed));
        }

        return Command::SUCCESS;
    }

    /**
     * Dispatch job to appropriate handler based on type.
     *
     * @param array<string, mixed> $job
     */
    private function processJob(array $job, string $basePath): void
    {
        $type = $job['type'] ?? '';
        $payload = $job['payload'] ?? [];

        match ($type) {
            'send-contact-email' => $this->handleContactEmail($payload, $basePath),
            default => throw new \RuntimeException('Unknown job type: ' . $type),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function handleContactEmail(array $payload, string $basePath): void
    {
        $config = $this->loadMailConfig($basePath);
        $brand = array_merge(
            ['site_name' => $config['from_name'] ?? 'RakunCMS'],
            $config['brand'] ?? []
        );
        $renderer = new EmailRenderer(
            EmailRenderer::resolveTemplatePaths($basePath),
            $brand
        );
        $mailer = new Mailer($config, $renderer);
        $mailer->sendContactForm($payload);
    }

    /**
     * Bootstrap the framework Application so .env is loaded and
     * ${VAR} placeholders in YAML are resolved (same pipeline as HTTP).
     */
    private function bootstrapFramework(string $basePath): void
    {
        if (\Rkn\Framework\Application::getInstance() !== null) {
            return;
        }
        if (!class_exists(\Rkn\Framework\Application::class)) {
            return;
        }
        new \Rkn\Framework\Application($basePath);
    }

    /**
     * @return array<string, mixed>
     */
    private function loadMailConfig(string $basePath): array
    {
        $app = \Rkn\Framework\Application::getInstance();
        if ($app !== null) {
            $mail = $app->config('mail', []);
            if (is_array($mail)) {
                return $mail;
            }
        }

        $configFile = $basePath . '/config/rakun.yaml';
        if (!is_file($configFile)) {
            return [];
        }

        $config = \Symfony\Component\Yaml\Yaml::parseFile($configFile);

        return $config['mail'] ?? [];
    }

    private function findBasePath(): string
    {
        // Try Application instance first
        try {
            $app = \Rkn\Framework\Application::getInstance();
            if ($app !== null) {
                return $app->getBasePath();
            }
        } catch (\Throwable) {
        }

        return getcwd() ?: dirname(__DIR__, 3);
    }
}
