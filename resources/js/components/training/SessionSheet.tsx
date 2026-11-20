import { useState } from 'react'
import { api, uuid } from '@/lib/http'
import { INTENSITY_LABEL, type Catalogue, type Session, type TemplateRow } from '@/lib/training'
import { Sheet } from '@/components/today/Sheet'

type Mode = { kind: 'template'; day: string; row?: TemplateRow } | { kind: 'log'; date: string; row?: Session }

/** Add or edit one session: either a slot in your usual week, or something that really happened on a date. */
export function SessionSheet({ mode, catalogue, kg, onClose, onSaved }: { mode: Mode; catalogue: Catalogue; kg: number; onClose: () => void; onSaved: () => void }) {
  const ex = mode.row
  const [type, setType] = useState(ex?.type ?? 'bjj')
  const def = catalogue[type]
  const [intensity, setIntensity] = useState(ex?.intensity ?? 'standard')
  const [minutes, setMinutes] = useState(String(ex?.minutes ?? catalogue[ex?.type ?? 'bjj']?.minutes ?? 60))
  const [start, setStart] = useState((mode.kind === 'template' ? (ex as TemplateRow | undefined)?.start : (ex as Session | undefined)?.start) ?? '')
  const [met, setMet] = useState(ex && 'customMet' in ex && ex.customMet ? String(ex.customMet) : ex && 'met' in ex && ex.type === 'custom' ? String(ex.met) : '')
  const [name, setName] = useState(ex?.name ?? '')
  const [kcal, setKcal] = useState(mode.kind === 'log' && (ex as Session | undefined)?.override ? String((ex as Session).override) : '')
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const key = useState(() => uuid())[0]

  const mins = Number(minutes)
  const metN = type === 'custom' ? Number(met.replace(',', '.')) : (def?.intensities[intensity]?.met ?? 0)
  const est = Number.isFinite(mins) && metN > 1 ? Math.max(0, (metN - 1) * kg * mins / 60) : 0
  const valid = mins >= 5 && mins <= 600 && (type !== 'custom' || (metN >= 1.5 && name.trim().length > 0))

  const pickType = (t: string) => {
    setType(t); setMinutes(String(catalogue[t]?.minutes ?? 45))
    setIntensity(Object.keys(catalogue[t]?.intensities ?? {})[0] ?? 'standard')
    // Keep the intensity people actually use: standard for BJJ, HIT for lifting is the default style.
    if (t === 'bjj') setIntensity('standard'); if (t === 'lifting') setIntensity('hit')
  }

  async function save() {
    setBusy(true); setErr(null)
    const body: Record<string, unknown> = { type, intensity: type === 'custom' ? 'custom' : intensity, minutes: mins, start_time: start || null, name: type === 'custom' ? name.trim() : null, custom_met: type === 'custom' ? metN : null }
    try {
      if (mode.kind === 'template') {
        body.day = mode.day
        await api(ex ? `/training/template/${ex.id}` : '/training/template', { method: ex ? 'PUT' : 'POST', body })
      } else if (ex) {
        await api(`/workouts/${(ex as Session).id}`, { method: 'PATCH', body: { minutes: mins, intensity: body.intensity, start_time: start || null, kcal_override: kcal === '' ? null : Number(kcal.replace(',', '.')) } })
      } else {
        await api('/workouts', { method: 'POST', body: { ...body, local_date: mode.date, kcal_override: kcal === '' ? null : Number(kcal.replace(',', '.')), idempotency_key: key } })
      }
      onSaved()
    } catch (e) { setErr(e instanceof Error ? e.message : 'Could not save.'); setBusy(false) }
  }

  const field = 'mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-ink outline-none focus:ring-2 focus:ring-accent'
  const editingLogType = mode.kind === 'log' && !!ex   // a logged session keeps its activity; change the numbers, or delete and re-add
  return (
    <Sheet label={ex ? 'Edit session' : 'Add a session'} onClose={onClose} tall z={60}>
      <div className="flex items-center gap-2 px-4 pt-4"><h2 className="flex-1 text-lg font-semibold">{ex ? 'Edit session' : mode.kind === 'template' ? 'Add to your week' : 'Log a session'}</h2><button onClick={onClose} aria-label="Close" className="size-11 rounded-full bg-surface">✕</button></div>
      <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-4 pt-2">
        <div className="flex flex-wrap gap-2" role="group" aria-label="Activity">
          {Object.entries(catalogue).map(([t, c]) => (
            <button key={t} disabled={editingLogType && t !== type} aria-pressed={type === t} onClick={() => pickType(t)}
              className={`min-h-10 rounded-full px-3 text-sm disabled:opacity-40 ${type === t ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-surface text-muted'}`}>{c.label}</button>
          ))}
        </div>
        {type === 'custom' ? (
          <div className="mt-3 grid grid-cols-2 gap-2">
            <label className="col-span-2 text-xs text-muted">What is it called?<input value={name} onChange={(e) => setName(e.target.value)} placeholder="Rock climbing" className={field} /></label>
            <label className="col-span-2 text-xs text-muted">Effort (MET)<input inputMode="decimal" value={met} onChange={(e) => setMet(e.target.value)} placeholder="6.5" className={field + ' tabular-nums'} /></label>
            <p className="col-span-2 -mt-1 text-xs text-muted">MET is effort relative to rest. Easy walking is about 3.5, a hard run about 10, easy cycling about 6.</p>
          </div>
        ) : (
          <div className="mt-3 flex flex-wrap gap-2" role="group" aria-label="Intensity">
            {Object.keys(def?.intensities ?? {}).map((i) => (
              <button key={i} aria-pressed={intensity === i} onClick={() => setIntensity(i)} className={`min-h-10 rounded-full px-3 text-sm ${intensity === i ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-surface text-muted'}`}>{INTENSITY_LABEL[i] ?? i}</button>
            ))}
          </div>
        )}
        {type !== 'custom' && def?.intensities[intensity] ? <p className="mt-2 text-xs text-muted">{def.intensities[intensity]!.src}</p> : null}
        <div className="mt-3 grid grid-cols-2 gap-2">
          <label className="text-xs text-muted">Minutes<input inputMode="numeric" value={minutes} onChange={(e) => setMinutes(e.target.value)} className={field + ' tabular-nums'} /></label>
          <label className="text-xs text-muted">Starts at (optional)<input type="time" value={start} onChange={(e) => setStart(e.target.value)} className={field + ' tabular-nums'} /></label>
        </div>
        {mode.kind === 'log' ? (
          <label className="mt-3 block text-xs text-muted">Calories from your watch (optional)
            <input inputMode="decimal" value={kcal} onChange={(e) => setKcal(e.target.value)} placeholder="Leave blank to use the estimate" className={field + ' tabular-nums'} />
          </label>
        ) : null}
        <p className="mt-3 rounded-xl bg-surface p-3 text-sm" aria-live="polite">
          {est > 0 ? <>About <span className="font-semibold tabular-nums">{Math.round(est)} kcal</span> above resting ({Math.round(est * 0.75)}–{Math.round(est * 1.25)}) at {kg} kg. </> : null}
          <span className="text-muted">This is an estimate, good to about ±25%. It shapes your day type and fuelling, and is never added to your food target: your expenditure already includes your usual training.</span>
        </p>
        {err ? <p role="alert" className="mt-2 text-sm text-danger">{err}</p> : null}
      </div>
      <div className="px-4 pb-4 pt-2"><button disabled={!valid || busy} onClick={() => void save()} className="min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">{busy ? 'Saving…' : ex ? 'Save changes' : mode.kind === 'template' ? 'Add to my week' : 'Log it'}</button></div>
    </Sheet>
  )
}
