<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Avatar;

final class AvatarCatalogValidator
{
    /** @return list<string> */
    public function validate(AvatarCatalog $catalog): array
    {
        $errors = [];
        $ids = [];
        $assetsById = [];
        foreach (['face', 'jaw', 'ears', 'eyes', 'brows', 'nose', 'mouth', 'hair', 'facial_hair', 'skin_detail', 'scar', 'accessory', 'age', 'body', 'kit', 'background', 'expression'] as $category) {
            foreach ($catalog->assets($category) as $asset) {
                $id = (string) ($asset['id'] ?? '');
                if ($id === '' || isset($ids[$id])) { $errors[] = 'duplicate or empty asset id: ' . $id; }
                $ids[$id] = true;
                $assetsById[$id] = $asset;
                try {
                    $path = $catalog->fileFor($asset);
                    $svg = file_get_contents($path);
                    if ($svg === false || !str_contains($svg, 'viewBox="0 0 512 512"')) { $errors[] = 'invalid SVG viewBox: ' . $id; }
                    if ($svg !== false && (str_contains($svg, 'href="http') || str_contains($svg, 'href="https') || str_contains($svg, 'url(') || str_contains($svg, '<script'))) { $errors[] = 'external/script content: ' . $id; }
                } catch (\Throwable $exception) { $errors[] = $exception->getMessage(); }
            }
        }
        foreach (['skin', 'eye', 'hair'] as $category) {
            if ($catalog->palettes($category) === []) { $errors[] = 'missing palette category: ' . $category; }
        }
        foreach ($catalog->presets() as $preset) {
            if (!is_array($preset['appearance'] ?? null)) { $errors[] = 'invalid preset: ' . (string) ($preset['id'] ?? ''); }
            foreach (($preset['appearance'] ?? []) as $field => $value) {
                if (in_array($field, ['skin_tone', 'eye_color', 'hair_color', 'facial_hair_color'], true)) {
                    $paletteCategory = $field === 'skin_tone' ? 'skin' : ($field === 'eye_color' ? 'eye' : 'hair');
                    $valid = array_filter($catalog->palettes($paletteCategory), static fn (array $palette): bool => ($palette['id'] ?? null) === $value);
                    if ($valid === []) { $errors[] = 'invalid preset palette: ' . (string) $value; }
                } elseif (!isset($assetsById[(string) $value])) {
                    $errors[] = 'invalid preset asset: ' . (string) $value;
                }
            }
        }
        foreach ($assetsById as $asset) {
            if (($asset['deprecated'] ?? false) && ($asset['replacement_id'] ?? null) !== null && !isset($assetsById[(string) $asset['replacement_id']])) {
                $errors[] = 'invalid deprecated replacement: ' . (string) $asset['id'];
            }
        }
        return $errors;
    }
}
