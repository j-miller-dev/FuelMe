import { ChevronDown, Dumbbell, Flame, Trophy } from 'lucide-react'
import { useState } from 'react'

export interface LiftRow { id: string; title: string; muscle: string | null; sets: number; volume: number; best: { kg: number; reps: number } | null; pr: ('heaviest' | 'e1rm')[]; detail: { kg: number | null; reps: number | null; type: string }[] }
export interface LiftCard {
  id: number | null; date: string; title: string; minutes: number | null; deload: boolean; exercises: number; sets: number; reps: number; volume_kg: number; bodyweight_sets: number
  top_set: { exercise: string; kg: number; reps: number } | null; pr_count: number; muscles: { muscle: string; sets: number; pct: number }[]
  effort: { working: number; failure: number; rpe_logged: number; unlabelled: number; avg_rpe: number | null }
  vs_last: { pct: number; last_kg: number } | null; vs_plan?: { verdict?: string; planned?: string } | null; rows: LiftRow[]
}

const TONES = ['bg-accent', 'bg-protein', 'bg-carbs', 'bg-fat', 'bg-muted'] as const
const nice = (m: string) => m.replace(/_/g, ' ')
const n = (x: number) => (Number.isInteger(x) ? String(x) : x.toFixed(1).replace(/\.0$/, ''))
const when = (d: string) => new Date(`${d}T12:00:00Z`).toLocaleDateString('en-AU', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })

function Chip({ children, tone = 'bg-raised text-ink' }: { children: React.ReactNode; tone?: string }) {
  return <span className={`inline-flex items-center gap-1 rounded-full px-2.5 py-1 text-xs font-medium ${tone}`}>{children}</span>
}

/** A lifting session as a card: the big numbers, where the work went (by muscle and by exercise), and what stood out. Descriptions from your own sets, never scores. */
export function SessionCard({ c, startOpen = false, heading = true }: { c: LiftCard; startOpen?: boolean; heading?: boolean }) {
  const [open, setOpen] = useState(startOpen)
  const maxVol = Math.max(1, ...c.rows.map((r) => r.volume))
  return (
    <article className={`rounded-2xl bg-surface p-4 ${c.deload ? 'opacity-80' : ''}`} aria-label={`${c.title}, ${when(c.date)}`}>
      <header className="flex items-start justify-between gap-2">
        <div className="min-w-0">
          <h3 className="flex items-center gap-2 font-semibold"><Dumbbell className="size-4 shrink-0 text-accent" aria-hidden /><span className="truncate">{c.title}</span></h3>
          {heading ? <p className="text-xs text-muted">{when(c.date)}{c.minutes ? ` · ${c.minutes} min` : ''}</p> : c.minutes ? <p className="text-xs text-muted">{c.minutes} min</p> : null}
        </div>
        {c.pr_count > 0 ? <Chip tone="bg-carbs/20 text-carbs"><Trophy className="size-3.5" aria-hidden />{c.pr_count} {c.pr_count === 1 ? 'PR' : 'PRs'}</Chip> : null}
      </header>

      <div className="mt-3 flex items-end gap-5">
        <div><p className="text-3xl font-semibold leading-none tabular-nums">{c.volume_kg.toLocaleString('en-AU')}<span className="ml-1 text-sm font-normal text-muted">kg</span></p><p className="mt-1 text-xs text-muted">total volume</p></div>
        <div><p className="text-xl font-semibold leading-none tabular-nums">{c.sets}</p><p className="mt-1 text-xs text-muted">sets</p></div>
        <div><p className="text-xl font-semibold leading-none tabular-nums">{c.exercises}</p><p className="mt-1 text-xs text-muted">exercises</p></div>
        <div><p className="text-xl font-semibold leading-none tabular-nums">{c.reps}</p><p className="mt-1 text-xs text-muted">reps</p></div>
      </div>

      {c.top_set ? <p className="mt-3 text-sm">Top set: <span className="font-medium">{c.top_set.exercise}</span> {n(c.top_set.kg)} kg × {c.top_set.reps}</p> : null}

      {c.muscles.length ? (
        <div className="mt-3" role="img" aria-label={`Sets by muscle: ${c.muscles.map((m) => `${nice(m.muscle)} ${m.sets}`).join(', ')}`}>
          <div className="flex h-2.5 w-full overflow-hidden rounded-full bg-raised">{c.muscles.map((m, i) => <span key={m.muscle} className={TONES[i % TONES.length]} style={{ width: `${m.pct}%` }} />)}</div>
          <ul className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted">{c.muscles.map((m, i) => <li key={m.muscle} className="flex items-center gap-1 capitalize"><span className={`size-2 rounded-full ${TONES[i % TONES.length]}`} aria-hidden />{nice(m.muscle)} {m.sets}</li>)}</ul>
        </div>
      ) : null}

      <div className="mt-3 flex flex-wrap gap-1.5">
        {c.deload ? <Chip>Deload week</Chip> : null}
        {c.vs_last ? <Chip>{c.vs_last.pct > 0 ? '+' : ''}{c.vs_last.pct}% volume vs last {c.title}</Chip> : null}
        {c.effort.failure > 0 ? <Chip tone="bg-fat/15 text-fat"><Flame className="size-3.5" aria-hidden />{c.effort.failure} to failure</Chip> : null}
        {c.effort.avg_rpe !== null ? <Chip>avg RPE {n(c.effort.avg_rpe)}</Chip> : null}
        {c.vs_plan?.verdict && c.vs_plan.verdict !== 'unknown' ? <Chip>{c.vs_plan.verdict === 'in_range' ? 'effort in your plan' : c.vs_plan.verdict === 'harder' ? 'harder than your plan' : 'easier than your plan'}</Chip> : null}
      </div>

      <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="mt-2 flex min-h-11 w-full items-center justify-between text-sm text-muted">
        <span>{open ? 'Hide exercises' : 'Show exercises'}</span><ChevronDown className={`size-4 transition-transform ${open ? 'rotate-180' : ''}`} aria-hidden />
      </button>
      {open ? (
        <ul className="space-y-3 border-t border-raised pt-3">
          {c.rows.map((r) => (
            <li key={r.id + r.title}>
              <div className="flex items-baseline justify-between gap-2 text-sm"><span className="font-medium">{r.title}{r.pr.length ? <Trophy className="ml-1 inline size-3.5 text-carbs" aria-label="PR" /> : null}</span><span className="text-xs text-muted tabular-nums">{r.sets} sets · {Math.round(r.volume).toLocaleString('en-AU')} kg</span></div>
              <div className="mt-1 h-1.5 w-full rounded-full bg-raised"><div className="h-1.5 rounded-full bg-accent" style={{ width: `${Math.max(3, (r.volume / maxVol) * 100)}%` }} /></div>
              <p className="mt-1 text-xs text-muted tabular-nums">{r.detail.map((s) => (s.kg ? `${n(s.kg)}×${s.reps ?? '?'}` : `${s.reps ?? '?'} reps`)).join(' · ')}{r.pr.length ? ` · ${r.pr.map((p) => (p === 'heaviest' ? 'heaviest yet' : 'best est. 1RM')).join(', ')}` : ''}</p>
            </li>
          ))}
        </ul>
      ) : null}
    </article>
  )
}
