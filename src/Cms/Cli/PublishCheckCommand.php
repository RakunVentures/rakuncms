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

        $checker = new ScheduleChecker($basePath);
        $due = $checker->findPublishableEntries();

        if ($due === []) {
            $output->writeln('<info>No scheduled entries are due.</info>');
            return Command::SUCCESS;
        }

        $storage = ContentStorageFactory::make($basePath);

        // Mapa archivo-relativo → ContentRef. listKeys() es la vista de la fuente
        // de verdad (1 query en MySQL); ContentRef->file coincide con el .md que
        // escaneó ScheduleChecker por construcción (el cache se genera ahí mismo).
        $refByFile = [];
        foreach ($storage->listKeys() as $ref) {
            $refByFile[$ref->file] = $ref;
        }

        $output->writeln(sprintf(
            '<info>Found %d due entr%s:</info>',
            count($due),
            count($due) === 1 ? 'y' : 'ies',
        ));

        $promoted = 0;
        foreach ($due as $entry) {
            $rel = ltrim(substr((string) $entry['file'], strlen($basePath)), '/');
            $ref = $refByFile[$rel] ?? null;
            if ($ref === null) {
                $output->writeln(sprintf('  - [%s] %s (no en el store, omitida)', $entry['collection'], $entry['title']));
                continue;
            }

            $body = $storage->read($ref->collection, $ref->locale, $ref->slug);
            if ($body === null) {
                continue;
            }

            $meta = $body->frontmatter;
            $rawStatus = strtolower(trim((string) ($meta['status'] ?? '')));
            if (in_array($rawStatus, self::SCHEDULED_STATUSES, true)) {
                $meta['status'] = 'publish';
            }
            // Quitar el gate publish_date (si lo hubiera) → idempotente.
            unset($meta['publish_date']);

            $storage->write(new ContentDraft($ref->collection, $ref->locale, $ref->slug, $meta, $body->body));
            $promoted++;
            $output->writeln(sprintf('  - [%s] %s', $entry['collection'], $entry['title']));
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
