<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Finance;

/** Small immutable controlled-player lifestyle catalog. */
final class LifestyleCatalog
{
    /** @return list<array<string, mixed>> */
    public static function all(): array
    {
        return [
            self::item('transport.bicycle', 'Transport', 'Modest', 'Training bicycle', 'A reliable way to keep short trips simple.', 30, ['travel_convenience' => 1]),
            self::item('transport.scooter', 'Transport', 'Comfortable', 'City scooter', 'Makes recovery days and Club travel easier.', 200, ['travel_convenience' => 2]),
            self::item('transport.used-car', 'Transport', 'Premium', 'Reliable used car', 'A practical step toward independence between Matches.', 5000, ['travel_convenience' => 3]),
            self::item('transport.premium-car', 'Transport', 'Elite', 'Premium car', 'A comfortable upgrade for a settled professional life.', 25000, ['travel_convenience' => 3]),
            self::item('home.shared-room', 'Home', 'Modest', 'Private room', 'A stable first home base near the football routine.', 75, ['lifestyle_event_weight' => 1]),
            self::item('home.city-apartment', 'Home', 'Comfortable', 'City apartment', 'A more comfortable place to recover between fixtures.', 2500, ['recovery_support' => 1, 'lifestyle_event_weight' => 1]),
            self::item('home.premium-residence', 'Home', 'Premium', 'Premium residence', 'Space for a calmer, more established routine.', 15000, ['recovery_support' => 2, 'lifestyle_event_weight' => 2]),
            self::item('recovery.recovery-kit', 'Recovery', 'Modest', 'Recovery kit', 'A simple home routine for stretching and recovery.', 50, ['recovery_support' => 1]),
            self::item('recovery.home-setup', 'Recovery', 'Comfortable', 'Home recovery setup', 'A stronger recovery routine outside Club hours.', 1000, ['recovery_support' => 2]),
            self::item('recovery.premium-setup', 'Recovery', 'Premium', 'Premium recovery setup', 'A dedicated space for disciplined recovery.', 5000, ['recovery_support' => 3]),
            self::item('training.video-library', 'Training', 'Modest', 'Technical video library', 'Study material to support a focused training block.', 50, ['training_support' => 1]),
            self::item('training.home-equipment', 'Training', 'Comfortable', 'Home training equipment', 'Useful equipment for a consistent individual routine.', 1200, ['training_support' => 2]),
            self::item('training.analysis-device', 'Training', 'Premium', 'Performance analysis device', 'A clearer view of the work behind each development block.', 7500, ['training_support' => 3]),
            self::item('tech.phone', 'Tech', 'Modest', 'Reliable phone', 'Keeps the football and personal routine connected.', 75, ['media_event_weight' => 1]),
            self::item('tech.laptop', 'Tech', 'Comfortable', 'Work laptop', 'Useful for study, planning, and life away from the pitch.', 1500, ['professional_event_weight' => 1]),
            self::item('style.first-watch', 'Style', 'Modest', 'First career watch', 'A small marker of the first professional steps.', 100, ['lifestyle_event_weight' => 1]),
            self::item('style.matchday-wardrobe', 'Style', 'Premium', 'Matchday wardrobe', 'A more confident presentation around Club duties.', 4000, ['media_event_weight' => 1, 'lifestyle_event_weight' => 1]),
            self::item('experience.short-break', 'Experience', 'Comfortable', 'Short recovery break', 'A planned break that gives the football routine some balance.', 150, ['recovery_support' => 1, 'lifestyle_event_weight' => 1]),
            self::item('experience.family-trip', 'Experience', 'Premium', 'Family trip', 'A meaningful shared experience during an available break.', 3000, ['community_event_weight' => 1, 'lifestyle_event_weight' => 2]),
            self::item('experience.legacy-milestone', 'Experience', 'Elite', 'Career milestone experience', 'A memorable celebration of a major career step.', 30000, ['lifestyle_event_weight' => 3, 'media_event_weight' => 1]),
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $item) {
            if ($item['id'] === $id) {
                return $item;
            }
        }

        return null;
    }

    public static function activeGroup(array $item): ?string
    {
        return ($item['category'] ?? null) === 'Experience' ? null : (string) ($item['category'] ?? '');
    }

    public static function isExperience(array $item): bool
    {
        return ($item['category'] ?? null) === 'Experience';
    }

    public static function tierRank(string $tier): int
    {
        return match ($tier) { 'Modest' => 1, 'Comfortable' => 2, 'Premium' => 3, 'Elite' => 4, default => 0 };
    }

    /** @return list<string> */
    public static function validate(): array
    {
        $errors = [];
        $ids = [];
        $categories = ['Transport', 'Home', 'Recovery', 'Training', 'Tech', 'Style', 'Experience'];
        $tiers = ['Modest', 'Comfortable', 'Premium', 'Elite'];
        $effects = ['recovery_support', 'training_support', 'lifestyle_event_weight', 'professional_event_weight', 'media_event_weight', 'community_event_weight', 'travel_convenience'];
        foreach (self::all() as $item) {
            $id = (string) ($item['id'] ?? '');
            if ($id === '' || isset($ids[$id])) { $errors[] = 'duplicate or empty item id: ' . $id; }
            $ids[$id] = true;
            if (!in_array($item['category'] ?? null, $categories, true)) { $errors[] = $id . ': invalid category'; }
            if (!in_array($item['tier'] ?? null, $tiers, true)) { $errors[] = $id . ': invalid tier'; }
            if (!is_int($item['price'] ?? null) || $item['price'] <= 0) { $errors[] = $id . ': invalid price'; }
            if (trim((string) ($item['label'] ?? '')) === '' || trim((string) ($item['description'] ?? '')) === '') { $errors[] = $id . ': missing copy'; }
            foreach ((array) ($item['effects'] ?? []) as $effect => $value) {
                if (!in_array($effect, $effects, true) || !is_int($value) || $value < 0 || $value > 3) { $errors[] = $id . ': invalid effect'; }
            }
        }

        return $errors;
    }

    /** @param array<string,int> $effects @return array<string,mixed> */
    private static function item(string $id, string $category, string $tier, string $label, string $description, int $price, array $effects): array
    {
        return compact('id', 'category', 'tier', 'label', 'description', 'price', 'effects');
    }
}
