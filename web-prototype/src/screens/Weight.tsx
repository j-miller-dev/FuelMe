import { useState } from 'react'
import { addDays, fmtDate, mean, sd, todayIso, weekStart, type WeighIn } from '../lib/data'
import { RANGE_DAYS, RangeChips, Stat, TrendChart, type Range } from '../lib/charts'

const W = 320
function DotsChart({ rows, h = 150 }: { rows: { x: number; kg?: number; trend?: number }[]; h?: number }) {
  const vals = rows.flatMap(r => [r.kg, r.trend].filter((v): v is number => v !== undefined))
  const lo = Math.min(...vals) - 0.3, hi = Math.max(...vals) + 0.3, n = Math.max(rows.length - 1, 1)
  const X = (i: number) => 8 + i / n * (W - 16), Y = (v: number) => 8 + (1 - (v - lo) / (hi - lo)) * (h - 16)
  return (
    <svg viewBox={`0 0 ${W} ${h}`} className="w-full" role="img" aria-label="Scale weight and trend">
      <polyline fill="none" stroke="var(--color-accent)" strokeWidth="2.5" points={rows.flatMap((r, i) => r.trend === undefined ? [] : [`${X(i)},${Y(r.trend)}`]).join(' ')} />
      {rows.map((r, i) => r.kg !== undefined && <circle key={i} cx={X(i)} cy={Y(r.kg)} r="3" fill="var(--color-muted)" />)}
      <text x="8" y="12" fontSize="9" fill="var(--color-muted)">{hi.toFixed(1)}</text><text x="8" y={h - 2} fontSize="9" fill="var(--color-muted)">{lo.toFixed(1)}</text>
    </svg>)
}

export default function Weight({ weighIns, add }: { weighIns: WeighIn[]; add: (kg: number) => void }) {
  const [view, setView] = useState<'daily' | 'weekly'>('daily')
  const [val, setVal] = useState(String(weighIns[weighIns.length - 1].kg))
  const [saved, setSaved] = useState(false)
  const [range, setRange] = useState<Range>('1M')
  const [fit, setFit] = useState(true)
  const last = weighIns[weighIns.length - 1], doneToday = last.date === todayIso()

  const from = addDays(todayIso(), -RANGE_DAYS[range]), inRange = weighIns.filter(w => w.date >= from), recent = inRange.length > 1 ? inRange : weighIns.slice(-2)
  const avg = mean(recent.map(w => w.kg)), diff = recent[recent.length - 1].trend - recent[0].trend
  const resid = weighIns.slice(-28).map(w => w.kg - w.trend)
  const noise = sd(resid)
  const wk = new Map<string, WeighIn[]>()
  weighIns.forEach(w => { const k = weekStart(w.date); wk.set(k, [...(wk.get(k) ?? []), w]) })
  const weeks = [...wk.entries()].slice(-9).map(([k, ws]) => ({ start: k, avg: mean(ws.map(w => w.kg)), trend: ws[ws.length - 1].trend, n: ws.length }))
  const trendPerWk = weeks.length > 2 ? (weeks[weeks.length - 1].trend - weeks[weeks.length - 3].trend) / 2 : 0
  const last7 = weighIns.slice(-7).map(w => w.kg), swing = Math.max(...last7) - Math.min(...last7)

  return (
    <div className="space-y-3 pb-28">
      <section className="rounded-2xl bg-surface p-4">
        <div className="flex items-end justify-between">
          <div><div className="text-xs text-muted">Trend weight</div><div className="text-3xl font-bold tabular-nums">{last.trend.toFixed(1)}<span className="text-base text-muted"> kg</span></div></div>
          <div className="text-right"><div className="text-xs text-muted">Last weigh-in · {fmtDate(last.date)}</div><div className="text-xl tabular-nums">{last.kg.toFixed(1)} kg</div></div>
        </div>
        <div className="mt-3 flex gap-2">
          <input aria-label="Weight in kg" inputMode="decimal" value={val} onChange={e => { setVal(e.target.value); setSaved(false) }} className="min-h-12 w-28 rounded-xl bg-raised px-3 text-lg tabular-nums" />
          <button className="min-h-12 flex-1 rounded-xl bg-accent font-semibold text-canvas disabled:opacity-40" disabled={!(parseFloat(val) > 30)}
            onClick={() => { add(parseFloat(val)); setSaved(true); navigator.vibrate?.(10) }}>{doneToday ? 'Update today' : 'Log weight'}</button>
        </div>
        {saved && <p className="mt-2 text-xs text-accent">Saved for {fmtDate(todayIso())}. The trend line updates when the engine reruns.</p>}
        <p className="mt-2 text-xs text-muted">Weigh first thing, after the toilet, before eating. Any consistent routine works.</p>
      </section>

      <div className="flex gap-2">{(['daily', 'weekly'] as const).map(v => (
        <button key={v} onClick={() => setView(v)} className={`min-h-11 flex-1 rounded-xl text-sm capitalize ${view === v ? 'bg-accent font-semibold text-canvas' : 'bg-surface'}`}>{v}</button>))}</div>

      {view === 'daily' ? <>
        <section className="rounded-2xl bg-surface p-4">
          <div className="flex items-start justify-between">
            <div className="flex gap-6"><Stat label="Average" value={avg.toFixed(1)} unit="kg" /><Stat label="Difference" value={`${diff >= 0 ? '+' : ''}${diff.toFixed(1)}`} unit="kg" /></div>
            <button aria-label={fit ? 'Widen the chart axis' : 'Fit the chart axis to the data'} aria-pressed={!fit} className="size-11 rounded-full bg-raised" onClick={() => setFit(f => !f)}>{fit ? '⇕' : '⇔'}</button>
          </div>
          <div className="mb-2 text-xs text-muted">{fmtDate(recent[0].date)} to {fmtDate(recent[recent.length - 1].date)}</div>
          <TrendChart pts={recent.map(w => ({ date: w.date, raw: w.kg, line: w.trend }))} fit={fit} unit="kg" />
          <div className="mb-3 mt-1 flex gap-4 text-xs text-muted"><span><span className="mr-1 inline-block h-0.5 w-4 bg-accent/40 align-middle" />Scale weight</span><span><span className="mr-1 inline-block h-0.5 w-4 bg-accent align-middle" />Trend weight</span></div>
          <RangeChips value={range} onChange={setRange} />
        </section>
        <section className="rounded-2xl bg-surface p-2">
          {weighIns.slice(-10).reverse().map(w => { const d = w.kg - w.trend; return (
            <div key={w.date} className="flex min-h-11 items-center justify-between px-2 text-sm">
              <span className="text-muted">{fmtDate(w.date)}{w.pending ? ' · new' : ''}</span>
              <span className="tabular-nums">{w.kg.toFixed(1)} kg <span className={`ml-2 inline-block w-14 text-right text-xs ${Math.abs(d) > noise * 1.5 ? 'text-carbs' : 'text-muted'}`}>{d >= 0.05 ? '+' : ''}{Math.abs(d) < 0.05 ? '0.0' : d.toFixed(1)}</span></span>
            </div>) })}
          <p className="px-2 pb-1 text-xs text-muted">Right column = scale minus trend.</p>
        </section>
      </> : <>
        <section className="rounded-2xl bg-surface p-3">
          <div className="mb-1 text-xs text-muted">Weekly average of the scale · line = trend</div>
          <DotsChart rows={weeks.map((w, i) => ({ x: i, kg: w.avg, trend: w.trend }))} h={130} />
        </section>
        <section className="rounded-2xl bg-surface p-2">
          {weeks.slice().reverse().map((w, i, a) => { const prev = a[i + 1]; return (
            <div key={w.start} className="flex min-h-11 items-center justify-between px-2 text-sm">
              <span className="text-muted">Week of {fmtDate(w.start)} · {w.n} weigh-ins</span>
              <span className="tabular-nums">{w.avg.toFixed(1)} kg{prev && <span className="ml-2 inline-block w-14 text-right text-xs text-muted">{w.avg - prev.avg >= 0 ? '+' : ''}{(w.avg - prev.avg).toFixed(2)}</span>}</span>
            </div>) })}
        </section>
      </>}

      <section className="rounded-2xl bg-surface p-4">
        <div className="text-sm font-medium">How much you fluctuate</div>
        <div className="mt-2 grid grid-cols-3 gap-2 text-center">
          <div><div className="text-xl font-bold tabular-nums">±{noise.toFixed(1)}</div><div className="text-xs text-muted">kg daily noise</div></div>
          <div><div className="text-xl font-bold tabular-nums">{swing.toFixed(1)}</div><div className="text-xs text-muted">kg swing, last 7 d</div></div>
          <div><div className="text-xl font-bold tabular-nums">{trendPerWk >= 0 ? '+' : ''}{trendPerWk.toFixed(2)}</div><div className="text-xs text-muted">kg/wk trend</div></div>
        </div>
        <p className="mt-3 text-xs text-muted">Daily swings of this size are normal (water, glycogen, food in the gut, salt). The trend line smooths them out, so judge progress by the trend per week, not by any single morning.</p>
      </section>
      <section className="rounded-2xl bg-surface p-4 text-sm">
        <div className="font-medium">Daily or weekly weigh-ins?</div>
        <p className="mt-1 text-xs text-muted">Daily gives the engine the most data to separate real change from noise. Three or four mornings a week still works well. A single weekly weigh-in is usable but slower to calibrate, and one unusual morning counts for more. Missed days are fine: the engine just skips them.</p>
      </section>
    </div>)
}
