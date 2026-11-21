<?php

namespace App\Services;

use App\Domain\Progress\WeeklyReview;
use Illuminate\Support\Facades\Http;

/**
 * Optional: a small model rewrites the template note in a warmer voice. It gets the review JSON as DATA and may not add numbers:
 * the result is dropped (template kept) if it is over 150 words, empty, or uses any number that is not in the JSON.
 */
class ReviewWriter
{
    public function enabled(): bool
    {
        return (string) config('fuel.capture.api_key') !== '' && config('fuel.review.use_model');
    }

    /** @return ?string the rewritten note, or null to keep the template */
    public function rewrite(array $review, string $template): ?string
    {
        if (! $this->enabled()) {
            return null;
        }
        try {
            $res = Http::withHeaders(['x-api-key' => (string) config('fuel.capture.api_key'), 'anthropic-version' => '2023-06-01'])
                ->timeout((int) config('fuel.capture.timeout') + 6)->acceptJson()
                ->post(config('fuel.capture.api_url'), [
                    'model' => config('fuel.capture.model'),
                    'max_tokens' => 400,
                    'system' => 'You write a short weekly nutrition review for one person. Use ONLY the numbers in the JSON, never calculate or invent any. At most 140 words: what happened, what it means, one or two suggested actions as options. Plain, neutral, kind, no medical advice, no shaming. Output the note text only.',
                    'messages' => [['role' => 'user', 'content' => "<review_json>\n" . json_encode($review) . "\n</review_json>\n<draft>\n" . $template . "\n</draft>"]],
                ])->throw()->json();
            $text = trim((string) collect($res['content'] ?? [])->where('type', 'text')->pluck('text')->implode(' '));
        } catch (\Throwable) {
            return null;
        }

        return $text !== '' && str_word_count($text) <= 150 && WeeklyReview::groundedNumbers($text, $review) ? $text : null;
    }
}
