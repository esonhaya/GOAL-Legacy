<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Domain;

use InvalidArgumentException;

/** Input collected by the playable career-start boundary. */
final readonly class CareerStartRequest
{
    public function __construct(
        public string $careerId,
        public string $name,
        public string $nationId,
        public int $heightCm,
        public int $weightKg,
        public string $position,
        public string $archetype,
        public int $seed,
        public ?string $preferredFoot = null,
    ) {
        new CareerId($careerId);
        if (strlen($careerId) > 40) {
            throw new InvalidArgumentException('Career start IDs must leave room for canonical Player and Contract IDs.');
        }
        if (trim($name) === '' || strlen(trim($name)) > 80) {
            throw new InvalidArgumentException('Player name must contain 1-80 bytes of visible text.');
        }
        if ($seed < 0) {
            throw new InvalidArgumentException('Career seed cannot be negative.');
        }
        if ($preferredFoot !== null) {
            PlayerFoot::fromInput($preferredFoot);
        }
    }
}
