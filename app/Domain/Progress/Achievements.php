<?php
declare(strict_types=1);

namespace App\Domain\Progress;

/**
 * Wins worth a pause, from your own logged data. Pure PHP. Only things that actually happened ever appear: there is no "you missed" card, no
 * streak counter, and a quiet week shows nothing at all.
 *
 *  - PR: a lift where a session beat everything logged earlier (first log of a lift is a baseline, never a PR). Estimated 1RM is an estimate.
 *  - Volume best: a session whose volume beat every earlier session of the same name (needs 3 earlier ones to mean anything).
 *  - Milestones: lifetime tonnes lifted and number of sessions crossing round numbers.
 *  - Last week (shown Mon-Wed): every planned session done, protein on target most days, most days logged, sleep near target most nights.
 * Each item has a stable key so a dismissed card stays dismissed and a new win gets a new card.
 */
final class Achievements
{
    public const RECENT_DAYS = 1;        // a session from today or yesterday
    public const WEEK_SHOWN_DAYS = 3;    // last week's wins stay for Mon, Tue, Wed
    public const MAX_ITEMS = 5;
    private const TONNES = [5, 10, 25, 50, 100, 250, 500, 1000];
    private const SESSIONS = [10, 25, 50, 100, 150, 200, 300, 500];
    private const MIN_EARLIER_SAME_TITLE = 3;

    /**
     * @param array{today:string,cards:list<array<string,mixed>>,week:?array<string,mixed>,dismissed?:list<string>} $in cards newest first
     * @return list<array{key:string,kind:string,icon:string,title:string,detail:string,date:string,rank:int}>
     */
    public static function build(array $in): array
    {
        $today = $in['today'];
        $since = date('Y-m-d', strtotime($today . ' -' . self::RECENT_DAYS . ' days'));
        $items = [];
        $old = array_reverse($in['cards']);   // oldest first
        $volByTitle = [];
        $count = 0;
        $tonnes = 0.0;
        foreach ($old as $c) {
            $beforeKg = $tonnes;
            $beforeN = $count;
            $count++;
            $tonnes += (float) $c['volume_kg'] / 1000;
            $earlier = $volByTitle[$c['title']] ?? [];
            if ($c['date'] >= $since) {
                $items = array_merge($items, self::sessionWins($c, $earlier, $beforeKg, $tonnes, $beforeN, $count));
            }
            $c['volume_kg'] > 0 && $volByTitle[$c['title']][] = (float) $c['volume_kg'];
        }
        if ($in['week'] !== null && $today <= date('Y-m-d', strtotime($in['week']['end'] . ' +' . self::WEEK_SHOWN_DAYS . ' days'))) {
            $items = array_merge($items, self::weekWins($in['week']));
        }

        $dismissed = array_flip($in['dismissed'] ?? []);
        $items = array_values(array_filter($items, fn ($i) => ! isset($dismissed[$i['key']])));
        usort($items, fn ($a, $b) => $a['rank'] <=> $b['rank'] ?: strcmp($b['date'], $a['date']) ?: strcmp($a['key'], $b['key']));

        return array_slice($items, 0, self::MAX_ITEMS);
    }

    /** @param array<string,mixed> $c @param list<float> $earlier @return list<array<string,mixed>> */
    private static function sessionWins(array $c, array $earlier, float $beforeT, float $afterT, int $beforeN, int $afterN): array
    {
        $out = [];
        foreach ($c['rows'] as $r) {
            if (! $r['pr']) {
                continue;
            }
            $bits = [];
            in_array('heaviest', $r['pr'], true) && $r['heaviest'] && $bits[] = sprintf('Heaviest yet: %s kg × %d', self::n((float) $r['heaviest']['kg']), $r['heaviest']['reps']);
            in_array('e1rm', $r['pr'], true) && $r['e1rm'] && $bits[] = sprintf('estimated 1RM about %s kg (an estimate)', self::n((float) $r['e1rm']));
            $out[] = self::item('pr:' . $c['date'] . ':' . $r['id'], 'pr', 'trophy', 'Personal best: ' . $r['title'], implode(' · ', $bits) . '.', $c['date'], 1);
        }
        if (count($earlier) >= self::MIN_EARLIER_SAME_TITLE && $c['volume_kg'] > max($earlier)) {
            $prev = max($earlier);
            $out[] = self::item('vol:' . $c['date'] . ':' . $c['title'], 'volume', 'trending-up', 'Most volume yet in a ' . $c['title'] . ' session',
                sprintf('%s kg, %d%% more than your previous best of %s kg.', number_format((float) $c['volume_kg']), (int) round(($c['volume_kg'] / $prev - 1) * 100), number_format($prev)), $c['date'], 2);
        }
        foreach (self::TONNES as $t) {
            if ($beforeT < $t && $afterT >= $t) {
                $out[] = self::item('tonnes:' . $t, 'milestone', 'medal', $t . ' tonnes lifted in total', 'Across every Hevy session you have logged. Just over ' . $t . ' tonnes.', $c['date'], 3);
            }
        }
        foreach (self::SESSIONS as $n) {
            if ($beforeN < $n && $afterN >= $n) {
                $out[] = self::item('sessions:' . $n, 'milestone', 'medal', $n . ' lifting sessions logged', 'Session number ' . $n . ' in Hevy: ' . $c['title'] . '.', $c['date'], 3);
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $w @return list<array<string,mixed>> */
    private static function weekWins(array $w): array
    {
        $out = [];
        $k = fn (string $x) => 'week:' . $x . ':' . $w['start'];
        $date = $w['end'];
        if (($w['planned'] ?? 0) >= 2 && $w['done'] === $w['planned']) {
            $out[] = self::item($k('train'), 'week', 'calendar-check', ($w['firstFull'] ?? false) ? 'First full training week' : 'Full training week', 'All ' . $w['planned'] . ' planned sessions done last week.', $date, 4);
        }
        if (($w['loggedDays'] ?? 0) >= 5 && ($w['proteinDays'] ?? 0) >= 5) {
            $out[] = self::item($k('protein'), 'week', 'utensils', 'Protein on target ' . $w['proteinDays'] . ' of 7 days', 'Last week. Protein is the one that matters most for building muscle.', $date, 5);
        }
        if (($w['loggedDays'] ?? 0) >= 6) {
            $out[] = self::item($k('logged'), 'week', 'utensils', 'Logged ' . $w['loggedDays'] . ' of 7 days', 'Consistent logging is what makes your trend and targets trustworthy.', $date, 7);
        }
        if (($w['sleepNights'] ?? 0) >= 5 && ($w['sleepOn'] ?? 0) >= 5) {
            $out[] = self::item($k('sleep'), 'week', 'moon', 'Near your sleep target ' . $w['sleepOn'] . ' of ' . $w['sleepNights'] . ' nights', 'Within 10% of your target, as recorded by your watch.', $date, 6);
        }

        return $out;
    }

    /** @return array{key:string,kind:string,icon:string,title:string,detail:string,date:string,rank:int} */
    private static function item(string $key, string $kind, string $icon, string $title, string $detail, string $date, int $rank): array
    {
        return ['key' => $key, 'kind' => $kind, 'icon' => $icon, 'title' => $title, 'detail' => $detail, 'date' => $date, 'rank' => $rank];
    }

    private static function n(float $x): string
    {
        return rtrim(rtrim(number_format($x, 1, '.', ''), '0'), '.');
    }
}
