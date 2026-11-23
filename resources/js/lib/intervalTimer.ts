/**
 * Interval timer engine. PURE: no DOM, no clock. The screen feeds it elapsed milliseconds and gets back where you are and what to say.
 * Time is always measured from timestamps (never by counting ticks), so a throttled or backgrounded tab catches up instead of drifting.
 */
export type Kind = 'warmup' | 'work' | 'rest' | 'cooldown'

export interface Segment { kind: Kind; label: string; seconds: number; round: number; of: number }

export interface Protocol {
  id: string
  name: string
  blurb: string
  warmupSec: number
  workSec: number
  restSec: number
  rounds: number
  cooldownSec: number
  /** Wording for the work block, e.g. "Hard" or "Round". Voice and screen only. */
  workLabel: string
  restLabel: string
}

export const LIMITS = { warmupSec: [0, 1800], workSec: [5, 1800], restSec: [0, 1800], rounds: [1, 30], cooldownSec: [0, 1800] } as const

/** Clamp every number to a sane range so a typo cannot build a 40-hour timer. */
export function sanitise(p: Protocol): Protocol {
  const c = (v: number, [lo, hi]: readonly [number, number]) => Math.min(hi, Math.max(lo, Math.round(Number.isFinite(v) ? v : lo)))
  return { ...p, warmupSec: c(p.warmupSec, LIMITS.warmupSec), workSec: c(p.workSec, LIMITS.workSec), restSec: c(p.restSec, LIMITS.restSec), rounds: c(p.rounds, LIMITS.rounds), cooldownSec: c(p.cooldownSec, LIMITS.cooldownSec) }
}

/** The ordered blocks of a session. No rest after the last round; zero-length blocks are left out. */
export function buildSegments(input: Protocol): Segment[] {
  const p = sanitise(input)
  const out: Segment[] = []
  if (p.warmupSec > 0) out.push({ kind: 'warmup', label: 'Warm-up', seconds: p.warmupSec, round: 0, of: p.rounds })
  for (let r = 1; r <= p.rounds; r++) {
    out.push({ kind: 'work', label: p.workLabel, seconds: p.workSec, round: r, of: p.rounds })
    if (r < p.rounds && p.restSec > 0) out.push({ kind: 'rest', label: p.restLabel, seconds: p.restSec, round: r, of: p.rounds })
  }
  if (p.cooldownSec > 0) out.push({ kind: 'cooldown', label: 'Cool-down', seconds: p.cooldownSec, round: p.rounds, of: p.rounds })
  return out
}

export const totalSeconds = (segs: Segment[]) => segs.reduce((n, s) => n + s.seconds, 0)

export interface Position { index: number; segment: Segment | null; segmentElapsedMs: number; remainingMs: number; totalRemainingMs: number; done: boolean }

/** Where you are after `elapsedMs` of running time (pauses already removed). */
export function position(segs: Segment[], elapsedMs: number): Position {
  const total = totalSeconds(segs) * 1000
  const e = Math.max(0, elapsedMs)
  if (!segs.length || e >= total) return { index: segs.length, segment: null, segmentElapsedMs: 0, remainingMs: 0, totalRemainingMs: 0, done: true }
  let start = 0
  for (let i = 0; i < segs.length; i++) {
    const len = segs[i]!.seconds * 1000
    if (e < start + len) return { index: i, segment: segs[i]!, segmentElapsedMs: e - start, remainingMs: start + len - e, totalRemainingMs: total - e, done: false }
    start += len
  }
  return { index: segs.length, segment: null, segmentElapsedMs: 0, remainingMs: 0, totalRemainingMs: 0, done: true }
}

export interface Cue { atMs: number; kind: 'speak' | 'beep' | 'long-beep'; text?: string }

const secs = (n: number) => (n === 1 ? '1 second' : n % 60 === 0 && n >= 60 ? `${n / 60} minute${n === 60 ? '' : 's'}` : `${n} seconds`)

/** What to say at the START of a block. `next` is the block after it, if any. */
export function announce(seg: Segment, next: Segment | null): string {
  switch (seg.kind) {
    case 'warmup': return `Warm-up. ${secs(seg.seconds)}.`
    case 'work': {
      const last = seg.round === seg.of
      // The "round complete" call-out belongs to the rest block, so a work block only announces itself.
      return seg.of === 1 ? 'Go.' : last ? 'Final round. Go.' : `Round ${seg.round}. Go.`
    }
    case 'rest': return `Round ${seg.round} complete. ${seg.label} ${secs(seg.seconds)}.${next?.kind === 'work' && next.round === next.of ? ' Next is the final round.' : ''}`
    case 'cooldown': return `That is all ${seg.of} round${seg.of === 1 ? '' : 's'}. Cool down for ${secs(seg.seconds)}.`
  }
}

/**
 * Every cue that falls in (fromMs, toMs]. Call with the previous and the current elapsed time on each tick so nothing is missed or repeated,
 * even if the tab was asleep for a while (a long gap returns every cue in it, and the caller can choose to speak only the latest).
 */
export function cuesBetween(segs: Segment[], fromMs: number, toMs: number): Cue[] {
  const cues: Cue[] = []
  let start = 0
  for (let i = 0; i < segs.length; i++) {
    const s = segs[i]!
    const len = s.seconds * 1000
    const end = start + len
    const inRange = (t: number) => t > fromMs && t <= toMs
    // the first block is announced at 0, which is "after" fromMs = -1 on the very first tick
    if (inRange(start) || (start === 0 && fromMs < 0 && toMs >= 0)) cues.push({ atMs: start, kind: 'speak', text: announce(s, segs[i + 1] ?? null) })
    // halfway call for long blocks (2 minutes or more)
    if (s.seconds >= 120 && inRange(start + len / 2)) cues.push({ atMs: start + len / 2, kind: 'speak', text: s.kind === 'work' ? 'Halfway.' : 'Halfway through.' })
    if (s.seconds >= 30 && s.kind !== 'cooldown' && inRange(end - 10000)) cues.push({ atMs: end - 10000, kind: 'speak', text: '10 seconds.' })
    for (const n of [3, 2, 1]) if (s.seconds > 3 && inRange(end - n * 1000)) cues.push({ atMs: end - n * 1000, kind: 'beep' })
    start = end
  }
  if (inRangeAt(start, fromMs, toMs)) cues.push({ atMs: start, kind: 'speak', text: 'Session complete. Well done.' }, { atMs: start, kind: 'long-beep' })
  return cues.sort((a, b) => a.atMs - b.atMs)
}

const inRangeAt = (t: number, from: number, to: number) => t > from && t <= to

export const PRESETS: Protocol[] = [
  { id: 'hiit', name: 'HIIT', blurb: 'Short hard efforts, short rests', warmupSec: 120, workSec: 30, restSec: 30, rounds: 8, cooldownSec: 120, workLabel: 'Hard', restLabel: 'Rest' },
  { id: 'norwegian', name: 'Norwegian 4x4', blurb: '4 minutes hard, 3 minutes easy', warmupSec: 600, workSec: 240, restSec: 180, rounds: 4, cooldownSec: 300, workLabel: 'Hard', restLabel: 'Easy recovery' },
  { id: 'jumprope', name: 'Jump rope', blurb: 'Rounds on the rope', warmupSec: 60, workSec: 60, restSec: 30, rounds: 10, cooldownSec: 60, workLabel: 'Jump', restLabel: 'Rest' },
  { id: 'bjj', name: 'BJJ rounds', blurb: 'Rolling rounds with a break', warmupSec: 0, workSec: 300, restSec: 60, rounds: 6, cooldownSec: 0, workLabel: 'Roll', restLabel: 'Rest' },
]

export const fmtClock = (ms: number) => {
  const s = Math.ceil(Math.max(0, ms) / 1000)
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`
}
