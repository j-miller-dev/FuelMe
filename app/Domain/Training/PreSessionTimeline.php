<?php
declare(strict_types=1);

namespace App\Domain\Training;

/**
 * A clock-time plan for one session: "9:00 am have X, 11:00 am have Y". It reuses the PeriWorkout numbers (pre meal for ~3 h out, a small
 * quick-carb top-up for ~30 min out) and adds fluids from Hydration. Nothing here is extra food on top of the day: it is how the day's
 * targets are spread around the session. Pure PHP.
 */
final class PreSessionTimeline
{
    /**
     * @param array{carbsG:int,proteinG:int,fatMaxG:int,fibreMaxG:int}|null $mainPre plan for a meal ~3 h before (null if not applicable)
     * @param array{carbsG?:int}|null $topUp plan for a small snack under an hour out
     * @param array<string,mixed> $post the session's post plan (proteinG, carbsG|carbsGPerHour, withinHours)
     * @return list<array{atMin:int,at:string,inMin:int,kind:string,title:string,text:string}>
     */
    public static function build(int $startMin, int $minutes, float $kg, int $nowMin, ?array $mainPre, ?array $topUp, array $post): array
    {
        $steps = [];
        $add = function (int $at, string $kind, string $title, string $text) use (&$steps, $nowMin) {
            $steps[] = ['atMin' => $at, 'at' => self::clock($at), 'inMin' => $at - $nowMin, 'kind' => $kind, 'title' => $title, 'text' => $text];
        };

        $f4 = Hydration::preMl($kg, 5, 7);
        $add($startMin - 240, 'fluid', 'Start hydrated', sprintf('Drink %d to %d ml of water slowly over the next hour or so (about 5 to 7 ml per kg). Skip it if your urine is already pale.', $f4['lowMl'], $f4['highMl']));

        if ($mainPre !== null) {
            $add($startMin - 180, 'meal', 'Main pre-session meal', sprintf('%d g carbs, %d g protein, fat under %d g, fibre under %d g. Familiar, easy to digest food.', $mainPre['carbsG'], $mainPre['proteinG'], $mainPre['fatMaxG'], $mainPre['fibreMaxG']));
        }

        $f2 = Hydration::preMl($kg, 3, 5);
        $add($startMin - 120, 'fluid', 'Check your urine', sprintf('If it is dark, drink another %d to %d ml slowly (about 3 to 5 ml per kg). If it is pale, sip to thirst.', $f2['lowMl'], $f2['highMl']));

        if ($topUp !== null && isset($topUp['carbsG'])) {
            $add($startMin - 40, 'snack', 'Optional quick top-up', sprintf('If you are hungry: about %d g of fast carbs (a banana or a few dates), nothing heavy.', $topUp['carbsG']));
        }

        $add($startMin, 'session', 'Session', Hydration::sodiumNote($minutes) . ' Sip water as you need it.');

        $carbs = isset($post['carbsG']) ? sprintf(', %d g carbs', $post['carbsG']) : (isset($post['carbsGPerHour']) ? sprintf(', %d to %d g carbs an hour', $post['carbsGPerHour'][0], $post['carbsGPerHour'][1]) : '');
        $add($startMin + $minutes, 'after', 'After', sprintf('%d g protein%s within %s h, plus water. Weigh yourself before and after to see how much to drink.', $post['proteinG'], $carbs, (string) ($post['withinHours'] ?? 2)));

        usort($steps, fn ($a, $b) => $a['atMin'] <=> $b['atMin']);

        return $steps;
    }

    public static function clock(int $min): string
    {
        $m = (($min % 1440) + 1440) % 1440;
        $h = intdiv($m, 60);

        return sprintf('%d:%02d %s', $h % 12 === 0 ? 12 : $h % 12, $m % 60, $h < 12 ? 'am' : 'pm');
    }
}
