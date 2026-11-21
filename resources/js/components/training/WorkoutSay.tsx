import { useRef, useState } from 'react'
import { api, uuid } from '@/lib/http'
import { speechCtor, type Recognition } from '@/lib/speech'
import { INTENSITY_LABEL, type Catalogue } from '@/lib/training'
import { Sheet } from '@/components/today/Sheet'

interface ApiItem { text: string; type: string; intensity: string; minutes: number | null; rpe: number | null; start: string | null; name: string | null; approximate: boolean; needs: string[]; local_date: string }
interface Card { id: string; type: string; intensity: string; minutes: string; met: string; name: string; rpe: number | null; start: string | null; local_date: string; text: string; approximate: boolean }

/** Say or type what you trained. The words become editable cards (nothing is saved until you tap Log), energy is an estimate from the activity table, never from the words. */
export function WorkoutSay({ catalogue, kg, onClose, onSaved }: { catalogue: Catalogue; kg: number; onClose: () => void; onSaved: () => void }) {
  const [text, setText] = useState('')
  const [cards, setCards] = useState<Card[] | null>(null)
  const [unparsed, setUnparsed] = useState<string[]>([])
  const [busy, setBusy] = useState(false)
  const [err, setErr] = useState<string | null>(null)
  const [listening, setListening] = useState(false)
  const rec = useRef<Recognition | null>(null)
  const base = useRef('')
  const Speech = speechCtor()

  const listen = () => {
    if (!Speech) return
    if (listening) { rec.current?.stop(); return }
    const r = new Speech()
    r.lang = 'en-AU'; r.interimResults = true; r.continuous = false
    base.current = text ? text.trimEnd() + ' ' : ''
    r.onresult = (e) => { let t = ''; for (let i = 0; i < e.results.length; i++) t += e.results[i]?.[0]?.transcript ?? ''; setText(base.current + t) }
    r.onend = () => setListening(false)
    r.onerror = () => { setListening(false); setErr('Could not hear that. You can type it instead.') }
    rec.current = r; setListening(true); setErr(null); r.start()
  }

  const parse = async () => {
    setBusy(true); setErr(null)
    try {
      const r = await api<{ items: ApiItem[]; unparsed: string[] }>('/workouts/parse', { method: 'POST', body: { text } })
      setUnparsed(r.unparsed)
      setCards(r.items.map((i) => ({ id: uuid(), type: i.type, intensity: i.intensity, minutes: i.minutes ? String(i.minutes) : '', met: '', name: i.name ?? '', rpe: i.rpe, start: i.start, local_date: i.local_date, text: i.text, approximate: i.approximate })))
    } catch (e) { setErr(e instanceof Error ? e.message : 'Could not read that.') } finally { setBusy(false) }
  }

  const patch = (id: string, p: Partial<Card>) => setCards((cs) => (cs ?? []).map((c) => (c.id === id ? { ...c, ...p } : c)))
  const energy = (c: Card) => {
    const mins = Number(c.minutes)
    const met = c.type === 'custom' ? Number(c.met.replace(',', '.')) : (catalogue[c.type]?.intensities[c.intensity]?.met ?? 0)
    return mins >= 5 && met > 1 ? Math.round(Math.max(0, (met - 1) * kg * mins / 60)) : null
  }
  const valid = (c: Card) => Number(c.minutes) >= 5 && Number(c.minutes) <= 600 && (c.type !== 'custom' || (Number(c.met.replace(',', '.')) >= 1.5 && c.name.trim().length > 0))

  const logAll = async () => {
    if (!cards?.length || !cards.every(valid)) return
    setBusy(true); setErr(null)
    try {
      for (const c of cards) {
        await api('/workouts', { method: 'POST', body: { type: c.type, intensity: c.type === 'custom' ? 'custom' : c.intensity, minutes: Number(c.minutes), start_time: c.start, rpe: c.rpe, local_date: c.local_date, idempotency_key: c.id, ...(c.type === 'custom' ? { name: c.name.trim(), custom_met: Number(c.met.replace(',', '.')) } : {}) } })
      }
      onSaved()
    } catch (e) { setErr(e instanceof Error ? e.message : 'Could not save.'); setBusy(false) }
  }

  const field = 'mt-1 min-h-11 w-full rounded-xl bg-raised px-3 text-ink outline-none focus:ring-2 focus:ring-accent'
  return (
    <Sheet label="Say what you trained" onClose={onClose} tall>
      <div className="flex items-center justify-between px-4 pt-4"><h2 className="text-lg font-semibold">Say what you trained</h2><button onClick={onClose} className="min-h-11 px-2 text-muted">Close</button></div>
      <div className="flex-1 overflow-y-auto px-4 pb-4">
        <textarea value={text} onChange={(e) => setText(e.target.value)} rows={3} maxLength={600} placeholder="e.g. rolled five rounds hard, about an hour with drilling, then 40 minutes heavy lifting"
          className="mt-3 w-full rounded-2xl bg-surface p-3 text-ink outline-none focus:ring-2 focus:ring-accent" aria-label="What you trained" />
        <div className="mt-2 flex gap-2">
          {Speech ? <button type="button" onClick={listen} aria-pressed={listening} className={`min-h-11 rounded-xl px-4 text-sm ${listening ? 'bg-accent text-on-accent' : 'bg-raised'}`}>{listening ? 'Listening… tap to stop' : 'Speak'}</button> : null}
          <button type="button" disabled={busy || text.trim().length < 3} onClick={parse} className="min-h-11 flex-1 rounded-xl bg-accent font-semibold text-on-accent disabled:opacity-40">Read it</button>
        </div>
        {err ? <p role="alert" className="mt-2 text-sm text-danger">{err}</p> : null}

        {cards ? (
          <div className="mt-4 space-y-3">
            {cards.length === 0 ? <p className="text-sm text-muted">I could not find a session in that. Try something like "bjj an hour" or "lifting 40 minutes".</p> : null}
            {cards.map((c) => {
              const kc = energy(c)
              return (
                <div key={c.id} className="rounded-2xl bg-surface p-3">
                  <div className="flex items-start justify-between gap-2"><p className="text-xs text-muted">“{c.text}”{c.approximate ? ' (about)' : ''}</p><button onClick={() => setCards((cs) => (cs ?? []).filter((x) => x.id !== c.id))} className="min-h-11 shrink-0 px-2 text-sm text-muted">Remove</button></div>
                  <div className="grid grid-cols-2 gap-2">
                    <label className="text-xs text-muted">Activity
                      <select value={c.type} onChange={(e) => patch(c.id, { type: e.target.value, intensity: Object.keys(catalogue[e.target.value]?.intensities ?? {})[0] ?? 'standard' })} className={field}>
                        {Object.entries(catalogue).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}<option value="custom">Something else</option>
                      </select>
                    </label>
                    {c.type === 'custom' ? (
                      <label className="text-xs text-muted">Effort (MET)<input inputMode="decimal" value={c.met} onChange={(e) => patch(c.id, { met: e.target.value })} placeholder="e.g. 3.5 walk, 10 run" className={field} /></label>
                    ) : (
                      <label className="text-xs text-muted">Intensity
                        <select value={c.intensity} onChange={(e) => patch(c.id, { intensity: e.target.value })} className={field}>{Object.keys(catalogue[c.type]?.intensities ?? {}).map((k) => <option key={k} value={k}>{INTENSITY_LABEL[k] ?? k}</option>)}</select>
                      </label>
                    )}
                    {c.type === 'custom' ? <label className="col-span-2 text-xs text-muted">Name<input value={c.name} onChange={(e) => patch(c.id, { name: e.target.value })} className={field} /></label> : null}
                    <label className="text-xs text-muted">Minutes {c.minutes === '' ? <span className="text-carbs">(not stated)</span> : null}<input inputMode="numeric" value={c.minutes} onChange={(e) => patch(c.id, { minutes: e.target.value.replace(/\D/g, '') })} className={field} /></label>
                    <div className="text-xs text-muted">Energy estimate<div className="mt-1 flex min-h-11 items-center text-ink tabular-nums">{kc === null ? 'Fill in the blanks' : `≈ ${kc} kcal`}</div></div>
                  </div>
                  {c.local_date !== cards[0]?.local_date || c.text.includes('yesterday') ? <p className="mt-1 text-xs text-muted">Date: {c.local_date}</p> : null}
                </div>
              )
            })}
            {unparsed.length ? <p className="text-xs text-muted">Not used: {unparsed.join('; ')}</p> : null}
            <p className="text-xs text-muted">Energy is an estimate from the activity (±25%), and it is never added to your food target.</p>
            {cards.length ? <button type="button" disabled={busy || !cards.every(valid)} onClick={logAll} className="min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">Log {cards.length} session{cards.length === 1 ? '' : 's'}</button> : null}
          </div>
        ) : null}
      </div>
    </Sheet>
  )
}
