<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Avatar;

use Goal\Legacy\Core\Persistence\DatabaseInterface;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAppearance;
use Goal\Legacy\Modules\Player\Persistence\PlayerAppearanceRepository;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class PlayerAppearanceService
{
    public function __construct(
        private readonly AvatarCatalog $catalog = new AvatarCatalog(),
        private readonly PlayerAppearanceGenerator $generator = new PlayerAppearanceGenerator(),
    ) {
    }

    public function repository(DatabaseInterface $database): PlayerAppearanceRepository
    {
        return new PlayerAppearanceRepository($database);
    }

    public function getOrGenerate(DatabaseInterface $database, Player $player, ?SimulationDate $date = null): PlayerAppearance
    {
        $repository = $this->repository($database);
        $existing = $repository->get($player->id()->value());
        if ($existing !== null) { return $existing; }
        $appearance = $this->generator->generate($player, $date);
        $repository->save($player->id()->value(), $appearance);
        return $appearance;
    }

    public function save(DatabaseInterface $database, Player $player, PlayerAppearance $appearance): void
    {
        $this->repository($database)->save($player->id()->value(), $appearance);
    }

    public function catalog(): AvatarCatalog { return $this->catalog; }

    public function generator(): PlayerAppearanceGenerator { return $this->generator; }

    public function preset(string $presetId): ?PlayerAppearance { return $this->generator->preset($presetId); }
}
