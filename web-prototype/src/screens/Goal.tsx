import { useMemo, useState } from 'react'
import { assess, fromMuscle, type Experience } from '../goal'
import { addDays, daysBetween, fmtDate, todayIso } from '../lib/data'
import { DAYS, gridPlan, type Goal as Phase, type Settings } from '../engine'

const pill = (on: boolean) => `min-h-11 flex-1 rounded-xl px-2 text-sm first-letter:uppercase ${on ? 'bg-accent font-semibold text-canvas' : 'bg-raised'}`
const STATUS = { on_track: ['On track', 'text-accent bg-accent/10'], too_fast: ['Too fast', 'text-carbs bg-carbs/10'], not_achievable: ['Not achievable safely', 'text-fat bg-fat/10'] } as const

export default function GoalScreen({ trendKg, tdee, settings, set }: { trendKg: number; tdee: number; settings: Settings; set: (s: Settings) => void }) {
  const [kind, setKind] = useState<'muscle' | 'weight'>('muscle')
  const [muscle, setMuscle] = useState(2)
  const [target, setTarget] = useState(String(Math.round((trendKg + 3) * 2) / 2))
  const [date, setDate] = useState(addDays(todayIso(), 112))
  const [exp, setExp] = useState<Experience>('intermediate')
  const [lean, setLean] = useState(0.5)
  const days = Math.max(daysBetween(todayIso(), date), 1)

  const m = useMemo(() => kind === 'muscle' ? fromMuscle(muscle, lean, trendKg, days, exp) : null, [kind, muscle, lean, trendKg, days, exp])
  const targetKg = m ? m.targetKg : parseFloat(target) || trendKg
  const a = useMemo(() => assess(trendKg, targetKg, days, exp, tdee), [trendKg, targetKg, days, exp, tdee])
  const phase: Phase = a.direction === 'gain' ? 'bulk' : a.direction === 'loss' ? 'cut' : 'maintain'
  const plan = gridPlan(phase, a.kcalTarget), carbs = DAYS.map(d => plan.days[d].carbs)
  const [label, cls] = STATUS[a.status]
  const kgPerWk = a.planRatePctPerWeek / 100 * trendKg
  const label2 = kind === 'muscle' ? `+${muscle} kg muscle by ${fmtDate(date)}` : `${targetKg.toFixed(1)} kg by ${fmtDate(date)}`

  return (
    <div className="space-y-3 pb-28">
      <section className="space-y-3 rounded-2xl bg-surface p-4">
        <div className="flex gap-2">{([['muscle', 'Gain muscle'], ['weight', 'Reach a weight']] as const).map(([k, l]) => <button key={k} onClick={() => setKind(k)} className={pill(kind === k)}>{l}</button>)}</div>
        {kind === 'muscle' ? <div>
          <div className="mb-1 text-xs text-muted">Muscle to gain</div>
          <div className="flex items-center gap-3">
            <button aria-label="Less" className="size-11 rounded-xl bg-raised" onClick={() => setMuscle(x => Math.max(0.5, x - 0.5))}>−</button>
            <span className="w-20 text-center text-xl tabular-nums">{muscle.toFixed(1)} kg</span>
            <button aria-label="More" className="size-11 rounded-xl bg-raised" onClick={() => setMuscle(x => Math.min(8, x + 0.5))}>+</button>
          </div></div> : <label className="block text-xs text-muted">Target weight (kg)
          <input inputMode="decimal" value={target} onChange={e => setTarget(e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-lg text-white tabular-nums" /></label>}
        <label className="block text-xs text-muted">By
          <input type="date" min={addDays(todayIso(), 7)} value={date} onChange={e => e.target.value && setDate(e.target.value)} className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-white" /></label>
        <div><div className="mb-1 text-xs text-muted">Training experience</div>
          <div className="flex gap-2">{(['novice', 'intermediate', 'advanced'] as const).map(x => <button key={x} onClick={() => setExp(x)} className={pill(exp === x)}>{x}</button>)}</div></div>
        {kind === 'muscle' && <div><div className="mb-1 text-xs text-muted">Share of weight gain that is muscle (assumption)</div>
          <div className="flex gap-2">{[0.4, 0.5, 0.6].map(x => <button key={x} onClick={() => setLean(x)} className={pill(lean === x)}>{x * 100}%</button>)}</div>
          <p className="mt-1 text-xs text-muted">The scale can't see muscle, so FUEL turns your muscle goal into a weight target using this guess. A smaller surplus and good training push it up. Check it against your waist and arm measurements.</p></div>}
      </section>

      <section className="rounded-2xl border border-accent/30 bg-surface p-4">
        <div className="flex items-center justify-between"><div className="text-sm text-accent">Your plan</div><span className={`rounded-full px-2 py-0.5 text-xs ${cls}`}>{label}</span></div>
        <p className="mt-1 text-sm text-muted">From {trendKg.toFixed(1)} kg to {targetKg.toFixed(1)} kg in {Math.round(days / 7)} weeks ({a.deltaKg >= 0 ? '+' : ''}{a.deltaKg} kg)</p>
        {m && <p className={`mt-1 text-sm ${m.muscleStatus === 'within' ? '' : 'text-carbs'}`}>
          Muscle: {m.muscleKgPerMonth} kg/month ({m.musclePctBwPerMonth}% of bodyweight). {m.muscleStatus === 'within' ? `Inside the typical ceiling for ${exp} lifters (about ${m.ceilingPctPerMonth}%/month).` : `Above the typical ceiling for ${exp} lifters (about ${m.ceilingPctPerMonth}%/month). Treat the date as ambitious.`}</p>}
        <div className="mt-3 grid grid-cols-3 gap-2 text-center">
          <div><div className="text-xl font-bold tabular-nums">{a.ratePctPerWeek >= 0 ? '+' : ''}{a.ratePctPerWeek}%</div><div className="text-xs text-muted">needed per week (cap {a.capPctPerWeek}%)</div></div>
          <div><div className="text-xl font-bold tabular-nums">{kgPerWk >= 0 ? '+' : ''}{kgPerWk.toFixed(2)}</div><div className="text-xs text-muted">kg/wk planned</div></div>
          <div><div className="text-xl font-bold tabular-nums">{a.balanceKcalPerDay >= 0 ? '+' : ''}{a.balanceKcalPerDay}</div><div className="text-xs text-muted">kcal/day vs maintenance</div></div>
        </div>
        {a.warnings.map(w => <p key={w} className="mt-3 text-sm text-carbs">{w}</p>)}
        {a.status !== 'on_track' && a.earliestDays && <p className="mt-2 text-sm">Earliest safe date for this target: <b>{fmtDate(addDays(todayIso(), a.earliestDays))}</b>. By {fmtDate(date)} the safe pace gets you to about <b>{a.achievableKgByDate} kg</b>.</p>}
      </section>

      <section className="rounded-2xl bg-surface p-4">
        <div className="flex items-baseline justify-between"><div className="text-sm font-medium">Daily targets this gives</div><div className="text-2xl font-bold tabular-nums">{Math.round(plan.weekly.kcalAvg)}<span className="text-sm text-muted"> kcal avg</span></div></div>
        <div className="mt-3 grid grid-cols-3 gap-2 text-center">
          <div><div className="text-2xl font-bold text-protein tabular-nums">{Math.round(plan.weekly.protein)}</div><div className="text-xs text-muted">protein g</div></div>
          <div><div className="whitespace-nowrap text-xl font-bold text-carbs tabular-nums">{Math.min(...carbs)}–{Math.max(...carbs)}</div><div className="text-xs text-muted">carbs g (rest–hard day)</div></div>
          <div><div className="text-2xl font-bold text-fat tabular-nums">{Math.round(plan.weekly.fat)}</div><div className="text-xs text-muted">fat g</div></div>
        </div>
        <p className="mt-3 text-xs text-muted">Based on trend {trendKg.toFixed(1)} kg and an expenditure estimate of about {Math.round(tdee / 10) * 10} kcal. If either moves, the plan re-solves. In the full app your target steps toward this by at most 150 kcal a week; the prototype applies it at once.</p>
        {plan.warnings.filter(w => w.level !== 'info').map(w => <p key={w.msg} className="mt-2 text-sm text-carbs">{w.msg}</p>)}
        <div className="mt-3 flex gap-2">
          {settings.override && <button className="min-h-12 rounded-2xl bg-raised px-4" onClick={() => set({ ...settings, override: null })}>Clear goal</button>}
          <button className="min-h-12 flex-1 rounded-2xl bg-accent font-semibold text-canvas" onClick={() => set({ ...settings, override: { kcal: a.kcalTarget, phase, label: label2 } })}>
            {settings.override ? 'Update my targets' : 'Use these as my targets'}</button>
        </div>
        {settings.override && <p className="mt-2 text-xs text-accent">Active goal: {settings.override.label}</p>}
      </section>
    </div>)
}
