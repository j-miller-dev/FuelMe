<?php

namespace App\Domain\Coach;

/**
 * Which card to show on Today, and why. Rule based, never random: the reason is shown with the card.
 * Priority: low readiness -> recovery; a session today -> training; under-eating nudge -> nutrition; else the date rotates through your "follow" cards.
 */
final class PrincipleContext
{
    /** @return array{tags:list<string>,reason:string}|null */
    public static function pick(bool $readinessHook, bool $sessionToday, bool $underEating): ?array
    {
        if ($readinessHook) {
            return ['tags' => ['recovery', 'rest', 'sleep'], 'reason' => 'Your last few check-ins were low'];
        }
        if ($sessionToday) {
            return ['tags' => ['intensity', 'training', 'effort', 'technique'], 'reason' => 'You have a session today'];
        }
        if ($underEating) {
            return ['tags' => ['nutrition', 'food', 'eating'], 'reason' => 'Intake has been under target'];
        }

        return null;
    }

    /**
     * @param list<array{id:int|string,tags:list<string>,stance:string}> $cards
     * @return array<string,mixed>|null
     */
    public static function choose(array $cards, ?array $ctx, string $ymd): ?array
    {
        $usable = array_values(array_filter($cards, fn ($c) => $c['stance'] !== 'ignore'));
        if (! $usable) {
            return null;
        }
        if ($ctx) {
            $hit = array_values(array_filter($usable, fn ($c) => array_intersect($ctx['tags'], array_map('strtolower', $c['tags'])) !== []));
            $pool = $hit ?: [];
        } else {
            $pool = array_values(array_filter($usable, fn ($c) => $c['stance'] === 'follow')) ?: [];
        }
        if (! $pool) {
            return null;
        }
        usort($pool, fn ($a, $b) => strcmp((string) $a['id'], (string) $b['id']));

        return $pool[abs(crc32($ymd)) % count($pool)];
    }
}
