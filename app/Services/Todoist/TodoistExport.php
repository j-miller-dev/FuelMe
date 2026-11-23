<?php

namespace App\Services\Todoist;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Sends the items you still NEED on a shopping list to Todoist as tasks, grouped under one "FUEL shopping" project. Items already sent are skipped
 * (so a second tap adds only what is new), and nothing is ever changed or removed in Todoist. At most MAX_ITEMS go in one tap.
 */
class TodoistExport
{
    public const MAX_ITEMS = 100;
    public const PROJECT = 'FUEL shopping';

    public static function qtyText(?float $qty, ?string $unit): string
    {
        if ($qty === null || $qty <= 0) {
            return '';
        }

        return match ($unit) {
            'g' => $qty >= 1000 ? rtrim(rtrim(number_format($qty / 1000, 2, '.', ''), '0'), '.') . ' kg' : (int) round($qty) . ' g',
            'ml' => $qty >= 1000 ? rtrim(rtrim(number_format($qty / 1000, 2, '.', ''), '0'), '.') . ' L' : (int) round($qty) . ' ml',
            default => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.'),
        };
    }

    /** @return array{sent:int,skipped:int,error:?string} */
    public function export(User $user, int $listId, ?TodoistClient $client = null): array
    {
        $conn = DB::table('todoist_connections')->where('user_id', $user->id)->first();
        if (! $conn) {
            return ['sent' => 0, 'skipped' => 0, 'error' => 'Todoist is not connected.'];
        }
        $client ??= new TodoistClient(Crypt::decryptString($conn->api_token));
        $already = DB::table('todoist_exports')->where('user_id', $user->id)->pluck('list_item_id')->flip();
        $items = DB::table('shopping_list_items')->where('list_id', $listId)->where('state', 'need')->orderBy('aisle')->orderBy('label')->get();
        $todo = $items->reject(fn ($i) => $already->has($i->id))->take(self::MAX_ITEMS);
        $sent = 0;
        $projectId = $conn->project_id;

        try {
            if ($todo->isNotEmpty() && ! $projectId) {
                $projectId = $this->newProject($user, $client);
            }
            foreach ($todo as $i) {
                $need = $i->qty !== null ? max(0.0, (float) $i->qty - (float) ($i->have_qty ?? 0)) : null;
                if ($need !== null && $need <= 0) {
                    continue;   // the pantry covers it
                }
                $qty = self::qtyText($need, $i->unit);
                $recipes = implode(', ', json_decode($i->source_meals, true) ?: []);
                $desc = trim('Aisle: ' . $i->aisle . ($recipes !== '' ? ' · For: ' . $recipes : ''));
                try {
                    $task = $client->createTask(trim($i->label . ($qty !== '' ? ', ' . $qty : '')), $desc, $projectId);
                } catch (TodoistException $e) {
                    if ($e->getCode() !== 404) {
                        throw $e;
                    }
                    $projectId = $this->newProject($user, $client);   // the project was deleted in Todoist: make a fresh one
                    $task = $client->createTask(trim($i->label . ($qty !== '' ? ', ' . $qty : '')), $desc, $projectId);
                }
                DB::table('todoist_exports')->insert(['user_id' => $user->id, 'list_item_id' => $i->id, 'task_id' => $task, 'created_at' => now(), 'updated_at' => now()]);
                $sent++;
            }
        } catch (TodoistException $e) {
            return ['sent' => $sent, 'skipped' => $items->count() - $todo->count(), 'error' => $e->getMessage()];
        }

        return ['sent' => $sent, 'skipped' => $items->count() - $todo->count(), 'error' => null];
    }

    private function newProject(User $user, TodoistClient $client): string
    {
        $id = $client->createProject(self::PROJECT);
        DB::table('todoist_connections')->where('user_id', $user->id)->update(['project_id' => $id, 'updated_at' => now()]);

        return $id;
    }
}
