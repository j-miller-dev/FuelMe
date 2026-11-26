<?php

namespace App\Services\Hevy;

use App\Domain\Training\RoutineFolders;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Which routine folders show up in FUEL (display only; every folder is still synced and nothing is deleted). */
class RoutineFoldersService
{
    /** @return array{folders:list<array{key:string,title:string,routines:int,shown:bool}>,shown:list<string>,auto:bool,hidden:int} */
    public function state(User $user): array
    {
        $folders = [];
        foreach (DB::table('hevy_routines')->where('user_id', $user->id)->get(['folder_id', 'folder_title']) as $r) {
            $k = $r->folder_id ?? 'none';
            $folders[$k] ??= ['key' => (string) $k, 'title' => $r->folder_title ?: 'No folder', 'routines' => 0];
            $folders[$k]['routines']++;
        }
        $list = array_values($folders);
        usort($list, fn ($a, $b) => [$a['key'] === 'none', mb_strtolower($a['title'])] <=> [$b['key'] === 'none', mb_strtolower($b['title'])]);
        $raw = DB::table('hevy_connections')->where('user_id', $user->id)->value('shown_folders');
        $chosen = $raw ? (json_decode((string) $raw, true) ?: null) : null;
        $v = RoutineFolders::visible($list, is_array($chosen) ? array_map('strval', $chosen) : null);
        $out = array_map(fn ($f) => $f + ['shown' => in_array($f['key'], $v['shown'], true)], $list);

        return ['folders' => $out, 'shown' => $v['shown'], 'auto' => $v['auto'], 'hidden' => count(array_filter($out, fn ($f) => ! $f['shown']))];
    }

    /** @return list<string> keys of the shown folders (a routine with no folder has key "none") */
    public function shownKeys(User $user): array
    {
        return $this->state($user)['shown'];
    }

    /** @param list<string> $keys */
    public function save(User $user, array $keys): void
    {
        DB::table('hevy_connections')->where('user_id', $user->id)->update(['shown_folders' => json_encode(array_values(array_unique(array_map('strval', $keys)))), 'updated_at' => now()]);
    }

    public function reset(User $user): void
    {
        DB::table('hevy_connections')->where('user_id', $user->id)->update(['shown_folders' => null, 'updated_at' => now()]);
    }
}
