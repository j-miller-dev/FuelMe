<?php

namespace App\Services\Hevy;

use App\Domain\Training\PersonalRecords;
use App\Domain\Training\Strength;
use App\Models\User;
use App\Services\Coach\PrincipleService;
use Illuminate\Support\Facades\DB;

/** Best lifts and the per-exercise page: records, trend, your own notes, and your principle cards that fit. Read-only on Hevy data; notes are yours. */
class LiftsService
{
    public const KINDS = ['cue', 'worked', 'note'];

    public function __construct(private StrengthService $strength, private PrincipleService $principles) {}

    private function today(User $u): string
    {
        return now($u->profile->tz)->toDateString();
    }

    /** All synced history (not just the 180-day window the Lifting page uses), so an old best is still your best. */
    private function sessions(User $u): array
    {
        return $this->strength->sessions($u, 3650);
    }

    /** @return array<string,mixed> */
    public function board(User $user): array
    {
        $records = PersonalRecords::build($this->sessions($user), $this->today($user));
        $notes = DB::table('exercise_notes')->where('user_id', $user->id)->selectRaw('template_id, count(*) as n')->groupBy('template_id')->pluck('n', 'template_id');
        foreach ($records as &$r) {
            $r['notes'] = (int) ($notes[$r['id']] ?? 0);
        }
        unset($r);

        return [
            'exercises' => $records,
            'new_count' => count(array_filter($records, fn ($r) => $r['has_new'])),
            'notes' => ['Records come from your synced Hevy sets. Warm-ups, and sets without a weight or reps, are left out.', 'Estimated 1RM is an estimate (Epley, 1-12 reps; a set tagged failure counts as 0 reps in reserve, and a logged RPE adds the reps left).', 'A record is the first time you reached a number. The first time you log a lift is a baseline, never a PR. "New" means set in the last ' . PersonalRecords::NEW_DAYS . ' days.'],
        ];
    }

    /** @return array<string,mixed>|null null when the exercise is unknown (no synced sets and no template) */
    public function exercise(User $user, string $id): ?array
    {
        $all = $this->sessions($user);
        $rec = array_values(array_filter(PersonalRecords::build($all, $this->today($user)), fn ($r) => $r['id'] === $id))[0] ?? null;
        $tpl = DB::table('hevy_templates')->where('user_id', $user->id)->where('template_id', $id)->first();
        $title = $rec['title'] ?? $tpl->title ?? null;
        $notes = $this->notes($user, $id);
        if ($title === null && ! $notes) {
            return null;
        }
        $title ??= 'Exercise';
        $muscle = $rec['muscle'] ?? $tpl->muscle ?? null;

        $train = array_values(array_filter($all, fn ($s) => ! $s['deload']));   // an easy deload week is not a stall
        $h = Strength::history($train, $id);
        $status = $h ? Strength::status($h) : null;
        $last = $h ? $h[count($h) - 1] : null;

        $recent = [];
        foreach (array_slice(array_reverse($all), 0, 60) as $s) {
            foreach ($s['exercises'] as $ex) {
                if ((string) $ex['id'] !== $id) {
                    continue;
                }
                $recent[] = ['date' => $s['date'], 'deload' => $s['deload'], 'sets' => array_map(fn ($x) => ['type' => $x['type'] ?? 'normal', 'kg' => $x['kg'], 'reps' => $x['reps'], 'rpe' => $x['rpe']], $ex['sets'])];
                break;
            }
            if (count($recent) >= 6) {
                break;
            }
        }

        $cards = $this->principles->search($user, trim($title . ' ' . str_replace('_', ' ', (string) $muscle)), 3);

        return [
            'id' => $id, 'title' => $title, 'muscle' => $muscle,
            'records' => $rec,
            'status' => $status ? ['status' => $status['status'], 'sessions' => $status['sessions'], 'latest_e1rm' => $status['latest'], 'best_e1rm' => $status['best']] : null,
            'trend' => array_map(fn ($x) => ['date' => $x['date'], 'e1rm' => $x['e1rm']], array_slice($h, -20)),
            'beat_the_log' => $last ? Strength::beatTheLog($last, (float) config('fuel.hevy.plate_step_kg')) : [],
            'recent' => $recent,
            'notes' => $notes,
            'principles' => array_map(fn ($c) => ['id' => $c['id'], 'title' => $c['title'], 'source' => $c['source'], 'stance' => $c['stance'], 'body' => mb_substr($c['body'], 0, 400)], $cards),
        ];
    }

    /** @return list<array{id:int,kind:string,body:string,date:string}> newest first */
    public function notes(User $user, string $id): array
    {
        return DB::table('exercise_notes')->where('user_id', $user->id)->where('template_id', $id)->orderByDesc('local_date')->orderByDesc('id')->get()
            ->map(fn ($n) => ['id' => (int) $n->id, 'kind' => $n->kind, 'body' => $n->body, 'date' => (string) $n->local_date])->all();
    }

    public function addNote(User $user, string $id, string $kind, string $body): int
    {
        return (int) DB::table('exercise_notes')->insertGetId(['user_id' => $user->id, 'template_id' => $id, 'kind' => $kind, 'body' => $body, 'local_date' => $this->today($user), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Finds an exercise by a name fragment ("bench"): the best match is the one trained most recently. @return array<string,mixed>|null */
    public function find(User $user, string $name): ?array
    {
        $n = mb_strtolower(trim($name));
        if ($n === '') {
            return null;
        }
        foreach (PersonalRecords::build($this->sessions($user), $this->today($user)) as $r) {
            if (str_contains(mb_strtolower($r['title']), $n)) {
                return $r;
            }
        }

        return null;
    }
}
