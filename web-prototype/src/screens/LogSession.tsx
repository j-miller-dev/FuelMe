import { useState } from 'react'
import { CLASS_LABEL, adjustInfo, applySession, catalogue, getPlan, sessionInfo, todayDow, type Session, type Settings } from '../engine'

const MINUTES = [30, 45, 60, 90]
const TYPES: [string, string][] = [['bjj', 'BJJ'], ['lifting', 'Lifting'], ['hiit', 'HIIT'], ['norwegian_4x4', 'Norwegian 4×4']]
const pill = (on: boolean) => `min-h-11 flex-1 rounded-xl px-2 text-sm capitalize ${on ? 'bg-accent font-semibold text-canvas' : 'bg-raised'}`

export default function LogSession({ settings, current, eatBack, onAdd, onClear, onEatBack, close }:
  { settings: Settings; current: Session | null; eatBack: boolean; onAdd: (s: Session) => void; onClear: () => void; onEatBack: (v: boolean) => void; close: () => void }) {
  const [type, setType] = useState(current?.type ?? 'bjj')
  const [intensity, setIntensity] = useState(current?.intensity ?? 'standard')
  const [minutes, setMinutes] = useState(current?.minutes ?? 60)
  const ints = Object.keys(catalogue[type].intensities)
  const iv = ints.includes(intensity) ? intensity : ints[ints.length > 1 ? 1 : 0]
  const s: Session = { type, intensity: iv, minutes }
  const info = sessionInfo(s), adj = adjustInfo(todayDow(), s, settings.style)
  const b = getPlan(settings).days[todayDow()], a = applySession(b, adj), dk = a.kcal - b.kcal, dc = a.carbs - b.carbs
  return (
    <div className="space-y-3 px-4 pb-32 pt-6">
      <div className="flex items-center justify-between"><h1 className="text-xl font-semibold">Log a session</h1><button className="min-h-11 text-sm text-muted" onClick={close}>Close</button></div>
      <div className="grid grid-cols-2 gap-2">{TYPES.map(([k, l]) => <button key={k} onClick={() => setType(k)} className={pill(type === k)}>{l}</button>)}</div>
      <div className="flex gap-2">{ints.map(i => <button key={i} onClick={() => setIntensity(i)} className={pill(i === iv)}>{i}</button>)}</div>
      <div className="flex gap-2">{MINUTES.map(m => <button key={m} onClick={() => setMinutes(m)} className={pill(m === minutes) + " normal-case"}>{m} min</button>)}</div>

      <section className="rounded-2xl bg-surface p-4">
        <div className="text-xs text-muted">Estimated burn (above resting)</div>
        <div className="text-3xl font-bold tabular-nums">{info.net}<span className="text-base text-muted"> kcal</span></div>
        <div className="text-xs text-muted">Range {info.low}–{info.high} · MET {catalogue[type].intensities[iv].met}</div>
      </section>

      <section className="rounded-2xl border border-accent/30 bg-surface p-4">
        <div className="text-sm text-accent">What it changes today</div>
        {dk === 0 && dc === 0 ? <p className="mt-1 text-sm">Nothing. Today is already a {CLASS_LABEL[b.class].toLowerCase()}, so your targets already cover this.</p> : <>
          <div className="mt-1 flex items-baseline justify-between"><span className="text-sm text-muted">{CLASS_LABEL[b.class]} → {CLASS_LABEL[a.class]}</span>
            <span className="text-2xl font-bold tabular-nums">{dk > 0 ? '+' : ''}{dk} kcal</span></div>
          <div className="text-sm tabular-nums">Carbs {b.carbs} → {a.carbs} g ({dc >= 0 ? '+' : ''}{dc}) · Protein {a.protein} g · Fat {a.fat} g</div>
          <p className="mt-2 text-xs text-muted">The week's total stays the same, so the other days trim about {Math.abs(dk)} kcal between them. If you train like this regularly, your expenditure estimate rises over 2–3 weeks and the weekly target follows.</p></>}
        <label className="mt-3 flex min-h-11 items-center gap-3 text-sm">
          <input type="checkbox" className="size-5 accent-[var(--color-accent)]" checked={eatBack} onChange={e => onEatBack(e.target.checked)} />
          Also eat back the burn (+{Math.round(info.net / 10) * 10} kcal as carbs, this one session)
        </label>
      </section>

      <section className="rounded-2xl bg-surface p-4 text-sm">
        <div className="text-accent">Fuelling for this session</div>
        <p className="mt-1"><b>Before:</b> {info.peri.pre.carbsG} g carbs + {info.peri.pre.proteinG} g protein, low fat and fibre. <span className="text-muted">{info.peri.pre.note}</span></p>
        {info.peri.during.carbsGPerHour[1] > 0 && <p className="mt-1"><b>During:</b> {info.peri.during.carbsGPerHour[0]}–{info.peri.during.carbsGPerHour[1]} g carbs/h.</p>}
        <p className="mt-1"><b>After:</b> {info.peri.post.proteinG} g protein{info.peri.post.carbsG ? ` + ${info.peri.post.carbsG} g carbs` : ''}. <span className="text-muted">{info.peri.post.note}</span></p>
      </section>

      <div className="fixed inset-x-0 bottom-16 mx-auto flex max-w-md gap-2 bg-canvas/95 px-4 py-3 backdrop-blur">
        {current && <button className="min-h-12 rounded-2xl bg-raised px-4" onClick={() => { onClear(); close() }}>Remove</button>}
        <button className="min-h-12 flex-1 rounded-2xl bg-accent font-semibold text-canvas" onClick={() => { onAdd(s); close() }}>Add to today</button>
      </div>
    </div>)
}
