import assert from 'node:assert/strict'
import { test } from 'node:test'
import { buildSegments, cuesBetween, fmtClock, position, PRESETS, sanitise, totalSeconds, type Protocol } from '../../resources/js/lib/intervalTimer.ts'

const p: Protocol = { id: 't', name: 'T', blurb: '', warmupSec: 10, workSec: 30, restSec: 20, rounds: 3, cooldownSec: 15, workLabel: 'Hard', restLabel: 'Rest' }

test('builds warm-up, work/rest rounds with no rest after the last, then cool-down', () => {
  const s = buildSegments(p)
  assert.deepEqual(s.map((x) => x.kind), ['warmup', 'work', 'rest', 'work', 'rest', 'work', 'cooldown'])
  assert.equal(totalSeconds(s), 10 + 30 * 3 + 20 * 2 + 15)
})

test('leaves out zero-length blocks and clamps silly numbers', () => {
  const s = buildSegments({ ...p, warmupSec: 0, cooldownSec: 0, restSec: 0 })
  assert.deepEqual(s.map((x) => x.kind), ['work', 'work', 'work'])
  const c = sanitise({ ...p, rounds: 9999, workSec: -5, restSec: NaN })
  assert.equal(c.rounds, 30); assert.equal(c.workSec, 5); assert.equal(c.restSec, 0)
})

test('finds the block from elapsed time, at the boundaries too', () => {
  const s = buildSegments(p)
  assert.equal(position(s, 0).segment?.kind, 'warmup')
  assert.equal(position(s, 9999).segment?.kind, 'warmup')
  const w = position(s, 10000)
  assert.equal(w.segment?.kind, 'work'); assert.equal(w.segment?.round, 1); assert.equal(w.remainingMs, 30000)
  assert.equal(position(s, 40000).segment?.kind, 'rest')
  assert.equal(position(s, totalSeconds(s) * 1000).done, true)
  assert.equal(position(s, 1e9).done, true)
  assert.equal(position(s, -50).segment?.kind, 'warmup')
})

test('speaks each block once, and never twice when ticks overlap or the tab slept', () => {
  const s = buildSegments(p)
  const all = cuesBetween(s, -1, totalSeconds(s) * 1000)
  const spoken = all.filter((c) => c.kind === 'speak').map((c) => c.text)
  assert.equal(spoken[0], 'Warm-up. 10 seconds.')
  assert.ok(spoken.includes('Round 1. Go.') && spoken.includes('Round 1 complete. Rest 20 seconds.'))
  assert.ok(spoken.includes('Final round. Go.'))
  assert.equal(spoken.at(-1), 'Session complete. Well done.')
  // splitting the range anywhere gives exactly the same cues, with no repeats
  const a = cuesBetween(s, -1, 37000), b = cuesBetween(s, 37000, totalSeconds(s) * 1000)
  assert.equal(a.length + b.length, all.length)
})

test('says what is next before the final round, with 10-second and 3-2-1 calls', () => {
  const s = buildSegments(p)
  const rest2Start = (10 + 30 + 20 + 30) * 1000
  const c = cuesBetween(s, rest2Start - 1, rest2Start)
  assert.ok(c.some((x) => x.text?.includes('Next is the final round')))
  const ten = cuesBetween(s, 0, 40000).filter((x) => x.text === '10 seconds.')
  assert.ok(ten.length >= 1)
  const beeps = cuesBetween(s, 36000, 40000).filter((x) => x.kind === 'beep')
  assert.deepEqual(beeps.map((x) => x.atMs), [37000, 38000, 39000])
})

test('halfway call only on long blocks', () => {
  const n = PRESETS.find((x) => x.id === 'norwegian')!
  const s = buildSegments(n)
  const spoken = cuesBetween(s, -1, totalSeconds(s) * 1000).filter((c) => c.text === 'Halfway.')
  assert.equal(spoken.length, 4)
  const h = cuesBetween(buildSegments(PRESETS[0]!), -1, 1e9).filter((c) => c.text === 'Halfway.')
  assert.equal(h.length, 0)
})

test('every preset builds and the clock reads like a clock', () => {
  for (const x of PRESETS) assert.ok(totalSeconds(buildSegments(x)) > 0, x.id)
  assert.equal(fmtClock(61000), '1:01'); assert.equal(fmtClock(59001), '1:00'); assert.equal(fmtClock(-5), '0:00')
})
