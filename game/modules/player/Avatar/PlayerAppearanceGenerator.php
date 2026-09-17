<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Avatar;

use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAppearance;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

/** Deterministic appearance generation with independent category namespaces. */
final class PlayerAppearanceGenerator
{
    public function __construct(private readonly AvatarCatalog $catalog = new AvatarCatalog())
    {
    }

    public function generate(Player $player, ?SimulationDate $date = null): PlayerAppearance
    {
        $date ??= SimulationDate::fromIsoString('2024-08-01');
        $age = $player->ageAt($date);
        $hairColors = $this->catalog->palettes('hair');
        if ($age < 31) {
            $hairColors = array_values(array_filter($hairColors, static fn (array $palette): bool => !in_array($palette['id'] ?? '', ['palette.hair.10', 'palette.hair.11', 'palette.hair.12'], true)));
        }
        $beards = $this->catalog->assetIds('facial_hair');
        if ($age < 20) {
            $beards = array_values(array_filter($beards, static fn (string $id): bool => str_contains($id, '.none.') || str_contains($id, '.stubble.') || str_contains($id, '.light.')));
        }

        return PlayerAppearance::fromArray([
            'skin_tone' => $this->pickPalette('skin', $player, 'skin'),
            'face' => $this->pick('face', $player),
            'jaw' => $this->pick('jaw', $player),
            'ears' => $this->pick('ears', $player),
            'eyes' => $this->pick('eyes', $player),
            'eye_color' => $this->pickPalette('eye', $player, 'eye_color'),
            'brows' => $this->pick('brows', $player),
            'nose' => $this->pick('nose', $player),
            'mouth' => $this->pick('mouth', $player),
            'hair' => $this->pick('hair', $player),
            'hair_color' => $this->pickFrom($hairColors, $player, 'hair_color'),
            'facial_hair' => $this->pickFromIds($beards, $player, 'facial_hair'),
            'facial_hair_color' => $this->pickPalette('hair', $player, 'facial_hair_color'),
            'skin_detail' => $this->pick('skin_detail', $player),
            'scar' => $this->pick('scar', $player),
            'accessory' => $this->pick('accessory', $player),
        ]);
    }

    public function preset(string $presetId): ?PlayerAppearance
    {
        $preset = $this->catalog->preset($presetId);
        return is_array($preset['appearance'] ?? null) ? PlayerAppearance::fromArray($preset['appearance']) : null;
    }

    public function randomize(Player $player, ?SimulationDate $date = null, ?PlayerAppearance $current = null): PlayerAppearance
    {
        return $this->generate($player, $date);
    }

    public function randomizeCategory(Player $player, PlayerAppearance $current, string $category): PlayerAppearance
    {
        $field = match ($category) {
            'skin' => 'skin_tone', 'eyes' => 'eyes', 'eye_color' => 'eye_color', 'hair' => 'hair',
            'hair_color' => 'hair_color', 'facial_hair' => 'facial_hair', 'facial_hair_color' => 'facial_hair_color',
            'details' => 'skin_detail', default => $category,
        };
        $value = str_ends_with($field, '_color') || $field === 'skin_tone'
            ? $this->pickPalette($field === 'skin_tone' ? 'skin' : ($field === 'eye_color' ? 'eye' : 'hair'), $player, 'creator:' . $field)
            : $this->pick($field === 'skin_detail' ? 'skin_detail' : $field, $player, 'creator:' . $field);
        return $current->withChanges([$field => $value]);
    }

    /** @param list<Player> $players @return array<string, PlayerAppearance> */
    public function generateSquad(array $players, ?SimulationDate $date = null): array
    {
        $result = [];
        $used = [];
        foreach ($players as $player) {
            $appearance = $this->generate($player, $date);
            $attempt = 0;
            while (isset($used[$appearance->fingerprint()]) && $attempt < 4) {
                ++$attempt;
                $appearance = $appearance->withChanges(['hair' => $this->pick('hair', $player, 'squad-variation-' . $attempt)]);
            }
            $used[$appearance->fingerprint()] = true;
            $result[$player->id()->value()] = $appearance;
        }
        return $result;
    }

    private function pick(string $category, Player $player, string $namespace = ''): string
    {
        return $this->pickFromIds($this->catalog->assetIds($category, true), $player, $namespace === '' ? $category : $namespace);
    }

    private function pickPalette(string $category, Player $player, string $namespace): string
    {
        return $this->pickFrom($this->catalog->palettes($category), $player, $namespace);
    }

    /** @param list<array<string, mixed>> $items */
    private function pickFrom(array $items, Player $player, string $namespace): string
    {
        if ($items === []) { return ''; }
        $index = $this->index($player, $namespace, count($items));
        return (string) ($items[$index]['id'] ?? '');
    }

    /** @param list<string> $items */
    private function pickFromIds(array $items, Player $player, string $namespace): string
    {
        if ($items === []) { return ''; }
        return $items[$this->index($player, $namespace, count($items))];
    }

    private function index(Player $player, string $namespace, int $count): int
    {
        $hash = hash('sha256', 'appearance:v1:' . $namespace . ':' . $player->id()->value() . ':' . $player->creationSeed());
        return (int) (hexdec(substr($hash, 0, 12)) % $count);
    }
}
