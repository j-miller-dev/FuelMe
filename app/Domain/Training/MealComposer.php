<?php
declare(strict_types=1);

namespace App\Domain\Training;

/**
 * Picks 2-3 concrete options for a pre- or post-session meal from foods and meals the person already uses (BLUEPRINT 9.8 "meal composer").
 * A small search over 1-3 items at a few portion sizes: carbs within +/-10% of the target, fat and fibre under their caps (10% slack),
 * then closest protein. If nothing fits it says exactly what is short instead of pretending. Pure.
 */
final class MealComposer
{
    private const SCALES = [0.5, 1.0, 1.5, 2.0];
    public const TOLERANCE = 0.10;

    /**
     * @param array{carbsG:float,proteinG:float,fatMaxG:?float,fibreMaxG:?float} $target
     * @param list<array{id:string,kind:string,ref:int,name:string,unit:string,amount:float,kcal:float,protein:float,carbs:float,fat:float,fibre:float,tagged:bool}> $cands one usual portion each
     * @param array{kcal:float,protein:float,carbs:float,fat:float} $remaining what is left of today's targets
     * @return array{options:list<array<string,mixed>>, shortfall:?string, shift:?array{carbsG:float,note:string}}
     */
    public static function compose(array $target, array $cands, array $remaining, int $maxOptions = 3, int $maxItems = 3): array
    {
        $cands = array_values(array_filter($cands, fn ($c) => $c['carbs'] >= 5 || $c['protein'] >= 10));
        // Tagged meals first, then the most carb-relevant, capped so the search stays tiny.
        usort($cands, fn ($a, $b) => [$b['tagged'], $b['carbs']] <=> [$a['tagged'], $a['carbs']]);
        $cands = array_slice($cands, 0, 12);
        $opts = [];
        foreach ($cands as $c) {
            foreach (self::SCALES as $s) {
                $opts[] = ['c' => $c, 's' => $s, 'carbs' => $c['carbs'] * $s, 'protein' => $c['protein'] * $s, 'fat' => $c['fat'] * $s, 'fibre' => $c['fibre'] * $s, 'kcal' => $c['kcal'] * $s];
            }
        }

        $fits = [];
        $best = null;
        $n = count($opts);
        $try = function (array $set) use (&$fits, &$best, $target) {
            $t = ['carbs' => 0.0, 'protein' => 0.0, 'fat' => 0.0, 'fibre' => 0.0, 'kcal' => 0.0];
            $ids = [];
            foreach ($set as $o) {
                foreach ($t as $k => $_) {
                    $t[$k] += $o[$k];
                }
                $ids[] = $o['c']['id'];
            }
            if (count(array_unique($ids)) !== count($ids)) {
                return;   // the same food twice is one bigger portion, which is already an option
            }
            $carbErr = $target['carbsG'] > 0 ? abs($t['carbs'] - $target['carbsG']) / $target['carbsG'] : 0.0;
            $fatOk = $target['fatMaxG'] === null || $t['fat'] <= $target['fatMaxG'] * 1.10;
            $fibreOk = $target['fibreMaxG'] === null || $t['fibre'] <= $target['fibreMaxG'] * 1.10;
            $score = $carbErr + 0.4 * ($target['proteinG'] > 0 ? abs($t['protein'] - $target['proteinG']) / $target['proteinG'] : 0.0) + 0.02 * count($set);
            $row = ['set' => $set, 'total' => $t, 'carbErr' => $carbErr, 'score' => $score, 'fatOk' => $fatOk, 'fibreOk' => $fibreOk];
            if ($carbErr <= self::TOLERANCE && $fatOk && $fibreOk) {
                $fits[] = $row;
            }
            if ($fatOk && $fibreOk && ($best === null || $score < $best['score'])) {
                $best = $row;
            }
        };
        for ($i = 0; $i < $n; $i++) {
            $try([$opts[$i]]);
            if ($maxItems < 2) {
                continue;
            }
            for ($j = $i + 1; $j < $n; $j++) {
                $try([$opts[$i], $opts[$j]]);
                if ($maxItems < 3) {
                    continue;
                }
                for ($k = $j + 1; $k < $n; $k++) {
                    $try([$opts[$i], $opts[$j], $opts[$k]]);
                }
            }
        }

        usort($fits, fn ($a, $b) => $a['score'] <=> $b['score']);
        $picked = [];
        $seen = [];
        foreach ($fits as $f) {
            $sig = implode('|', array_map(fn ($o) => $o['c']['id'], $f['set']));
            if (isset($seen[$sig])) {
                continue;   // same foods at another portion size is not a different option
            }
            $seen[$sig] = true;
            $picked[] = $f;
            if (count($picked) === $maxOptions) {
                break;
            }
        }

        $shift = $target['carbsG'] > $remaining['carbs'] + 0.5 ? ['carbsG' => round($target['carbsG'] - $remaining['carbs']),
            'note' => sprintf('Not enough carbs left today for this meal: it needs about %d g and %d g are left. Shift about %d g from a later meal.', round($target['carbsG']), round($remaining['carbs']), round($target['carbsG'] - $remaining['carbs']))] : null;

        $short = null;
        if (! $picked) {
            if ($best === null) {
                $short = 'Nothing in your meals and favourites is low enough in fat and fibre for this window. Tag a meal pre or post, or add a favourite like a banana or white rice.';
            } else {
                $short = sprintf('Nothing quite fits. The closest is %s: about %d g of the %d g of carbs wanted (%d g short). Add a fast carb like a banana, juice or white rice to close it.',
                    self::names($best['set']), round($best['total']['carbs']), round($target['carbsG']), max(0, round($target['carbsG'] - $best['total']['carbs'])));
            }
        }

        return ['options' => array_map(fn ($f) => self::present($f, $remaining), $picked), 'shortfall' => $short, 'shift' => $shift];
    }

    /** @param list<array> $set */
    private static function names(array $set): string
    {
        return implode(' + ', array_map(fn ($o) => self::portionLabel($o), $set));
    }

    private static function portionLabel(array $o): string
    {
        $amt = $o['c']['kind'] === 'meal' ? rtrim(rtrim(number_format($o['c']['amount'] * $o['s'], 2, '.', ''), '0'), '.') . ($o['c']['amount'] * $o['s'] == 1.0 ? ' portion' : ' portions')
            : round($o['c']['amount'] * $o['s']) . ' ' . $o['c']['unit'];

        return $o['c']['name'] . ' (' . $amt . ')';
    }

    /** @return array<string,mixed> */
    private static function present(array $f, array $remaining): array
    {
        $t = $f['total'];

        return [
            'items' => array_map(fn ($o) => ['kind' => $o['c']['kind'], 'ref' => $o['c']['ref'], 'name' => $o['c']['name'], 'label' => self::portionLabel($o),
                'amount' => round($o['c']['amount'] * $o['s'], $o['c']['kind'] === 'meal' ? 2 : 1), 'unit' => $o['c']['unit']], $f['set']),
            'carbs' => round($t['carbs']), 'protein' => round($t['protein']), 'fat' => round($t['fat']), 'fibre' => round($t['fibre']), 'kcal' => round($t['kcal']),
            'after' => ['kcal' => round($remaining['kcal'] - $t['kcal']), 'protein' => round($remaining['protein'] - $t['protein']), 'carbs' => round($remaining['carbs'] - $t['carbs']), 'fat' => round($remaining['fat'] - $t['fat'])],
        ];
    }
}
