import { router } from '@inertiajs/react'
import { Droplets } from 'lucide-react'
import { useState } from 'react'
import { api, uuid } from '@/lib/http'

export interface Water { ml: number; targetMl: number; lastId: number | null }

const L = (ml: number) => `${(ml / 1000).toFixed(ml % 100 === 0 ? 1 : 2)} L`

/** One-tap fluid log, plus the weigh-before-and-after sweat check. The daily number is the NHMRC adequate intake for fluids (all drinks), a guide not a goal to force. */
export function WaterCard({ water, date }: { water: Water; date: string }) {
  const [busy, setBusy] = useState(false)
  const [check, setCheck] = useState(false)
  const [before, setBefore] = useState('')
  const [after, setAfter] = useState('')
  const reload = () => router.reload({ only: ['water'] })
  const add = async (ml: number) => { setBusy(true); try { await api('/water', { method: 'POST', body: { ml, idempotency_key: uuid(), local_date: date } }); reload() } finally { setBusy(false) } }
  const undo = async () => { if (water.lastId === null) return; setBusy(true); try { await api(`/water/${water.lastId}`, { method: 'DELETE' }); reload() } finally { setBusy(false) } }

  const loss = Number(before.replace(',', '.')) - Number(after.replace(',', '.'))
  const valid = Number(before) > 0 && Number(after) > 0 && loss > 0 && loss < 5
  const pct = Math.min(100, Math.round((water.ml / water.targetMl) * 100))

  return (
    <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Fluids">
      <div className="flex items-baseline justify-between">
        <h2 className="flex items-center gap-2 font-semibold"><Droplets aria-hidden size={18} className="text-protein" />Fluids</h2>
        <span className="text-sm tabular-nums"><span className="font-semibold">{L(water.ml)}</span><span className="text-muted"> of about {L(water.targetMl)}</span></span>
      </div>
      <div className="mt-2 h-2 overflow-hidden rounded-full bg-raised" role="progressbar" aria-valuenow={pct} aria-valuemin={0} aria-valuemax={100} aria-label="Fluids today"><div className="h-full rounded-full bg-accent" style={{ width: `${pct}%` }} /></div>
      <div className="mt-3 grid grid-cols-4 gap-2">
        {[250, 500, 750].map((ml) => <button key={ml} type="button" disabled={busy} onClick={() => add(ml)} className="min-h-11 rounded-xl bg-raised text-sm font-medium disabled:opacity-40">+{ml}</button>)}
        <button type="button" disabled={busy || water.lastId === null} onClick={undo} className="min-h-11 rounded-xl text-sm text-accent disabled:opacity-30">Undo</button>
      </div>
      <p className="mt-2 text-xs text-muted">Water, tea, coffee and milk all count. About {L(water.targetMl)} is the NHMRC adequate intake for fluids. Thirsty or training hard or in the heat, drink more.</p>
      <button type="button" onClick={() => setCheck(!check)} aria-expanded={check} className="mt-1 min-h-11 text-sm text-accent underline">Sweat check after a session</button>
      {check ? (
        <div className="rounded-xl bg-raised p-3 text-sm">
          <p className="text-xs text-muted">Weigh yourself (after the toilet, before drinking) before and after. 1 kg lost is about 1 L of fluid.</p>
          <div className="mt-2 grid grid-cols-2 gap-2">
            <label className="text-xs text-muted">Before (kg)<input inputMode="decimal" value={before} onChange={(e) => setBefore(e.target.value)} className="mt-1 min-h-11 w-full rounded-xl bg-surface px-3 text-base tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" /></label>
            <label className="text-xs text-muted">After (kg)<input inputMode="decimal" value={after} onChange={(e) => setAfter(e.target.value)} className="mt-1 min-h-11 w-full rounded-xl bg-surface px-3 text-base tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" /></label>
          </div>
          {valid ? <p className="mt-2">You lost about <span className="font-semibold">{loss.toFixed(1)} kg</span>. Drink roughly <span className="font-semibold">{(loss * 1.25).toFixed(1)} to {(loss * 1.5).toFixed(1)} L</span> over the next few hours, with your meal.{loss / Number(before) > 0.02 ? ' That is over 2% of your body weight, so drink steadily and see a professional if you feel unwell.' : ''}</p> : <p className="mt-2 text-xs text-muted">Enter both weights to see how much to replace (125 to 150% of the loss).</p>}
        </div>
      ) : null}
    </section>
  )
}
