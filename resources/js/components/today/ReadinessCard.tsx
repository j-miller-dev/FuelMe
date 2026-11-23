import { router } from '@inertiajs/react'
import { Sun } from 'lucide-react'
import { useState } from 'react'
import { api } from '@/lib/http'

export interface Readiness { today: { sleep: number; soreness: number; energy: number; notes: string | null } | null; hook: { days: number; message: string; options: string[] } | null; watch?: { sleepMin: number | null; restingHr: number | null; hrv: number | null; steps: number | null } | null }

const hm = (min: number) => `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')} min`

const ROWS = [
  { k: 'sleep', label: 'Sleep', lo: 'Poor', hi: 'Great' },
  { k: 'soreness', label: 'Soreness', lo: 'None', hi: 'Very sore' },
  { k: 'energy', label: 'Energy', lo: 'Flat', hi: 'Great' },
] as const
type K = (typeof ROWS)[number]['k']

const skipKey = (date: string) => `fuel.readiness.skip.${date}`
const skipped = (date: string) => { try { return localStorage.getItem(skipKey(date)) === '1' } catch { return false } }

/** The optional 10-second morning check-in. Skippable, one tap per score, and it never nags: skipping hides it for the day. */
export function ReadinessCard({ r, date }: { r: Readiness; date: string }) {
  const [v, setV] = useState<Partial<Record<K, number>>>({})
  const [gone, setGone] = useState(() => skipped(date))
  const [busy, setBusy] = useState(false)
  const reload = () => router.reload({ only: ['readiness'] })
  const ready = v.sleep && v.soreness && v.energy
  const save = async () => { if (!ready) return; setBusy(true); try { await api('/readiness', { method: 'POST', body: { ...v, local_date: date } }); reload() } finally { setBusy(false) } }
  const skip = () => { try { localStorage.setItem(skipKey(date), '1') } catch { /* light preference only */ } setGone(true) }
  const redo = async () => { setBusy(true); try { await api(`/readiness/${date}`, { method: 'DELETE' }); setGone(false); reload() } finally { setBusy(false) } }

  return (
    <>
      {r.hook ? (
        <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Readiness suggestion">
          <p className="text-sm">{r.hook.message}</p>
          <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted">{r.hook.options.map((o) => <li key={o}>{o}</li>)}</ul>
          <p className="mt-2 text-xs text-muted">Just suggestions based on how you have been feeling. Not medical advice.</p>
        </section>
      ) : null}

      {r.watch ? (
        <p className="mt-3 rounded-2xl bg-surface px-4 py-3 text-sm text-muted" aria-label="From your watch">
          From your watch: {[r.watch.sleepMin !== null ? `slept ${hm(r.watch.sleepMin)}` : null, r.watch.restingHr !== null ? `resting HR ${r.watch.restingHr}` : null, r.watch.hrv !== null ? `HRV ${r.watch.hrv}` : null].filter(Boolean).join(', ') || 'no overnight data yet'}.
        </p>
      ) : null}

      {r.today ? (
        <p className="mt-3 flex items-center justify-between rounded-2xl bg-surface px-4 text-sm"><span className="py-3 text-muted">Checked in: sleep {r.today.sleep}, soreness {r.today.soreness}, energy {r.today.energy} (of 5)</span><button type="button" disabled={busy} onClick={redo} className="min-h-11 px-2 text-accent underline">Redo</button></p>
      ) : gone ? null : (
        <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Morning check-in">
          <div className="flex items-baseline justify-between"><h2 className="flex items-center gap-2 font-semibold"><Sun aria-hidden size={18} className="text-carbs" />Morning check-in</h2><button type="button" onClick={skip} className="min-h-11 px-2 text-sm text-muted underline">Skip today</button></div>
          {ROWS.map((row) => (
            <div key={row.k} className="mt-2">
              <div className="flex justify-between text-xs text-muted"><span>{row.label}</span><span>1 {row.lo} · 5 {row.hi}</span></div>
              <div className="mt-1 grid grid-cols-5 gap-2" role="group" aria-label={row.label}>
                {[1, 2, 3, 4, 5].map((n) => <button key={n} type="button" aria-pressed={v[row.k] === n} onClick={() => setV((s) => ({ ...s, [row.k]: n }))} className={`min-h-11 rounded-xl text-sm font-medium ${v[row.k] === n ? 'bg-accent text-on-accent' : 'bg-raised'}`}>{n}</button>)}
              </div>
            </div>
          ))}
          <button type="button" disabled={!ready || busy} onClick={save} className="mt-3 min-h-11 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">Save check-in</button>
        </section>
      )}
    </>
  )
}
