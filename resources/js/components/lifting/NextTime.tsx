import { ArrowDown, ArrowUp, Equal, Repeat } from 'lucide-react'

export interface Next {
  action: 'increase' | 'hold' | 'repeat' | 'reduce' | 'start' | 'no_load'
  kg: number | null; reps_low: number | null; reps_high: number | null; expected_reps: number | null
  last: { date: string; kg: number; reps: number[]; sets_at_top: number } | null
  reason: string; notes: string[]; confidence: 'effort logged' | 'reps only' | null
  effort_target: { min: number; max: number } | null; last_avg_rir: number | null; jump_pct: number | null; step: number
}
export interface Projection {
  status: 'not_enough_data' | 'flat_or_down' | 'ok' | 'noisy'; sessions: number; latest: number | null; slope_per_week: number | null; fit: number | null
  weeks: { weeks: number; e1rm_low: number; e1rm_high: number; reps?: number; kg_low?: number; kg_high?: number }[]; note: string
}
export interface StepInfo { kg: number; custom: boolean }

const num = (n: number) => (Number.isInteger(n) ? String(n) : n.toFixed(2).replace(/0+$/, '').replace(/\.$/, ''))
const range = (a: number | null, b: number | null) => (a === null ? '' : b === null || a === b ? String(a) : `${a}-${b}`)

const BADGE = {
  increase: { label: 'Go up', cls: 'bg-accent text-canvas', Icon: ArrowUp },
  hold: { label: 'Same weight', cls: 'bg-raised text-fg', Icon: Equal },
  repeat: { label: 'Repeat', cls: 'bg-raised text-fg', Icon: Repeat },
  reduce: { label: 'Drop a little', cls: 'bg-fat/20 text-fat', Icon: ArrowDown },
  start: { label: 'Start here', cls: 'bg-raised text-fg', Icon: Equal },
} as const

export function targetText(n: Next): string {
  if (n.kg === null) return ''
  return `${num(n.kg)} kg × ${range(n.reps_low, n.reps_high)}`
}

/** The suggestion for one exercise: what to aim for next time, why, and a rough projection. Suggestions only, never an order. */
export default function NextTime({ next, projection, step }: { next: Next; projection: Projection; step: StepInfo }) {
  if (next.action === 'no_load') {
    return <p className="mt-2 text-xs text-muted">No weight to progress here ({next.reason.startsWith('No logged') ? 'timed, bodyweight, or not done yet' : next.reason}).</p>
  }
  const b = BADGE[next.action]
  const proj = projection.status === 'ok' || projection.status === 'noisy' ? projection.weeks : []
  return (
    <div className="mt-3 rounded-xl bg-canvas/60 p-3" aria-label="Next time">
      <div className="flex items-center justify-between gap-2">
        <span className={`flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${b.cls}`}><b.Icon className="size-3.5" aria-hidden />{b.label}</span>
        <span className="text-base font-semibold">{targetText(next)}</span>
      </div>
      <p className="mt-2 text-sm">{next.reason}</p>
      {next.notes.map((n) => <p key={n} className="mt-1 text-xs text-muted">{n}</p>)}
      <p className="mt-1 text-xs text-muted">
        {next.confidence === 'effort logged' ? `Effort was logged (average ${num(next.last_avg_rir ?? 0)} reps in reserve).` : next.confidence === 'reps only' ? 'No effort logged on those sets, so this is based on reps only.' : ''}
        {next.effort_target ? ` Your plan this week: ${next.effort_target.min}${next.effort_target.max !== next.effort_target.min ? ` to ${next.effort_target.max}` : ''} in reserve.` : ''}
        {` Smallest jump ${num(step.kg)} kg${step.custom ? ' (yours)' : ' (guessed)'}.`}
      </p>
      {proj.length ? (
        <div className="mt-2 border-t border-raised pt-2 text-xs">
          <p className="font-medium">Rough projection from your own trend{projection.slope_per_week !== null ? ` (${projection.slope_per_week > 0 ? '+' : ''}${num(projection.slope_per_week)} kg a week on estimated 1RM)` : ''}</p>
          <ul className="mt-1 space-y-0.5 text-muted">
            {proj.map((w) => (
              <li key={w.weeks}>In {w.weeks} weeks: est. 1RM {num(w.e1rm_low)}{w.e1rm_high !== w.e1rm_low ? ` to ${num(w.e1rm_high)}` : ''} kg{w.kg_low !== undefined ? `, about ${num(w.kg_low)}${w.kg_high !== w.kg_low ? ` to ${num(w.kg_high ?? w.kg_low)}` : ''} kg for ${w.reps} reps` : ''}</li>
            ))}
          </ul>
          <p className="mt-1 text-muted">{projection.note}</p>
        </div>
      ) : <p className="mt-2 border-t border-raised pt-2 text-xs text-muted">{projection.note}</p>}
    </div>
  )
}
