<?php

namespace App\Services\Coach;

/** The model behind the coach. An interface so tests (and the eval harness) can script it without a network. */
interface CoachClient
{
    /**
     * @param list<array<string,mixed>> $messages Anthropic-style messages
     * @param list<array<string,mixed>> $tools
     * @return array{content:list<array<string,mixed>>,stop_reason:string,input_tokens:int,output_tokens:int}
     * @throws \Throwable on any API problem
     */
    public function send(string $system, array $messages, array $tools): array;
}
