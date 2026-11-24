import { useState } from 'react'
import { Head, Link, router, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'

interface Beat { kg: number; reps: number; label: string }
interface Ex { id: string; title: string; muscle: string | null; status: 'not_enough_data' | 'progressing' | 'holding' | 'stalled' | 'down'; sessions: number; best_e1rm: number | null; latest_e1rm: number | null; last_date: string; last_set: { kg: number; reps: number }; trend: number[]; beat_the_log: Beat[] }
interface Effort { working: number; failure: number; rpe_logged: number; unlabelled: number; avg_rpe: number | null }
interface Recent { date: string; title: string; minutes: number | null; exercises: number; working_sets: number; effort: Effort; vs_plan: { verdict: 'harder' | 'easier' | 'in_range' | 'unknown'; avg_rir: number | null; planned: string } | null; vs_your_best: { index: number | null; beat: number; matched: number; down: number; compared: number }; deload?: boolean }
interface Data { deload_now?: boolean; sessions_in_window: number; exercises: Ex[]; recent: Recent[]; weekly_sets: { this_week: Record<string, number>; last_week: Record<string, number> }; strength_vs_bodyweight: { strength_change_pct: number | null; weight_change_kg: number | null; exercises: number; note: string }; assumptions: string[] }
interface Insight { key: string; title: string; n: number; strength: string; text: string; caveats: string[] }
interface Ramp { start: string; weeks: [number, number][] }
interface RampNow { week: number; of: number; min: number; max: number; label: string }
interface Props { ramp: Ramp | null; rampNow: RampNow | null; today: string; connection: { last_synced_at: string | null; last_error: string | null } | null; data: Data | null; insights: Insight[] }

const STATUS: Record<Ex['status'], { label: string; cls: string }> = {
  progressing: { label: 'Progressing', cls: 'text-accent' }, holding: { label: 'Holding', cls: 'text-muted' }, stalled: { label: 'Stalled', cls: 'text-carbs' },
  down: { label: 'Down on your best', cls: 'text-fat' }, not_enough_data: { label: 'Not enough data yet', cls: 'text-muted' },
}
const pretty = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', timeZone: 'UTC' })

function Spark({ v }: { v: number[] }) {
  if (v.length < 2) return null
  const min = Math.min(...v), max = Math.max(...v), span = Math.max(1, max - min)
  const pts = v.map((y, i) => `${(i / (v.length - 1)) * 60},${22 - ((y - min) / span) * 20}`).join(' ')
  return <svg viewBox="0 0 60 24" className="h-6 w-16" aria-hidden><polyline points={pts} fill="none" stroke="currentColor" strokeWidth="2" /></svg>
}

const VERDICT: Record<string, string> = { harder: 'harder than planned', easier: 'easier than planned', in_range: 'in your planned range', unknown: 'no effort logged to compare' }

function RampCard({ ramp, now, today }: { ramp: Ramp | null; now: RampNow | null; today: string }) {
  const monday = (() => { const d = new Date(`${today}T12:00:00Z`); d.setUTCDate(d.getUTCDate() - ((d.getUTCDay() + 6) % 7)); return d.toISOString().slice(0, 10) })()
  const [weeks, setWeeks] = useState<[number, number][]>(ramp?.weeks ?? [[2, 3], [1, 2], [0, 1]])
  const [start, setStart] = useState(ramp?.start ?? monday)
  const set = (i: number, k: 0 | 1, v: number) => setWeeks((w) => w.map((x, j) => (j === i ? (k === 0 ? [v, Math.max(v, x[1])] : [Math.min(x[0], v), v]) : x)) as [number, number][])
  return (
    <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Effort plan">
      <h2 className="font-semibold">Effort plan</h2>
      <p className="mt-1 text-xs text-muted">Your own plan for reps in reserve (0 = failure) across lifting weeks. The cycle repeats after the last week. FUEL compares your logged sets with it; it never changes your lifting.</p>
      {now ? <p className="mt-2 text-sm">This week: {now.label} (week {now.week} of {now.of}).</p> : ramp ? <p className="mt-2 text-sm text-muted">Starts on {ramp.start}.</p> : null}
      <label className="mt-3 block text-xs text-muted">Week 1 starts<input type="date" value={start} onChange={(e) => setStart(e.target.value)} className="mt-1 block min-h-11 rounded-xl bg-canvas/60 px-3 text-sm text-fg" /></label>
      <ul className="mt-2 space-y-2">
        {weeks.map((w, i) => (
          <li key={i} className="flex items-center gap-2 text-sm">
            <span className="w-16">Week {i + 1}</span>
            <select aria-label={`Week ${i + 1} fewest reps in reserve`} value={w[0]} onChange={(e) => set(i, 0, Number(e.target.value))} className="min-h-11 rounded-xl bg-canvas/60 px-2">{[0, 1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select>
            <span>to</span>
            <select aria-label={`Week ${i + 1} most reps in reserve`} value={w[1]} onChange={(e) => set(i, 1, Number(e.target.value))} className="min-h-11 rounded-xl bg-canvas/60 px-2">{[0, 1, 2, 3, 4, 5].map((n) => <option key={n} value={n}>{n}</option>)}</select>
            {weeks.length > 1 ? <button type="button" aria-label={`Remove week ${i + 1}`} className="size-11 text-muted" onClick={() => setWeeks((x) => x.filter((_, j) => j !== i))}>✕</button> : null}
          </li>
        ))}
      </ul>
      <div className="mt-2 flex flex-wrap gap-2">
        {weeks.length < 8 ? <button type="button" className="min-h-11 rounded-full bg-raised px-4 text-sm" onClick={() => setWeeks((w) => [...w, [0, 1]])}>Add a week</button> : null}
        <Button type="button" onClick={() => router.put('/lifting/effort-ramp', { ramp: { start, weeks } }, { preserveScroll: true })}>Save plan</Button>
        {ramp ? <button type="button" className="min-h-11 px-3 text-sm text-muted underline" onClick={() => router.delete('/lifting/effort-ramp', { preserveScroll: true })}>Clear</button> : null}
      </div>
      <p className="mt-2 text-xs text-muted">In Hevy, tag your true failure set as failure, and give the other top sets an RPE (RPE 8 = 2 in reserve, 7 = 3). Sets with no effort logged are left out of the comparison.</p>
    </section>
  )
}

export default function Lifting({ connection, data, insights, ramp, rampNow, today }: Props) {
  const form = useForm({ api_key: '' })
  const [open, setOpen] = useState<string | null>(null)
  const sets = Object.entries(data?.weekly_sets.this_week ?? {})
  return (
    <AppLayout>
      <Head title="Lifting" />
      <header className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Lifting</h1>
        <span className="flex gap-1"><Link href="/lifting/routines" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Routines</Link><Link href="/lifting/best" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Best lifts</Link><Link href="/training" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Training</Link></span>
      </header>
      <p className="mt-1 text-sm text-muted">Your Hevy workouts, read-only. FUEL never changes anything in Hevy. Strength numbers are estimates, not measurements of muscle.</p>

      {!connection ? (
        <form className="mt-4 rounded-2xl bg-surface p-4" onSubmit={(e) => { e.preventDefault(); form.post('/lifting/connect', { preserveScroll: true, onSuccess: () => form.reset() }) }}>
          <h2 className="font-semibold">Connect Hevy</h2>
          <p className="mt-1 text-xs text-muted">Needs Hevy Pro. In Hevy: Settings, then Developer, then generate an API key. It is stored encrypted on your server and never shown again.</p>
          <label className="sr-only" htmlFor="hevy-key">Hevy API key</label>
          <input id="hevy-key" type="password" autoComplete="off" value={form.data.api_key} onChange={(e) => form.setData('api_key', e.target.value)} className="mt-3 min-h-11 w-full rounded-xl bg-canvas/60 px-3 text-sm" placeholder="Paste your API key" />
          {form.errors.api_key ? <p role="alert" className="mt-2 text-sm text-fat">{form.errors.api_key}</p> : null}
          <Button type="submit" className="mt-3" disabled={form.processing || form.data.api_key.length < 10}>Connect</Button>
        </form>
      ) : (
        <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="Sync">
          <p className="text-sm">{connection.last_synced_at ? `Last synced ${new Date(connection.last_synced_at).toLocaleString('en-AU', { dateStyle: 'medium', timeStyle: 'short' })}.` : 'First sync is running. Refresh in a minute.'} It updates by itself every 30 minutes.</p>
          {connection.last_error ? <p role="alert" className="mt-1 text-sm text-fat">{connection.last_error}</p> : null}
          <div className="mt-2 flex flex-wrap gap-2">
            <Button type="button" onClick={() => router.post('/lifting/sync', {}, { preserveScroll: true })}>Sync now</Button>
            <button type="button" className="min-h-11 px-3 text-sm text-muted underline" onClick={() => { if (confirm('Disconnect Hevy? Your synced history stays unless you delete it too.')) router.delete('/lifting/connection', { preserveScroll: true }) }}>Disconnect</button>
            <button type="button" className="min-h-11 px-3 text-sm text-muted underline" onClick={() => { if (confirm('Disconnect and delete all synced Hevy history from FUEL?')) router.delete('/lifting/connection?delete_data=1', { preserveScroll: true }) }}>Disconnect and delete data</button>
          </div>
        </section>
      )}

      {data?.deload_now ? <p className="mt-4 rounded-2xl bg-surface p-3 text-sm">This week is labelled a deload. Its sessions are left out of stall checks and best-set comparisons. <Link href="/recovery" className="text-accent underline">Change</Link></p> : null}

      <RampCard key={ramp ? JSON.stringify(ramp) : 'none'} ramp={ramp} now={rampNow} today={today} />

      {data && data.exercises.length === 0 ? <p className="mt-4 text-sm text-muted">No workouts yet. They appear here once the first sync finishes.</p> : null}

      {data && data.exercises.length > 0 ? (
        <>
          <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="Exercises">
            <h2 className="font-semibold">Your lifts</h2>
            <ul className="mt-2 divide-y divide-raised">
              {data.exercises.map((e) => (
                <li key={e.id} className="py-3">
                  <button type="button" className="flex min-h-11 w-full items-center justify-between gap-2 text-left" aria-expanded={open === e.id} onClick={() => setOpen(open === e.id ? null : e.id)}>
                    <span><span className="block font-medium">{e.title}</span><span className={`text-xs ${STATUS[e.status].cls}`}>{STATUS[e.status].label}</span></span>
                    <span className={`flex items-center gap-2 ${STATUS[e.status].cls}`}><Spark v={e.trend} /><span className="text-sm text-fg">{e.latest_e1rm ? `${e.latest_e1rm} kg` : ''}</span></span>
                  </button>
                  {open === e.id ? (
                    <div className="mt-1 text-sm text-muted">
                      <p>Last time ({pretty(e.last_date)}): {e.last_set.kg} kg × {e.last_set.reps}. Estimated 1RM now {e.latest_e1rm} kg, best {e.best_e1rm} kg ({e.sessions} sessions).</p>
                      <p className="mt-1 font-medium text-fg">Beat the log next time:</p>
                      <ul className="mt-1 space-y-1">{e.beat_the_log.map((b) => <li key={b.label}>{b.kg} kg × {b.reps}: {b.label.toLowerCase()}</li>)}</ul>
                      <Link href={`/lifting/exercise/${encodeURIComponent(e.id)}`} className="mt-2 inline-block min-h-11 text-accent underline leading-[2.75rem]">Records and notes</Link>
                    </div>
                  ) : null}
                </li>
              ))}
            </ul>
          </section>

          {data.recent.length ? (
            <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Recent sessions">
              <h2 className="font-semibold">Recent sessions</h2>
              <ul className="mt-2 space-y-2 text-sm">
                {data.recent.map((r) => (
                  <li key={r.date + r.title}>
                    <span className="font-medium">{pretty(r.date)}: {r.title}</span>
                    <span className="block text-muted">{r.exercises} exercises, {r.working_sets} working sets{r.minutes ? `, ${r.minutes} min` : ''}. {r.effort.failure ? `${r.effort.failure} to failure` : 'None tagged failure'}{r.effort.avg_rpe !== null ? `, average RPE ${r.effort.avg_rpe}` : ''}{r.effort.unlabelled ? `, ${r.effort.unlabelled} without effort info` : ''}{r.deload ? '. Deload week: not compared' : ''}{r.vs_plan && r.vs_plan.verdict !== 'unknown' ? `. Against your plan (${r.vs_plan.planned}): ${VERDICT[r.vs_plan.verdict]}, average ${r.vs_plan.avg_rir} in reserve` : ''}{r.vs_your_best.compared ? `. Against your own best: ${r.vs_your_best.beat} beat, ${r.vs_your_best.matched} matched, ${r.vs_your_best.down} below.` : ''}</span>
                  </li>
                ))}
              </ul>
            </section>
          ) : null}

          <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Weekly sets">
            <h2 className="font-semibold">Hard sets this week</h2>
            {sets.length ? <ul className="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">{sets.map(([m, n]) => <li key={m} className="flex justify-between"><span className="capitalize">{m.replace(/_/g, ' ')}</span><span>{n}</span></li>)}</ul> : <p className="mt-1 text-sm text-muted">Nothing logged this week yet.</p>}
            <p className="mt-2 text-xs text-muted">Working sets only (warm-ups left out). A secondary muscle counts half, a rule of thumb. FUEL does not judge your volume: HIT and higher-volume plans differ on purpose.</p>
          </section>

          <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Strength and weight">
            <h2 className="font-semibold">Strength and bodyweight</h2>
            <p className="mt-1 text-sm">{data.strength_vs_bodyweight.strength_change_pct !== null ? `Typical lift ${data.strength_vs_bodyweight.strength_change_pct > 0 ? 'up' : 'down'} ${Math.abs(data.strength_vs_bodyweight.strength_change_pct)}% (across ${data.strength_vs_bodyweight.exercises} lifts)` : 'Not enough sessions on one lift yet (needs 4).'}{data.strength_vs_bodyweight.weight_change_kg !== null ? `; weight ${data.strength_vs_bodyweight.weight_change_kg > 0 ? '+' : ''}${data.strength_vs_bodyweight.weight_change_kg} kg over the same time.` : '.'}</p>
            <p className="mt-1 text-xs text-muted">{data.strength_vs_bodyweight.note}</p>
          </section>

          {insights.length ? (
            <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Patterns">
              <h2 className="font-semibold">Patterns</h2>
              <ul className="mt-2 space-y-3 text-sm">{insights.map((i) => <li key={i.key}><p>{i.text}</p><p className="text-xs text-muted">{i.caveats.join(' ')}</p></li>)}</ul>
            </section>
          ) : null}
          <p className="mt-3 text-xs text-muted">{data.assumptions.join('. ')}.</p>
        </>
      ) : null}
    </AppLayout>
  )
}
