<?php

namespace App\Services\Hevy;

use App\Domain\Training\HevyMapper;
use App\Models\User;
use App\Services\TrainingService;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Pulls your Hevy workouts into FUEL. First run reads everything; after that only the changes since the last run (updates and deletes).
 * Re-running is safe: workouts are matched by Hevy's id and their sets are replaced. Workouts from the last 14 days also mark the matching
 * planned lifting session as done (or add one), so the plan and Today agree; older history is used for strength numbers only.
 */
class HevySync
{
    public const LINK_DAYS = 14;

    public function __construct(private TrainingService $training) {}

    public function client(object $conn): HevyClient
    {
        return new HevyClient(Crypt::decryptString($conn->api_key));
    }

    /** @return array{ok:bool,workouts:int,deleted:int,error:?string} */
    public function sync(User $user, ?HevyClient $client = null): array
    {
        $conn = DB::table('hevy_connections')->where('user_id', $user->id)->first();
        if (! $conn) {
            return ['ok' => false, 'workouts' => 0, 'deleted' => 0, 'error' => 'Not connected.'];
        }
        $client ??= $this->client($conn);
        $startedAt = now();
        $count = $deleted = 0;
        try {
            if (! $conn->templates_synced_at || now()->diffInDays($conn->templates_synced_at, true) >= 7 || ! $conn->cursor_at) {
                $this->templates($user, $client);
            }
            if (! $conn->cursor_at) {
                $page = 1;
                do {
                    $r = $client->workouts($page);
                    foreach ((array) ($r['workouts'] ?? []) as $w) {
                        $count += $this->upsert($user, $w) ? 1 : 0;
                    }
                    $pages = (int) ($r['page_count'] ?? 1);
                } while (++$page <= min($pages, 500));
            } else {
                $since = \Illuminate\Support\Carbon::parse($conn->cursor_at)->utc()->format('Y-m-d\TH:i:s\Z');
                $page = 1;
                do {
                    $r = $client->events($since, $page);
                    foreach ((array) ($r['events'] ?? []) as $e) {
                        if (($e['type'] ?? '') === 'deleted') {
                            $deleted += $this->delete($user, (string) ($e['id'] ?? $e['workout_id'] ?? ''));
                        } elseif (isset($e['workout']) && is_array($e['workout'])) {
                            $count += $this->upsert($user, $e['workout']) ? 1 : 0;
                        }
                    }
                    $pages = (int) ($r['page_count'] ?? 1);
                } while (++$page <= min($pages, 100));
            }
            DB::table('hevy_connections')->where('user_id', $user->id)->update(['cursor_at' => $startedAt->copy()->subMinutes(5), 'last_synced_at' => now(), 'last_error' => null, 'updated_at' => now()]);
            $this->routines($user, $client);   // separate: a routines problem is recorded on its own and never fails the workout sync

            return ['ok' => true, 'workouts' => $count, 'deleted' => $deleted, 'error' => null];
        } catch (HevyException $e) {
            DB::table('hevy_connections')->where('user_id', $user->id)->update(['last_error' => mb_substr($e->getMessage(), 0, 200), 'updated_at' => now()]);

            return ['ok' => false, 'workouts' => $count, 'deleted' => $deleted, 'error' => $e->getMessage()];
        }
    }

    /** Replaces your routines with Hevy's current list. Routines have no change feed. Nothing is removed unless every page was read. */
    public function routines(User $user, HevyClient $client): bool
    {
        try {
            $folders = [];
            $page = 1;
            do {
                $r = $client->routineFolders($page);
                foreach ((array) ($r['routine_folders'] ?? []) as $f) {
                    if (isset($f['id'])) {
                        $folders[(string) $f['id']] = mb_substr((string) ($f['title'] ?? ''), 0, 120);
                    }
                }
                $pages = (int) ($r['page_count'] ?? 1);
            } while (++$page <= min($pages, 20));

            $mapped = [];
            $page = 1;
            do {
                $r = $client->routines($page);
                if ($page === 1 && ! array_key_exists('routines', $r)) {   // the field names are unverified: never wipe stored routines on a response we do not recognise
                    throw new HevyException('Hevy sent routines in a shape FUEL does not recognise.');
                }
                foreach ((array) ($r['routines'] ?? []) as $raw) {
                    if ($m = HevyMapper::routine((array) $raw, $folders)) {
                        $mapped[$m['hevy_id']] = $m;
                    }
                }
                $pages = (int) ($r['page_count'] ?? 1);
            } while (++$page <= min($pages, 50));
        } catch (HevyException $e) {
            DB::table('hevy_connections')->where('user_id', $user->id)->update(['routines_error' => mb_substr($e->getMessage(), 0, 200)]);

            return false;
        }
        DB::transaction(function () use ($user, $mapped) {
            foreach ($mapped as $m) {
                $row = ['title' => $m['title'], 'folder_id' => $m['folder_id'], 'folder_title' => $m['folder_title'], 'notes' => $m['notes'], 'hevy_updated_at' => $m['updated_at'], 'updated_at' => now()];
                $existing = DB::table('hevy_routines')->where('user_id', $user->id)->where('hevy_id', $m['hevy_id'])->first();
                if ($existing) {
                    DB::table('hevy_routines')->where('id', $existing->id)->update($row);
                    DB::table('hevy_routine_exercises')->where('hevy_routine_id', $existing->id)->delete();
                    $id = $existing->id;
                } else {
                    $id = DB::table('hevy_routines')->insertGetId($row + ['user_id' => $user->id, 'hevy_id' => $m['hevy_id'], 'created_at' => now()]);
                }
                foreach ($m['exercises'] as $e) {
                    DB::table('hevy_routine_exercises')->insert(['hevy_routine_id' => $id, 'exercise_index' => $e['exercise_index'], 'template_id' => $e['template_id'], 'title' => $e['title'], 'rest_seconds' => $e['rest_seconds'],
                        'notes' => $e['notes'], 'superset_id' => $e['superset_id'], 'sets' => json_encode($e['sets'])]);
                }
            }
            DB::table('hevy_routines')->where('user_id', $user->id)->whereNotIn('hevy_id', array_keys($mapped) ?: [''])->delete();   // deleted in Hevy
        });
        DB::table('hevy_connections')->where('user_id', $user->id)->update(['routines_synced_at' => now(), 'routines_error' => null]);

        return true;
    }

    private function templates(User $user, HevyClient $client): void
    {
        $page = 1;
        do {
            $r = $client->templates($page);
            foreach ((array) ($r['exercise_templates'] ?? []) as $t) {
                $m = HevyMapper::template((array) $t);
                if ($m) {
                    DB::table('hevy_templates')->updateOrInsert(['user_id' => $user->id, 'template_id' => $m['template_id']], ['title' => $m['title'], 'muscle' => $m['muscle'], 'secondary' => json_encode($m['secondary'])]);
                }
            }
            $pages = (int) ($r['page_count'] ?? 1);
        } while (++$page <= min($pages, 100));
        DB::table('hevy_connections')->where('user_id', $user->id)->update(['templates_synced_at' => now()]);
    }

    /** @param array<string,mixed> $w */
    public function upsert(User $user, array $w): bool
    {
        $m = HevyMapper::workout($w, $user->profile->tz, (int) $user->profile->rollover_hour);
        if (! $m) {
            return false;
        }
        DB::transaction(function () use ($user, $m) {
            $row = ['title' => $m['title'], 'routine_id' => $m['routine_id'], 'start_at' => $m['start_at'], 'end_at' => $m['end_at'], 'local_date' => $m['local_date'], 'minutes' => $m['minutes'], 'updated_at' => now()];
            $existing = DB::table('hevy_workouts')->where('user_id', $user->id)->where('hevy_id', $m['hevy_id'])->first();
            if ($existing) {
                DB::table('hevy_workouts')->where('id', $existing->id)->update($row);
                DB::table('hevy_sets')->where('hevy_workout_id', $existing->id)->delete();
                $id = $existing->id;
            } else {
                $id = DB::table('hevy_workouts')->insertGetId($row + ['user_id' => $user->id, 'hevy_id' => $m['hevy_id'], 'created_at' => now()]);
            }
            foreach (array_chunk(array_map(fn ($s) => ['hevy_workout_id' => $id] + $s, $m['sets']), 200) as $chunk) {
                DB::table('hevy_sets')->insert($chunk);
            }
            $this->link($user, $m);
        });

        return true;
    }

    private function delete(User $user, string $hevyId): int
    {
        return $hevyId === '' ? 0 : DB::table('hevy_workouts')->where('user_id', $user->id)->where('hevy_id', $hevyId)->delete();
    }

    /** Recent workouts confirm the planned lifting session for that day (or add one). Never touches older days. */
    private function link(User $user, array $m): void
    {
        $today = now($user->profile->tz)->toDateString();
        if ($m['local_date'] < date('Y-m-d', strtotime($today . ' -' . self::LINK_DAYS . ' days')) || $m['local_date'] > date('Y-m-d', strtotime($today . ' +1 day'))) {
            return;
        }
        $key = $this->uuidFor($user, $m['hevy_id']);
        if (DB::table('workout_logs')->where('user_id', $user->id)->where('idempotency_key', $key)->exists()) {
            return;
        }
        $this->training->materialise($user, $m['local_date']);
        $minutes = max(5, min(240, (int) ($m['minutes'] ?? 40)));
        $planned = DB::table('workout_logs')->where('user_id', $user->id)->where('local_date', $m['local_date'])->where('type', 'lifting')->where('status', 'planned')->orderBy('id')->first();
        if ($planned) {
            $this->training->update($user, (int) $planned->id, ['status' => 'done', 'minutes' => $minutes]);
            DB::table('workout_logs')->where('id', $planned->id)->update(['idempotency_key' => $key, 'name' => $m['title']]);

            return;
        }
        if (DB::table('workout_logs')->where('user_id', $user->id)->where('local_date', $m['local_date'])->where('type', 'lifting')->where('status', 'done')->exists()) {
            return;   // you already logged a lifting session that day by hand
        }
        $this->training->add($user, $m['local_date'], ['type' => 'lifting', 'intensity' => $user->profile->lifting_style === 'volume' ? 'standard' : 'hit', 'minutes' => $minutes, 'name' => $m['title'], 'idempotency_key' => $key]);
    }

    private function uuidFor(User $user, string $hevyId): string
    {
        $h = md5('hevy:' . $user->id . ':' . $hevyId);

        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), '5' . substr($h, 13, 3), '8' . substr($h, 17, 3), substr($h, 20, 12));
    }
}
