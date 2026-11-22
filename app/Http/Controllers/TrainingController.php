<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Domain\Nutrition\ExerciseCalculator;
use App\Domain\Nutrition\MacroPlanner;
use App\Services\PlanService;
use App\Services\TrainingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** The training week, and logging sessions that really happened (BLUEPRINT 7). Exercise energy is an estimate (+/-25%) and is NEVER added to your food budget. */
class TrainingController extends Controller
{
    public function __construct(private TrainingService $training, private PlanService $plans) {}

    public function show(Request $request): Response
    {
        $user = $request->user();
        $today = $this->today($request);
        $recent = [];
        for ($i = 0; $i < 14; $i++) {
            $d = date('Y-m-d', strtotime($today . " -{$i} days"));
            foreach ($this->training->sessions($user, $d, true) as $s) {
                if ($s['id'] !== null) {
                    $recent[] = ['date' => $d] + $s;
                }
            }
        }

        return Inertia::render('Training', [
            'template' => $this->training->template($user), 'catalogue' => $this->training->catalogue(), 'recent' => $recent, 'today' => $today,
            'week' => $this->summary($user), 'kg' => round($this->training->kg($user), 1),
            'style' => $user->profile->lifting_style,
            'load' => $this->load($user, $today),
        ]);
    }

    /** @return array{this_week:array<string,int>,last_week:array<string,int>} session load (your effort rating x minutes), rated sessions only */
    private function load($user, string $today): array
    {
        $monday = $this->training->mondayOf($today);
        $lastMonday = date('Y-m-d', strtotime($monday . ' -7 days'));
        $rows = \Illuminate\Support\Facades\DB::table('workout_logs')->where('user_id', $user->id)->where('status', 'done')->where('local_date', '>=', $lastMonday)->get()
            ->map(fn ($r) => ['date' => (string) $r->local_date, 'minutes' => (int) $r->minutes, 'rpe' => $r->rpe !== null ? (float) $r->rpe : null])->all();

        return ['this_week' => \App\Domain\Training\SessionLoad::between($rows, $monday, $today), 'last_week' => \App\Domain\Training\SessionLoad::between($rows, $lastMonday, date('Y-m-d', strtotime($monday . ' -1 day')))];
    }

    /** @return array<string,array{class:string,netKcal:float,carbs:int,kcal:int}> what the template week looks like to the planner */
    private function summary($user): array
    {
        $plan = $this->plans->base($user);

        return array_map(fn ($d) => ['class' => $d['class'], 'netKcal' => $d['netKcal'], 'carbs' => (int) $d['carbs'], 'kcal' => (int) $d['kcal']], $plan['days']);
    }

    public function storeTemplate(Request $request): JsonResponse
    {
        $d = $this->validateSession($request, true);
        $this->training->template($request->user());   // seed first, so the first manual add does not hide the default week
        $id = DB::table('training_templates')->insertGetId($this->templateRow($request->user()->id, $d) + ['sort' => 99, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['id' => $id, 'template' => $this->training->template($request->user()), 'week' => $this->summary($request->user())], 201);
    }

    public function updateTemplate(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('training_templates')->where('id', $id)->where('user_id', $request->user()->id)->exists(), 404);
        $d = $this->validateSession($request, true);
        DB::table('training_templates')->where('id', $id)->update($this->templateRow($request->user()->id, $d) + ['updated_at' => now()]);

        return response()->json(['template' => $this->training->template($request->user()), 'week' => $this->summary($request->user())]);
    }

    public function destroyTemplate(Request $request, int $id): JsonResponse
    {
        abort_unless(DB::table('training_templates')->where('id', $id)->where('user_id', $request->user()->id)->exists(), 404);
        DB::table('training_templates')->where('id', $id)->delete();   // workout_logs.template_id becomes null; logged history stays

        return response()->json(['template' => $this->training->template($request->user()), 'week' => $this->summary($request->user())]);
    }

    /** POST /workouts/parse: words in, session cards out. Nothing is saved; each card goes through the normal POST /workouts. */
    public function parse(Request $request): JsonResponse
    {
        $d = $request->validate(['text' => ['required', 'string', 'max:600']]);
        $user = $request->user();
        $r = \App\Domain\Training\WorkoutTextParser::parse($d['text'], $user->profile->lifting_style === 'volume' ? 'standard' : 'hit');
        $today = \App\Domain\Foods\LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);
        $items = array_map(fn ($i) => $i + ['local_date' => $i['day'] === 'yesterday' ? date('Y-m-d', strtotime($today . ' -1 day')) : $today], $r['items']);

        return response()->json(['items' => $items, 'unparsed' => $r['unparsed'], 'mode' => 'basic']);
    }

    /** POST /workouts: a session that happened (or one you add for today). */
    public function store(Request $request): JsonResponse
    {
        $d = $this->validateSession($request, false);
        $date = $this->date($request);
        $id = $this->training->add($request->user(), $date, $d);

        return response()->json(['id' => $id], 201);
    }

    /** POST /workouts/template-session: confirm or skip one of today's planned sessions. */
    public function mark(Request $request): JsonResponse
    {
        $d = $request->validate(['template_id' => ['required', 'integer'], 'action' => ['required', Rule::in(['done', 'skip', 'reset'])], 'local_date' => ['nullable', 'date_format:Y-m-d']]);
        $this->training->mark($request->user(), $this->date($request), (int) $d['template_id'], $d['action']);

        return response()->json(['ok' => true]);
    }

    public function update(Request $request, int $workout): JsonResponse
    {
        $d = $request->validate(['minutes' => ['sometimes', 'integer', 'min:1', 'max:600'], 'intensity' => ['sometimes', 'string', 'max:20'], 'start_time' => ['sometimes', 'nullable', 'date_format:H:i'],
            'kcal_override' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:5000'], 'status' => ['sometimes', Rule::in(['planned', 'done', 'skipped'])], 'rpe' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:10']]);
        $row = $this->training->own($request->user(), $workout);
        if (isset($d['intensity']) && $row->type !== 'custom') {
            abort_unless(isset(ExerciseCalculator::CATALOGUE[$row->type]['intensities'][$d['intensity']]), 422, 'Unknown intensity for that activity.');
        }
        $this->training->update($request->user(), $workout, $d);

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, int $workout): JsonResponse
    {
        $this->training->delete($request->user(), $workout);

        return response()->json(['ok' => true]);
    }

    private function today(Request $request): string
    {
        $p = $request->user()->profile;

        return LocalDay::of(now(), $p->tz, $p->rollover_hour);
    }

    private function date(Request $request): string
    {
        $today = $this->today($request);
        $d = $request->input('local_date') ?: $today;
        abort_unless(is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && $d <= date('Y-m-d', strtotime($today . ' +1 day')) && $d > date('Y-m-d', strtotime($today . ' -400 days')), 422, 'Pick a date within the last year.');

        return $d;
    }

    /** @return array<string,mixed> */
    private function validateSession(Request $request, bool $template): array
    {
        $rules = [
            'type' => ['required', Rule::in([...array_keys(ExerciseCalculator::CATALOGUE), 'custom'])], 'intensity' => ['required', 'string', 'max:20'], 'minutes' => ['required', 'integer', 'min:5', 'max:600'],
            'start_time' => ['nullable', 'date_format:H:i'], 'name' => ['nullable', 'string', 'max:80'], 'custom_met' => ['nullable', 'numeric', 'min:1.5', 'max:20'],
        ] + ($template ? ['day' => ['required', Rule::in(MacroPlanner::DAYS)]] : ['kcal_override' => ['nullable', 'numeric', 'min:0', 'max:5000'], 'rpe' => ['nullable', 'numeric', 'min:1', 'max:10'], 'idempotency_key' => ['nullable', 'uuid']]);
        $d = $request->validate($rules);
        if ($d['type'] === 'custom') {
            abort_if(empty($d['custom_met']), 422, 'Enter the effort level (MET) for a custom activity. Easy walking is about 3.5, hard running about 10.');
            abort_if(trim((string) ($d['name'] ?? '')) === '', 422, 'Give a custom activity a name.');
            $d['intensity'] = 'custom';
        } else {
            abort_unless(isset(ExerciseCalculator::CATALOGUE[$d['type']]['intensities'][$d['intensity']]), 422, 'Unknown intensity for that activity.');
        }

        return $d;
    }

    /** @return array<string,mixed> */
    private function templateRow(int $userId, array $d): array
    {
        return ['user_id' => $userId, 'day' => $d['day'], 'start_time' => $d['start_time'] ?? null, 'type' => $d['type'], 'intensity' => $d['intensity'], 'minutes' => $d['minutes'],
            'custom_met' => $d['type'] === 'custom' ? $d['custom_met'] : null, 'name' => $d['type'] === 'custom' ? trim($d['name']) : null];
    }
}
