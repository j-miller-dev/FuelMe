<?php

namespace App\Services\Intervals;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Read-only client for the Intervals.icu API (HTTP Basic: user "API_KEY", password = your key). FUEL never writes anything to Intervals.icu. */
class IntervalsClient
{
    public function __construct(private string $key, private string $athlete = '0') {}

    /** @return array<string,mixed> */
    public function athlete(): array
    {
        return $this->get("/api/v1/athlete/{$this->athlete}");
    }

    /** @return list<array<string,mixed>> */
    public function wellness(string $oldest, string $newest): array
    {
        return $this->get("/api/v1/athlete/{$this->athlete}/wellness", ['oldest' => $oldest, 'newest' => $newest]);
    }

    /** @return list<array<string,mixed>> */
    public function activities(string $oldest, string $newest): array
    {
        return $this->get("/api/v1/athlete/{$this->athlete}/activities", ['oldest' => $oldest, 'newest' => $newest]);
    }

    /** @return array<mixed> */
    private function get(string $path, array $query = []): array
    {
        try {
            $res = Http::baseUrl((string) config('fuel.intervals.base_url'))->withBasicAuth('API_KEY', $this->key)->acceptJson()->timeout((int) config('fuel.intervals.timeout'))
                ->retry(2, 800, fn ($e) => $e instanceof ConnectionException || ($e instanceof RequestException && in_array($e->response->status(), [429, 500, 502, 503, 504], true)), throw: false)->get($path, $query);
        } catch (ConnectionException) {
            throw new IntervalsException('Could not reach Intervals.icu.');
        }

        return $this->body($res);
    }

    /** @return array<mixed> */
    private function body(Response $res): array
    {
        if (in_array($res->status(), [401, 403], true)) {
            throw new IntervalsException('Intervals.icu did not accept that API key.');
        }
        if (! $res->successful()) {
            throw new IntervalsException('Intervals.icu returned an error (' . $res->status() . ').');
        }
        $j = $res->json();

        return is_array($j) ? $j : [];
    }
}
