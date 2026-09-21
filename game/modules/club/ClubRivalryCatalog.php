<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Club;

/**
 * Canonical Club-to-Club relationships for fixture context.
 *
 * This is intentionally a small, reviewed content catalog.  It is not a
 * reputation, sentiment, or procedural relationship system.  Pair lookup is
 * symmetric and has no persistence side effect.
 */
final class ClubRivalryCatalog
{
    public const NONE = 'none';
    public const DERBY = 'derby';
    public const RIVALRY = 'rivalry';
    public const DERBY_RIVALRY = 'derby_rivalry';

    /** @var list<array{a:string,b:string,type:string,name:string,reason:string}> */
    private const PAIRS = [
        ['a' => 'arsenal', 'b' => 'tottenham-hotspur', 'type' => self::DERBY_RIVALRY, 'name' => 'North London derby', 'reason' => 'A long-established local rivalry between two London Clubs.'],
        ['a' => 'borussia-dortmund', 'b' => 'schalke-04', 'type' => self::DERBY_RIVALRY, 'name' => 'Revierderby', 'reason' => 'A historic regional derby in the Ruhr football area.'],
        ['a' => 'cardiff-city', 'b' => 'swansea-city', 'type' => self::DERBY_RIVALRY, 'name' => 'South Wales derby', 'reason' => 'A long-running Welsh Club derby.'],
        ['a' => 'everton', 'b' => 'liverpool', 'type' => self::DERBY_RIVALRY, 'name' => 'Merseyside derby', 'reason' => 'A historic local rivalry between Liverpool Clubs.'],
        ['a' => 'inter', 'b' => 'milan', 'type' => self::DERBY_RIVALRY, 'name' => 'Milan derby', 'reason' => 'A historic Milan derby between two established Clubs.'],
        ['a' => 'juventus', 'b' => 'torino', 'type' => self::DERBY_RIVALRY, 'name' => 'Turin derby', 'reason' => 'A traditional Turin derby.'],
        ['a' => 'manchester-city', 'b' => 'manchester-united', 'type' => self::DERBY_RIVALRY, 'name' => 'Manchester derby', 'reason' => 'A historic local rivalry between Manchester Clubs.'],
        ['a' => 'newcastle-united', 'b' => 'sunderland', 'type' => self::DERBY_RIVALRY, 'name' => 'Tyne–Wear derby', 'reason' => 'A traditional North-East derby.'],
        ['a' => 'nottingham-forest', 'b' => 'derby-county', 'type' => self::DERBY_RIVALRY, 'name' => 'East Midlands derby', 'reason' => 'A long-running East Midlands derby.'],
        ['a' => 'portsmouth', 'b' => 'southampton', 'type' => self::DERBY_RIVALRY, 'name' => 'South Coast derby', 'reason' => 'A historic South Coast Club rivalry.'],
        ['a' => 'roma', 'b' => 'lazio', 'type' => self::DERBY_RIVALRY, 'name' => 'Rome derby', 'reason' => 'A historic Rome derby.'],
        ['a' => 'sheffield-united', 'b' => 'sheffield-wednesday', 'type' => self::DERBY_RIVALRY, 'name' => 'Steel City derby', 'reason' => 'A traditional Sheffield derby.'],
        ['a' => 'west-ham-united', 'b' => 'millwall', 'type' => self::DERBY_RIVALRY, 'name' => 'Dockers derby', 'reason' => 'A historic East London derby.'],
        ['a' => 'arsenal', 'b' => 'manchester-united', 'type' => self::RIVALRY, 'name' => 'Historic English rivalry', 'reason' => 'A sustained competitive rivalry between major English Clubs.'],
        ['a' => 'bayern-munich', 'b' => 'borussia-dortmund', 'type' => self::RIVALRY, 'name' => 'German title rivalry', 'reason' => 'A sustained high-level German competitive rivalry.'],
        ['a' => 'barcelona', 'b' => 'real-madrid', 'type' => self::RIVALRY, 'name' => 'El Clásico', 'reason' => 'A historic Spanish Club rivalry.'],
        ['a' => 'liverpool', 'b' => 'manchester-united', 'type' => self::RIVALRY, 'name' => 'North-West rivalry', 'reason' => 'A historic English rivalry between two major Clubs.'],
        ['a' => 'marseille', 'b' => 'paris-saint-germain', 'type' => self::RIVALRY, 'name' => 'Le Classique', 'reason' => 'A historic French Club rivalry.'],
    ];

    /** @return list<array{a:string,b:string,type:string,name:string,reason:string}> */
    public static function pairs(): array
    {
        return self::PAIRS;
    }

    /** @return array<string,mixed> */
    public static function relationship(string $homeClubId, string $awayClubId): array
    {
        $key = self::pairKey($homeClubId, $awayClubId);
        foreach (self::PAIRS as $pair) {
            if (self::pairKey($pair['a'], $pair['b']) !== $key) {
                continue;
            }

            return [
                'type' => $pair['type'],
                'is_derby' => in_array($pair['type'], [self::DERBY, self::DERBY_RIVALRY], true),
                'is_rivalry' => in_array($pair['type'], [self::RIVALRY, self::DERBY_RIVALRY], true),
                'name' => $pair['name'],
                'reason' => $pair['reason'],
                'pair_key' => $key,
            ];
        }

        return [
            'type' => self::NONE,
            'is_derby' => false,
            'is_rivalry' => false,
            'name' => null,
            'reason' => null,
            'pair_key' => null,
        ];
    }

    /** @param list<string> $clubIds @return list<string> */
    public static function validate(array $clubIds): array
    {
        $known = array_fill_keys(array_map('strval', $clubIds), true);
        $errors = [];
        $seen = [];
        foreach (self::PAIRS as $pair) {
            $key = self::pairKey($pair['a'], $pair['b']);
            if (isset($seen[$key])) {
                $errors[] = 'duplicate:' . $key;
            }
            $seen[$key] = true;
            foreach (['a', 'b'] as $side) {
                if (!isset($known[$pair[$side]])) {
                    $errors[] = 'unknown_club:' . $pair[$side];
                }
            }
            $forward = self::relationship($pair['a'], $pair['b']);
            $reverse = self::relationship($pair['b'], $pair['a']);
            if ($forward['type'] !== $reverse['type'] || $forward['pair_key'] !== $reverse['pair_key']) {
                $errors[] = 'asymmetric:' . $key;
            }
        }

        return array_values(array_unique($errors));
    }

    /** @return array{clubs_total:int,rivalry_pairs:int,derby_pairs:int,rivalry_only_pairs:int,clubs_with_rival:int,clubs_without_rival:int} */
    public static function audit(array $clubIds): array
    {
        $clubs = array_values(array_unique(array_map('strval', $clubIds)));
        $rivalClubs = [];
        $derbyPairs = 0;
        $rivalryOnlyPairs = 0;
        foreach (self::PAIRS as $pair) {
            $rivalClubs[$pair['a']] = true;
            $rivalClubs[$pair['b']] = true;
            if ($pair['type'] === self::DERBY_RIVALRY || $pair['type'] === self::DERBY) {
                ++$derbyPairs;
            }
            if ($pair['type'] === self::RIVALRY) {
                ++$rivalryOnlyPairs;
            }
        }

        return [
            'clubs_total' => count($clubs),
            'rivalry_pairs' => count(self::PAIRS),
            'derby_pairs' => $derbyPairs,
            'rivalry_only_pairs' => $rivalryOnlyPairs,
            'clubs_with_rival' => count(array_intersect(array_keys($rivalClubs), $clubs)),
            'clubs_without_rival' => count(array_diff($clubs, array_keys($rivalClubs))),
        ];
    }

    private static function pairKey(string $left, string $right): string
    {
        $ids = [$left, $right];
        sort($ids, SORT_STRING);

        return $ids[0] . '|' . $ids[1];
    }
}
