<?php

namespace App\Services;

use App\Domain\Recipes\MarkdownRecipeParser;
use App\Models\User;
use App\Services\Capture\CaptureService;

/**
 * Recipe notes -> a review payload. Each ingredient line is matched to the food database with the same matcher as the Say tab, and the
 * person confirms every match (which chicken, which store) before anything is saved. Nothing here writes to the database.
 */
class RecipeImportService
{
    public const MAX_LINES_PER_REQUEST = 300;
    private const STORES = ['woolworths', 'woolies', 'coles', 'aldi', 'iga', 'costco'];

    public function __construct(private CaptureService $capture) {}

    /**
     * @param list<array{name:string,contents:string}> $files
     * @return array{recipes:list<array<string,mixed>>,skipped:int}
     */
    public function preview(User $user, array $files): array
    {
        $budget = self::MAX_LINES_PER_REQUEST;
        $recipes = [];
        $skipped = 0;
        foreach ($files as $f) {
            $parsed = MarkdownRecipeParser::parse($f['contents'], $this->titleFromName($f['name']));
            $rows = [];
            foreach ($parsed['ingredients'] as $i => $ing) {
                if ($budget <= 0) {
                    $skipped++;
                    continue;
                }
                $budget--;
                [$query, $note] = $this->split($ing['line']);
                $rows[] = [
                    'key' => $i, 'raw' => $ing['line'], 'group' => $ing['group'], 'note' => $note,
                    'match' => $query === '' ? null : $this->capture->ingredient($user, $query, 'r' . $i),
                ];
            }
            $recipes[] = ['file' => $f['name'], 'title' => $parsed['title'], 'servings' => $parsed['servings'], 'tags' => $this->mealTags($parsed['tags']),
                'warnings' => $parsed['warnings'], 'ingredients' => $rows];
        }

        return ['recipes' => $recipes, 'skipped' => $skipped];
    }

    /**
     * "3 cloves garlic, crushed" -> ["3 cloves garlic", "crushed"]; "chicken breast (Woolworths, skinless)" -> ["woolworths chicken breast", "skinless"].
     * A store named in brackets moves in front so the matcher treats it as the store; any other bracket text is a note.
     *
     * @return array{0:string,1:?string}
     */
    private function split(string $line): array
    {
        $notes = [];
        $store = null;
        $line = preg_replace_callback('/\(([^)]*)\)/u', function ($m) use (&$notes, &$store) {
            foreach (preg_split('/\s*[,;]\s*/u', $m[1]) ?: [] as $bit) {
                if (in_array(mb_strtolower(trim($bit)), self::STORES, true)) {
                    $store = mb_strtolower(trim($bit));
                } elseif (trim($bit) !== '') {
                    $notes[] = trim($bit);
                }
            }

            return '';
        }, $line) ?? $line;

        $parts = explode(',', $line, 2);
        $query = trim($parts[0]);
        if (isset($parts[1]) && trim($parts[1]) !== '') {
            $notes[] = trim($parts[1]);
        }
        if ($store !== null && $query !== '') {
            $query = $store . ' ' . $query;
        }

        return [trim(preg_replace('/\s+/u', ' ', $query) ?? $query), $notes ? implode('; ', $notes) : null];
    }

    private function titleFromName(string $name): string
    {
        $base = preg_replace('/\.(md|markdown|txt)$/i', '', basename($name)) ?? $name;

        return trim(str_replace(['_', '-'], ' ', $base)) ?: 'Untitled recipe';
    }

    /** Only the tags the meals feature knows; the rest of a note's tags are Obsidian's business. @param list<string> $tags @return list<string> */
    private function mealTags(array $tags): array
    {
        $map = ['meal-prep' => 'prep', 'mealprep' => 'prep', 'prep' => 'prep', 'breakfast' => 'breakfast', 'snack' => 'snack', 'pre' => 'pre', 'post' => 'post', 'pre-workout' => 'pre', 'post-workout' => 'post'];

        return array_values(array_unique(array_filter(array_map(fn ($t) => $map[$t] ?? null, $tags))));
    }
}
