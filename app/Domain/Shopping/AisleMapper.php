<?php
declare(strict_types=1);

namespace App\Domain\Shopping;

/** Which supermarket aisle a food belongs to, from its category text and name. Keyword rules in data/aisle_keywords.json; unknown = Other. Pure. */
final class AisleMapper
{
    /** @param list<array{0:string,1:list<string>}> $rules */
    public function __construct(private array $rules) {}

    public static function fromFile(string $path): self
    {
        return new self(json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['rules']);
    }

    public function aisle(?string $name, ?string $category = null): string
    {
        // The category is the better signal when present (OFF: "en:frozen-foods"), the name the fallback (AFCD has no category).
        foreach ([$category, $name] as $text) {
            $t = ' ' . mb_strtolower(str_replace(['en:', '-'], ['', ' '], (string) $text)) . ' ';
            if (trim($t) === '') {
                continue;
            }
            foreach ($this->rules as [$aisle, $words]) {
                foreach ($words as $w) {
                    if (preg_match('/(?<![a-z])' . preg_quote($w, '/') . '(?![a-z])/u', $t) || (str_ends_with($w, ',') && str_contains($t, $w))) {
                        return $aisle;
                    }
                }
            }
        }

        return 'Other';
    }
}
