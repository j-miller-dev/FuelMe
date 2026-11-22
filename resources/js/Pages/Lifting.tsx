import { useState } from 'react'
import { Head, Link, router, useForm } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'

interface Beat { kg: number; reps: number; label: string }
interface Ex { id: string; title: string; muscle: string | null; status: 'not_enough_data' | 'progressing' | 'holding' | 'stalled' | 'down'; sessions: number; best_e1rm: number | null; latest_e1rm: number | null; last_date: string; last_set: { kg: number; reps: number }; trend: number[]; beat_the_log: Beat[] }
interface Recent { date: string; title: string; minutes: number | null; exercises: number; working_sets: number; vs_your_best: { index: number | null; beat: number; matched: number; down: number; compared: number } }
interface Data { sessions_in_window: number; exercises: Ex[]; recent: Recent[]; weekly_sets: { this_week: Record<string, number>; last_week: Record<string, number> }; strength_vs_bodyweight: { strength_change_pct: number | null; weight_change_kg: number | null; exercises: number; note: string }; assumptions: string[] }
interface Insight { key: string; title: string; n: number; strength: string; text: string; caveats: string[] }
interface Props { connection: { last_synced_at: string | null; last_error: string | null } | null; data: Data | null; insights: Insight[] }

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

export default function Lifting({ connection, data, insights }: Props) {
  const form = useForm({ api_key: '' })
  const [open, setOpen] = useState<string | null>(null)
  const sets = Object.entries(data?.weekly_sets.this_week ?? {})
  return (
    <AppLayout>
      <Head title="Lifting" />
      <header className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Lifting</h1>
        <Link href="/training" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Training</Link>
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
                    <span className="block text-muted">{r.exercises} exercises, {r.working_sets} working sets{r.minutes ? `, ${r.minutes} min` : ''}{r.vs_your_best.compared ? `. Against your own best: ${r.vs_your_best.beat} beat, ${r.vs_your_best.matched} matched, ${r.vs_your_best.down} below.` : ''}</span>
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
