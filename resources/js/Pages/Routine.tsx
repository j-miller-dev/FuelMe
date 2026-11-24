import { Head, Link } from '@inertiajs/react'
import { ArrowLeft } from 'lucide-react'
import AppLayout from '@/layouts/AppLayout'

interface Planned { type: string; kg: number | null; reps: number | null; rep_min: number | null; rep_max: number | null }
interface LastSet { type: string; kg: number | null; reps: number | null; rpe: number | null }
interface Rec { kg: number; reps: number; date: string }
interface Ex {
  index: number; template_id: string; title: string; muscle: string | null; rest_seconds: number | null; hevy_note: string | null; superset: string | null
  planned: Planned[]; last: { date: string; sets: LastSet[] } | null
  status: 'not_enough_data' | 'progressing' | 'holding' | 'stalled' | 'down' | null
  beat_the_log: { kg: number; reps: number; label: string }[]
  heaviest: Rec | null; best_e1rm: { e1rm: number } | null; has_new: boolean
  notes: { id: number; kind: 'cue' | 'worked' | 'note'; body: string; date: string }[]; linkable: boolean
}
interface Routine {
  id: number; title: string; folder: string | null; notes: string | null; last_done: string | null; exercises: Ex[]
  effort_now: { week: number; of: number; label: string } | null; deload_now: boolean; notes_about: string[]
}

const STATUS = { progressing: ['Progressing', 'text-accent'], holding: ['Holding', 'text-muted'], stalled: ['Stalled', 'text-carbs'], down: ['Down on your best', 'text-fat'], not_enough_data: ['Not enough data yet', 'text-muted'] } as const
const KIND = { cue: 'Cue', worked: 'What worked', note: 'Note' } as const
const pretty = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', timeZone: 'UTC' })
const num = (n: number) => (Number.isInteger(n) ? String(n) : n.toFixed(1))

/** "3 × 80 kg × 8-10" from the planned working sets; identical neighbours are merged. Warm-ups are counted separately. */
function plannedText(sets: Planned[]): string {
  const work = sets.filter((s) => s.type !== 'warmup')
  const warm = sets.length - work.length
  const reps = (s: Planned) => (s.rep_min !== null && s.rep_max !== null && s.rep_min !== s.rep_max ? `${s.rep_min}-${s.rep_max}` : s.reps !== null ? String(s.reps) : s.rep_min !== null ? String(s.rep_min) : '?')
  const label = (s: Planned) => `${s.kg !== null && s.kg > 0 ? `${num(s.kg)} kg × ` : ''}${reps(s)}${s.type === 'failure' ? ' (failure)' : s.type === 'dropset' ? ' (drop)' : ''}`
  const parts: string[] = []
  for (let i = 0; i < work.length; ) {
    let j = i
    while (j + 1 < work.length && label(work[j + 1]!) === label(work[i]!)) j++
    parts.push(`${j - i + 1} × ${label(work[i]!)}`)
    i = j + 1
  }
  return `${parts.join(', ') || 'No sets planned'}${warm ? ` (+${warm} warm-up${warm === 1 ? '' : 's'})` : ''}`
}

function lastText(sets: LastSet[]): string {
  return sets.filter((s) => s.type !== 'warmup').map((s) => `${s.kg !== null ? num(s.kg) : '-'}×${s.reps ?? '-'}${s.type === 'failure' ? ' (failure)' : s.rpe ? ` @${s.rpe}` : ''}`).join(', ')
}

export default function RoutinePage({ routine: r }: { routine: Routine }) {
  return (
    <AppLayout>
      <Head title={r.title} />
      <header className="flex items-center gap-2">
        <Link href="/lifting/routines" aria-label="Back to routines" className="flex size-11 items-center justify-center rounded-full text-muted"><ArrowLeft className="size-5" aria-hidden /></Link>
        <div>
          <h1 className="text-lg font-semibold">{r.title}</h1>
          <p className="text-xs text-muted">{r.folder ? `${r.folder} · ` : ''}{r.last_done ? `Last done ${pretty(r.last_done)}` : 'Not done yet'}</p>
        </div>
      </header>

      {r.effort_now ? (
        <p className="mt-3 rounded-2xl bg-surface p-3 text-sm">Your effort plan this week: <span className="font-medium">{r.effort_now.label}</span> (week {r.effort_now.week} of {r.effort_now.of}).{r.deload_now ? ' This week is labelled a deload.' : ''}</p>
      ) : r.deload_now ? <p className="mt-3 rounded-2xl bg-surface p-3 text-sm">This week is labelled a deload.</p> : null}
      {r.notes ? <p className="mt-3 rounded-2xl bg-surface p-3 text-sm text-muted">{r.notes}</p> : null}

      <ul className="mt-3 space-y-3">
        {r.exercises.map((e) => (
          <li key={e.index} className="rounded-2xl bg-surface p-4">
            <div className="flex items-start justify-between gap-2">
              <div>
                {e.linkable ? <Link href={`/lifting/exercise/${encodeURIComponent(e.template_id)}`} className="font-medium underline decoration-raised underline-offset-4">{e.title}</Link> : <span className="font-medium">{e.title}</span>}
                <p className="text-xs text-muted capitalize">{e.muscle ? e.muscle.replace(/_/g, ' ') : ''}{e.superset ? `${e.muscle ? ' · ' : ''}superset ${e.superset}` : ''}</p>
              </div>
              <span className="flex flex-col items-end gap-1 text-xs">
                {e.has_new ? <span className="rounded-full bg-accent px-2 py-0.5 font-medium text-canvas">New PR</span> : null}
                {e.status ? <span className={STATUS[e.status][1]}>{STATUS[e.status][0]}</span> : null}
              </span>
            </div>

            <dl className="mt-2 space-y-1 text-sm">
              <div><dt className="inline text-muted">Plan: </dt><dd className="inline">{plannedText(e.planned)}{e.rest_seconds ? `, rest ${e.rest_seconds}s` : ''}</dd></div>
              <div><dt className="inline text-muted">Last time{e.last ? ` (${pretty(e.last.date)})` : ''}: </dt><dd className="inline">{e.last ? lastText(e.last.sets) || 'warm-ups only' : 'not logged yet'}</dd></div>
              {e.beat_the_log.length ? <div><dt className="inline text-muted">Beat the log: </dt><dd className="inline">{e.beat_the_log.map((b) => `${num(b.kg)} kg × ${b.reps}`).join(' or ')}</dd></div> : null}
              {e.heaviest ? <div><dt className="inline text-muted">Heaviest: </dt><dd className="inline">{num(e.heaviest.kg)} kg × {e.heaviest.reps} ({pretty(e.heaviest.date)}){e.best_e1rm ? `, est. 1RM ${num(e.best_e1rm.e1rm)} kg` : ''}</dd></div> : null}
            </dl>

            {e.hevy_note ? <p className="mt-2 text-sm"><span className="text-muted">Hevy note: </span>{e.hevy_note}</p> : null}
            {e.notes.length ? (
              <ul className="mt-2 space-y-1 border-t border-raised pt-2 text-sm">
                {e.notes.map((n) => <li key={n.id}><span className="text-xs text-muted">{KIND[n.kind]}: </span>{n.body}</li>)}
              </ul>
            ) : null}
          </li>
        ))}
      </ul>
      <ul className="mt-4 list-disc space-y-1 pl-5 text-xs text-muted">{r.notes_about.map((n) => <li key={n}>{n}</li>)}</ul>
    </AppLayout>
  )
}
