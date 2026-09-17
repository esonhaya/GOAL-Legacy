<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Avatar;

use RuntimeException;

/** Immutable installed V1 avatar content; save files store IDs only. */
final class AvatarCatalog
{
    /** @var list<array<string, mixed>> */
    private array $assetList;
    /** @var array<string, array<string, mixed>> */
    private array $assetsById = [];
    /** @var array<string, list<array<string, mixed>>> */
    private array $palettes;
    /** @var list<array<string, mixed>> */
    private array $presets;
    private string $root;

    public function __construct(?string $root = null)
    {
        $root ??= dirname(__DIR__, 4) . '/game/assets/avatar/v1';
        $this->root = rtrim($root, '/');
        $this->assetList = $this->read($this->root . '/catalog/assets.json')['assets'] ?? [];
        $this->palettes = $this->read($this->root . '/catalog/palettes.json')['palettes'] ?? [];
        $this->presets = $this->read($this->root . '/catalog/presets.json')['presets'] ?? [];
        if ($this->assetList === [] || $this->palettes === []) {
            throw new RuntimeException('Avatar V1 catalog is empty or unavailable.');
        }
        foreach ($this->assetList as $asset) {
            if (is_array($asset) && isset($asset['id'])) {
                $this->assetsById[(string) $asset['id']] = $asset;
            }
        }
    }

    /** @return list<array<string, mixed>> */
    public function assets(string $category): array
    {
        $result = [];
        foreach ($this->assetList as $asset) {
            if (is_array($asset) && ($asset['category'] ?? null) === $category) {
                $result[] = $asset;
            }
        }
        return array_values($result);
    }

    /** @return list<string> */
    public function assetIds(string $category, bool $creatorOnly = false): array
    {
        return array_values(array_map(
            static fn (array $asset): string => (string) $asset['id'],
            array_filter($this->assets($category), static fn (array $asset): bool => !$creatorOnly || (bool) ($asset['creator_enabled'] ?? false)),
        ));
    }

    public function asset(string $id): ?array
    {
        $asset = $this->assetsById[$id] ?? null;
        return is_array($asset) && isset($asset['file']) ? $asset : null;
    }

    public function safeAsset(string $category, string $id): array
    {
        $asset = $this->asset($id);
        if ($asset !== null && ($asset['category'] ?? null) === $category) {
            return $asset;
        }
        $fallback = $this->assets($category)[0] ?? null;
        if ($fallback === null) {
            throw new RuntimeException(sprintf('Avatar category "%s" has no fallback asset.', $category));
        }
        return $fallback;
    }

    /** @return list<array<string, mixed>> */
    public function palettes(string $category): array { return $this->palettes[$category] ?? []; }

    public function palette(string $id, string $category): array
    {
        foreach ($this->palettes($category) as $palette) {
            if (($palette['id'] ?? null) === $id) { return $palette; }
        }
        $fallback = $this->palettes($category)[0] ?? null;
        if ($fallback === null) { throw new RuntimeException(sprintf('Avatar palette "%s" has no fallback.', $category)); }
        return $fallback;
    }

    /** @return list<array<string, mixed>> */
    public function presets(): array { return $this->presets; }

    public function preset(string $id): ?array
    {
        foreach ($this->presets as $preset) {
            if (($preset['id'] ?? null) === $id) { return $preset; }
        }
        return null;
    }

    public function fileFor(array $asset): string
    {
        $file = (string) ($asset['file'] ?? '');
        if ($file === '' || !is_file($this->root . '/' . $file)) {
            throw new RuntimeException(sprintf('Avatar asset file is missing for "%s".', (string) ($asset['id'] ?? 'unknown')));
        }
        return $this->root . '/' . $file;
    }

    /** @return array<string, mixed> */
    private function read(string $path): array
    {
        $json = file_get_contents($path);
        if ($json === false) { throw new RuntimeException(sprintf('Unable to read avatar catalog "%s".', $path)); }
        $value = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) { throw new RuntimeException(sprintf('Avatar catalog "%s" is not an object.', $path)); }
        return $value;
    }
}
