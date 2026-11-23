<?php

namespace App\Http\Controllers;

use App\Domain\Shopping\ShoppingStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Recipes -> shopping list (BLUEPRINT 5.6). Everything is scoped to the signed-in user. */
class ShoppingController extends Controller
{
    private const UNITS = ['g', 'ml', 'count'];

    public function __construct(private ShoppingStore $store) {}

    public function index(Request $request): Response
    {
        $lists = DB::table('shopping_lists')->where('user_id', $request->user()->id)->where('status', 'active')->orderByDesc('updated_at')->get()
            ->map(function ($l) {
                $c = DB::table('shopping_list_items')->where('list_id', $l->id)->selectRaw("count(*) as total, sum(case when state = 'bought' then 1 else 0 end) as bought, sum(case when state = 'have' then 1 else 0 end) as have")->first();

                return ['id' => (int) $l->id, 'name' => $l->name, 'total' => (int) $c->total - (int) $c->have, 'bought' => (int) $c->bought, 'updated_at' => $l->updated_at];
            })->all();

        return Inertia::render('Shopping/Index', ['lists' => $lists]);
    }

    public function show(Request $request, int $list): Response
    {
        return Inertia::render('Shopping/Show', ['list' => $this->store->payload($request->user()->id, $list), 'todoist' => DB::table('todoist_connections')->where('user_id', $request->user()->id)->exists()]);
    }

    public function store(Request $request): JsonResponse
    {
        $d = $request->validate(['name' => ['nullable', 'string', 'max:120'], 'recipes' => ['required', 'array', 'min:1', 'max:30'],
            'recipes.*.meal_id' => ['required', 'integer', 'distinct'], 'recipes.*.servings' => ['required', 'numeric', 'min:0.5', 'max:200']]);
        $id = $this->store->create($request->user()->id, trim($d['name'] ?? '') ?: 'Shopping ' . now()->format('j M'), $d['recipes']);

        return response()->json(['id' => $id], 201);
    }

    public function recipes(Request $request, int $list): JsonResponse
    {
        $this->store->own($request->user()->id, $list);
        $d = $request->validate(['recipes' => ['present', 'array', 'max:30'], 'recipes.*.meal_id' => ['required', 'integer', 'distinct'], 'recipes.*.servings' => ['required', 'numeric', 'min:0.5', 'max:200']]);
        $this->store->setRecipes($request->user()->id, $list, $d['recipes']);

        return response()->json(['list' => $this->store->payload($request->user()->id, $list)]);
    }

    /** POST /shopping/{list}/refresh: the explicit "Recipes changed, refresh list" confirmation. */
    public function refresh(Request $request, int $list): JsonResponse
    {
        $this->store->own($request->user()->id, $list);
        $this->store->regenerate($request->user()->id, $list);

        return response()->json(['list' => $this->store->payload($request->user()->id, $list)]);
    }

    public function addItem(Request $request, int $list): JsonResponse
    {
        $this->store->own($request->user()->id, $list);
        $d = $request->validate($this->addRules());
        $this->store->addManual($request->user()->id, $list, $d['label'], $d['qty'] ?? null, $d['unit'] ?? null, $d['aisle'] ?? null, $d['uuid'] ?? null);

        return response()->json(['list' => $this->store->payload($request->user()->id, $list)], 201);
    }

    public function updateItem(Request $request, int $item): JsonResponse
    {
        $row = DB::table('shopping_list_items as i')->join('shopping_lists as l', 'l.id', '=', 'i.list_id')->where('i.id', $item)->where('l.user_id', $request->user()->id)->first(['i.*']);
        abort_unless($row, 404);
        $d = $request->validate(['state' => ['sometimes', 'in:need,have,bought'], 'is_staple' => ['sometimes', 'boolean'], 'have_qty' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:1000000'],
            'pack_size' => ['sometimes', 'nullable', 'numeric', 'min:1', 'max:1000000']]);

        if (isset($d['state'])) {
            $this->store->setState((int) $row->list_id, $item, $d['state']);
        }
        if (array_key_exists('pack_size', $d)) {
            DB::table('shopping_list_items')->where('id', $item)->update(['pack_size' => $d['pack_size']]);
        }
        if (isset($d['is_staple']) || array_key_exists('have_qty', $d)) {
            $this->store->setPantry($request->user()->id, DB::table('shopping_list_items')->where('id', $item)->first(), $d['is_staple'] ?? null, $d['have_qty'] ?? null, array_key_exists('have_qty', $d));
        }

        return response()->json(['list' => $this->store->payload($request->user()->id, (int) $row->list_id)]);
    }

    /** Only manual items can be deleted; a recipe's items go when the recipe is taken off the list. */
    public function destroyItem(Request $request, int $item): JsonResponse
    {
        $row = DB::table('shopping_list_items as i')->join('shopping_lists as l', 'l.id', '=', 'i.list_id')->where('i.id', $item)->where('l.user_id', $request->user()->id)->first(['i.*']);
        abort_unless($row, 404);
        abort_unless($row->is_manual, 422, 'Take the recipe off the list to remove its ingredients, or move the item to Have.');
        DB::table('shopping_list_items')->where('id', $item)->delete();

        return response()->json(['list' => $this->store->payload($request->user()->id, (int) $row->list_id)]);
    }

    /**
     * POST /shopping/{list}/sync: replay what was done while offline, in order. Every op is idempotent (set a state, add with a
     * client uuid, delete a manual item), so a retry after a dropped connection can never duplicate or undo anything.
     */
    public function sync(Request $request, int $list): JsonResponse
    {
        $this->store->own($request->user()->id, $list);
        $d = $request->validate(['ops' => ['required', 'array', 'max:500'], 'ops.*.op' => ['required', 'in:state,add,remove'], 'ops.*.item_id' => ['nullable', 'integer'],
            'ops.*.state' => ['nullable', 'in:need,have,bought'], 'ops.*.uuid' => ['nullable', 'uuid'], 'ops.*.label' => ['nullable', 'string', 'max:200'],
            'ops.*.qty' => ['nullable', 'numeric', 'min:0'], 'ops.*.unit' => ['nullable', 'in:g,ml,count'], 'ops.*.aisle' => ['nullable', 'string', 'max:40']]);

        DB::transaction(function () use ($d, $request, $list) {
            foreach ($d['ops'] as $op) {
                // An item added offline has no server id yet: later ops on it carry its client uuid instead.
                if (empty($op['item_id']) && ! empty($op['uuid']) && $op['op'] !== 'add') {
                    $op['item_id'] = DB::table('shopping_list_items')->where('list_id', $list)->where('client_uuid', $op['uuid'])->value('id');
                }
                match ($op['op']) {
                    'state' => isset($op['item_id'], $op['state']) ? $this->store->setState($list, (int) $op['item_id'], $op['state']) : null,
                    'add' => ! empty(trim($op['label'] ?? '')) ? $this->store->addManual($request->user()->id, $list, $op['label'], $op['qty'] ?? null, $op['unit'] ?? null, $op['aisle'] ?? null, $op['uuid'] ?? null) : null,
                    'remove' => isset($op['item_id']) ? DB::table('shopping_list_items')->where('id', $op['item_id'])->where('list_id', $list)->where('is_manual', true)->delete() : null,
                };
            }
        });

        return response()->json(['list' => $this->store->payload($request->user()->id, $list)]);
    }

    public function done(Request $request, int $list): JsonResponse
    {
        $this->store->own($request->user()->id, $list);
        DB::table('shopping_lists')->where('id', $list)->update(['status' => 'done', 'updated_at' => now()]);

        return response()->json(['done' => $list]);
    }

    /** @return array<string,mixed> */
    private function addRules(): array
    {
        return ['label' => ['required', 'string', 'max:200'], 'qty' => ['nullable', 'numeric', 'min:0', 'max:1000000'], 'unit' => ['nullable', 'in:' . implode(',', self::UNITS)],
            'aisle' => ['nullable', 'string', 'max:40'], 'uuid' => ['nullable', 'uuid']];
    }
}
