<?php

declare(strict_types=1);

namespace Goal\Legacy\Web;

/** Small shared server-rendered view helpers for the local graphical shell. */
final class WebView
{
    public static function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $page, array $parameters = []): string
    {
        return '/?' . http_build_query(array_merge(['page' => $page], $parameters));
    }

    public static function link(string $page, array $parameters, string $label, string $class = 'button button-secondary'): string
    {
        return '<a class="' . self::e($class) . '" href="' . self::e(self::url($page, $parameters)) . '">' . self::e($label) . '</a>';
    }

    public static function form(string $action, string $label, array $hidden = [], string $class = 'button button-primary', string $extra = ''): string
    {
        $fields = '';
        foreach ($hidden as $name => $value) {
            $fields .= '<input type="hidden" name="' . self::e((string) $name) . '" value="' . self::e($value) . '">';
        }

        return '<form method="post" action="' . self::e(self::url('action')) . '" class="inline-form" ' . $extra . '>'
            . '<input type="hidden" name="action" value="' . self::e($action) . '">' . $fields
            . '<button class="' . self::e($class) . '" type="submit">' . self::e($label) . '</button></form>';
    }

    public static function layout(string $title, string $content, ?string $saveId = null, string $active = '', ?string $flash = null): string
    {
        $navigation = '';
        if ($saveId !== null) {
            $primaryItems = [
                'home' => ['Career Home', 'home'],
                'profile' => ['Profile', 'profile'],
                'training' => ['Training', 'training'],
                'career' => ['Career History', 'career'],
            ];
            $secondaryItems = [
                'legacy' => ['Career Legacy', 'legacy'],
                'trophies' => ['Trophy Room', 'trophies'],
                'market' => ['Transfer Market', 'market'],
                'relationships' => ['Relationships', 'relationships'],
                'pulse' => ['Pulse', 'pulse'],
                'squad' => ['Squad', 'squad'],
                'world' => ['World', 'world'],
                'international' => ['International', 'international'],
                'news' => ['News', 'news'],
                'finances' => ['Finances', 'finances'],
                'lifestyle' => ['Lifestyle', 'lifestyle'],
            ];
            foreach ($primaryItems as $key => [$label, $page]) {
                $class = $active === $key ? 'nav-link active' : 'nav-link';
                $current = $active === $key ? ' aria-current="page"' : '';
                $navigation .= '<a class="' . $class . '" href="' . self::e(self::url($page, ['save' => $saveId])) . '"' . $current . '>' . self::e($label) . '</a>';
            }
            $secondaryActive = array_key_exists($active, $secondaryItems);
            $moreClass = $secondaryActive ? 'nav-more active' : 'nav-more';
            $moreLinks = '';
            foreach ($secondaryItems as $key => [$label, $page]) {
                $class = $active === $key ? 'nav-link active' : 'nav-link';
                $current = $active === $key ? ' aria-current="page"' : '';
                $moreLinks .= '<a class="' . $class . '" href="' . self::e(self::url($page, ['save' => $saveId])) . '"' . $current . '>' . self::e($label) . '</a>';
            }
            $navigation .= '<details class="' . $moreClass . '"><summary>More</summary><div class="nav-more-menu" aria-label="More Career destinations">' . $moreLinks . '</div></details>';
        }
        $flashHtml = $flash === null || trim($flash) === '' ? '' : '<div class="flash feedback-banner feedback-info" role="status" aria-live="polite">' . self::e($flash) . '</div>';
        $back = $saveId === null ? self::link('menu', [], 'Main Menu', 'brand-link') : self::link('home', ['save' => $saveId], 'GOAL: LEGACY', 'brand-link');

        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . self::e($title) . ' · GOAL: Legacy</title><link rel="stylesheet" href="/assets/app.css"></head><body class="app-body">'
            . '<a class="skip-link" href="#main-content">Skip to main content</a>'
            . '<header class="topbar"><div class="topbar-inner"><div class="brand-area">' . $back . ($saveId === null ? '' : '<span class="brand-context">CAREER</span>') . '</div><nav class="main-nav" aria-label="Career navigation">' . $navigation . '</nav></div></header>'
            . '<main id="main-content" class="page-shell">' . $flashHtml . $content . '</main>'
            . '<script>
                document.querySelectorAll("form[data-busy]").forEach(function(form){form.addEventListener("submit",function(){var button=form.querySelector("button[type=submit]");if(button){button.disabled=true;button.textContent="Working...";}});});
                function updateDraftPortrait(){var image=document.querySelector("[data-draft-portrait]");if(!image){return;}var spec={};document.querySelectorAll("[data-appearance-field]").forEach(function(field){spec[field.dataset.appearanceField]=field.value;});var encoded=btoa(JSON.stringify(spec));image.src="/?page=portrait&draft=1&size=256&spec="+encodeURIComponent(encoded)+"&v="+Date.now();}
            </script></body></html>';
    }

    /** @param array<string, mixed> $identity */
    public static function careerIdentity(array $identity): string
    {
        $name = (string) ($identity['name'] ?? 'Player');
        $club = (string) ($identity['club'] ?? 'Free Agent');
        $position = (string) ($identity['position'] ?? 'Position not set');
        $meta = array_values(array_filter([
            $position,
            isset($identity['age']) && $identity['age'] !== '' ? 'Age ' . (string) $identity['age'] : null,
            $club,
        ], static fn (?string $value): bool => $value !== null && trim($value) !== ''));
        $facts = '';
        foreach ([
            'OVR' => $identity['ovr'] ?? null,
            'Role' => $identity['role'] ?? null,
            'Status' => $identity['status'] ?? null,
        ] as $label => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $facts .= '<span class="identity-fact"><small>' . self::e($label) . '</small><strong>' . self::e($value) . '</strong></span>';
        }

        return '<section class="career-identity" aria-label="Career identity" data-career-identity><div class="career-identity-copy"><span class="eyebrow">YOUR CAREER</span><strong class="career-identity-name">' . self::e($name) . '</strong><span class="career-identity-meta">' . self::e(implode(' · ', $meta)) . '</span></div>'
            . ($facts === '' ? '' : '<div class="career-identity-facts">' . $facts . '</div>') . '</section>';
    }

    public static function section(string $eyebrow, string $title, string $body, string $class = ''): string
    {
        return '<section class="panel ' . self::e($class) . '"><div class="eyebrow">' . self::e($eyebrow) . '</div><h2>' . self::e($title) . '</h2>' . $body . '</section>';
    }

    public static function stat(string $label, mixed $value, string $note = ''): string
    {
        return '<div class="stat"><span>' . self::e($label) . '</span><strong>' . self::e($value) . '</strong>' . ($note === '' ? '' : '<small>' . self::e($note) . '</small>') . '</div>';
    }

    public static function portrait(string $url, string $alt, string $class = 'portrait portrait-medium'): string
    {
        return '<img class="' . self::e($class) . '" src="' . self::e($url) . '" alt="' . self::e($alt) . '" loading="lazy">';
    }

    public static function playerCard(string $url, string $portraitUrl, string $name, string $meta, string $context = ''): string
    {
        $contextHtml = $context === '' ? '' : '<small class="player-card-context">' . self::e($context) . '</small>';

        return '<a class="player-card" href="' . self::e($url) . '">'
            . self::portrait($portraitUrl, $name, 'portrait portrait-small')
            . '<span class="player-card-info"><strong>' . self::e($name) . '</strong><small>' . self::e($meta) . '</small>' . $contextHtml . '</span></a>';
    }

    public static function emptyState(string $message): string
    {
        return '<p class="empty-state">' . self::e($message) . '</p>';
    }
}
