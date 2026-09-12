<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SqliteDatabase;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Time\SimulationTime;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use RuntimeException;
use Throwable;

final class PersistenceSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'persistence:self-check'; }

    public function description(): string { return 'Run an isolated SQLite persistence and save round-trip self-check.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-persistence-' . bin2hex(random_bytes(8));

        try {
            if (!$this->services->saveStore() instanceof SqliteSaveStore) {
                throw new RuntimeException('Bootstrapped Core save store is not SQLite-backed.');
            }

            $store = new SqliteSaveStore($directory, new JsonSerializer());
            $metadata = SaveMetadata::create(
                'self-check',
                'Persistence self-check',
                new SimulationTime(7),
                new DateTimeImmutable('@0'),
            );
            $store->create($metadata);
            $loaded = $store->open($metadata->id());

            if (!$store->exists($metadata->id()) || $loaded->simulationTime()->ticks() !== 7 || count($store->list()) !== 1) {
                throw new RuntimeException('Save metadata round-trip failed.');
            }

            $database = new SqliteDatabase(':memory:');
            $database->connection()->exec('CREATE TABLE rollback_probe (value INTEGER NOT NULL)');
            try {
                $database->transaction(static function (\PDO $connection): void {
                    $connection->exec('INSERT INTO rollback_probe (value) VALUES (1)');
                    throw new RuntimeException('expected rollback');
                });
            } catch (RuntimeException $exception) {
                if ($exception->getMessage() !== 'expected rollback') {
                    throw $exception;
                }
            }

            if ((int) $database->connection()->query('SELECT COUNT(*) FROM rollback_probe')->fetchColumn() !== 0) {
                throw new RuntimeException('Transaction rollback failed.');
            }

            $output->write('Persistence self-check passed with isolated SQLite storage.');

            return 0;
        } catch (Throwable $exception) {
            $output->error('Persistence self-check failed: ' . $exception->getMessage());

            return 1;
        } finally {
            $this->removeIsolatedDirectory($directory);
        }
    }

    private function removeIsolatedDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $files = glob($directory . '/*');
        if ($files === false) {
            throw new RuntimeException('Unable to inspect isolated persistence directory for cleanup.');
        }
        foreach ($files as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Unable to clean isolated persistence file.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Unable to clean isolated persistence directory.');
        }
    }
}
