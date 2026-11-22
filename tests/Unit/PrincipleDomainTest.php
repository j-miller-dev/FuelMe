<?php

use App\Domain\Coach\PrincipleContext;
use App\Domain\Coach\PrincipleParser;
use App\Domain\Coach\PrincipleRanker;

function pnote(string $front = '', string $body = "# Stop at true failure\nOne hard set taken to failure beats several easy ones."): string
{
    return ($front !== '' ? "---\n{$front}\n---\n" : '') . $body;
}

it('parses a note into a card with defaults that never promote it to a rule', function () {
    $r = PrincipleParser::parse(pnote(), 'stop-at-failure.md');
    expect($r['ok'])->toBeTrue()->and($r['card']['title'])->toBe('Stop at true failure')->and($r['card']['ext_id'])->toBe('stop-at-failure')
        ->and($r['card']['stance'])->toBe('context')->and($r['card']['grade'])->toBe('opinion')->and($r['card']['body'])->not->toContain('# ');
});

it('reads frontmatter lists, wikilinks and warns on unknown grade or stance', function () {
    $r = PrincipleParser::parse(pnote("id: HIT-01\nsource: Beyond Brawn\ntags:\n  - Intensity\n  - \"#recovery\"\ngrade: strong\nstance: follow", "# T\nSee [[Rest|rest days]] and [[Sleep]]."), 'x.md');
    expect($r['card'])->toMatchArray(['ext_id' => 'hit-01', 'source' => 'Beyond Brawn', 'tags' => ['intensity', 'recovery'], 'grade' => 'strong', 'stance' => 'follow'])
        ->and($r['card']['body'])->toBe('See rest days and Sleep.');
    $w = PrincipleParser::parse(pnote("grade: gospel\nstance: worship"), 'y.md');
    expect($w['card']['grade'])->toBe('opinion')->and($w['card']['stance'])->toBe('context')->and($w['warnings'])->toHaveCount(2);
});

it('rejects an empty note and anything too long for a card', function () {
    expect(PrincipleParser::parse("---\nid: a\n---\n# Only a title\n", 'a.md')['ok'])->toBeFalse();
    $long = PrincipleParser::parse(pnote('', '# Chapter' . "\n" . str_repeat('word ', 400)), 'c.md');
    expect($long['ok'])->toBeFalse()->and($long['error'])->toContain('Too long');
});

it('changes the hash when content or stance changes and not otherwise', function () {
    $a = PrincipleParser::parse(pnote('stance: follow'), 'a.md')['card']['hash'];
    expect(PrincipleParser::parse(pnote('stance: follow'), 'a.md')['card']['hash'])->toBe($a)
        ->and(PrincipleParser::parse(pnote('stance: context'), 'a.md')['card']['hash'])->not->toBe($a);
});

function pcards(): array
{
    return [
        ['id' => 1, 'title' => 'Train to failure', 'body' => 'One hard set to momentary failure.', 'tags' => ['intensity'], 'grade' => 'opinion', 'stance' => 'context'],
        ['id' => 2, 'title' => 'Sleep before volume', 'body' => 'Recovery and sleep decide progress.', 'tags' => ['recovery', 'sleep'], 'grade' => 'strong', 'stance' => 'follow'],
        ['id' => 3, 'title' => 'Junk volume', 'body' => 'Failure not needed.', 'tags' => ['intensity'], 'grade' => 'opinion', 'stance' => 'ignore'],
    ];
}

it('ranks by overlap, boosts follow and strong, and never returns ignored cards', function () {
    $r = PrincipleRanker::rank(pcards(), 'how important is sleep and recovery?');
    expect(array_column($r, 'id'))->toBe([2]);
    $f = PrincipleRanker::rank(pcards(), 'training to failure intensity');
    expect(array_column($f, 'id'))->toBe([1])->and(PrincipleRanker::rank(pcards(), 'the and of'))->toBe([]);
});

it('picks a card by a visible rule and rotates deterministically', function () {
    expect(PrincipleContext::pick(true, true, true)['tags'])->toContain('recovery')
        ->and(PrincipleContext::pick(false, true, false)['tags'])->toContain('intensity')
        ->and(PrincipleContext::pick(false, false, true)['tags'])->toContain('nutrition')
        ->and(PrincipleContext::pick(false, false, false))->toBeNull();
    $ctx = PrincipleContext::pick(true, false, false);
    expect(PrincipleContext::choose(pcards(), $ctx, '2026-11-22')['id'])->toBe(2);
    $a = PrincipleContext::choose(pcards(), null, '2026-11-22');
    expect($a['id'])->toBe(2)->and(PrincipleContext::choose(pcards(), null, '2026-11-22')['id'])->toBe($a['id']);
    expect(PrincipleContext::choose([], null, '2026-11-22'))->toBeNull()->and(PrincipleContext::choose(pcards(), PrincipleContext::pick(false, false, true), '2026-11-22'))->toBeNull();
});
