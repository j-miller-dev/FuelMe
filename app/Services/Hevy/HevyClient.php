<?php

namespace App\Services\Hevy;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Read-only client for the Hevy public API (needs a Hevy Pro key, sent in the `api-key` header). FUEL never creates or edits anything in Hevy. */
class HevyClient
{
    public function __construct(private string $key) {}

    /** @return array<string,mixed> */
    public function userInfo(): array
    {
        return $this->get('/v1/user/info');
    }

    /** @return array<string,mixed> {page, page_count, workouts[]} */
    public function workouts(int $page): array
    {
        return $this->get('/v1/workouts', ['page' => $page, 'pageSize' => 10]);
    }

    /** @return array<string,mixed> {page, page_count, events[]} workouts updated or deleted since a moment */
    public function events(string $sinceIso, int $page): array
    {
        return $this->get('/v1/workouts/events', ['since' => $sinceIso, 'page' => $page, 'pageSize' => 10]);
    }

    /** @return array<string,mixed> {page, page_count, exercise_templates[]} */
    public function templates(int $page): array
    {
        return $this->get('/v1/exercise_templates', ['page' => $page, 'pageSize' => 100]);
    }

    /** @return array<string,mixed> */
    private function get(string $path, array $query = []): array
    {
        try {
            $res = Http::baseUrl((string) config('fuel.hevy.base_url'))->withHeaders(['api-key' => $this->key])->acceptJson()->timeout((int) config('fuel.hevy.timeout'))
                ->retry(2, 800, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)), throw: false)->get($path, $query);
        } catch (ConnectionException) {
            throw new HevyException('Could not reach Hevy.');
        }

        return $this->body($res);
    }

    /** @return array<string,mixed> */
    private function body(Response $res): array
    {
        if (in_array($res->status(), [401, 403], true)) {
            throw new HevyException('Hevy did not accept that API key. It needs a Hevy Pro account.', $res->status());
        }
        if (! $res->successful()) {
            throw new HevyException('Hevy returned an error (' . $res->status() . ').', $res->status());
        }
        $j = $res->json();

        return is_array($j) ? $j : [];
    }
}
