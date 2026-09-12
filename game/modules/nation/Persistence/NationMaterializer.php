<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Nation\Persistence;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Nation\Domain\Nation;
use Goal\Legacy\Modules\Nation\Domain\NationException;

final class NationMaterializer
{
    public function __construct(private readonly DatabaseInterface $database)
    {
    }

    /** @param list<Nation> $nations */
    public function materialize(array $nations): int
    {
        $ordered = [];
        foreach ($nations as $nation) {
            $id = $nation->id()->value();
            if (isset($ordered[$id])) {
                throw new NationException(sprintf('Duplicate Nation ID "%s" cannot be materialized.', $id));
            }
            $ordered[$id] = $nation;
        }
        ksort($ordered, SORT_STRING);
        $repository = new NationRepository($this->database);

        return $this->database->transaction(function () use ($ordered, $repository): int {
            foreach ($ordered as $nation) {
                $repository->save($nation);
            }

            return count($ordered);
        });
    }
}
