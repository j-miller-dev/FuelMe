<?php

namespace App\Services\Coach;

use App\Domain\Coach\PrincipleContext;
use App\Domain\Coach\PrincipleParser;
use App\Domain\Coach\PrincipleRanker;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The user's principle cards (from their Obsidian notes). One-way: notes in, nothing written back. */
class PrincipleService
{
    /** @return list<array<string,mixed>> */
    public function cards(User $user, bool $withArchived = false): array
    {
        $q = DB::table('principles')->where('user_id', $user->id)->orderBy('title');
        if (! $withArchived) {
            $q->where('status', 'active');
        }

        return $q->get()->map(fn ($r) => [
            'id' => (int) $r->id, 'ext_id' => $r->ext_id, 'title' => $r->title, 'body' => $r->body, 'source' => $r->source,
            'tags' => json_decode($r->tags, true) ?: [], 'grade' => $r->grade, 'stance' => $r->stance_override ?: $r->stance,
            'note_stance' => $r->stance, 'overridden' => $r->stance_override !== null, 'status' => $r->status,
        ])->all();
    }

    /**
     * @param list<array{name:string,contents:string}> $files
     * @return array{created:int,updated:int,unchanged:int,archived:int,errors:list<array{file:string,error:string}>,warnings:list<string>}
     */
    public function import(User $user, array $files, bool $fullSync): array
    {
        $out = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'archived' => 0, 'errors' => [], 'warnings' => []];
        $seen = [];
        foreach ($files as $f) {
            $r = PrincipleParser::parse($f['contents'], $f['name']);
            if (! $r['ok']) {
                $out['errors'][] = ['file' => $f['name'], 'error' => $r['error']];

                continue;
            }
            foreach ($r['warnings'] as $w) {
                $out['warnings'][] = $f['name'] . ': ' . $w;
            }
            $c = $r['card'];
            $seen[] = $c['ext_id'];
            $row = DB::table('principles')->where('user_id', $user->id)->where('ext_id', $c['ext_id'])->first();
            $data = ['title' => $c['title'], 'body' => $c['body'], 'source' => $c['source'], 'tags' => json_encode($c['tags']), 'grade' => $c['grade'], 'stance' => $c['stance'], 'hash' => $c['hash'], 'status' => 'active', 'updated_at' => now()];
            if (! $row) {
                DB::table('principles')->insert($data + ['user_id' => $user->id, 'ext_id' => $c['ext_id'], 'created_at' => now()]);
                $out['created']++;
            } elseif ($row->hash === $c['hash'] && $row->status === 'active') {
                $out['unchanged']++;
            } else {
                DB::table('principles')->where('id', $row->id)->update($data);
                $out['updated']++;
            }
        }
        if ($fullSync && $seen) {
            $out['archived'] = DB::table('principles')->where('user_id', $user->id)->where('status', 'active')->whereNotIn('ext_id', $seen)->update(['status' => 'archived', 'updated_at' => now()]);
        }

        return $out;
    }

    public function setStance(User $user, int $id, ?string $stance): bool
    {
        abort_if($stance !== null && ! in_array($stance, PrincipleParser::STANCES, true), 422);

        return DB::table('principles')->where('user_id', $user->id)->where('id', $id)->update(['stance_override' => $stance, 'updated_at' => now()]) > 0;
    }

    public function archive(User $user, int $id): bool
    {
        return DB::table('principles')->where('user_id', $user->id)->where('id', $id)->update(['status' => 'archived', 'updated_at' => now()]) > 0;
    }

    /** @return list<array<string,mixed>> */
    public function search(User $user, string $query, int $limit = 3): array
    {
        return PrincipleRanker::rank($this->cards($user), $query, $limit);
    }

    /** The one card for Today, with the reason it was picked; null when you have none that fit. */
    public function forToday(User $user, string $ymd, bool $readinessHook, bool $sessionToday, bool $underEating): ?array
    {
        $cards = $this->cards($user);
        if (! $cards) {
            return null;
        }
        $ctx = PrincipleContext::pick($readinessHook, $sessionToday, $underEating);
        $c = PrincipleContext::choose($cards, $ctx, $ymd);

        return $c ? ['id' => $c['id'], 'title' => $c['title'], 'body' => $c['body'], 'source' => $c['source'], 'grade' => $c['grade'], 'reason' => $ctx['reason'] ?? 'From your notes'] : null;
    }
}
