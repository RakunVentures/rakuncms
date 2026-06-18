<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use Rkn\Cms\Content\ContentDraft;
use Rkn\Cms\Content\ContentStorageFactory;
use Rkn\Cms\Content\Indexer;
use Rkn\Cms\Content\IndexStoreFactory;
use Rkn\Cms\Content\ScheduleChecker;
use Rkn\Cms\Content\Stores\SqliteIndexStore;
use Rkn\Framework\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Publica las entradas programadas cuya fecha ya venció. Pensado para un cron
 * (Plesk/cPanel).
 *
 * Por cada entrada programada exigible (status future/scheduled/pending o
 * publish_date, con fecha efectiva pasada):
 *   1. Promueve la entrada a `publish` a través del ContentStorage — la FUENTE
 *      DE VERDAD (MySQL en sitios gestionados como Fiancee; .md en sitios
 *      flat-file). Escribir vía storage también actualiza el cache .md, lo que
 *      bumpea su mtime. Esto es clave: el índice sqlite es incremental por mtime
 *      y NUNCA recomputa el status de un archivo sin cambios, así que una fecha
 *      que vence no afloraría sola. Quita publish_date para que sea idempotente.
 *   2. Re-sincroniza el índice (sqlite/php) para que las promovidas afloren.
 *   3. Limpia la page cache para que listados/feeds regeneren.
 *
 * NO reescribe el .md directamente: en sitios MySQL el .md es un cache
 * regenerable y un edit directo se perdería en el próximo content:rebuild-cache.
 */
#[AsCommand(name: 'publish:check', description: 'Publish scheduled entries whose date is due')]
final class PublishCheckCommand extends Command
{
    private const SCHEDULED_STATUSES = ['future', 'scheduled', 'pending'];

    protected function configure(): void
    {
        $this->addOption(
            'base',
            null,
            InputOption::VALUE_REQUIRED,
            'Site root path (defaults to the container base_path or cwd). Operates on <base>/content + <base>/cache.',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $baseOpt = $input->getOption('base');
        if (is_string($baseOpt) && $baseOpt !== '') {
            if (!is_dir($baseOpt)) {
                $output->writeln("<error>Base path does not exist: {$baseOpt}</error>");
                return Command::FAILURE;
            }
            $basePath = rtrim($baseOpt, '/');
        } else {
            $basePath = $this->findBasePath();
        }

        // Boot the app so config('content.driver' / 'index.driver') resolve.
        if (Application::getInstance() === null) {
            new Application($basePath);
        }

        $storage = ContentStorageFactory::make($basePath);
        $checker = new ScheduleChecker($basePath);
        $now = new \DateTimeImmutable();

        // Recorre la FUENTE DE VERDAD por su propia identidad (listScheduled+read),
        // NO por rutas: en sitios MySQL el .md es un cache y su ruta
        // (cacheRelativePath, con sufijo de locale) no coincide con el layout real
        // (p.ej. WXR anidado sin locale) — casar por path fallaba y promovía 0.
        // read()/write() usan (collection, locale, slug) tal cual los emite el
        // store, así la escritura golpea exactamente la misma fila/archivo.
        // listScheduled() acota a las ~programadas (MySQL: índice de status),
        // evitando leer las ~10k entradas en cada corrida del cron.
        $promoted = 0;
        $titles = [];
        foreach ($storage->listScheduled() as $ref) {
            $body = $storage->read($ref->collection, $ref->locale, $ref->slug);
            if ($body === null) {
                continue;
            }

            $meta = $body->frontmatter;
            if (!$checker->isDuePublishable($meta, $now)) {
                continue;
            }

            $rawStatus = strtolower(trim((string) ($meta['status'] ?? '')));
            if (in_array($rawStatus, self::SCHEDULED_STATUSES, true)) {
                $meta['status'] = 'publish';
            }
            unset($meta['publish_date']); // quita el gate → idempotente

            $storage->write(new ContentDraft($ref->collection, $ref->locale, $ref->slug, $meta, $body->body));
            $promoted++;
            $titles[] = sprintf('  - [%s] %s', $ref->collection, $meta['title'] ?? $ref->slug);
        }

        if ($promoted === 0) {
            $output->writeln('<info>No scheduled entries are due.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<info>Promoting %d due entr%s:</info>',
            $promoted,
            $promoted === 1 ? 'y' : 'ies',
        ));
        foreach ($titles as $line) {
            $output->writeln($line);
        }

        // Re-sync del índice: el write de storage actualizó el .md (mtime), así que
        // el sync incremental (sqlite) reprocesa las promovidas.
        $store = IndexStoreFactory::make($basePath);
        if ($store instanceof SqliteIndexStore) {
            $report = $store->sync();
            $output->writeln(sprintf(
                '<info>Index synced (sqlite): %d inserted, %d updated.</info>',
                (int) ($report['inserted'] ?? 0),
                (int) ($report['updated'] ?? 0),
            ));
        } else {
            $index = (new Indexer($basePath))->rebuild();
            $output->writeln(sprintf(
                '<info>Index rebuilt with %d entries.</info>',
                $index['meta']['entry_count'] ?? 0,
            ));
        }

        $cleared = $this->clearPageCache($basePath);
        $output->writeln(sprintf('<info>Page cache cleared (%d files).</info>', $cleared));
        $output->writeln(sprintf(
            '<info>Done. %d entr%s promoted to published.</info>',
            $promoted,
            $promoted === 1 ? 'y' : 'ies',
        ));

        return Command::SUCCESS;
    }

    /** Borra recursivamente el HTML cacheado bajo cache/pages. */
    private function clearPageCache(string $basePath): int
    {
        $cachePath = $basePath . '/cache/pages';
        if (!is_dir($cachePath)) {
            return 0;
        }

        $count = 0;
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cachePath, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'html' && @unlink($file->getPathname())) {
                $count++;
            }
        }

        return $count;
    }

    private function findBasePath(): string
    {
        try {
            return \app('base_path');
        } catch (\Throwable) {
        }

        return getcwd() ?: dirname(__DIR__, 3);
    }
}
