import { useState } from 'react'
import { fmtDate, type Measure } from '../lib/data'

function Spark({ pts }: { pts: number[] }) {
  const lo = Math.min(...pts), hi = Math.max(...pts), rng = Math.max(hi - lo, 0.5), n = Math.max(pts.length - 1, 1)
  return <svg viewBox="0 0 80 28" className="h-7 w-20" aria-hidden><polyline fill="none" stroke="var(--color-accent)" strokeWidth="2" points={pts.map((v, i) => `${2 + i / n * 76},${26 - (v - lo) / rng * 24}`).join(' ')} /></svg>
}

export default function Body({ measures, add, weightChangeKg }: { measures: Measure[]; add: (site: string, v: number) => void; weightChangeKg: number }) {
  const [site, setSite] = useState(measures[0].site)
  const [val, setVal] = useState('')
  const waist = measures.find(m => m.site === 'Waist'), wd = waist ? waist.points[waist.points.length - 1].v - waist.points[0].v : 0
  return (
    <div className="space-y-3 pb-28">
      <section className="space-y-2 rounded-2xl bg-surface p-4">
        <div className="text-sm font-medium">Log a measurement</div>
        <div className="flex flex-wrap gap-2">{measures.map(m => (
          <button key={m.site} onClick={() => setSite(m.site)} className={`min-h-11 rounded-xl px-3 text-sm ${site === m.site ? 'bg-accent font-semibold text-canvas' : 'bg-raised'}`}>{m.site}</button>))}</div>
        <div className="flex gap-2">
          <input aria-label="Measurement in cm" inputMode="decimal" placeholder="cm" value={val} onChange={e => setVal(e.target.value)} className="min-h-12 w-28 rounded-xl bg-raised px-3 text-lg tabular-nums" />
          <button className="min-h-12 flex-1 rounded-xl bg-accent font-semibold text-canvas disabled:opacity-40" disabled={!(parseFloat(val) > 5)} onClick={() => { add(site, parseFloat(val)); setVal('') }}>Save {site.toLowerCase()}</button>
        </div>
        <p className="text-xs text-muted">Measure the same spot, relaxed, same time of day. Monthly is enough.</p>
      </section>
      <section className="rounded-2xl bg-surface p-2">
        {measures.map(m => { const first = m.points[0], last = m.points[m.points.length - 1], d = last.v - first.v; return (
          <div key={m.site} className="flex min-h-14 items-center justify-between px-2">
            <div><div className="text-sm font-medium">{m.site}</div><div className="text-xs text-muted">{fmtDate(last.date)}</div></div>
            <Spark pts={m.points.map(p => p.v)} />
            <div className="text-right"><div className="tabular-nums">{last.v.toFixed(1)} {m.unit}</div><div className={`text-xs tabular-nums ${d > 0 ? 'text-accent' : 'text-muted'}`}>{d >= 0 ? '+' : ''}{d.toFixed(1)} since {fmtDate(first.date).split(' ').slice(1).join(' ')}</div></div>
          </div>) })}
      </section>
      <section className="rounded-2xl border border-accent/30 bg-surface p-4 text-sm">
        <div className="text-accent">Reading the numbers</div>
        <p className="mt-1">Scale weight is up {weightChangeKg.toFixed(1)} kg and your waist is up {wd.toFixed(1)} cm over the same period.</p>
        <p className="mt-1 text-xs text-muted">Rule of thumb, not a measurement: on a lean bulk the arms, chest and thighs should be growing too. If the waist climbs much faster than the rest, the surplus is probably too big. Photos in the same light every few weeks are the other useful check.</p>
      </section>
    </div>)
}
