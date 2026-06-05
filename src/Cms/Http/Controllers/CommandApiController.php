<?php

declare(strict_types=1);

namespace Rkn\Cms\Http\Controllers;

use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use Rkn\Cms\Cli\CacheClearCommand;
use Rkn\Cms\Cli\CacheWarmupCommand;
use Rkn\Cms\Cli\IndexRebuildCommand;
use Rkn\Cms\Cli\QueueProcessCommand;
use Rkn\Cms\Cli\SitemapGenerateCommand;
use Rkn\Cms\Cli\TemplateWarmupCommand;
use Rkn\Cms\Cli\WxrImportCommand;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Ejecuta una ALLOWLIST de comandos de mantenimiento del CLI (cache, índice,
 * sitemap, cola) y el import de WordPress (wxr:import) vía HTTP, para que un admin
 * remoto pueda dispararlos sobre el sitio sin acceso SSH.
 *
 * Modelo de seguridad:
 *   - Comandos de mantenimiento: allowlist de NOMBRE PURO, SIN argumentos del
 *     usuario (run()). Solo se instancia/ejecuta lo que esté en ALLOWED.
 *   - Import WXR (importWxr()): único comando con argumentos. El path lo GENERA el
 *     servidor (la subida, no el usuario); collection/post-type se SANEAN; los flags
 *     son booleanos. Se invoca por argv (ArrayInput), nunca por shell.
 *   El gateo de permiso 'admin' lo hace el dispatcher (requirePermission).
 *
 * Ejecución IN-PROCESS (sin proc_open) con chdir($basePath) porque los comandos
 * resuelven rutas vía getcwd().
 */
final class CommandApiController
{
    /** @var array<string, class-string<Command>> */
    private const ALLOWED = [
        'cache:clear'      => CacheClearCommand::class,
        'cache:warmup'     => CacheWarmupCommand::class,
        'templates:warmup' => TemplateWarmupCommand::class,
        'index:rebuild'    => IndexRebuildCommand::class,
        'sitemap:generate' => SitemapGenerateCommand::class,
        'queue:process'    => QueueProcessCommand::class,
    ];

    public function __construct(private readonly string $basePath)
    {
    }

    /** Lista los comandos de mantenimiento disponibles (solo lectura). */
    public function list(): ResponseInterface
    {
        return $this->json(200, ['commands' => array_keys(self::ALLOWED)]);
    }

    /** Ejecuta un comando de mantenimiento de la allowlist (sin argumentos). */
    public function run(string $command): ResponseInterface
    {
        if (! isset(self::ALLOWED[$command])) {
            return $this->json(404, [
                'error'   => "Command '{$command}' is not available",
                'allowed' => array_keys(self::ALLOWED),
            ]);
        }

        $class  = self::ALLOWED[$command];
        $result = $this->runConsole(new $class(), ['command' => $command]);

        return $this->json(200, [
            'ok'        => $result['exit'] === 0,
            'command'   => $command,
            'exit_code' => $result['exit'],
            'output'    => $result['output'],
        ]);
    }

    /**
     * Importa un export WXR de WordPress (síncrono). El admin sube el .xml por
     * multipart; el servidor lo guarda en una ruta propia y corre wxr:import con
     * args saneados. wxr:import es idempotente (salta lo ya importado): si el web
     * server corta un import grande, re-subir reanuda donde quedó.
     */
    public function importWxr(ServerRequestInterface $request): ResponseInterface
    {
        $file = $request->getUploadedFiles()['file'] ?? null;
        if (! $file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->json(400, ['error' => 'No se subió ningún archivo (campo «file») o hubo un error de subida.']);
        }

        $contents = (string) $file->getStream();
        if (! str_contains($contents, '<rss') && ! str_contains($contents, '<?xml')) {
            return $this->json(415, ['error' => 'El archivo no parece un export WXR de WordPress (XML).']);
        }

        $dir = $this->basePath . '/storage/uploads/wxr';
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        // Ruta GENERADA por el servidor — nunca proviene del usuario.
        $stored = $dir . '/' . uniqid('wxr_', true) . '.xml';
        file_put_contents($stored, $contents);

        $body       = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $collection = $this->sanitizeSlug((string) ($body['collection'] ?? 'blog')) ?: 'blog';
        $postTypes  = $this->parsePostTypes($body['post_type'] ?? 'post,page');

        $input = [
            'command'       => 'wxr:import',
            'path'          => $stored,
            '--collection'  => $collection,
            '--post-type'   => $postTypes,
        ];
        if ($this->truthy($body['download_images'] ?? false)) {
            $input['--download-images'] = true;
        }
        if ($this->truthy($body['overwrite'] ?? false)) {
            $input['--overwrite'] = true;
        }

        try {
            $result = $this->runConsole(new WxrImportCommand(), $input);
        } finally {
            @unlink($stored);
        }

        return $this->json(200, [
            'ok'         => $result['exit'] === 0,
            'command'    => 'wxr:import',
            'collection' => $collection,
            'exit_code'  => $result['exit'],
            'output'     => $result['output'],
        ]);
    }

    /**
     * Corre un Command de Symfony Console IN-PROCESS y captura su salida + código.
     * chdir($basePath) porque los comandos resuelven rutas vía getcwd(); se restaura
     * siempre. Sube memory/time limit (warmup/rebuild/import recorren todo el sitio).
     *
     * @param  array<string, mixed>  $input  ArrayInput (command + args/opts; sin shell)
     * @return array{exit: int, output: string}
     */
    private function runConsole(Command $command, array $input): array
    {
        @ini_set('memory_limit', '1024M');
        @set_time_limit(0);

        $previousCwd = getcwd();
        if (is_string($previousCwd)) {
            chdir($this->basePath);
        }

        $output   = new BufferedOutput();
        $exitCode = 1;

        try {
            $app = new Application('RakunCMS');
            $app->setAutoExit(false);
            $app->setCatchExceptions(false);
            $app->addCommand($command);
            $exitCode = $app->run(new ArrayInput($input), $output);
        } catch (\Throwable $e) {
            $output->writeln('Error: ' . $e->getMessage());
            $exitCode = 1;
        } finally {
            if (is_string($previousCwd)) {
                chdir($previousCwd);
            }
        }

        return ['exit' => $exitCode, 'output' => trim($this->stripAnsi($output->fetch()))];
    }

    /** Slug seguro para una colección (sin traversal): solo [a-z0-9_-]. */
    private function sanitizeSlug(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9_-]/i', '', $value);
    }

    /**
     * post-type de wxr:import: acepta lista o CSV; sanea cada valor; default post,page.
     *
     * @param  mixed  $value
     * @return list<string>
     */
    private function parsePostTypes(mixed $value): array
    {
        $raw = is_array($value) ? $value : explode(',', (string) $value);
        $types = [];
        foreach ($raw as $type) {
            $clean = $this->sanitizeSlug((string) $type);
            if ($clean !== '') {
                $types[] = $clean;
            }
        }

        return $types === [] ? ['post', 'page'] : array_values(array_unique($types));
    }

    private function truthy(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'on', 'yes'], true);
    }

    /** @param array<string, mixed> $data */
    private function json(int $status, array $data): ResponseInterface
    {
        return new Response($status, ['Content-Type' => 'application/json'], json_encode($data) ?: '{}');
    }

    /** Quita los códigos de color ANSI de la salida del comando. */
    private function stripAnsi(string $text): string
    {
        return (string) preg_replace('/\e\[[0-9;]*m/', '', $text);
    }
}
