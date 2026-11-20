import { useMemo, useState } from 'react'
import { extent, longDate, shortDate, type Point } from '@/lib/progress'

const W = 360, PAD_L = 38, PAD_R = 8
const H1 = 150, H2 = 110, GAP = 26

/**
 * Two panels on ONE shared x axis: scale dots with the trend line on top, estimated expenditure (with its uncertainty band) below.
 * Plain SVG so it works offline and weighs nothing. Touch or drag across it to read any day.
 */
export function TrendChart({ points, energyFmt }: { points: Point[]; energyFmt: (kcal: number) => string }) {
  const [hover, setHover] = useState<number | null>(null)
  const n = points.length
  const x = (i: number) => PAD_L + (n <= 1 ? (W - PAD_L - PAD_R) / 2 : (i / (n - 1)) * (W - PAD_L - PAD_R))

  const m = useMemo(() => {
    const w = points.flatMap((p) => (p.scale !== null ? [p.scale, p.trend] : [p.trend]))
    const [w0, w1] = extent(w)
    const e = points.flatMap((p) => [p.tdee - p.tdeeSd, p.tdee + p.tdeeSd])
    const [e0, e1] = extent(e, 0.2)
    return { w0, w1, e0, e1 }
  }, [points])
  const yW = (v: number) => 8 + (1 - (v - m.w0) / (m.w1 - m.w0)) * (H1 - 16)
  const yE = (v: number) => H1 + GAP + 8 + (1 - (v - m.e0) / (m.e1 - m.e0)) * (H2 - 16)

  const trend = points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${yW(p.trend).toFixed(1)}`).join(' ')
  const tdee = points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${yE(p.tdee).toFixed(1)}`).join(' ')
  const band = n > 1 ? `${points.map((p, i) => `${i ? 'L' : 'M'}${x(i).toFixed(1)},${yE(p.tdee + p.tdeeSd).toFixed(1)}`).join(' ')} ${[...points].reverse().map((p, k) => `L${x(n - 1 - k).toFixed(1)},${yE(p.tdee - p.tdeeSd).toFixed(1)}`).join(' ')} Z` : ''
  const ticks = (a: number, b: number, k: number) => Array.from({ length: k }, (_, i) => a + ((b - a) * i) / (k - 1))
  const idx = hover ?? n - 1
  const cur = points[idx]
  const first = points[0], last = points[n - 1]
  const summary = last && first ? `Trend weight ${last.trend.toFixed(1)} kg on ${longDate(last.date)}, from ${first.trend.toFixed(1)} kg on ${longDate(first.date)}. Estimated expenditure ${energyFmt(last.tdee)}.` : 'No data yet'

  const pick = (e: React.PointerEvent<SVGSVGElement>) => {
    const r = e.currentTarget.getBoundingClientRect()
    const px = ((e.clientX - r.left) / r.width) * W
    setHover(Math.max(0, Math.min(n - 1, Math.round(((px - PAD_L) / (W - PAD_L - PAD_R)) * (n - 1)))))
  }

  if (n === 0) return null
  return (
    <figure className="m-0">
      <svg viewBox={`0 0 ${W} ${H1 + GAP + H2 + 30}`} className="w-full touch-pan-y select-none" role="img" aria-label={summary}
        onPointerMove={pick} onPointerDown={pick} onPointerLeave={() => setHover(null)}>
        {/* weight panel */}
        {ticks(m.w0, m.w1, 4).map((t) => (<g key={`w${t}`}><line x1={PAD_L} x2={W - PAD_R} y1={yW(t)} y2={yW(t)} stroke="var(--raised)" strokeWidth="1" /><text x={PAD_L - 4} y={yW(t) + 3} textAnchor="end" fontSize="9" fill="var(--muted)">{t.toFixed(1)}</text></g>))}
        {points.map((p, i) => (p.scale !== null ? <circle key={p.date} cx={x(i)} cy={yW(p.scale)} r="2.4" fill="var(--muted)" opacity="0.75" /> : null))}
        <path d={trend} fill="none" stroke="var(--accent)" strokeWidth="2.4" strokeLinejoin="round" strokeLinecap="round" />
        <text x={PAD_L} y={H1 + 11} fontSize="9" fill="var(--muted)">kg · dots are the scale, the line is the trend</text>
        {/* expenditure panel */}
        {ticks(m.e0, m.e1, 3).map((t) => (<g key={`e${t}`}><line x1={PAD_L} x2={W - PAD_R} y1={yE(t)} y2={yE(t)} stroke="var(--raised)" strokeWidth="1" /><text x={PAD_L - 4} y={yE(t) + 3} textAnchor="end" fontSize="9" fill="var(--muted)">{Math.round(t / 10) * 10}</text></g>))}
        {band ? <path d={band} fill="var(--carbs)" opacity="0.18" /> : null}
        <path d={tdee} fill="none" stroke="var(--carbs)" strokeWidth="2" strokeLinejoin="round" strokeLinecap="round" />
        <text x={PAD_L} y={H1 + GAP + H2 + 12} fontSize="9" fill="var(--muted)">kcal/day · estimated expenditure ± uncertainty</text>
        {/* shared x axis */}
        {[0, Math.floor((n - 1) / 2), n - 1].filter((v, i, a) => a.indexOf(v) === i).map((i) => <text key={i} x={x(i)} y={H1 + GAP + H2 + 29} fontSize="9" fill="var(--muted)" textAnchor={i === 0 ? 'start' : i === n - 1 ? 'end' : 'middle'}>{shortDate(points[i]!.date)}</text>)}
        {hover !== null ? <line x1={x(idx)} x2={x(idx)} y1="4" y2={H1 + GAP + H2} stroke="var(--ink)" strokeWidth="1" opacity="0.4" /> : null}
      </svg>
      <figcaption className="mt-1 text-center text-xs text-muted tabular-nums" aria-live="polite">
        {cur ? `${longDate(cur.date)} · ${cur.scale !== null ? `scale ${cur.scale.toFixed(1)} · ` : ''}trend ${cur.trend.toFixed(1)} kg · ${energyFmt(cur.tdee)}` : ''}
      </figcaption>
    </figure>
  )
}
