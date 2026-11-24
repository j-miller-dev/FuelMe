import { useState } from 'react'
import { Head, Link, router, useForm } from '@inertiajs/react'
import { ArrowLeft, Trash2 } from 'lucide-react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'

interface Rec { kg: number; reps: number; date: string; new: boolean }
interface Records {
  heaviest: Rec; best_e1rm: { e1rm: number; kg: number; reps: number; date: string; new: boolean } | null
  best_volume: { kg: number; sets: number; date: string; new: boolean }; rep_bests: Rec[]; sessions: number
}
interface SetRow { type: string; kg: number | null; reps: number | null; rpe: number | null }
interface Note { id: number; kind: Kind; body: string; date: string }
type Kind = 'cue' | 'worked' | 'note'
interface Card { id: number; title: string; source: string; stance: string; body: string }
interface Exercise {
  id: string; title: string; muscle: string | null; records: Records | null
  status: { status: 'not_enough_data' | 'progressing' | 'holding' | 'stalled' | 'down'; sessions: number; latest_e1rm: number | null; best_e1rm: number | null } | null
  trend: { date: string; e1rm: number }[]; beat_the_log: { kg: number; reps: number; label: string }[]
  recent: { date: string; deload: boolean; sets: SetRow[] }[]; notes: Note[]; principles: Card[]
}

const STATUS = { progressing: 'Progressing', holding: 'Holding', stalled: 'Stalled', down: 'Down on your best', not_enough_data: 'Not enough data yet (needs 3 sessions)' } as const
const KIND_LABEL: Record<Kind, string> = { cue: 'Cue', worked: 'What worked', note: 'Note' }
const pretty = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: '2-digit', timeZone: 'UTC' })
const kg = (n: number) => `${Number.isInteger(n) ? n : n.toFixed(1)} kg`

function Trend({ pts }: { pts: { date: string; e1rm: number }[] }) {
  const first = pts[0], last = pts[pts.length - 1]
  if (pts.length < 2 || !first || !last) return <p className="text-sm text-muted">Needs two sessions to draw a trend.</p>
  const v = pts.map((p) => p.e1rm), min = Math.min(...v), max = Math.max(...v), span = Math.max(1, max - min)
  const xy = pts.map((p, i) => [(i / (pts.length - 1)) * 300 + 5, 70 - ((p.e1rm - min) / span) * 60] as const)
  return (
    <figure>
      <svg viewBox="0 0 310 80" className="h-24 w-full text-accent" role="img" aria-label={`Estimated 1RM from ${min} to ${max} kg over ${pts.length} sessions`}>
        <polyline points={xy.map(([x, y]) => `${x},${y}`).join(' ')} fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinejoin="round" />
        {xy.map(([x, y], i) => <circle key={i} cx={x} cy={y} r={i === xy.length - 1 ? 4 : 2.5} fill="currentColor" />)}
      </svg>
      <figcaption className="flex justify-between text-xs text-muted"><span>{pretty(first.date)}: {first.e1rm} kg</span><span>{pretty(last.date)}: {last.e1rm} kg</span></figcaption>
    </figure>
  )
}

function Pr({ on }: { on: boolean }) { return on ? <span className="ml-2 rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-canvas">New PR</span> : null }

function NoteItem({ n }: { n: Note }) {
  const [edit, setEdit] = useState(false)
  const [body, setBody] = useState(n.body)
  const [kind, setKind] = useState<Kind>(n.kind)
  if (edit) {
    return (
      <li className="py-3">
        <KindPicker value={kind} onChange={setKind} />
        <label className="sr-only" htmlFor={`n${n.id}`}>Edit note</label>
        <textarea id={`n${n.id}`} value={body} maxLength={600} onChange={(e) => setBody(e.target.value)} className="mt-2 min-h-24 w-full rounded-xl bg-canvas/60 p-3 text-sm" />
        <div className="mt-2 flex gap-2">
          <Button type="button" disabled={!body.trim()} onClick={() => router.patch(`/lifting/notes/${n.id}`, { kind, body }, { preserveScroll: true, onSuccess: () => setEdit(false) })}>Save</Button>
          <button type="button" className="min-h-11 px-3 text-sm text-muted underline" onClick={() => { setEdit(false); setBody(n.body); setKind(n.kind) }}>Cancel</button>
        </div>
      </li>
    )
  }
  return (
    <li className="py-3">
      <p className="text-xs text-muted">{KIND_LABEL[n.kind]} · {pretty(n.date)}</p>
      <p className="mt-0.5 whitespace-pre-wrap text-sm">{n.body}</p>
      <div className="mt-1 flex gap-1">
        <button type="button" className="min-h-11 px-2 text-sm text-accent underline" onClick={() => setEdit(true)}>Edit</button>
        <button type="button" aria-label="Delete note" className="flex min-h-11 items-center px-2 text-muted" onClick={() => { if (confirm('Delete this note?')) router.delete(`/lifting/notes/${n.id}`, { preserveScroll: true }) }}><Trash2 className="size-4" aria-hidden /></button>
      </div>
    </li>
  )
}

function KindPicker({ value, onChange }: { value: Kind; onChange: (k: Kind) => void }) {
  return (
    <div className="flex gap-2" role="group" aria-label="Kind of note">
      {(Object.keys(KIND_LABEL) as Kind[]).map((k) => (
        <button key={k} type="button" aria-pressed={value === k} onClick={() => onChange(k)} className={`min-h-11 rounded-full px-4 text-sm ${value === k ? 'bg-accent text-canvas' : 'bg-raised'}`}>{KIND_LABEL[k]}</button>
      ))}
    </div>
  )
}

export default function ExercisePage({ exercise: e }: { exercise: Exercise }) {
  const form = useForm<{ kind: Kind; body: string }>({ kind: 'cue', body: '' })
  const r = e.records
  return (
    <AppLayout>
      <Head title={e.title} />
      <header className="flex items-center gap-2">
        <Link href="/lifting/best" aria-label="Back to best lifts" className="flex size-11 items-center justify-center rounded-full text-muted"><ArrowLeft className="size-5" aria-hidden /></Link>
        <div>
          <h1 className="text-lg font-semibold">{e.title}</h1>
          {e.muscle ? <p className="text-xs text-muted capitalize">{e.muscle.replace(/_/g, ' ')}</p> : null}
        </div>
      </header>

      {r ? (
        <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Records">
          <h2 className="font-semibold">Records</h2>
          <dl className="mt-2 space-y-2 text-sm">
            <div className="flex justify-between"><dt>Heaviest<Pr on={r.heaviest.new} /></dt><dd className="text-right">{kg(r.heaviest.kg)} × {r.heaviest.reps}<span className="block text-xs text-muted">{pretty(r.heaviest.date)}</span></dd></div>
            {r.best_e1rm ? <div className="flex justify-between"><dt>Best estimated 1RM<Pr on={r.best_e1rm.new} /></dt><dd className="text-right">{kg(r.best_e1rm.e1rm)}<span className="block text-xs text-muted">from {kg(r.best_e1rm.kg)} × {r.best_e1rm.reps}, {pretty(r.best_e1rm.date)}</span></dd></div> : null}
            <div className="flex justify-between"><dt>Best session volume<Pr on={r.best_volume.new} /></dt><dd className="text-right">{Math.round(r.best_volume.kg).toLocaleString('en-AU')} kg<span className="block text-xs text-muted">{r.best_volume.sets} sets, {pretty(r.best_volume.date)}</span></dd></div>
          </dl>
          <h3 className="mt-4 text-sm font-medium">Best reps at each weight</h3>
          <ul className="mt-1 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
            {r.rep_bests.map((b) => <li key={b.kg} className="flex justify-between"><span>{kg(b.kg)}</span><span>{b.reps} reps{b.new ? ' ↑' : ''}</span></li>)}
          </ul>
          <p className="mt-3 text-xs text-muted">Warm-ups are left out. Estimated 1RM is an estimate (Epley, 1 to 12 reps). The first time you log a lift or a weight is a baseline, never a PR.</p>
        </section>
      ) : (
        <p className="mt-3 rounded-2xl bg-surface p-4 text-sm text-muted">No synced sets with a weight and reps for this lift. Your notes below are kept.</p>
      )}

      {e.status ? (
        <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Trend">
          <h2 className="font-semibold">Trend</h2>
          <p className="text-sm text-muted">{STATUS[e.status.status]}{e.status.latest_e1rm ? `. Latest estimated 1RM ${e.status.latest_e1rm} kg, best ${e.status.best_e1rm} kg.` : ''}</p>
          <div className="mt-2"><Trend pts={e.trend} /></div>
          {e.beat_the_log.length ? (
            <>
              <h3 className="mt-3 text-sm font-medium">Beat the log next time</h3>
              <ul className="mt-1 space-y-1 text-sm">{e.beat_the_log.map((b) => <li key={b.label}>{b.kg} kg × {b.reps}: <span className="text-muted">{b.label.toLowerCase()}</span></li>)}</ul>
            </>
          ) : null}
        </section>
      ) : null}

      <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Your notes">
        <h2 className="font-semibold">Your notes</h2>
        <p className="mt-1 text-xs text-muted">Cues and what worked, in your own words. Kept even if you clear your synced Hevy history.</p>
        <form className="mt-3" onSubmit={(ev) => { ev.preventDefault(); form.post(`/lifting/exercise/${encodeURIComponent(e.id)}/notes`, { preserveScroll: true, onSuccess: () => form.reset('body') }) }}>
          <KindPicker value={form.data.kind} onChange={(k) => form.setData('kind', k)} />
          <label className="sr-only" htmlFor="new-note">New note</label>
          <textarea id="new-note" value={form.data.body} maxLength={600} onChange={(ev) => form.setData('body', ev.target.value)} placeholder="e.g. Elbows tucked, pause on the chest" className="mt-2 min-h-20 w-full rounded-xl bg-canvas/60 p-3 text-sm" />
          {form.errors.body ? <p role="alert" className="mt-1 text-sm text-fat">{form.errors.body}</p> : null}
          <div className="mt-2 flex items-center justify-between"><Button type="submit" disabled={form.processing || !form.data.body.trim()}>Add note</Button><span className="text-xs text-muted">{form.data.body.length}/600</span></div>
        </form>
        {e.notes.length ? <ul className="mt-2 divide-y divide-raised">{e.notes.map((n) => <NoteItem key={n.id} n={n} />)}</ul> : <p className="mt-3 text-sm text-muted">No notes yet.</p>}
      </section>

      {e.principles.length ? (
        <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="From your reading">
          <h2 className="font-semibold">From your reading</h2>
          <p className="mt-1 text-xs text-muted">Your own principle cards that mention this lift or its muscle. Numbers in them are the author’s, not your targets.</p>
          <ul className="mt-2 space-y-3 text-sm">{e.principles.map((c) => <li key={c.id}><p className="font-medium">{c.title}</p><p className="text-muted">{c.body}</p><p className="text-xs text-muted">{c.source}</p></li>)}</ul>
        </section>
      ) : null}

      {e.recent.length ? (
        <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Recent sessions">
          <h2 className="font-semibold">Recent sessions</h2>
          <ul className="mt-2 space-y-2 text-sm">
            {e.recent.map((s) => (
              <li key={s.date}>
                <span className="font-medium">{pretty(s.date)}{s.deload ? ' (deload)' : ''}</span>
                <span className="block text-muted">{s.sets.map((x, i) => <span key={i}>{i ? ', ' : ''}{x.type === 'warmup' ? 'warm-up ' : ''}{x.kg ?? '-'}×{x.reps ?? '-'}{x.type === 'failure' ? ' (failure)' : x.rpe ? ` @${x.rpe}` : ''}</span>)}</span>
              </li>
            ))}
          </ul>
        </section>
      ) : null}
    </AppLayout>
  )
}
