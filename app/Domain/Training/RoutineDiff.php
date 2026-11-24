<?php

namespace App\Domain\Training;

/**
 * How a finished session compared with the routine it came from: what you skipped, what you cut short, what you added. Pure PHP, matched by Hevy's
 * exercise template id (title as a fallback). It describes; it never scores you.
 */
final class RoutineDiff
{
    /**
     * @param list<array<string,mixed>> $planned routine exercises: ['template_id','title','planned' => [{type,...}]]
     * @param list<array<string,mixed>> $done session exercises (Strength shape): ['id','title','sets' => [{type,kg,reps}]]
     * @return array{rows:list<array<string,mixed>>,skipped:int,partial:int,extra:int,done:int}
     */
    public static function compare(array $planned, array $done): array
    {
        $key = fn (string $id, string $title) => $id !== '' ? 'id:' . $id : 't:' . strtolower(trim($title));
        $doneBy = [];
        foreach ($done as $d) {
            $k = $key((string) ($d['id'] ?? ''), (string) ($d['title'] ?? ''));
            $doneBy[$k]['title'] = $d['title'] ?? '';
            $doneBy[$k]['sets'] = array_merge($doneBy[$k]['sets'] ?? [], $d['sets'] ?? []);
        }
        $rows = [];
        $skipped = $partial = $extra = $ok = 0;
        $seen = [];
        foreach ($planned as $p) {
            $k = $key((string) ($p['template_id'] ?? ''), (string) ($p['title'] ?? ''));
            $seen[$k] = true;
            $nPlan = count(array_filter($p['planned'] ?? [], fn ($s) => ($s['type'] ?? 'normal') !== 'warmup'));
            $d = $doneBy[$k] ?? null;
            $nDone = $d ? count(array_filter($d['sets'], fn ($s) => ($s['type'] ?? 'normal') !== 'warmup')) : 0;
            if ($d === null || $nDone === 0) {
                $skipped++;
                $rows[] = ['title' => $p['title'], 'status' => 'skipped', 'planned_sets' => $nPlan, 'done_sets' => 0];
            } elseif ($nPlan > 0 && $nDone < $nPlan) {
                $partial++;
                $rows[] = ['title' => $p['title'], 'status' => 'partial', 'planned_sets' => $nPlan, 'done_sets' => $nDone];
            } else {
                $ok++;
                $rows[] = ['title' => $p['title'], 'status' => 'done', 'planned_sets' => $nPlan, 'done_sets' => $nDone];
            }
        }
        foreach ($doneBy as $k => $d) {
            if (! isset($seen[$k]) && count(array_filter($d['sets'], fn ($s) => ($s['type'] ?? 'normal') !== 'warmup')) > 0) {
                $extra++;
                $rows[] = ['title' => $d['title'], 'status' => 'extra', 'planned_sets' => 0, 'done_sets' => count($d['sets'])];
            }
        }

        return ['rows' => $rows, 'skipped' => $skipped, 'partial' => $partial, 'extra' => $extra, 'done' => $ok];
    }
}
