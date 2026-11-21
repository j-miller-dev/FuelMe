<?php

namespace App\Services\Coach;

use Illuminate\Support\Facades\Http;

class AnthropicCoachClient implements CoachClient
{
    public function send(string $system, array $messages, array $tools): array
    {
        $res = Http::withHeaders(['x-api-key' => (string) config('fuel.capture.api_key'), 'anthropic-version' => '2023-06-01'])
            ->timeout((int) config('fuel.coach.timeout'))->acceptJson()
            ->post(config('fuel.capture.api_url'), ['model' => config('fuel.coach.model'), 'max_tokens' => 900, 'system' => $system, 'tools' => $tools, 'messages' => $messages])
            ->throw()->json();

        return ['content' => $res['content'] ?? [], 'stop_reason' => (string) ($res['stop_reason'] ?? 'end_turn'),
            'input_tokens' => (int) ($res['usage']['input_tokens'] ?? 0), 'output_tokens' => (int) ($res['usage']['output_tokens'] ?? 0)];
    }
}
