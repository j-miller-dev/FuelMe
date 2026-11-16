import { useState } from 'react'
import hitBanner from '../hit-banner.png'
import { CLASS_LABEL, DAYS, DAY_NAMES, getPlan, plannedSessions, sessionLabel, todayDow, weight, type CarbMode, type Dow, type Goal, type Settings, type Style } from '../engine'

const Seg = <T extends string | number>({ value, options, onChange }: { value: T; options: [T, string][]; onChange: (v: T) => void }) => (
  <div className="flex gap-2">{options.map(([v, l]) => (
    <button key={String(v)} onClick={() => onChange(v)} className={`min-h-11 flex-1 rounded-xl text-sm ${v === value ? 'bg-accent font-semibold text-canvas' : 'bg-raised'}`}>{l}</button>))}</div>)
const classColor: Record<string, string> = { rest: 'bg-raised', light: 'bg-protein/30', moderate: 'bg-carbs/30', high: 'bg-accent/30', very_high: 'bg-fat/40' }

export default function Targets({ settings, set }: { settings: Settings; set: (s: Settings) => void }) {
  const plan = getPlan(settings)
  const [sel, setSel] = useState<Dow>(todayDow())
  const [open, setOpen] = useState(false)
  const [checked, setChecked] = useState(false)
  const d = plan.days[sel], w = plan.weekly
  const tot = d.protein * 4 + d.carbs * 4 + d.fat * 9
  return (
    <div className="space-y-3 px-4 pb-28">
      <section className="rounded-2xl bg-surface p-4">
        {!checked ? <button className="flex min-h-14 w-full items-center gap-4 text-left" onClick={() => setOpen(o => !o)} aria-expanded={open}>
            <span className="grid size-16 shrink-0 place-items-center rounded-full bg-accent text-center text-[11px] font-bold leading-tight text-canvas">CHECK<br />IN</span>
            <span><span className="block font-medium">Weekly check-in</span><span className="block text-xs text-muted">It's time. Review your trend and update next week's targets.</span></span></button>
          : <div className="text-sm text-accent">Checked in. Targets updated for the week.</div>}
        {open && !checked && <div className="mt-3 text-sm"><p>Trend is up 0.25 kg/wk, right on your goal. The expenditure estimate is stable, so calories move +60 kcal a day (the weekly cap is ±150).</p>
          <button className="mt-3 min-h-12 w-full rounded-xl bg-accent font-semibold text-canvas" onClick={() => { setChecked(true); setOpen(false) }}>Apply new targets</button></div>}
      </section>
      {settings.override && <section className="rounded-2xl border border-accent/40 bg-surface p-4 text-sm">
        <div className="text-accent">Targets from your goal</div><div className="mt-1">{settings.override.label}</div>
        <p className="mt-1 text-xs text-muted">Calories solved to hit it at a safe weekly rate (protein 2.2 g/kg, flexible carbs). Changing anything below switches back to manual settings.</p>
        <button className="mt-2 min-h-11 text-accent" onClick={() => set({ ...settings, override: null })}>Clear goal</button></section>}
      <section className="rounded-2xl bg-surface p-3">
        <div className="mb-2 flex items-center justify-between text-xs text-muted"><span>Your week</span><span>{settings.override ? settings.override.label : 'Manual targets'}</span></div>
        <div className="grid grid-cols-7 gap-1.5">{DAYS.map(k => { const x = plan.days[k], H = (g: number, per: number) => Math.max(per * g, 22), isSel = sel === k
          return (
          <button key={k} onClick={() => setSel(k)} aria-label={`${DAY_NAMES[k]}: ${x.kcal} kcal, ${x.protein} protein, ${x.fat} fat, ${x.carbs} carbs`} aria-pressed={isSel}
            className={`flex h-[246px] flex-col items-stretch gap-1 rounded-xl p-0.5 ${isSel ? 'ring-2 ring-accent' : ''}`}>
            <span className="rounded-full bg-accent/20 py-1 text-center text-[10px] font-semibold tabular-nums">{x.kcal}</span>
            <span className="flex min-h-0 flex-1 flex-col justify-end gap-1">
            <span className="flex items-center justify-center rounded-md text-[10px] tabular-nums text-canvas" style={{ background: 'var(--color-protein)', height: H(x.protein, 0.3) }}>{x.protein}P</span>
            <span className="flex items-center justify-center rounded-md text-[10px] tabular-nums text-canvas" style={{ background: 'var(--color-fat)', height: H(x.fat, 0.3) }}>{x.fat}F</span>
            <span className="flex items-center justify-center rounded-md text-[10px] tabular-nums text-canvas" style={{ background: 'var(--color-carbs)', height: H(x.carbs, 0.3) }}>{x.carbs}C</span></span>
            <span className={`pt-0.5 text-center text-xs ${k === todayDow() ? 'font-bold text-accent' : 'text-muted'}`}>{DAY_NAMES[k][0]}</span>
          </button>) })}</div>
        <p className="mt-2 text-xs text-muted">Block heights are to scale. Training days carry more carbs, rest days fewer.</p>
      </section>

      <section className="rounded-2xl bg-surface p-4">
        <div className="flex items-baseline justify-between"><h2 className="text-lg font-semibold">{DAY_NAMES[sel]} · {CLASS_LABEL[d.class]}</h2><span className="text-2xl font-bold tabular-nums">{d.kcal}<span className="text-sm text-muted"> kcal</span></span></div>
        <p className="text-xs text-muted">{plannedSessions(sel, settings.style).map(sessionLabel).join(' + ') || 'No training planned'}</p>
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

      <section className="rounded-2xl bg-surface p-4 space-y-3">
        <div>{settings.style === 'hit' && <img src={hitBanner} alt="HIT training mode" className="mb-2 w-full" />}<div className="mb-1 text-xs text-muted">Lifting style</div>
          <Seg<Style> value={settings.style} onChange={style => set({ ...settings, style })} options={[['hit', 'HIT (Mentzer-style)'], ['volume', 'Higher volume']]} />
          <p className="mt-2 text-xs text-muted">{settings.style === 'hit'
            ? 'Few hard sets to failure, long rests. A HIT session burns little (about 120 kcal net for 40 min, an estimate), so lifting-only days get no extra carbs. BJJ and conditioning days drive your carb and timing needs. Protein stays the same every day, rest days included, because recovery is the longer part of the cycle.'
            : 'Many sets, shorter rests. Lifting days carry more carbs. Protein stays the same every day.'}</p></div>
        <div><div className="mb-1 text-xs text-muted">Goal</div>
          <Seg<Goal> value={settings.goal} onChange={goal => set({ ...settings, goal, override: null })} options={[['bulk', 'Lean bulk'], ['maintain', 'Maintain'], ['cut', 'Cut']]} /></div>
        <div><div className="mb-1 text-xs text-muted">Protein</div>
          <Seg<number> value={settings.protein} onChange={protein => set({ ...settings, protein: protein as Settings['protein'], override: null })} options={[[1.8, '1.8 g/kg'], [2.2, '2.2 g/kg'], [2.6, '2.6 g/kg']]} /></div>
        <div><div className="mb-1 text-xs text-muted">Carbs</div>
          <Seg<CarbMode> value={settings.mode} onChange={mode => set({ ...settings, mode, override: null })} options={[['flexible', 'Flexible'], ['cap', 'Cap at 300 g']]} /></div>
        <p className="text-xs text-muted">Weekly average {Math.round(w.kcalAvg)} kcal at {weight} kg. Protein {Math.round(w.protein)} g, fat {Math.round(w.fat)} g ({w.fatGPerKg} g/kg), carbs flex with training.</p>
      </section>

      {plan.warnings.filter(x => x.level !== 'info').map(x => <div key={x.code + x.msg} className="rounded-2xl border border-carbs/40 bg-surface p-3 text-sm text-carbs">{x.msg}</div>)}
    </div>)
}
