import { useState } from 'react'
import { CLASS_LABEL, DAYS, DAY_NAMES, getPlan, plannedSessions, sessionLabel, todayDow, weight, type CarbMode, type Dow, type Goal, type Settings } from '../engine'

const Seg = <T extends string | number>({ value, options, onChange }: { value: T; options: [T, string][]; onChange: (v: T) => void }) => (
  <div className="flex gap-2">{options.map(([v, l]) => (
    <button key={String(v)} onClick={() => onChange(v)} className={`min-h-11 flex-1 rounded-xl text-sm ${v === value ? 'bg-accent font-semibold text-canvas' : 'bg-raised'}`}>{l}</button>))}</div>)
const classColor: Record<string, string> = { rest: 'bg-raised', light: 'bg-protein/30', moderate: 'bg-carbs/30', high: 'bg-accent/30', very_high: 'bg-fat/40' }

export default function Targets({ settings, set }: { settings: Settings; set: (s: Settings) => void }) {
  const plan = getPlan(settings)
  const [sel, setSel] = useState<Dow>(todayDow())
  const d = plan.days[sel], w = plan.weekly
  const tot = d.protein * 4 + d.carbs * 4 + d.fat * 9
  return (
    <div className="space-y-3 px-4 pb-28">
      <section className="rounded-2xl bg-surface p-4 space-y-3">
        <div><div className="mb-1 text-xs text-muted">Goal</div>
          <Seg<Goal> value={settings.goal} onChange={goal => set({ ...settings, goal })} options={[['bulk', 'Lean bulk'], ['maintain', 'Maintain'], ['cut', 'Cut']]} /></div>
        <div><div className="mb-1 text-xs text-muted">Protein</div>
          <Seg<number> value={settings.protein} onChange={protein => set({ ...settings, protein: protein as Settings['protein'] })} options={[[1.8, '1.8 g/kg'], [2.2, '2.2 g/kg'], [2.6, '2.6 g/kg']]} /></div>
        <div><div className="mb-1 text-xs text-muted">Carbs</div>
          <Seg<CarbMode> value={settings.mode} onChange={mode => set({ ...settings, mode })} options={[['flexible', 'Flexible'], ['cap', 'Cap at 300 g']]} /></div>
        <p className="text-xs text-muted">Weekly average {Math.round(w.kcalAvg)} kcal at {weight} kg. Protein {Math.round(w.protein)} g, fat {Math.round(w.fat)} g ({w.fatGPerKg} g/kg), carbs flex with training.</p>
      </section>

      <section className="rounded-2xl bg-surface p-3">
        <div className="mb-2 text-xs text-muted">Your week</div>
        <div className="grid grid-cols-7 gap-1">{DAYS.map(k => (
          <button key={k} onClick={() => setSel(k)} aria-label={`${DAY_NAMES[k]} ${plan.days[k].kcal} kcal`}
            className={`min-h-16 rounded-xl p-1 text-center ${classColor[plan.days[k].class]} ${sel === k ? 'ring-2 ring-accent' : ''}`}>
            <div className="text-xs">{DAY_NAMES[k]}</div><div className="text-sm font-semibold tabular-nums">{plan.days[k].kcal}</div>
          </button>))}</div>
      </section>

      <section className="rounded-2xl bg-surface p-4">
        <div className="flex items-baseline justify-between"><h2 className="text-lg font-semibold">{DAY_NAMES[sel]} · {CLASS_LABEL[d.class]}</h2><span className="text-2xl font-bold tabular-nums">{d.kcal}<span className="text-sm text-muted"> kcal</span></span></div>
        <p className="text-xs text-muted">{plannedSessions(sel).map(sessionLabel).join(' + ') || 'No training planned'}</p>
        <div className="mt-3 flex h-3 overflow-hidden rounded-full bg-raised" aria-hidden>
          <div style={{ width: `${d.protein * 4 / tot * 100}%`, background: 'var(--color-protein)' }} /><div style={{ width: `${d.carbs * 4 / tot * 100}%`, background: 'var(--color-carbs)' }} /><div style={{ width: `${d.fat * 9 / tot * 100}%`, background: 'var(--color-fat)' }} />
        </div>
        <div className="mt-3 grid grid-cols-3 gap-2 text-center">
          <div><div className="text-2xl font-bold text-protein tabular-nums">{d.protein}</div><div className="text-xs text-muted">protein g</div></div>
          <div><div className="text-2xl font-bold text-carbs tabular-nums">{d.carbs}</div><div className="text-xs text-muted">carbs g · {d.carbGPerKg}/kg</div></div>
          <div><div className="text-2xl font-bold text-fat tabular-nums">{d.fat}</div><div className="text-xs text-muted">fat g</div></div>
        </div>
        <p className="mt-3 text-xs text-muted">Training burns about {d.netKcal} kcal today, but it is not added on top. Your expenditure estimate already includes your usual training, so hard days get more carbs and rest days fewer, with the week's total held steady.</p>
      </section>

      {plan.warnings.filter(x => x.level !== 'info').map(x => <div key={x.code + x.msg} className="rounded-2xl border border-carbs/40 bg-surface p-3 text-sm text-carbs">{x.msg}</div>)}
    </div>)
}
