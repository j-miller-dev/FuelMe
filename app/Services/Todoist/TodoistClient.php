<?php

namespace App\Services\Todoist;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Minimal Todoist API v1 client (Bearer token): check the token, make a project, add a task. It only ever ADDS; FUEL never edits or deletes anything in Todoist. */
class TodoistClient
{
    public function __construct(private string $token) {}

    public function check(): void
    {
        $this->call('get', '/api/v1/projects', ['limit' => 1]);
    }

    public function createProject(string $name): string
    {
        return (string) ($this->call('post', '/api/v1/projects', ['name' => $name])['id'] ?? throw new TodoistException('Todoist did not return a project.'));
    }

    public function createTask(string $content, string $description, string $projectId): string
    {
        return (string) ($this->call('post', '/api/v1/tasks', ['content' => $content, 'description' => $description, 'project_id' => $projectId])['id'] ?? throw new TodoistException('Todoist did not return a task.'));
    }

    /** @return array<string,mixed> */
    private function call(string $method, string $path, array $data): array
    {
        try {
            $req = Http::baseUrl((string) config('fuel.todoist.base_url'))->withToken($this->token)->acceptJson()->timeout((int) config('fuel.todoist.timeout'));
            $res = $method === 'get' ? $req->get($path, $data) : $req->asJson()->post($path, $data);
        } catch (ConnectionException) {
            throw new TodoistException('Could not reach Todoist.');
        }

        return $this->body($res);
    }

    /** @return array<string,mixed> */
    private function body(Response $res): array
    {
        if (in_array($res->status(), [401, 403], true)) {
            throw new TodoistException('Todoist did not accept that token.', 401);
        }
        if ($res->status() === 404) {
            throw new TodoistException('Todoist could not find that project.', 404);
        }
        if (! $res->successful()) {
            throw new TodoistException('Todoist returned an error (' . $res->status() . ').', $res->status());
        }
        $j = $res->json();

        return is_array($j) ? $j : [];
    }
}
