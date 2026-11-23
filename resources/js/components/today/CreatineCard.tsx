import { router } from '@inertiajs/react'
import { Check, ChevronDown, Pill, Sparkles } from 'lucide-react'
import { useState } from 'react'
import { api, uuid } from '@/lib/http'

export interface CreatinePlan { mode: 'maintenance' | 'loading'; start: string; loading_days: number; maintenance_g: number }
export interface CreatineStatus {
  phase: 'not_started' | 'loading' | 'building' | 'saturated'; targetG: number; serveG: number; todayG: number; dayNumber: number; saturated: boolean; continueG: number
  progress: { done: number; of: number; label: string }; message: string; note: string | null
}
export interface Creatine { takenG: number; lastId: number | null; daysOfLast7: number; daysOfLast28: number; defaultG: number; serveG: number; loadingG: number; plan: CreatinePlan; hasPlan: boolean; status: CreatineStatus }

const g = (n: number) => `${Number.isInteger(n) ? n : n.toFixed(1)} g`

/**
 * One big tap to log creatine. The dose comes from your plan on the server (a 5 g serve while loading, otherwise your daily dose), not from typing.
 * Optional loading phase; when the rough 4-week (or loading) guide says stores should be full, the card tells you your ongoing daily dose.
 * Nothing here measures your muscle stores.
 */
export function CreatineCard({ c, date }: { c: Creatine; date: string }) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(false)
  const [info, setInfo] = useState(false)
  const s = c.status
  const loading = s.phase === 'loading'
  const complete = loading ? c.takenG >= s.targetG * 0.8 : c.takenG > 0
  const reload = () => router.reload({ only: ['creatine'] })
  const run = async (fn: () => Promise<unknown>) => { setBusy(true); setErr(false); try { await fn(); reload() } catch { setErr(true) } finally { setBusy(false); navigator.vibrate?.(12) } }
  const take = () => run(() => api('/supplements', { method: 'POST', body: { name: 'creatine', idempotency_key: uuid(), local_date: date } }))
  const undo = () => (c.lastId === null ? Promise.resolve() : run(() => api(`/supplements/${c.lastId}`, { method: 'DELETE' })))
  const pct = loading && s.targetG ? Math.min(100, Math.round((c.takenG / s.targetG) * 100)) : 0

  return (
    <section className="mt-3 rounded-2xl bg-surface p-3" aria-label="Creatine">
      {s.saturated ? (
        <p className="mb-2 flex items-start gap-2 rounded-xl bg-accent/15 p-3 text-sm text-accent"><Sparkles aria-hidden size={18} className="mt-0.5 shrink-0" /><span><span className="font-semibold">Stores should be full.</span> Continue at {g(s.continueG)} a day.</span></p>
      ) : null}

      {complete ? (
        <div className="flex min-h-14 items-center gap-3">
          <span className="grid size-11 shrink-0 place-items-center rounded-full bg-accent text-on-accent"><Check aria-hidden size={22} strokeWidth={3} /></span>
          <div className="min-w-0 flex-1"><p className="font-semibold">{loading ? `Loading dose done · ${g(c.takenG)}` : `Creatine taken · ${g(c.takenG)}`}</p><p className="text-xs text-muted">{c.daysOfLast7} of the last 7 days</p></div>
          <button type="button" disabled={busy} onClick={undo} className="min-h-11 px-3 text-sm text-accent disabled:opacity-40">Undo</button>
        </div>
      ) : (
        <>
          <button type="button" disabled={busy} onClick={take} className="flex min-h-14 w-full items-center gap-3 rounded-xl text-left active:scale-[0.99] disabled:opacity-60">
            <span className="grid size-11 shrink-0 place-items-center rounded-full bg-accent/15 text-accent"><Pill aria-hidden size={22} /></span>
            <span className="min-w-0 flex-1">
              <span className="block font-semibold">{loading ? `Loading · log ${g(s.serveG)}` : `Log creatine · ${g(s.serveG)}`}</span>
              <span className="block text-xs text-muted">{loading ? `${g(c.takenG)} of about ${g(s.targetG)} today · day ${s.dayNumber}` : `Tap once when you have had it · ${c.daysOfLast7} of the last 7 days`}</span>
            </span>
            <span className="rounded-full bg-accent px-4 py-2 text-sm font-semibold text-on-accent">Take</span>
          </button>
          {loading ? <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-raised" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Today's loading dose"><div className="h-full rounded-full bg-accent" style={{ width: `${pct}%` }} /></div> : null}
          {loading && c.lastId !== null ? <button type="button" disabled={busy} onClick={undo} className="mt-1 min-h-11 px-1 text-xs text-accent disabled:opacity-40">Undo last serve</button> : null}
        </>
      )}

      <button type="button" onClick={() => setInfo(!info)} aria-expanded={info} className="flex min-h-11 w-full items-center justify-between px-1 text-xs text-muted">
        <span>{s.phase === 'loading' ? `Loading ${s.progress.done} of ${s.progress.of} days` : `${s.progress.done} of ${s.progress.of} days`} · plan and saturation</span><ChevronDown aria-hidden size={16} className={info ? 'rotate-180' : ''} />
      </button>
      {info ? <PlanPanel c={c} onSaved={reload} /> : null}
      {err ? <p role="alert" className="mt-1 text-xs text-danger">Could not save. Check your connection and tap again.</p> : null}
    </section>
  )
}

function PlanPanel({ c, onSaved }: { c: Creatine; onSaved: () => void }) {
  const [mode, setMode] = useState<CreatinePlan['mode']>(c.plan.mode)
  const [days, setDays] = useState(c.plan.loading_days)
  const [dose, setDose] = useState(c.plan.maintenance_g)
  const [start, setStart] = useState(c.plan.start)
  const [busy, setBusy] = useState(false)
  const [msg, setMsg] = useState<string | null>(null)
  const s = c.status
  const call = async (fn: () => Promise<unknown>) => { setBusy(true); setMsg(null); try { await fn(); onSaved() } catch { setMsg('Could not save that plan.') } finally { setBusy(false) } }
  const save = () => call(() => api('/supplements/creatine-plan', { method: 'PUT', body: { plan: { mode, start, loading_days: days, maintenance_g: dose } } }))
  const reset = () => call(() => api('/supplements/creatine-plan', { method: 'DELETE' }))
  const seg = (on: boolean) => `min-h-11 flex-1 rounded-xl px-3 text-sm font-medium ${on ? 'bg-accent/15 text-accent' : 'bg-surface text-muted'}`

  return (
    <div className="space-y-3 rounded-xl bg-raised p-3 text-xs leading-relaxed text-muted">
      <p className="text-sm text-ink">{s.message}</p>
      {s.note ? <p>{s.note}</p> : null}
      <p>Two ways to get stores full. <span className="text-ink">No loading:</span> 3 to 5 g every day for about four weeks. <span className="text-ink">Loading:</span> about 0.3 g per kg a day (about {c.loadingG} g for you, split into 5 g serves) for 5 to 7 days, then 3 to 5 g a day. Both end up in the same place; the daily habit is what counts, not the time of day.</p>
      <div className="flex gap-2" role="group" aria-label="Plan type">
        <button type="button" aria-pressed={mode === 'maintenance'} onClick={() => setMode('maintenance')} className={seg(mode === 'maintenance')}>No loading</button>
        <button type="button" aria-pressed={mode === 'loading'} onClick={() => setMode('loading')} className={seg(mode === 'loading')}>Load first</button>
      </div>
      {mode === 'loading' ? (
        <label className="flex items-center justify-between text-sm text-ink">Loading days
          <select value={days} onChange={(e) => setDays(Number(e.target.value))} className="min-h-11 rounded-xl bg-surface px-3">{[5, 6, 7].map((n) => <option key={n} value={n}>{n}</option>)}</select>
        </label>
      ) : null}
      <label className="flex items-center justify-between text-sm text-ink">Daily dose after
        <select value={dose} onChange={(e) => setDose(Number(e.target.value))} className="min-h-11 rounded-xl bg-surface px-3">{[3, 3.5, 4, 4.5, 5].map((n) => <option key={n} value={n}>{n} g</option>)}</select>
      </label>
      <label className="flex items-center justify-between text-sm text-ink">Started on
        <input type="date" value={start} onChange={(e) => setStart(e.target.value)} className="min-h-11 rounded-xl bg-surface px-3 text-base" />
      </label>
      <p>Already taking it daily? Set the date you started and the 28 days count from there.</p>
      <div className="flex items-center gap-3">
        <button type="button" disabled={busy || !start} onClick={save} className="min-h-11 rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent disabled:opacity-40">Save plan</button>
        {c.hasPlan ? <button type="button" disabled={busy} onClick={reset} className="min-h-11 px-2 text-sm text-accent underline disabled:opacity-40">Reset</button> : null}
      </div>
      {msg ? <p role="alert" className="text-danger">{msg}</p> : null}
      <p>FUEL cannot measure your stores; this is a rough guide for healthy adults. Check with your GP first if you have kidney problems or take medication.</p>
    </div>
  )
}
