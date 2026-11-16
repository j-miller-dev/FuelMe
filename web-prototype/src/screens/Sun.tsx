import { useEffect, useState } from 'react'
import type { SunLog } from '../lib/outbox'
import { AREAS, SKIN, deleteSun, estimate, logSun, minutesFor, sunOn } from '../lib/sun'
import { listSupps, logsOn, totals } from '../lib/supplements'
import { todayIso } from '../lib/data'

const inp = 'mt-1 min-h-11 w-full rounded-xl bg-raised px-3 text-white'
export default function Sun() {
  const today = todayIso()
  const [f, setF] = useState({ minutes: 20, uvi: 2, area: 0.25, skin: 'fair' as SunLog['skin'] })
  const [logs, setLogs] = useState<SunLog[]>([]), [suppD, setSuppD] = useState(0)
  const refresh = async () => { setLogs(await sunOn(today)); const t = totals(await listSupps(), await logsOn(today)); setSuppD(Math.round((t.d ?? 0) * 40)) }
  useEffect(() => { void refresh() }, [])
  const e = estimate(f.minutes, f.uvi, f.area, f.skin), need = minutesFor(1000, f.uvi, f.area, f.skin)
  const sunMid = logs.reduce((a, l) => a + l.iuMid, 0), sunLow = logs.reduce((a, l) => a + l.iuLow, 0), sunHigh = logs.reduce((a, l) => a + l.iuHigh, 0)
  return (
    <div className="space-y-3 pb-28">
      <p className="rounded-2xl border border-carbs/40 bg-surface p-3 text-xs text-carbs">A rough estimate, not a measurement. It could be well off for your skin, the season, shade and the sun's angle, so a range is shown. A blood test is the real check. Early morning and winter sun can give less vitamin D than the UV index suggests.</p>
      <section className="space-y-2 rounded-2xl bg-surface p-4">
        <div className="text-sm font-medium">Log sun</div>
        <div className="flex gap-2"><label className="flex-1 text-sm text-muted">Minutes<input type="number" inputMode="numeric" className={inp} value={f.minutes} onChange={e2 => setF({ ...f, minutes: Math.max(0, +e2.target.value) })} /></label>
          <label className="flex-1 text-sm text-muted">UV index<input type="number" step="0.1" inputMode="decimal" className={inp} value={f.uvi} onChange={e2 => setF({ ...f, uvi: Math.max(0, +e2.target.value) })} /></label></div>
        <p className="text-xs text-muted">Read the UV index for Melbourne from the BoM, SunSmart or myuv.com.au for the time you were out. The real app will fetch it for you.</p>
        <label className="block text-sm text-muted">Skin showing<select className={inp} value={f.area} onChange={e2 => setF({ ...f, area: +e2.target.value })}>{AREAS.map(([v, l]) => <option key={v} value={v}>{l} (about {Math.round(v * 100)}%)</option>)}</select></label>
        <label className="block text-sm text-muted">Skin tone<select className={inp} value={f.skin} onChange={e2 => setF({ ...f, skin: e2.target.value as SunLog['skin'] })}>{Object.entries(SKIN).map(([k, v]) => <option key={k} value={k}>{v.label}</option>)}</select></label>
        <div className="rounded-xl bg-raised p-3 text-sm"><div>About <span className="text-lg font-semibold text-accent">{e.mid}</span> IU <span className="text-muted">(range {e.low} to {e.high})</span></div>
          <div className="mt-1 text-xs text-muted">{Number.isFinite(need) && need > 0 ? `At this UV index and clothing, about ${Math.round(need)} min would give roughly 1,000 IU.` : 'No UV at this index, so no vitamin D from sun.'}</div>
          {f.uvi < 3 && f.uvi > 0 && <div className="mt-1 text-xs text-muted">UV index under 3: the estimate is halved because low or winter sun makes less vitamin D than the index suggests. This halving is an assumption.</div>}
          {f.uvi >= 3 && <div className="mt-1 text-xs text-carbs">At UV index 3 or above, sun protection is recommended if you are out more than a few minutes.</div>}</div>
        <button className="min-h-12 w-full rounded-xl bg-accent font-semibold text-canvas disabled:opacity-40" disabled={f.minutes <= 0}
          onClick={async () => { await logSun({ date: today, ...f, iuLow: e.low, iuMid: e.mid, iuHigh: e.high }); void refresh() }}>Add to today</button>
      </section>
      <section className="rounded-2xl bg-surface p-4">
        <div className="text-sm font-medium">Vitamin D today</div>
        <div className="mt-2 flex justify-between text-sm"><span>Sun (estimate)</span><span className="tabular-nums">{sunMid} IU <span className="text-xs text-muted">({sunLow} to {sunHigh})</span></span></div>
        <div className="flex justify-between text-sm"><span>Supplements (logged)</span><span className="tabular-nums">{suppD} IU</span></div>
        <div className="mt-1 flex justify-between border-t border-raised pt-1 text-sm font-medium"><span>Total</span><span className="tabular-nums">{sunMid + suppD} IU</span></div>
        {logs.map(l => <div key={l.id} className="mt-2 flex items-center justify-between text-xs text-muted"><span>{l.minutes} min · UV {l.uvi} · {l.iuMid} IU</span><button className="min-h-9 px-2 text-fat" onClick={async () => { await deleteSun(l.id!); void refresh() }}>Remove</button></div>)}
        <p className="mt-2 text-xs text-muted">A common reference point from an Australian position statement is about 1,000 IU a day from sun on most days. This is a guide, not a target set for you.</p></section>
    </div>)
}
