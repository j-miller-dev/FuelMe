import { Link } from '@inertiajs/react'
import { Activity, Footprints, Heart, Moon } from 'lucide-react'

export interface Glance {
  today: string; sleepTargetMin: number; sleepDebtMin: number; sleepAvgMin: number | null
  hrv: { status: string; low: number | null; high: number | null; baseline: number | null }
  rhr: { status: string; low: number | null; high: number | null; baseline: number | null }
  days: { date: string; sleepMin: number | null; restingHr: number | null; hrv: number | null; steps: number | null }[]
}

const hm = (m: number) => `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}`
const dow = (d: string) => ['S', 'M', 'T', 'W', 'T', 'F', 'S'][new Date(d + 'T12:00:00').getDay()]

/** Last night as a ring against your sleep target, plus 7 nights as bars. Colour is never the only cue: the number and a word are always shown. */
function Sleep({ g }: { g: Glance }) {
  const last = g.days[g.days.length - 1]?.sleepMin ?? null
  const pct = last === null ? 0 : Math.min(100, Math.round((last / g.sleepTargetMin) * 100))
  const tone = last === null ? 'var(--color-muted)' : pct >= 90 ? 'var(--color-accent)' : pct >= 75 ? 'var(--color-carbs)' : 'var(--color-fat)'
  const word = last === null ? 'No data' : pct >= 90 ? 'On target' : pct >= 75 ? 'A bit short' : 'Short'
  const r = 34, c = 2 * Math.PI * r
  const max = Math.max(g.sleepTargetMin * 1.25, ...g.days.map((d) => d.sleepMin ?? 0))
  const H = 56, y = (m: number) => H - (m / max) * H
  return (
    <div className="flex items-center gap-4">
      <div className="relative size-24 shrink-0" role="img" aria-label={last === null ? 'No sleep recorded for last night' : `Last night ${hm(last)}, ${pct}% of your ${hm(g.sleepTargetMin)} target`}>
        <svg viewBox="0 0 88 88" className="size-24 -rotate-90"><circle cx="44" cy="44" r={r} fill="none" stroke="currentColor" className="text-raised" strokeWidth="9" /><circle cx="44" cy="44" r={r} fill="none" stroke={tone} strokeWidth="9" strokeLinecap="round" strokeDasharray={`${(pct / 100) * c} ${c}`} /></svg>
        <div className="absolute inset-0 flex flex-col items-center justify-center"><Moon className="size-3.5 text-muted" aria-hidden /><span className="text-base font-semibold leading-tight">{last === null ? '–' : hm(last)}</span></div>
      </div>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-medium">{word}{last !== null ? <span className="text-muted"> · {pct}% of {hm(g.sleepTargetMin)}</span> : null}</p>
        <svg viewBox={`0 0 140 ${H + 14}`} className="mt-1 w-full" role="img" aria-label="Sleep over the last 7 nights">
          <line x1="0" x2="140" y1={y(g.sleepTargetMin)} y2={y(g.sleepTargetMin)} stroke="currentColor" className="text-muted" strokeDasharray="3 3" strokeWidth="1" />
          {g.days.map((d, i) => {
            const x = i * 20 + 3
            const m = d.sleepMin
            return (
              <g key={d.date}>
                {m === null ? <rect x={x} y={H - 3} width="14" height="3" rx="1.5" className="fill-raised" /> : <rect x={x} y={y(m)} width="14" height={H - y(m)} rx="3" fill={m >= g.sleepTargetMin * 0.9 ? 'var(--color-accent)' : m >= g.sleepTargetMin * 0.75 ? 'var(--color-carbs)' : 'var(--color-fat)'} />}
                <text x={x + 7} y={H + 11} textAnchor="middle" fontSize="9" className="fill-muted">{dow(d.date)}</text>
              </g>
            )
          })}
        </svg>
        <p className="text-xs text-muted">Dashed line = your target.{g.sleepAvgMin !== null ? ` 7-night average ${hm(g.sleepAvgMin)}.` : ''}</p>
      </div>
    </div>
  )
}

/** A tiny line with your usual range as a band; today's point is the big dot. */
function Line({ values, band, label, unit, Icon, goodHigh, status }: { values: (number | null)[]; band: { low: number | null; high: number | null }; label: string; unit: string; Icon: typeof Heart; goodHigh: boolean; status: string }) {
  const pts = values.map((v, i) => (v === null ? null : { i, v }))
  const have = pts.filter((p): p is { i: number; v: number } => p !== null)
  const last = values[values.length - 1] ?? null
  const all = [...have.map((p) => p.v), ...(band.low !== null && band.high !== null ? [band.low, band.high] : [])]
  const lo = all.length ? Math.min(...all) : 0, hi = all.length ? Math.max(...all) : 1
  const pad = (hi - lo || 1) * 0.2
  const W = 120, H = 44
  const x = (i: number) => 4 + (i / (values.length - 1)) * (W - 8)
  const y = (v: number) => H - 4 - ((v - (lo - pad)) / (hi - lo + 2 * pad)) * (H - 8)
  const path = have.map((p, k) => `${k ? 'L' : 'M'}${x(p.i).toFixed(1)},${y(p.v).toFixed(1)}`).join(' ')
  const word = status === 'unknown' ? 'Learning your usual' : status === 'within' ? 'Usual range' : status === 'below' ? (goodHigh ? 'Lower than usual' : 'Lower than usual') : 'Higher than usual'
  const bad = (status === 'below' && goodHigh) || (status === 'above' && !goodHigh)
  const tone = bad ? 'var(--color-fat)' : status === 'within' ? 'var(--color-accent)' : 'var(--color-muted)'
  const lastPt = have[have.length - 1]
  return (
    <div className="rounded-xl bg-canvas/60 p-3">
      <p className="flex items-center gap-1 text-xs text-muted"><Icon className="size-3.5" aria-hidden />{label}</p>
      <p className="mt-0.5 text-xl font-semibold leading-none">{last ?? '–'}<span className="ml-1 text-xs font-normal text-muted">{last !== null ? unit : ''}</span></p>
      <svg viewBox={`0 0 ${W} ${H}`} className="mt-1 w-full" role="img" aria-label={`${label} over the last 7 days: ${word}`}>
        {band.low !== null && band.high !== null ? <rect x="0" y={y(band.high)} width={W} height={Math.max(2, y(band.low) - y(band.high))} rx="3" fill="var(--color-accent)" opacity="0.15" /> : null}
        {path ? <path d={path} fill="none" stroke="currentColor" className="text-muted" strokeWidth="1.5" strokeLinejoin="round" /> : null}
        {have.map((p) => <circle key={p.i} cx={x(p.i)} cy={y(p.v)} r="2" className="fill-muted" />)}
        {lastPt ? <circle cx={x(lastPt.i)} cy={y(lastPt.v)} r="4" fill={tone} /> : null}
      </svg>
      <p className="text-xs" style={{ color: tone }}>{word}</p>
    </div>
  )
}

function Steps({ days }: { days: Glance['days'] }) {
  const last = days[days.length - 1]?.steps ?? null
  const max = Math.max(1000, ...days.map((d) => d.steps ?? 0))
  const H = 36
  return (
    <div className="rounded-xl bg-canvas/60 p-3">
      <p className="flex items-center gap-1 text-xs text-muted"><Footprints className="size-3.5" aria-hidden />Steps</p>
      <p className="mt-0.5 text-xl font-semibold leading-none">{last !== null ? last.toLocaleString() : '–'}</p>
      <svg viewBox={`0 0 120 ${H + 12}`} className="mt-1 w-full" role="img" aria-label="Steps over the last 7 days">
        {days.map((d, i) => {
          const h = d.steps === null ? 0 : Math.max(2, (d.steps / max) * H)
          return <g key={d.date}><rect x={i * 17 + 2} y={H - h} width="12" height={h || 2} rx="3" className={d.steps === null ? 'fill-raised' : i === days.length - 1 ? 'fill-accent' : 'fill-muted'} opacity={i === days.length - 1 || d.steps === null ? 1 : 0.55} /><text x={i * 17 + 8} y={H + 10} textAnchor="middle" fontSize="9" className="fill-muted">{dow(d.date)}</text></g>
        })}
      </svg>
      <p className="text-xs text-muted">Today so far counts as it syncs.</p>
    </div>
  )
}

export function WatchGlance({ g }: { g: Glance }) {
  const hasAny = g.days.some((d) => d.sleepMin !== null || d.hrv !== null || d.restingHr !== null || d.steps !== null)
  if (!hasAny) return null
  return (
    <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="From your watch">
      <div className="mb-3 flex items-center justify-between"><h2 className="flex items-center gap-2 text-sm font-semibold"><Activity className="size-4 text-accent" aria-hidden />Last night and this week</h2><Link href="/recovery" className="text-xs text-muted">Details ›</Link></div>
      <Sleep g={g} />
      <div className="mt-3 grid grid-cols-2 gap-2">
        <Line values={g.days.map((d) => d.hrv)} band={g.hrv} label="HRV" unit="ms" Icon={Activity} goodHigh status={g.hrv.status} />
        <Line values={g.days.map((d) => d.restingHr)} band={g.rhr} label="Resting HR" unit="bpm" Icon={Heart} goodHigh={false} status={g.rhr.status} />
      </div>
      <div className="mt-2"><Steps days={g.days} /></div>
      <p className="mt-2 text-xs text-muted">Shaded band = your usual range. Compared only with you, not a medical reading.</p>
    </section>
  )
}
