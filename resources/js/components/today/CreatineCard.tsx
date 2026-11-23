import { router } from '@inertiajs/react'
import { Check, Pill } from 'lucide-react'
import { useState } from 'react'
import { api, uuid } from '@/lib/http'

export interface Creatine { takenG: number; lastId: number | null; daysOfLast7: number; defaultG: number }

const g = (n: number) => `${Number.isInteger(n) ? n : n.toFixed(1)} g`

/** One big tap to log the daily creatine dose. After it is taken the card is calm and shows an Undo. The dose is set on the server (5 g), not typed. */
export function CreatineCard({ c, date }: { c: Creatine; date: string }) {
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState(false)
  const taken = c.takenG > 0
  const reload = () => router.reload({ only: ['creatine'] })
  const run = async (fn: () => Promise<unknown>) => { setBusy(true); setErr(false); try { await fn(); reload() } catch { setErr(true) } finally { setBusy(false); navigator.vibrate?.(taken ? 5 : 15) } }
  const take = () => run(() => api('/supplements', { method: 'POST', body: { name: 'creatine', idempotency_key: uuid(), local_date: date } }))
  const undo = () => (c.lastId === null ? Promise.resolve() : run(() => api(`/supplements/${c.lastId}`, { method: 'DELETE' })))

  return (
    <section className="mt-3 rounded-2xl bg-surface p-3" aria-label="Creatine">
      {taken ? (
        <div className="flex min-h-14 items-center gap-3">
          <span className="grid size-11 shrink-0 place-items-center rounded-full bg-accent text-on-accent"><Check aria-hidden size={22} strokeWidth={3} /></span>
          <div className="min-w-0 flex-1"><p className="font-semibold">Creatine taken · {g(c.takenG)}</p><p className="text-xs text-muted">{c.daysOfLast7} of the last 7 days</p></div>
          <button type="button" disabled={busy} onClick={undo} className="min-h-11 px-3 text-sm text-accent disabled:opacity-40">Undo</button>
        </div>
      ) : (
        <button type="button" disabled={busy} onClick={take} className="flex min-h-14 w-full items-center gap-3 rounded-xl text-left active:scale-[0.99] disabled:opacity-60">
          <span className="grid size-11 shrink-0 place-items-center rounded-full bg-accent/15 text-accent"><Pill aria-hidden size={22} /></span>
          <span className="min-w-0 flex-1"><span className="block font-semibold">Log creatine · {g(c.defaultG)}</span><span className="block text-xs text-muted">Tap once when you have had it · {c.daysOfLast7} of the last 7 days</span></span>
          <span className="rounded-full bg-accent px-4 py-2 text-sm font-semibold text-on-accent">Take</span>
        </button>
      )}
      {err ? <p role="alert" className="mt-1 text-xs text-danger">Could not save. Check your connection and tap again.</p> : null}
    </section>
  )
}
