<?php

namespace App\Services;

use App\Domain\Foods\LocalDay;
use App\Domain\Progress\WeeklyReview;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Builds, stores and reads the weekly review (BLUEPRINT 9A.3). The structured JSON is always made; the note is the template unless the optional model passes the number check. */
class WeeklyReviewService
{
    public function __construct(private ProgressService $progress, private PlanService $plans, private ReviewWriter $writer) {}

    /** The week that ends on the given local date (default: the last 7 finished days). */
    public function generate(User $user, ?string $today = null, bool $force = false): array
    {
        $p = $user->profile;
        $today ??= LocalDay::of(now(), $p->tz, $p->rollover_hour);
        $end = date('Y-m-d', strtotime($today . ' -1 day'));
        $start = date('Y-m-d', strtotime($end . ' -6 days'));

        $existing = DB::table('weekly_reviews')->where('user_id', $user->id)->where('week_start', $start)->first();
        if ($existing && ! $force) {
            return $this->payload($existing);
        }

        $o = $this->progress->overview($user);
        $series = collect($o['series'])->keyBy('date');
        $before = $series->get(date('Y-m-d', strtotime($start . ' -1 day')));
        $atEnd = $series->get($end);
        $s = $o['summary'];
        $c = $o['consistency'] ?? ['counted' => 0, 'total' => 0, 'within15' => 0];

        $protein = DB::table('food_logs')->where('user_id', $user->id)->whereBetween('local_date', [$start, $end])->groupBy('local_date')->selectRaw('SUM(protein) as p')->pluck('p')->map(fn ($v) => (float) $v);
        $sessions = DB::table('workout_logs')->where('user_id', $user->id)->whereBetween('local_date', [$start, $end])->selectRaw('status, COUNT(*) as n')->groupBy('status')->pluck('n', 'status');
        $ck = $o['checkin'];
        $tdeeChange = $before && $atEnd ? (int) round($atEnd['tdee'] - $before['tdee']) : null;

        $review = WeeklyReview::build([
            'weekStart' => $start, 'weekEnd' => $end, 'phase' => $s['phase'], 'trendKg' => $s['trendKg'], 'ratePctPerWeek' => $s['ratePctPerWeek'] === null ? null : round((float) $s['ratePctPerWeek'], 2),
            'goalPctPerWeek' => round($s['goalPctPerWeek'], 2), 'daysCounted' => $c['counted'], 'daysTotal' => $c['total'], 'daysWithin15' => $c['within15'],
            'avgProtein' => $protein->isEmpty() ? null : (int) round($protein->avg()), 'proteinTarget' => (int) round($this->plans->forDay($user, $end)['day']['protein']),
            'undereating' => $o['undereating']['flag'] ?? false, 'sessionsDone' => (int) ($sessions['done'] ?? 0), 'sessionsSkipped' => (int) ($sessions['skipped'] ?? 0),
            'tdee' => (int) $s['tdee'], 'tdeeChange' => $tdeeChange, 'calibrating' => $s['calibrating'],
            'checkinStatus' => $ck['status'] ?? null, 'targetKcal' => (int) $s['target'], 'proposedKcal' => isset($ck['proposedKcal']) ? (int) $ck['proposedKcal'] : null,
            'reasons' => array_values(array_slice($ck['lines'] ?? [], 0, 4)),
        ]);

        $template = WeeklyReview::template($review);
        $model = $this->writer->rewrite($review, $template);
        DB::table('weekly_reviews')->updateOrInsert(['user_id' => $user->id, 'week_start' => $start], [
            'review_json' => json_encode($review), 'note_text' => $model ?? $template, 'generated_by' => $model ? 'model' : 'template', 'updated_at' => now(), 'created_at' => now(),
        ]);

        return $this->payload(DB::table('weekly_reviews')->where('user_id', $user->id)->where('week_start', $start)->first());
    }

    /** @return list<array<string,mixed>> newest first */
    public function recent(User $user, int $n = 8): array
    {
        return DB::table('weekly_reviews')->where('user_id', $user->id)->orderByDesc('week_start')->limit($n)->get()->map(fn ($r) => $this->payload($r))->all();
    }

    private function payload(object $r): array
    {
        return ['week_start' => (string) $r->week_start, 'note' => $r->note_text, 'generated_by' => $r->generated_by, 'review' => json_decode($r->review_json, true)];
    }
}
