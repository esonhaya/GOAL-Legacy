<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Avatar;

use Goal\Legacy\Modules\Club\Domain\Club;
use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\World\Domain\SimulationDate;

final class PortraitContext
{
    /** @return array<string, mixed> */
    public static function forPlayer(Player $player, SimulationDate $date, ?Club $club = null, string $background = 'career', string $expression = 'neutral'): array
    {
        $age = $player->ageAt($date);
        $ageFamily = $age < 18 ? 'youth' : ($age < 25 ? 'young' : ($age < 31 ? 'mature' : ($age < 36 ? 'veteran' : 'late')));
        $bodyMass = $player->weightKg() / (($player->heightCm() / 100) ** 2);
        $body = $bodyMass < 20.5 ? 'slim' : ($bodyMass > 27.5 ? 'broad' : ($bodyMass > 23.5 ? 'athletic' : 'average'));
        $kit = 'avatar.kit.solid.01';
        if ($club !== null) {
            $kitFamilies = ['solid', 'vertical_stripes', 'horizontal_stripes', 'halves', 'sash', 'shoulders', 'sleeves', 'center_stripe', 'gradient', 'pinstripe', 'chevron', 'hoops'];
            $index = (int) (hexdec(substr(hash('sha256', 'kit:v1:' . $club->id()->value()), 0, 8)) % count($kitFamilies));
            $kit = sprintf('avatar.kit.%s.%02d', $kitFamilies[$index], $index + 1);
        }
        return [
            'label' => $player->preferredName(), 'age' => 'avatar.age.' . $ageFamily . '.' . match ($ageFamily) { 'youth' => '01', 'young' => '02', 'mature' => '03', 'veteran' => '04', default => '05' },
            'body' => $body, 'kit' => $kit, 'club_colors' => $club?->clubColors() ?? '', 'background' => 'avatar.background.' . $background . '.' . match ($background) { 'club' => '02', 'matchday' => '03', 'transfer' => '04', 'news' => '05', 'career' => '06', default => '01' },
            'expression' => 'avatar.expression.' . $expression . '.' . match ($expression) { 'happy' => '02', 'focused' => '03', 'disappointed' => '04', 'celebrating' => '05', default => '01' },
        ];
    }
}
