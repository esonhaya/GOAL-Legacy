<?php

declare(strict_types=1);

namespace Goal\Legacy\Devtools\Commands;

use DateTimeImmutable;
use Goal\Legacy\Core\Bootstrap\CoreServices;
use Goal\Legacy\Core\Persistence\JsonSerializer;
use Goal\Legacy\Core\Persistence\SaveMetadata;
use Goal\Legacy\Core\Persistence\SqliteSaveStore;
use Goal\Legacy\Devtools\CommandInterface;
use Goal\Legacy\Devtools\ConsoleOutputInterface;
use Goal\Legacy\Core\Time\SimulationTime;
use RuntimeException;
use Throwable;

final class NationSelfCheckCommand implements CommandInterface
{
    public function __construct(private readonly CoreServices $services)
    {
    }

    public function name(): string { return 'nation:self-check'; }

    public function description(): string { return 'Validate, materialize, and reload Nations using isolated SQLite storage.'; }

    public function execute(array $arguments, ConsoleOutputInterface $output): int
    {
        $directory = sys_get_temp_dir() . '/goal-legacy-nation-' . bin2hex(random_bytes(8));

        try {
            $nations = $this->services->nationModule()->service()->loadSelected();
            if ($nations === []) {
                throw new RuntimeException('No selected Nation content is available.');
            }

            $store = new SqliteSaveStore($directory, new JsonSerializer());
            $metadata = SaveMetadata::create('nation-self-check', 'Nation self-check', new SimulationTime(0), new DateTimeImmutable('@0'));
            $store->create($metadata);
            $database = $store->openDatabase($metadata->id());
            $service = $this->services->nationModule()->service();
            $materialized = $service->materialize($database);
            $repository = $service->repository($database);
            $loaded = $repository->all();
            if ($materialized !== count($nations) || count($loaded) !== count($nations)) {
                throw new RuntimeException('Nation materialization count mismatch.');
            }
            foreach ($nations as $nation) {
                $restored = $repository->get($nation->id());
                if ($restored->toArray() !== $nation->toArray()) {
                    throw new RuntimeException(sprintf('Nation "%s" did not round-trip.', $nation->id()->value()));
                }
            }
            unset($database);

            $output->write(sprintf('Nation self-check passed with %d persisted records.', count($loaded)));

            return 0;
        } catch (Throwable $exception) {
            $output->error('Nation self-check failed: ' . $exception->getMessage());

            return 1;
        } finally {
            $this->removeIsolatedStorage($directory);
        }
    }

    private function removeIsolatedStorage(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (glob($directory . '/*') ?: [] as $file) {
            if (is_file($file) && !unlink($file)) {
                throw new RuntimeException('Unable to clean isolated Nation database.');
            }
        }
        if (!rmdir($directory)) {
            throw new RuntimeException('Unable to clean isolated Nation directory.');
        }
    }
}
