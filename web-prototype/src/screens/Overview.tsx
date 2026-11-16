import { useState } from 'react'
import { series } from '../mock'
import { addDays, todayIso } from '../lib/data'
import { RANGE_DAYS, RangeChips, Stat, TrendChart, type Range } from '../lib/charts'

export default function Overview() {
  const [range, setRange] = useState<Range>('1M')
  const [fit, setFit] = useState(true)
  const [applied, setApplied] = useState(false)
  const n = Math.min(RANGE_DAYS[range], series.length), rows = series.slice(-Math.max(n, 2))
  const dateOf = (i: number) => addDays(todayIso(), i - (series.length - 1))
  const last = series[series.length - 1], first = rows[0]
  const avgW = rows.reduce((a, r) => a + (r.scale ?? r.trend), 0) / rows.length
  const avgT = rows.reduce((a, r) => a + r.tdee, 0) / rows.length
  return (
    <div className="space-y-3 pb-28">
      <div className="grid grid-cols-2 gap-3">
        <div className="rounded-2xl bg-surface p-4"><div className="text-xs text-muted">Trend weight</div><div className="text-3xl font-bold">{last.trend.toFixed(1)}<span className="text-base text-muted"> kg</span></div><div className="text-xs text-accent">+0.25 kg/wk</div></div>
        <div className="rounded-2xl bg-surface p-4"><div className="text-xs text-muted">Expenditure</div><div className="text-3xl font-bold">{Math.round(last.tdee / 10) * 10}<span className="text-base text-muted"> ±{Math.round(last.sd / 10) * 10}</span></div><div className="text-xs text-muted">kcal/day</div></div>
      </div>
      <RangeChips value={range} onChange={setRange} />
      <section className="rounded-2xl bg-surface p-4">
        <div className="mb-2 flex items-start justify-between"><div className="flex gap-6">
          <Stat label="Average" value={avgW.toFixed(1)} unit="kg" />
          <Stat label="Difference" value={`${last.trend - first.trend >= 0 ? '+' : ''}${(last.trend - first.trend).toFixed(1)}`} unit="kg" /></div>
          <button aria-label={fit ? 'Widen the chart axis' : 'Fit the chart axis to the data'} className="size-11 rounded-full bg-raised" onClick={() => setFit(f => !f)}>{fit ? '⇕' : '⇔'}</button></div>
        <TrendChart pts={rows.map(r => ({ date: dateOf(r.i), raw: r.scale ?? undefined, line: r.trend }))} fit={fit} unit="kg" />
      </section>
      <section className="rounded-2xl bg-surface p-4">
        <div className="mb-2 flex gap-6"><Stat label="Average" value={String(Math.round(avgT))} unit="kcal" /><Stat label="Difference" value={`${last.tdee - first.tdee >= 0 ? '+' : ''}${Math.round(last.tdee - first.tdee)}`} unit="kcal" /></div>
        <TrendChart pts={rows.map(r => ({ date: dateOf(r.i), line: r.tdee, lo: r.tdee - r.sd, hi: r.tdee + r.sd }))} fit={fit} color="var(--color-carbs)" />
        <p className="mt-1 text-xs text-muted">Shaded band = how sure the estimate is. It narrows as you keep logging.</p>
      </section>
      <section className="rounded-2xl border border-accent/30 bg-surface p-4">
        <div className="text-sm text-accent">Weekly check-in</div>
        <p className="mt-1 text-sm">Trend is up 0.25 kg/wk, right on target. Expenditure estimate is stable, so next week's target moves +60 kcal (cap ±150).</p>
        <button disabled={applied} className="mt-3 min-h-12 w-full rounded-xl bg-accent font-semibold text-canvas disabled:opacity-40" onClick={() => setApplied(true)}>{applied ? 'Targets updated' : 'Apply new targets'}</button>
      </section>
    </div>)
}
