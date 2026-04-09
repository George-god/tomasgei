<?php
declare(strict_types=1);

/**
 * Realm art sprite (3×2 sheet → realm IDs 1–5).
 *
 * In <head> on pages that show the realm card: realm_sprite_head_link()
 * Card markup uses inline style background-position from realm_sprite_background_position().
 */
function realm_sprite_clamp_realm_id(int $id): int
{
    return max(1, min(5, $id));
}

function realm_sprite_background_position(int $realmId): string
{
    $id = realm_sprite_clamp_realm_id($realmId);
    $map = [
        1 => '0% 0%',
        2 => '50% 0%',
        3 => '100% 0%',
        4 => '0% 100%',
        5 => '100% 100%',
    ];
    return $map[$id];
}

function realm_sprite_head_link(): void
{
    $href = htmlspecialchars('../assets/realms/realm-sprite.css', ENT_QUOTES, 'UTF-8');
    echo '<link rel="stylesheet" href="' . $href . '">' . "\n";
}
