<?php

namespace App\Domain\Coach;

/**
 * One Obsidian permanent note -> one principle card. Pure PHP.
 * Cards are meant to be SHORT and in the reader's own words: a note over MAX_CHARS is rejected, not truncated, so book passages
 * cannot be pasted in by accident. Frontmatter: id, source, tags, grade (strong|rule_of_thumb|opinion), stance (follow|context|ignore).
 */
final class PrincipleParser
{
    public const MAX_CHARS = 1200;

    public const GRADES = ['strong', 'rule_of_thumb', 'opinion'];

    public const STANCES = ['follow', 'context', 'ignore'];

    /** @return array{ok:bool,error?:string,card?:array{ext_id:string,title:string,body:string,source:?string,tags:list<string>,grade:string,stance:string,hash:string},warnings?:list<string>} */
    public static function parse(string $markdown, string $filename): array
    {
        $md = str_replace(["\r\n", "\r"], "\n", $markdown);
        [$front, $body] = self::frontmatter($md);
        $warnings = [];

        $title = null;
        if (preg_match('/^#\s+(.+)$/m', $body, $m)) {
            $title = trim($m[1]);
            $body = preg_replace('/^#\s+.+\n?/m', '', $body, 1) ?? $body;
        }
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $title = $title !== null && $title !== '' ? $title : trim(str_replace(['-', '_'], ' ', $base));
        $body = trim(preg_replace('/\n{3,}/', "\n\n", self::stripLinks($body)) ?? $body);
        if ($body === '') {
            return ['ok' => false, 'error' => 'The note is empty.'];
        }
        if (mb_strlen($body) > self::MAX_CHARS) {
            return ['ok' => false, 'error' => 'Too long for a card (' . mb_strlen($body) . ' characters, limit ' . self::MAX_CHARS . '). Write the idea in your own words, one principle per note.'];
        }

        $id = self::slug((string) ($front['id'] ?? '')) ?: self::slug($base);
        if ($id === '') {
            return ['ok' => false, 'error' => 'The note needs an id or a file name.'];
        }
        $grade = strtolower(str_replace([' ', '-'], '_', (string) ($front['grade'] ?? '')));
        if (! in_array($grade, self::GRADES, true)) {
            if (($front['grade'] ?? '') !== '') {
                $warnings[] = 'Unknown grade "' . $front['grade'] . '", treated as opinion.';
            }
            $grade = 'opinion';
        }
        $stance = strtolower((string) ($front['stance'] ?? ''));
        if (! in_array($stance, self::STANCES, true)) {
            if (($front['stance'] ?? '') !== '') {
                $warnings[] = 'Unknown stance "' . $front['stance'] . '", treated as context.';
            }
            $stance = 'context';   // a note only becomes a rule when you say so
        }
        $tags = array_values(array_unique(array_filter(array_map(fn ($t) => strtolower(trim(ltrim(trim($t), '#'))), explode(',', (string) ($front['tags'] ?? ''))))));
        $source = trim((string) ($front['source'] ?? ''));

        return ['ok' => true, 'warnings' => $warnings, 'card' => [
            'ext_id' => $id, 'title' => mb_substr($title, 0, 160), 'body' => $body, 'source' => $source !== '' ? mb_substr($source, 0, 160) : null,
            'tags' => $tags, 'grade' => $grade, 'stance' => $stance, 'hash' => sha1($title . "\n" . $body . "\n" . $source . "\n" . implode(',', $tags) . "\n" . $grade . "\n" . $stance),
        ]];
    }

    public static function slug(string $s): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
    }

    /** [[Note|alias]] -> alias, [[Note]] -> Note. */
    private static function stripLinks(string $s): string
    {
        return (string) preg_replace_callback('/\[\[([^\]|]+)(?:\|([^\]]+))?\]\]/', fn ($m) => ($m[2] ?? '') !== '' ? $m[2] : $m[1], $s);
    }

    /** @return array{0:array<string,string>,1:string} */
    private static function frontmatter(string $md): array
    {
        if (! preg_match('/\A---\n(.*?)\n---\s*(?:\n|\z)/s', $md, $m)) {
            return [[], $md];
        }
        $front = [];
        $key = null;
        foreach (explode("\n", $m[1]) as $l) {
            if (preg_match('/^([A-Za-z_][\w -]*):\s*(.*)$/', $l, $kv)) {
                $key = strtolower(trim($kv[1]));
                $v = trim($kv[2], " \t\"'");
                $front[$key] = trim(trim($v, '[]'), " \t\"'");
            } elseif ($key !== null && preg_match('/^\s*-\s+(.+)$/', $l, $li)) {
                $front[$key] = trim(($front[$key] ?? '') . ',' . trim($li[1], " \t\"'"), ',');
            }
        }

        return [$front, substr($md, strlen($m[0]))];
    }
}
