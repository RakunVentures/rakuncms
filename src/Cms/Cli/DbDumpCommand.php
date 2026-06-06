<?php

declare(strict_types=1);

namespace Rkn\Cms\Cli;

use PDO;
use Rkn\Cms\Content\ContentStorageFactory;
use Rkn\Framework\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'db:dump', description: 'Dump the CMS MySQL database to a SQL file')]
final class DbDumpCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Output SQL file path')
            ->addOption('exclude-revisions', null, InputOption::VALUE_NONE, 'Exclude the content_revisions table from the dump');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $basePath = $this->findBasePath();

        if (Application::getInstance() === null) {
            new Application($basePath);
        }

        $driver = ContentStorageFactory::driver();
        if ($driver !== 'mysql') {
            $output->writeln("<error>Database dump is only available when content.driver is \"mysql\". Current driver is: \"{$driver}\"</error>");
            return Command::FAILURE;
        }

        $cfg  = ContentStorageFactory::mysqlConfig();
        $host = $cfg['host'];
        $port = $cfg['port'];
        $db   = $cfg['database'];

        if ($db === '') {
            $output->writeln('<error>No MySQL database name configured in config/rakun.yaml or .env</error>');
            return Command::FAILURE;
        }

        $output->writeln("<info>Connecting to database \"{$db}\" on {$host}:{$port}...</info>");

        try {
            $pdo = ContentStorageFactory::pdo();
        } catch (\Throwable $e) {
            $output->writeln("<error>Database connection failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        // Determine output path
        $outputPath = $input->getOption('output');
        if ($outputPath === null) {
            $backupDir = $basePath . '/storage/backups';
            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0700, true);
            }
            $outputPath = $backupDir . '/db-dump-' . $db . '-' . date('Y-m-d-His') . '.sql';
        }

        $output->writeln("<info>Exporting database structure and data to \"{$outputPath}\"...</info>");

        $outputStream = fopen($outputPath, 'w');
        if ($outputStream === false) {
            $output->writeln("<error>Failed to open output file for writing: {$outputPath}</error>");
            return Command::FAILURE;
        }
        // The dump contains the full content store — keep it owner-only (shared hosting).
        @chmod($outputPath, 0600);

        $tables = ['contents', 'content_revisions', 'content_tags'];
        if ($input->getOption('exclude-revisions')) {
            $tables = array_filter($tables, fn($t) => $t !== 'content_revisions');
            $output->writeln('<comment>Excluding "content_revisions" table from the dump.</comment>');
        }

        try {
            // Single point-in-time view across all tables (InnoDB) — equivalent to
            // mysqldump --single-transaction; readers don't block concurrent writers.
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            // Write header
            $now = date('Y-m-d H:i:s');
            fwrite($outputStream, "-- ------------------------------------------------------\n");
            fwrite($outputStream, "-- RakunCMS Database Dump\n");
            fwrite($outputStream, "-- Generated: {$now}\n");
            fwrite($outputStream, "-- Database: {$db}\n");
            fwrite($outputStream, "-- Host: {$host}\n");
            fwrite($outputStream, "-- ------------------------------------------------------\n\n");
            fwrite($outputStream, "SET NAMES utf8mb4;\n");
            fwrite($outputStream, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($tables as $table) {
                // Check if table exists
                try {
                    $pdo->query("SELECT 1 FROM `{$table}` LIMIT 1");
                } catch (\Throwable) {
                    $output->writeln("<comment>Table \"{$table}\" does not exist in the database, skipping.</comment>");
                    continue;
                }

                $output->write("  Dumping table \"{$table}\"... ");

                // Fetch CREATE TABLE
                $createResult = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
                $createSql = $createResult['Create Table'] ?? '';

                if ($createSql === '') {
                    throw new \RuntimeException("failed to read schema for table {$table}");
                }

                fwrite($outputStream, "--\n");
                fwrite($outputStream, "-- Table structure for table `{$table}`\n");
                fwrite($outputStream, "--\n\n");
                fwrite($outputStream, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($outputStream, "{$createSql};\n\n");

                fwrite($outputStream, "--\n");
                fwrite($outputStream, "-- Dumping data for table `{$table}`\n");
                fwrite($outputStream, "--\n\n");
                fwrite($outputStream, "LOCK TABLES `{$table}` WRITE;\n");

                // Fetch table data and stream to file
                $stmt = $pdo->query("SELECT * FROM `{$table}`");
                $columns = [];
                $chunk = [];
                $chunkSize = 100;
                $rowCount = 0;
                $insertPrefix = '';

                while ($row = $stmt->fetch()) {
                    if (empty($columns)) {
                        $columns = array_keys($row);
                        $escapedColumns = array_map(fn($col) => "`{$col}`", $columns);
                        $insertPrefix = "INSERT INTO `{$table}` (" . implode(', ', $escapedColumns) . ") VALUES \n";
                    }

                    $values = [];
                    foreach ($row as $val) {
                        if ($val === null) {
                            $values[] = 'NULL';
                        } elseif (is_int($val) || is_float($val)) {
                            $values[] = (string) $val;
                        } elseif (is_bool($val)) {
                            $values[] = $val ? '1' : '0';
                        } else {
                            $values[] = $pdo->quote((string) $val);
                        }
                    }
                    $chunk[] = "(" . implode(', ', $values) . ")";
                    $rowCount++;

                    if (count($chunk) >= $chunkSize) {
                        fwrite($outputStream, $insertPrefix . implode(",\n", $chunk) . ";\n");
                        $chunk = [];
                    }
                }

                if (!empty($chunk)) {
                    fwrite($outputStream, $insertPrefix . implode(",\n", $chunk) . ";\n");
                }

                fwrite($outputStream, "UNLOCK TABLES;\n\n");
                $output->writeln("<info>done ({$rowCount} rows)</info>");
            }

            fwrite($outputStream, "SET FOREIGN_KEY_CHECKS=1;\n");
            $pdo->exec('COMMIT');
            fclose($outputStream);
        } catch (\Throwable $e) {
            fclose($outputStream);
            // Never leave a half-written dump on disk — it would restore corrupt.
            @unlink($outputPath);
            $output->writeln("<error>Dump failed: {$e->getMessage()}</error>");
            return Command::FAILURE;
        }

        $size = filesize($outputPath);
        $sizeStr = $size > 1048576
            ? round($size / 1048576, 1) . 'MB'
            : round($size / 1024, 1) . 'KB';

        $output->writeln("<info>Database dump successfully created at: {$outputPath} ({$sizeStr})</info>");

        return Command::SUCCESS;
    }

    private function findBasePath(): string
    {
        try {
            return (string) \app('base_path');
        } catch (\Throwable) {
        }

        return getcwd() ?: dirname(__DIR__, 3);
    }
}
