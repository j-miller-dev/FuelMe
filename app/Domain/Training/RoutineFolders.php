<?php
declare(strict_types=1);

namespace App\Domain\Training;

/**
 * Which of your Hevy routine folders FUEL shows. Pure PHP. Automatic = the current program's folders ("Rational Intensity P1: ...", "P2: ...",
 * "P3: ..."), so old programs stay out of the way. You can pick your own set instead. If the automatic rule matches nothing (a different
 * program, or no folders named like that) everything is shown rather than an empty page. Hiding never deletes anything in Hevy or in FUEL.
 */
final class RoutineFolders
{
    public const AUTO_PATTERN = '/^\s*Rational\s+Intensity\s+P\d/i';

    /**
     * @param list<array{key:string,title:string}> $folders
     * @param list<string>|null $chosen folder keys you picked, or null for automatic
     * @return array{shown:list<string>,auto:bool}
     */
    public static function visible(array $folders, ?array $chosen): array
    {
        $all = array_column($folders, 'key');
        if ($chosen !== null) {
            $shown = array_values(array_filter($all, fn ($k) => in_array($k, $chosen, true)));
            if ($shown) {
                return ['shown' => $shown, 'auto' => false];
            }
        }
        $auto = array_values(array_map(fn ($f) => $f['key'], array_filter($folders, fn ($f) => preg_match(self::AUTO_PATTERN, $f['title']) === 1)));

        return ['shown' => $auto ?: $all, 'auto' => true];
    }
}
