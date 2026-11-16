import { series } from '../mock'

function Chart({ h, children }: { h: number; children: React.ReactNode }) {
  return <svg viewBox={`0 0 320 ${h}`} className="w-full" role="img">{children}</svg>
}
const scaleX = (i: number) => 8 + (i / 55) * 304

export default function Overview() {
  const w = series.flatMap(s => s.scale ?? []), lo = Math.min(...w) - 0.3, hi = Math.max(...w) + 0.3
  const wy = (v: number) => 8 + (1 - (v - lo) / (hi - lo)) * 124
  const tl = series.map(s => s.tdee), tlo = Math.min(...tl) - 300, thi = Math.max(...tl) + 300
  const ty = (v: number) => 8 + (1 - (v - tlo) / (thi - tlo)) * 84
  const last = series[series.length - 1]
  const band = [...series.map(s => `${scaleX(s.i)},${ty(s.tdee + s.sd)}`), ...[...series].reverse().map(s => `${scaleX(s.i)},${ty(s.tdee - s.sd)}`)].join(' ')
  return (
    <div className="px-4 pb-28">
      <div className="grid grid-cols-2 gap-3">
        <div className="rounded-2xl bg-surface p-4"><div className="text-xs text-muted">Trend weight</div><div className="text-3xl font-bold">{last.trend.toFixed(1)}<span className="text-base text-muted"> kg</span></div><div className="text-xs text-accent">+0.25 kg/wk</div></div>
        <div className="rounded-2xl bg-surface p-4"><div className="text-xs text-muted">Expenditure</div><div className="text-3xl font-bold">{Math.round(last.tdee / 10) * 10}<span className="text-base text-muted"> ±{Math.round(last.sd / 10) * 10}</span></div><div className="text-xs text-muted">kcal/day</div></div>
      </div>
      <div className="mt-3 rounded-2xl bg-surface p-3">
        <div className="mb-1 text-xs text-muted">Scale · trend</div>
        <Chart h={140}>
          <polyline fill="none" stroke="var(--color-accent)" strokeWidth="2.5" points={series.map(s => `${scaleX(s.i)},${wy(s.trend)}`).join(' ')} />
          {series.map(s => s.scale !== null && <circle key={s.i} cx={scaleX(s.i)} cy={wy(s.scale)} r="2.2" fill="var(--color-muted)" />)}
        </Chart>
        <div className="mb-1 mt-2 text-xs text-muted">Expenditure (band = uncertainty)</div>
        <Chart h={100}>
          <polygon points={band} fill="var(--color-carbs)" opacity=".18" />
          <polyline fill="none" stroke="var(--color-carbs)" strokeWidth="2" points={series.map(s => `${scaleX(s.i)},${ty(s.tdee)}`).join(' ')} />
        </Chart>
      </div>
      <div className="mt-3 rounded-2xl border border-accent/30 bg-surface p-4">
        <div className="text-sm text-accent">Weekly check-in</div>
        <p className="mt-1 text-sm">Trend is up 0.25 kg/wk, right on target. Expenditure estimate is stable, so next week's target moves +60 kcal (cap ±150).</p>
        <button className="mt-3 min-h-11 w-full rounded-xl bg-accent font-semibold text-canvas">Apply new targets</button>
      </div>
    </div>)
}
