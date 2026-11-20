<?php

namespace App\Http\Controllers;

use App\Domain\Foods\LocalDay;
use App\Models\WeightLog;
use App\Services\ProgressService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProgressController extends Controller
{
    public function __construct(private ProgressService $progress) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $request->user();
        if (! $user->profile) {
            return redirect()->route('onboarding');
        }

        return Inertia::render('Progress', ['data' => $this->progress->overview($user), 'today' => LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour)]);
    }

    /** POST /weights: one weigh-in per day; weighing again the same day replaces it. */
    public function weigh(Request $request): JsonResponse
    {
        $user = $request->user();
        $today = LocalDay::of(now(), $user->profile->tz, $user->profile->rollover_hour);
        $d = $request->validate([
            'kg' => ['required', 'numeric', 'min:30', 'max:300'],
            'local_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:' . $today, 'after:' . date('Y-m-d', strtotime($today . ' -400 days'))],
        ], ['kg.min' => 'That weight looks too low. Enter it in kilograms.', 'kg.max' => 'That weight looks too high. Enter it in kilograms.']);

        $date = $d['local_date'] ?? $today;
        $kg = round((float) $d['kg'], 1);
        $prev = WeightLog::where('user_id', $user->id)->where('local_date', '<', $date)->orderByDesc('local_date')->value('kg');
        WeightLog::updateOrCreate(['user_id' => $user->id, 'local_date' => $date], ['kg' => $kg, 'source' => 'manual']);

        // A big jump is usually water or a typo. It is saved (the filter down-weights outliers), but the person is told.
        $note = $prev !== null && abs($kg - (float) $prev) > 3 ? 'That is more than 3 kg from your last weigh-in. If it is a typo, weigh in again to replace it. The trend ignores one-off spikes.' : null;

        return response()->json(['saved' => ['date' => $date, 'kg' => $kg], 'note' => $note], 201);
    }

    public function destroyWeight(Request $request, WeightLog $weight): JsonResponse
    {
        abort_unless($weight->user_id === $request->user()->id, 404);
        abort_if(WeightLog::where('user_id', $request->user()->id)->count() <= 1, 422, 'Keep at least one weigh-in: the trend starts from it.');
        $weight->delete();

        return response()->json(['deleted' => $weight->id]);
    }

    public function applyCheckin(Request $request): JsonResponse
    {
        $d = $request->validate(['kcal' => ['required', 'integer', 'min:800', 'max:8000']]);

        return response()->json($this->progress->applyCheckin($request->user(), (int) $d['kcal']));
    }
}
