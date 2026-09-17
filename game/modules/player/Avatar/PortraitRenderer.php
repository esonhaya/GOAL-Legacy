<?php

declare(strict_types=1);

namespace Goal\Legacy\Modules\Player\Avatar;

use Goal\Legacy\Modules\Player\Domain\Player;
use Goal\Legacy\Modules\Player\Domain\PlayerAppearance;
use Goal\Legacy\Modules\World\Domain\SimulationDate;
use RuntimeException;

/** Composes installed SVG components and writes only disposable cache files. */
final class PortraitRenderer
{
    public const RENDERER_VERSION = 'avatar-renderer-v1';

    /** @var list<int> */
    private const SIZES = [32, 64, 128, 256, 512];

    public function __construct(
        private readonly AvatarCatalog $catalog = new AvatarCatalog(),
        private readonly string $cacheRoot = '',
    ) {
    }

    /** @param array<string, mixed> $context */
    public function render(PlayerAppearance $appearance, array $context = [], int $size = 256): string
    {
        if (!in_array($size, self::SIZES, true)) {
            throw new RuntimeException('Portrait size must be one of 32, 64, 128, 256 or 512.');
        }
        $cacheRoot = $this->cacheRoot !== '' ? $this->cacheRoot : dirname(__DIR__, 4) . '/storage/cache/portraits';
        $context = $this->normalizedContext($context);
        $key = hash('sha256', json_encode(['schema' => PlayerAppearance::SCHEMA_VERSION, 'renderer' => self::RENDERER_VERSION, 'appearance' => $appearance->toArray(), 'context' => $context, 'size' => $size], JSON_THROW_ON_ERROR));
        $directory = $cacheRoot . '/' . $size;
        $path = $directory . '/' . $key . '.svg';
        if (is_file($path)) { return $path; }
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create portrait cache directory "%s".', $directory));
        }
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        file_put_contents($temporary, $this->renderSvg($appearance, $context, $size), LOCK_EX);
        rename($temporary, $path);
        return $path;
    }

    /** @param array<string, mixed> $context */
    public function renderSvg(PlayerAppearance $appearance, array $context = [], int $size = 512): string
    {
        $context = $this->normalizedContext($context);
        $skin = $this->catalog->palette($appearance->toArray()['skin_tone'], 'skin');
        $eyes = $this->catalog->palette($appearance->toArray()['eye_color'], 'eye');
        $hair = $this->catalog->palette($appearance->toArray()['hair_color'], 'hair');
        $facialHair = $this->catalog->palette($appearance->toArray()['facial_hair_color'], 'hair');
        $colors = [
            '{BASE}' => (string) ($skin['base'] ?? '#C9865A'), '{SHADOW}' => (string) ($skin['shadow'] ?? '#945331'),
            '{HIGHLIGHT}' => (string) ($skin['highlight'] ?? '#E3AA7C'), '{DARK}' => '#24130D',
            '{EYE}' => (string) ($eyes['color'] ?? '#4A291A'), '{EYE_WHITE}' => '#FFF9F2',
            '{HAIR}' => (string) ($hair['base'] ?? '#2A1A15'), '{MOUTH}' => '#9A4C52',
            '{DETAIL}' => (string) ($skin['shadow'] ?? '#945331'), '{SCAR}' => '#A85F58',
            '{ACCESSORY}' => '#D5AA4A', '{AGE}' => (string) ($skin['shadow'] ?? '#945331'),
            '{BODY}' => '#D88762', '{KIT_PRIMARY}' => $context['kit_primary'], '{KIT_SECONDARY}' => $context['kit_secondary'],
            '{KIT_DARK}' => '#1A1A26', '{BACKGROUND}' => $context['background'],
            '{BACKGROUND_ACCENT}' => $context['background_accent'], '{EXPRESSION}' => '#742F3D',
        ];
        $values = $appearance->toArray();
        $layers = [];
        $layers[] = $this->component('background', (string) $context['background_id'], $colors);
        $layers[] = $this->component('body', $this->bodyId((string) $context['body']), $colors);
        $layers[] = $this->component('kit', (string) $context['kit_id'], $colors);
        $layers[] = $this->component('hair', $values['hair'], $colors);
        $layers[] = $this->component('ears', $values['ears'], $colors);
        $layers[] = $this->component('face', $values['face'], $colors);
        $layers[] = $this->component('jaw', $values['jaw'], $colors);
        $layers[] = $this->component('skin_detail', $values['skin_detail'], $colors);
        $layers[] = $this->component('scar', $values['scar'], $colors);
        $layers[] = $this->component('eyes', $values['eyes'], $colors);
        $layers[] = $this->component('brows', $values['brows'], $colors);
        $layers[] = $this->component('nose', $values['nose'], $colors);
        $layers[] = $this->component('mouth', $values['mouth'], $colors);
        $layers[] = $this->component('facial_hair', $values['facial_hair'], $colors);
        $layers[] = $this->component('age', (string) $context['age_id'], $colors);
        $layers[] = $this->component('expression', (string) $context['expression_id'], $colors);
        $layers[] = $this->component('accessory', $values['accessory'], $colors);
        $body = implode("\n", $layers);
        return sprintf('<svg xmlns="http://www.w3.org/2000/svg" width="%1$d" height="%1$d" viewBox="0 0 512 512" role="img" aria-label="Player portrait"><title>%2$s</title>%3$s</svg>\n', $size, htmlspecialchars((string) ($context['label'] ?? 'Player portrait'), ENT_QUOTES | ENT_XML1), $body);
    }

    /** @param array<string, mixed> $context @return array<string, mixed> */
    private function normalizedContext(array $context): array
    {
        $kitColors = $this->kitColors((string) ($context['club_colors'] ?? ''));
        $backgroundAsset = $this->contextAsset('background', (string) ($context['background'] ?? 'avatar.background.neutral.01'), 'neutral');
        $backgroundFamily = (string) ($backgroundAsset['family'] ?? 'neutral');
        $backgroundColors = match ($backgroundFamily) {
            'club' => ['#EAF0FF', '#AFC5EE'], 'matchday' => ['#12233E', '#3B6EA8'],
            'transfer' => ['#F3EAFE', '#B98FE0'], 'news' => ['#FFF8E6', '#E6C66A'],
            'career' => ['#EAF0F7', '#B8C9DE'], default => ['#F2F5FA', '#CBD7E5'],
        };
        return [
            'expression_id' => $this->contextAsset('expression', (string) ($context['expression'] ?? 'avatar.expression.neutral.01'), 'neutral')['id'],
            'background_id' => $backgroundAsset['id'],
            'age_id' => $this->contextAsset('age', (string) ($context['age'] ?? 'avatar.age.young.02'), 'young')['id'],
            'body' => (string) ($context['body'] ?? 'average'),
            'body_id' => $this->bodyId((string) ($context['body'] ?? 'average')),
            'kit_id' => $this->catalog->safeAsset('kit', (string) ($context['kit'] ?? 'avatar.kit.solid.01'))['id'],
            'kit_primary' => (string) ($context['kit_primary'] ?? $kitColors[0]),
            'kit_secondary' => (string) ($context['kit_secondary'] ?? $kitColors[1]),
            'background' => (string) ($context['background_color'] ?? $backgroundColors[0]),
            'background_accent' => (string) ($context['background_accent'] ?? $backgroundColors[1]),
            'label' => (string) ($context['label'] ?? 'Player portrait'),
        ];
    }

    private function bodyId(string $body): string
    {
        $family = in_array($body, ['slim', 'average', 'athletic', 'broad'], true) ? $body : 'average';
        return $this->catalog->safeAsset('body', 'avatar.body.' . $family . '.' . match ($family) { 'slim' => '01', 'average' => '02', 'athletic' => '03', 'broad' => '04' })['id'];
    }

    private function contextAsset(string $category, string $value, string $fallbackFamily): array
    {
        $asset = $this->catalog->asset($value);
        if ($asset !== null && ($asset['category'] ?? null) === $category) { return $asset; }
        foreach ($this->catalog->assets($category) as $candidate) {
            if (($candidate['family'] ?? null) === $value) { return $candidate; }
        }
        foreach ($this->catalog->assets($category) as $candidate) {
            if (($candidate['family'] ?? null) === $fallbackFamily) { return $candidate; }
        }
        return $this->catalog->safeAsset($category, $value);
    }

    /** @param array<string, string> $colors */
    private function component(string $category, string $assetId, array $colors): string
    {
        $asset = $this->catalog->safeAsset($category, $assetId);
        $raw = file_get_contents($this->catalog->fileFor($asset));
        if ($raw === false || !preg_match('/<svg[^>]*>(.*)<\/svg>/s', $raw, $matches)) {
            throw new RuntimeException(sprintf('Avatar SVG "%s" is invalid.', (string) $asset['id']));
        }
        return str_replace(array_keys($colors), array_values($colors), $matches[1]);
    }

    /** @return array{0:string,1:string} */
    private function kitColors(string $description): array
    {
        $description = strtolower($description);
        $colors = [
            'red' => '#B32635', 'blue' => '#2457A6', 'white' => '#F4F6FA', 'black' => '#1C1D26',
            'green' => '#21845A', 'yellow' => '#E4B52D', 'gold' => '#D49B2E', 'purple' => '#7046A5',
            'orange' => '#D96C2C', 'maroon' => '#6B2231', 'pink' => '#D46A8B', 'sky' => '#5BA5D5',
        ];
        foreach ($colors as $name => $color) {
            if (str_contains($description, $name)) {
                $second = $name === 'white' ? '#1C1D26' : '#F4F6FA';
                return [$color, $second];
            }
        }
        return ['#2457A6', '#F4F6FA'];
    }
}
