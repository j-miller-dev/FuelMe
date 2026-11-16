import { fmtDate } from './data'

export type Range = '1W' | '1M' | '3M' | '6M' | '1Y' | 'All'
export const RANGE_DAYS: Record<Range, number> = { '1W': 7, '1M': 30, '3M': 90, '6M': 180, '1Y': 365, All: 100000 }
export const RANGES = Object.keys(RANGE_DAYS) as Range[]

export function RangeChips({ value, onChange }: { value: Range; onChange: (r: Range) => void }) {
  return <div className="flex gap-1 rounded-2xl bg-raised p-1" role="tablist" aria-label="Date range">{RANGES.map(r => (
    <button key={r} role="tab" aria-selected={value === r} onClick={() => onChange(r)} className={`min-h-11 flex-1 rounded-xl text-xs ${value === r ? 'bg-canvas font-semibold text-white' : 'text-muted'}`}>{r}</button>))}</div>
}

export type Pt = { date: string; raw?: number; line: number; lo?: number; hi?: number }
/** Faint raw curve + bold trend line with markers (few points) and an optional uncertainty band. */
export function TrendChart({ pts, fit, color = 'var(--color-accent)', unit = '', h = 170 }: { pts: Pt[]; fit: boolean; color?: string; unit?: string; h?: number }) {
  const W = 320, P = 30
  const all = pts.flatMap(p => [p.raw, p.line, p.lo, p.hi].filter((v): v is number => v !== undefined))
  let lo = Math.min(...all), hi = Math.max(...all); const pad = (hi - lo) * 0.08 || 0.5
  lo -= pad; hi += pad
  if (!fit) { const m = (lo + hi) / 2, span = Math.max(hi - lo, 1) * 2.2; lo = m - span / 2; hi = m + span / 2 }
  const n = Math.max(pts.length - 1, 1), X = (i: number) => 4 + i / n * (W - P - 8), Y = (v: number) => 6 + (1 - (v - lo) / (hi - lo)) * (h - 26)
  const smooth = (key: 'raw' | 'line') => pts.flatMap((p, i) => p[key] === undefined ? [] : [`${X(i)},${Y(p[key]!)}`]).join(' ')
  const band = pts.every(p => p.lo !== undefined) ? [...pts.map((p, i) => `${X(i)},${Y(p.hi!)}`), ...[...pts].map((p, i) => ({ p, i })).reverse().map(({ p, i }) => `${X(i)},${Y(p.lo!)}`)].join(' ') : null
  const ticks = [0, Math.floor(n / 2), n]
  const dec = unit === 'kg' ? 1 : 0
  return (
    <svg viewBox={`0 0 ${W} ${h}`} className="w-full" role="img" aria-label="Trend chart">
      {[0, 1, 2, 3].map(g => { const v = hi - (hi - lo) * g / 3; return <g key={g}><line x1="0" x2={W - P} y1={Y(v)} y2={Y(v)} stroke="white" strokeOpacity=".08" strokeDasharray="3 3" /><text x={W - P + 4} y={Y(v) + 3} fontSize="9" fill="var(--color-muted)">{v.toFixed(dec)}</text></g> })}
      {band && <polygon points={band} fill={color} opacity=".16" />}
      <polyline fill="none" stroke={color} strokeOpacity=".35" strokeWidth="1.5" points={smooth('raw')} />
      <polyline fill="none" stroke={color} strokeWidth="2.5" strokeLinejoin="round" points={smooth('line')} />
      {pts.length <= 45 && pts.map((p, i) => <circle key={i} cx={X(i)} cy={Y(p.line)} r="2.4" fill="var(--color-canvas)" stroke={color} strokeWidth="1.5" />)}
      {ticks.map((i, k) => <text key={k} x={X(i)} y={h - 4} fontSize="9" textAnchor={k === 0 ? 'start' : k === 2 ? 'end' : 'middle'} fill="var(--color-muted)">{pts[i] ? fmtDate(pts[i].date).replace(/^\w+ /, '') : ''}</text>)}
    </svg>)
}

export function Stat({ label, value, unit, sub }: { label: string; value: string; unit: string; sub?: string }) {
  return <div><div className="text-xs text-muted">{label}</div><div className="text-2xl font-bold tabular-nums">{value}<span className="ml-1 text-sm font-normal text-muted">{unit}</span></div>{sub && <div className="text-xs text-muted">{sub}</div>}</div>
}
