<?php

namespace App\Http\Controllers;

use App\Services\ExportService;
use App\Services\ImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Settings > Your data: download everything, restore a backup, bring history in from a spreadsheet. */
class DataController extends Controller
{
    public function show(Request $request): Response
    {
        $u = $request->user();

        return Inertia::render('settings/Data', ['counts' => [
            'foodLogs' => DB::table('food_logs')->where('user_id', $u->id)->count(), 'weights' => DB::table('weight_logs')->where('user_id', $u->id)->count(),
            'workouts' => DB::table('workout_logs')->where('user_id', $u->id)->count(),
        ], 'max' => ImportService::MAX_ROWS]);
    }

    public function backup(Request $request, ExportService $export): StreamedResponse
    {
        $data = $export->backup($request->user());
        $name = 'fuel-backup-' . now()->format('Y-m-d') . '.json';

        return response()->streamDownload(function () use ($data) {
            echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        }, $name, ['Content-Type' => 'application/json']);
    }

    public function csv(Request $request, ExportService $export, string $what): StreamedResponse
    {
        abort_unless(in_array($what, ['food-logs', 'weights', 'workouts'], true), 404);
        $rows = match ($what) { 'food-logs' => $export->foodLogsCsv($request->user()), 'weights' => $export->weightsCsv($request->user()), 'workouts' => $export->workoutsCsv($request->user()) };

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // so Excel opens it as UTF-8
            foreach ($rows as $row) {
                fputcsv($out, array_map([ExportService::class, 'safeCell'], $row), ',', '"', '\\');
            }
            fclose($out);
        }, "fuel-{$what}-" . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** POST /import/rows {kind, rows}: at most 1000 rows a request; the page sends big files in chunks. */
    public function rows(Request $request, ImportService $import): JsonResponse
    {
        $d = $request->validate(['kind' => ['required', 'in:' . implode(',', ImportService::KINDS)], 'rows' => ['required', 'array', 'min:1', 'max:' . ImportService::MAX_ROWS], 'rows.*' => ['array']]);

        return response()->json($import->run($request->user(), $d['kind'], $d['rows']));
    }
}
