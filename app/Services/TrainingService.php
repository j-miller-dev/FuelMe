<?php

namespace App\Services;

use App\Domain\Foods\LocalDay;
use App\Domain\Nutrition\ExerciseCalculator;
use App\Domain\Nutrition\MacroPlanner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The usual training week (template) and what actually happened on real dates (workout_logs). A date with ANY workout row is described by
 * its rows alone; a date with none falls back to the template. Planned rows are copies of the template that have not been confirmed yet.
 */
class TrainingService
{
    public const DEFAULT_MINUTES = ['bjj' => 60, 'lifting' => 40, 'hiit' => 30, 'norwegian_4x4' => 40, 'custom' => 45];

    /** @return array<string,mixed> what the UI offers: types, intensities, defaults. */
    public function catalogue(): array
    {
        $out = [];
        foreach (ExerciseCalculator::CATALOGUE as $type => $def) {
            $out[$type] = ['label' => $def['label'], 'minutes' => self::DEFAULT_MINUTES[$type] ?? 45, 'intensities' => array_map(fn ($i) => ['met' => $i['met'], 'src' => $i['src']], $def['intensities'])];
        }
        $out['custom'] = ['label' => 'Something else', 'minutes' => 45, 'intensities' => ['custom' => ['met' => null, 'src' => 'You enter the MET (effort) value']]];

        return $out;
    }

    /** The user's trend weight for energy maths (latest check-in/onboarding weight). */
    public function kg(User $user): float
    {
        $t = DB::table('target_history')->where('user_id', $user->id)->orderByDesc('effective_from')->orderByDesc('id')->value('plan_json');
        $kg = $t ? (json_decode($t, true)['inputs']['weightKg'] ?? null) : null;

        return (float) ($kg ?? DB::table('weight_logs')->where('user_id', $user->id)->orderByDesc('local_date')->value('kg') ?? 75.0);
    }

    /** @return list<array<string,mixed>> the template rows; seeds from the default week the first time (idempotent). */
    public function template(User $user): array
    {
        if (! DB::table('training_templates')->where('user_id', $user->id)->exists()) {
            $style = $user->profile?->lifting_style ?? 'hit';
            $week = config("fuel.seed_week.$style") ?? config('fuel.seed_week.hit');
            DB::transaction(function () use ($user, $week) {
                if (DB::table('training_templates')->where('user_id', $user->id)->exists()) {
                    return;
                }
                foreach ($week as $day => $sessions) {
                    foreach (array_values($sessions) as $i => $s) {
                        DB::table('training_templates')->insert(['user_id' => $user->id, 'day' => $day, 'start_time' => $s['at'] ?? null, 'type' => $s['type'], 'intensity' => $s['intensity'],
                            'minutes' => $s['minutes'], 'sort' => $i, 'created_at' => now(), 'updated_at' => now()]);
                    }
                }
            });
        }

        return DB::table('training_templates')->where('user_id', $user->id)->orderByRaw("case day when 'mon' then 1 when 'tue' then 2 when 'wed' then 3 when 'thu' then 4 when 'fri' then 5 when 'sat' then 6 else 7 end")
            ->orderBy('start_time')->orderBy('sort')->orderBy('id')->get()->map(fn ($r) => [
                'id' => (int) $r->id, 'day' => $r->day, 'start' => $r->start_time, 'type' => $r->type, 'intensity' => $r->intensity, 'minutes' => (int) $r->minutes,
                'customMet' => $r->custom_met !== null ? (float) $r->custom_met : null, 'name' => $r->name,
            ])->all();
    }

    /** Planner shape for the template: day => list of {type,intensity,minutes,customMet?}. */
    public function templateWeek(User $user): array
    {
        $week = array_fill_keys(MacroPlanner::DAYS, []);
        foreach ($this->template($user) as $r) {
            $week[$r['day']][] = $this->plannerRow($r);
        }

        return $week;
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private function plannerRow(array $r): array
    {
        return ['type' => $r['type'], 'intensity' => $r['intensity'], 'minutes' => $r['minutes']] + (isset($r['customMet']) && $r['customMet'] !== null ? ['customMet' => $r['customMet']] : []);
    }

    /**
     * Sessions for one date, each with its energy estimate. status: virtual (template, never touched), planned, done, skipped.
     *
     * @return list<array<string,mixed>>
     */
    public function sessions(User $user, string $date, bool $includeSkipped = false): array
    {
        $kg = $this->kg($user);
        $rows = DB::table('workout_logs')->where('user_id', $user->id)->where('local_date', $date)->orderByRaw('start_time is null')->orderBy('start_time')->orderBy('id')->get();
        $out = [];
        if ($rows->isEmpty()) {
            foreach ($this->template($user) as $t) {
                if ($t['day'] === LocalDay::dayKey($date)) {
                    $out[] = $this->present(['key' => 't' . $t['id'], 'id' => null, 'template_id' => $t['id'], 'status' => 'virtual', 'type' => $t['type'], 'intensity' => $t['intensity'], 'minutes' => $t['minutes'],
                        'start' => $t['start'], 'name' => $t['name'], 'customMet' => $t['customMet'], 'override' => null, 'source' => 'template', 'rpe' => null], $kg);
                }
            }
        }
        foreach ($rows as $r) {
            $out[] = $this->present(['key' => 'w' . $r->id, 'id' => (int) $r->id, 'template_id' => $r->template_id ? (int) $r->template_id : null, 'status' => $r->status, 'type' => $r->type,
                'intensity' => $r->intensity, 'minutes' => (int) $r->minutes, 'start' => $r->start_time, 'name' => $r->name, 'customMet' => $r->type === 'custom' ? (float) $r->met : null,
                'override' => $r->kcal_override !== null ? (float) $r->kcal_override : null, 'source' => $r->source, 'rpe' => $r->rpe !== null ? (float) $r->rpe : null, 'storedNet' => (float) $r->net_kcal], $kg);
        }

        return $includeSkipped ? $out : array_values(array_filter($out, fn ($s) => $s['status'] !== 'skipped'));
    }

    /** @param array<string,mixed> $s @return array<string,mixed> */
    private function present(array $s, float $kg): array
    {
        $calc = ExerciseCalculator::session($s['type'], $s['intensity'], $s['minutes'], $kg, $s['customMet'] ?? null);
        $label = $s['name'] ?: ExerciseCalculator::CATALOGUE[$s['type']]['label'] ?? 'Session';

        return $s + ['label' => $label, 'met' => $calc['met']] + [
            'netKcal' => $s['override'] ?? $calc['netKcal'], 'low' => $s['override'] ?? $calc['low'], 'high' => $s['override'] ?? $calc['high'], 'glycolytic' => $calc['glycolytic'], 'src' => $calc['src'],
        ];
    }

    /** Planner shape for the Mon-Sun week containing $date, with real sessions replacing the template on days that have any. */
    public function effectiveWeek(User $user, string $date): array
    {
        $monday = date('Y-m-d', strtotime($date . ' -' . ((int) date('N', strtotime($date)) - 1) . ' days'));
        $week = [];
        foreach (MacroPlanner::DAYS as $i => $key) {
            $d = date('Y-m-d', strtotime($monday . " +{$i} days"));
            $week[$key] = array_map(fn ($s) => ['type' => $s['type'], 'intensity' => $s['intensity'], 'minutes' => $s['minutes']] + ($s['type'] === 'custom' ? ['customMet' => $s['met']] : []), $this->sessions($user, $d));
        }

        return $week;
    }

    public function mondayOf(string $date): string
    {
        return date('Y-m-d', strtotime($date . ' -' . ((int) date('N', strtotime($date)) - 1) . ' days'));
    }

    /** Copy the template's sessions for $date into real rows (planned), once, so they can be confirmed, skipped or edited. */
    public function materialise(User $user, string $date): void
    {
        if (DB::table('workout_logs')->where('user_id', $user->id)->where('local_date', $date)->exists()) {
            return;
        }
        $kg = $this->kg($user);
        foreach ($this->sessions($user, $date) as $s) {
            $this->insert($user, $date, $s, $kg, 'planned', 'template', null);
        }
    }

    /** @param array<string,mixed> $s */
    private function insert(User $user, string $date, array $s, float $kg, string $status, string $source, ?string $key): int
    {
        $calc = ExerciseCalculator::session($s['type'], $s['intensity'], $s['minutes'], $kg, $s['customMet'] ?? null);

        return DB::table('workout_logs')->insertGetId(['user_id' => $user->id, 'local_date' => $date, 'status' => $status, 'type' => $s['type'], 'intensity' => $s['intensity'],
            'minutes' => $s['minutes'], 'rpe' => $s['rpe'] ?? null, 'met' => $calc['met'], 'net_kcal' => $calc['netKcal'], 'kcal_override' => $s['override'] ?? null, 'start_time' => $s['start'] ?? null,
            'name' => $s['name'] ?? null, 'source' => $source, 'template_id' => $s['template_id'] ?? null, 'idempotency_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Log a session that happened (or is added for today). A replayed idempotency key returns the first result. */
    public function add(User $user, string $date, array $d): int
    {
        if (! empty($d['idempotency_key']) && ($id = DB::table('workout_logs')->where('user_id', $user->id)->where('idempotency_key', $d['idempotency_key'])->value('id'))) {
            return (int) $id;
        }

        return DB::transaction(function () use ($user, $date, $d) {
            $this->materialise($user, $date);   // a day you touch stops being "virtual": its template sessions become real, confirmable rows

            return $this->insert($user, $date, ['type' => $d['type'], 'intensity' => $d['intensity'], 'minutes' => (int) $d['minutes'], 'start' => $d['start_time'] ?? null, 'name' => $d['name'] ?? null,
                'customMet' => $d['custom_met'] ?? null, 'override' => $d['kcal_override'] ?? null, 'rpe' => $d['rpe'] ?? null, 'template_id' => null], $this->kg($user), 'done', 'manual', $d['idempotency_key'] ?? null);
        });
    }

    /** Confirm or skip a template session for a date (materialises the day first). */
    public function mark(User $user, string $date, int $templateId, string $action): void
    {
        abort_unless(DB::table('training_templates')->where('id', $templateId)->where('user_id', $user->id)->exists(), 404);
        DB::transaction(function () use ($user, $date, $templateId, $action) {
            $this->materialise($user, $date);
            DB::table('workout_logs')->where('user_id', $user->id)->where('local_date', $date)->where('template_id', $templateId)
                ->update(['status' => ['done' => 'done', 'skip' => 'skipped', 'reset' => 'planned'][$action], 'updated_at' => now()]);
        });
    }

    public function own(User $user, int $id): object
    {
        $r = DB::table('workout_logs')->where('id', $id)->where('user_id', $user->id)->first();
        abort_unless($r, 404);

        return $r;
    }

    /** Edit minutes / intensity / start / status / a watch's kcal; the estimate is recomputed. */
    public function update(User $user, int $id, array $d): void
    {
        $r = $this->own($user, $id);
        $type = $r->type;
        $intensity = $d['intensity'] ?? $r->intensity;
        $minutes = (int) ($d['minutes'] ?? $r->minutes);
        $calc = ExerciseCalculator::session($type, $intensity, $minutes, $this->kg($user), $type === 'custom' ? (float) $r->met : null);
        $upd = ['intensity' => $intensity, 'minutes' => $minutes, 'met' => $calc['met'], 'net_kcal' => $calc['netKcal'], 'updated_at' => now()];
        foreach (['status', 'start_time', 'rpe'] as $k) {
            if (array_key_exists($k, $d)) {
                $upd[$k] = $d[$k];
            }
        }
        if (array_key_exists('kcal_override', $d)) {
            $upd['kcal_override'] = $d['kcal_override'];
        }
        DB::table('workout_logs')->where('id', $id)->update($upd);
    }

    public function delete(User $user, int $id): void
    {
        $this->own($user, $id);
        DB::table('workout_logs')->where('id', $id)->delete();
    }
}
