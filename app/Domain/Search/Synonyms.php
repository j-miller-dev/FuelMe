<?php
declare(strict_types=1);

namespace App\Domain\Search;

/** Expands a query with AU synonyms; data lives in data/synonyms.json so the user can extend it. */
final class Synonyms
{
    /** @param array<string,list<string>> $map */
    public function __construct(private array $map) {}

    public static function fromFile(string $path): self
    {
        return new self(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR));
    }

    /** @return list<string> the original term first, then alternatives (deduped) */
    public function expand(string $term): array
    {
        $term = mb_strtolower(trim($term));
        $out = [$term];
        foreach ($this->map as $key => $alts) {
            $group = array_merge([$key], $alts);
            foreach ($group as $g) {
                if (preg_match('/\b' . preg_quote($g, '/') . '\b/u', $term)) {
                    foreach ($group as $other) {
                        $out[] = trim(preg_replace('/\b' . preg_quote($g, '/') . '\b/u', $other, $term));
                    }
                    break;
                }
            }
        }
        return array_values(array_unique($out));
    }
}
