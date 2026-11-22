<?php

namespace App\Services;

use App\Domain\Foods\LocalDay;
use App\Domain\Nutrition\ExerciseCalculator;
use App\Models\FoodLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Brings history in: a FUEL backup, or rows the person mapped from any spreadsheet (BLUEPRINT M9). Every row is validated; nothing is trusted
 * because a file said so. Importing the same rows twice changes nothing (existing dates are skipped, imported rows carry a key derived from
 * their content). Vendor presets (MacroFactor, Cronometer, Hevy) are NOT guessed here: the person maps the columns.
 */
class ImportService
{
    private const NS = '6f1c2f0e-6b3a-4c53-9d0a-3f0f0f0f4a11';
    public const MAX_ROWS = 1000;
    public const KINDS = ['weights', 'daily_totals', 'food_logs', 'workouts'];
    public const EARLIEST = '2000-01-01';

    public function __construct(private TrainingService $training) {}

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{added:int,skipped:int,invalid:list<array{row:int,reason:string}>,first_date:?string,last_date:?string}
     */
    public function run(User $user, string $kind, array $rows): array
    {
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);
        $out = ['added' => 0, 'skipped' => 0, 'invalid' => [], 'first_date' => null, 'last_date' => null];
        DB::transaction(function () use ($user, $kind, $rows, $today, &$out) {
            foreach ($rows as $i => $row) {
                try {
                    $date = $this->date($row['date'] ?? $row['local_date'] ?? null, $today);
                    $added = match ($kind) {
                        'weights' => $this->weight($user, $date, $row),
                        'daily_totals' => $this->dailyTotal($user, $date, $row),
                        'food_logs' => $this->foodLog($user, $date, $row),
                        'workouts' => $this->workout($user, $date, $row),
                    };
                    $out[$added ? 'added' : 'skipped']++;
                    $out['first_date'] = $out['first_date'] === null || $date < $out['first_date'] ? $date : $out['first_date'];
                    $out['last_date'] = $out['last_date'] === null || $date > $out['last_date'] ? $date : $out['last_date'];
                } catch (\InvalidArgumentException $e) {
                    if (count($out['invalid']) < 25) {
                        $out['invalid'][] = ['row' => $i + 1, 'reason' => $e->getMessage()];
                    }
                }
            }
        });

        return $out;
    }

    private function date(mixed $v, string $today): string
    {
        if (! is_string($v) || ! preg_match('/^\d{4}-\d{2}-\d{2}/', $v) || ! checkdate((int) substr($v, 5, 2), (int) substr($v, 8, 2), (int) substr($v, 0, 4))) {
            throw new \InvalidArgumentException('Not a date (expected YYYY-MM-DD).');
        }
        $d = substr($v, 0, 10);
        if ($d < self::EARLIEST || $d > $today) {
            throw new \InvalidArgumentException("Date {$d} is outside the range FUEL accepts (" . self::EARLIEST . ' to today).');
        }

        return $d;
    }

    private function num(mixed $v, string $what, float $min, float $max, bool $required = true): ?float
    {
        if ($v === null || $v === '') {
            if ($required) {
                throw new \InvalidArgumentException("Missing {$what}.");
            }

            return null;
        }
        if (! is_numeric($v)) {
            throw new \InvalidArgumentException("{$what} is not a number.");
        }
        $f = (float) $v;
        if ($f < $min || $f > $max) {
            throw new \InvalidArgumentException(sprintf('%s %s is outside %s to %s.', $what, rtrim(rtrim(number_format($f, 2, '.', ''), '0'), '.'), $min, $max));
        }

        return $f;
    }

    private function weight(User $user, string $date, array $row): bool
    {
        $kg = $this->num($row['kg'] ?? null, 'weight (kg)', 30, 300);
        if (DB::table('weight_logs')->where('user_id', $user->id)->where('local_date', $date)->exists()) {
            return false;   // never overwrite a weigh-in you already have
        }
        DB::table('weight_logs')->insert(['user_id' => $user->id, 'local_date' => $date, 'kg' => round($kg, 1), 'source' => 'import', 'created_at' => now(), 'updated_at' => now()]);

        return true;
    }

    /** One entry per day carrying that day's totals: enough for the trend and expenditure to learn from, without inventing foods. */
    private function dailyTotal(User $user, string $date, array $row): bool
    {
        $kcal = $this->num($row['kcal'] ?? null, 'calories', 200, 15000);
        $p = $this->num($row['protein'] ?? null, 'protein', 0, 1500, false) ?? 0.0;
        $c = $this->num($row['carbs'] ?? null, 'carbs', 0, 3000, false) ?? 0.0;
        $f = $this->num($row['fat'] ?? null, 'fat', 0, 1500, false) ?? 0.0;
        if (FoodLog::where('user_id', $user->id)->where('local_date', $date)->exists()) {
            return false;   // a day you logged yourself is never mixed with an imported total
        }
        $this->insertLog($user, $date, 'snack', 'Imported daily total', null, null, $kcal, $p, $c, $f, null, 'import', "{$date} 12:00:00", 'day');

        return true;
    }

    private function foodLog(User $user, string $date, array $row): bool
    {
        $slot = in_array($row['meal_slot'] ?? $row['meal'] ?? null, FoodLog::SLOTS, true) ? ($row['meal_slot'] ?? $row['meal']) : 'snack';
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            throw new \InvalidArgumentException('Missing food name.');
        }
        $grams = $this->num($row['grams'] ?? null, 'grams', 0.1, 50000, false);
        $kcal = $this->num($row['kcal'] ?? null, 'calories', 0, 20000);
        $logged = is_string($row['logged_at'] ?? null) && strtotime($row['logged_at']) ? gmdate('Y-m-d H:i:s', strtotime($row['logged_at'])) : "{$date} 12:00:00";
        $key = Uuid::uuid5(self::NS, implode('|', [$user->id, $date, $slot, mb_strtolower($name), $grams, round($kcal, 1), $logged]))->toString();
        if (FoodLog::where('user_id', $user->id)->where('idempotency_key', $key)->exists()) {
            return false;
        }
        FoodLog::create(['user_id' => $user->id, 'local_date' => $date, 'logged_at' => $logged, 'meal_slot' => $slot, 'food_id' => null, 'name' => mb_substr($name, 0, 200), 'grams' => $grams,
            'serving_label' => isset($row['serving_label']) ? mb_substr((string) $row['serving_label'], 0, 80) : null, 'kcal' => $kcal,
            'protein' => $this->num($row['protein'] ?? null, 'protein', 0, 3000, false) ?? 0, 'carbs' => $this->num($row['carbs'] ?? null, 'carbs', 0, 5000, false) ?? 0,
            'fat' => $this->num($row['fat'] ?? null, 'fat', 0, 3000, false) ?? 0, 'fibre' => $this->num($row['fibre'] ?? null, 'fibre', 0, 1000, false), 'source' => 'import', 'idempotency_key' => $key]);

        return true;
    }

    private function insertLog(User $user, string $date, string $slot, string $name, ?float $grams, ?string $serving, float $kcal, float $p, float $c, float $f, ?float $fibre, string $source, string $loggedAt, string $tag): void
    {
        FoodLog::create(['user_id' => $user->id, 'local_date' => $date, 'logged_at' => $loggedAt, 'meal_slot' => $slot, 'food_id' => null, 'name' => $name, 'grams' => $grams, 'serving_label' => $serving,
            'kcal' => $kcal, 'protein' => $p, 'carbs' => $c, 'fat' => $f, 'fibre' => $fibre, 'source' => $source, 'idempotency_key' => Uuid::uuid5(self::NS, "{$user->id}|{$date}|{$tag}")->toString()]);
    }

    private function workout(User $user, string $date, array $row): bool
    {
        if (($row['status'] ?? 'done') !== 'done') {
            return false;   // planned and skipped sessions are not history
        }
        $type = (string) ($row['type'] ?? $row['activity'] ?? '');
        $intensity = (string) ($row['intensity'] ?? '');
        if ($type === 'custom') {
            $met = $this->num($row['met'] ?? $row['custom_met'] ?? null, 'MET', 1.5, 20);
        } elseif (! isset(ExerciseCalculator::CATALOGUE[$type]['intensities'][$intensity])) {
            throw new \InvalidArgumentException("Unknown activity or intensity ({$type}/{$intensity}).");
        }
        $minutes = (int) $this->num($row['minutes'] ?? null, 'minutes', 5, 600);
        $start = is_string($row['start_time'] ?? null) && preg_match('/^\d{2}:\d{2}$/', $row['start_time']) ? $row['start_time'] : null;
        $key = Uuid::uuid5(self::NS, implode('|', [$user->id, $date, $type, $intensity, $minutes, $start]))->toString();
        if (DB::table('workout_logs')->where('user_id', $user->id)->where('idempotency_key', $key)->exists()) {
            return false;
        }
        $this->training->add($user, $date, ['type' => $type, 'intensity' => $type === 'custom' ? 'custom' : $intensity, 'minutes' => $minutes, 'start_time' => $start, 'name' => $row['name'] ?? null,
            'custom_met' => $type === 'custom' ? ($met ?? null) : null, 'kcal_override' => $this->num($row['kcal_override'] ?? null, 'calories', 0, 5000, false), 'idempotency_key' => $key, 'materialise' => false]);   // history must not grow "planned" copies of today's template

        return true;
    }
}
