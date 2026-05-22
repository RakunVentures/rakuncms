<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * CLI command: rakun llms:generate
 *
 * Generates an llms.txt file using a local AI agent.
 */
#[AsCommand(name: 'llms:generate', description: 'Generate llms.txt using a local LLM agent')]
final class LlmsGenerateCommand extends Command
{
    protected function configure(): void
    {
        $this->addOption('agent', 'a', InputOption::VALUE_OPTIONAL, 'The LLM agent to use (gemini, claude, codex, opencode)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();
        $agent = $input->getOption('agent');

        if (!$agent) {
            // Auto-detect agent
            if ($this->commandExists('gemini')) {
                $agent = 'gemini';
            } elseif ($this->commandExists('claude')) {
                $agent = 'claude';
            } elseif ($this->commandExists('codex')) {
                $agent = 'codex';
            } elseif ($this->commandExists('opencode')) {
                $agent = 'opencode';
            } else {
                $output->writeln('<error>No local LLM agent detected. Please install gemini or claude, or specify one with --agent.</error>');
                // Fallback to gemini by default if neither is found in PATH, hoping it's aliased somewhere
                $agent = 'gemini';
                $output->writeln("<info>Falling back to default agent: $agent</info>");
            }
        }

        $output->writeln("<info>Using agent: $agent</info>");
        $output->writeln("Generating public/llms.txt...");

        $prompt = "Analiza los archivos Markdown en la carpeta content/ y la configuración en config/ de este proyecto. " .
                  "Con base en esa información, genera un archivo en 'public/llms.txt'. " .
                  "El archivo llms.txt debe servir como un resumen optimizado para otros LLMs. " .
                  "Debe contener: el propósito general del sitio web, sus páginas principales, " .
                  "sus productos o servicios clave (si aplica) y la estructura de su contenido. " .
                  "El objetivo es que cuando una IA lea llms.txt, entienda perfectamente el contexto y " .
                  "oferta del sitio. Crea y guarda el archivo directamente sin hacer preguntas extra.";

        $command = '';
        switch ($agent) {
            case 'claude':
                $command = sprintf('claude -p %s', escapeshellarg($prompt));
                break;
            case 'gemini':
                // Use -y for auto-confirming file writes and -p for non-interactive prompt mode
                $command = sprintf('gemini -y -p %s', escapeshellarg($prompt));
                break;
            default:
                $command = sprintf('%s %s', escapeshellcmd($agent), escapeshellarg($prompt));
                break;
        }

        $output->writeln("Executing: $command");
        
        $process = proc_open($command, [
            0 => STDIN,
            1 => STDOUT,
            2 => STDERR,
        ], $pipes, $basePath);

        if (is_resource($process)) {
            $status = proc_close($process);
            if ($status === 0) {
                $output->writeln("<info>Successfully generated llms.txt</info>");
                return Command::SUCCESS;
            } else {
                $output->writeln("<error>Agent returned status code $status</error>");
                return Command::FAILURE;
            }
        }

        return Command::FAILURE;
    }

    private function commandExists(string $cmd): bool
    {
        $returnVal = shell_exec(sprintf("command -v %s 2>/dev/null", escapeshellarg($cmd)));
        return !empty(trim((string)$returnVal));
    }

    private function findBasePath(): string
    {
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
