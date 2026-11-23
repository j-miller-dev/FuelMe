import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'

interface Baseline { status: 'unknown' | 'below' | 'within' | 'above'; reason?: string; recent?: number; baseline?: number; low?: number; high?: number; pct?: number; last: number | null }
interface Signal { level: 'green' | 'amber' | 'red' | 'unknown'; headline: string; flags: string[]; goods: string[]; suggestion: string }
interface Sleep { targetMin: number; lastMin: number | null; performancePct: number | null; nights7: number; avg7Min: number | null; debt7Min: number }
interface Ratio { week: number; usual: number | null; ratio: number | null }
interface Day { date: string; sleepMin: number | null; restingHr: number | null; hrv: number | null; steps: number | null }
interface Data {
  connected: boolean; today: string; signal: Signal; hrv: Baseline; rhr: Baseline; sleep: Sleep; days: Day[]
  load: { srpe: Ratio; watch: Ratio; weeks: { weekStart: string; srpe: number; watch: number; deload: boolean }[] }
  deload: { now: boolean; thisWeek: string; nextWeek: string; nextWeekOn: boolean; weeksSince: number | null; recent: string[]; suggestion: { suggest: boolean; reasons: string[] } }
  assumptions: string[]
}

const hm = (min: number | null) => (min === null ? '–' : `${Math.floor(min / 60)} h ${String(min % 60).padStart(2, '0')} min`)
const dayLabel = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', timeZone: 'UTC' })
const TONE: Record<Signal['level'], string> = { green: 'bg-accent', amber: 'bg-carbs', red: 'bg-fat', unknown: 'bg-muted' }

function Spark({ values }: { values: (number | null)[] }) {
  const pts = values.map((v, i) => (v === null ? null : { v, i })).filter((p): p is { v: number; i: number } => p !== null)
  if (pts.length < 2) return null
  const lo = Math.min(...pts.map((p) => p.v)); const hi = Math.max(...pts.map((p) => p.v)); const span = hi - lo || 1
  const x = (i: number) => (i / Math.max(1, values.length - 1)) * 100
  const y = (v: number) => 22 - ((v - lo) / span) * 20
  return <svg viewBox="0 0 100 24" className="mt-2 h-8 w-full" role="img" aria-label="Last 14 days"><polyline fill="none" stroke="currentColor" strokeWidth="1.5" className="text-accent" points={pts.map((p) => `${x(p.i)},${y(p.v)}`).join(' ')} /></svg>
}

function BaselineCard({ title, unit, b, series, goodWhen }: { title: string; unit: string; b: Baseline; series: (number | null)[]; goodWhen: 'higher' | 'lower' }) {
  const word = b.status === 'within' ? 'within your usual range' : b.status === 'below' ? 'below your usual range' : b.status === 'above' ? 'above your usual range' : ''
  return (
    <section className="mt-3 rounded-2xl bg-surface p-4" aria-label={title}>
      <h2 className="font-semibold">{title}</h2>
      {b.status === 'unknown' ? <p className="mt-1 text-sm text-muted">{b.reason}{b.last !== null ? ` Latest reading: ${b.last}${unit}.` : ''}</p> : (
        <>
          <p className="mt-1 text-sm"><span className="tabular-nums">{b.recent}{unit}</span> over the last 7 days, {word}. <span className="text-muted">Your usual is {b.baseline}{unit} ({b.low} to {b.high}).</span></p>
          <p className="text-xs text-muted">{goodWhen === 'higher' ? 'Higher is usually the better direction.' : 'Lower is usually the better direction.'}{b.last !== null ? ` Latest reading: ${b.last}${unit}.` : ''}</p>
        </>
      )}
      <Spark values={series} />
    </section>
  )
}

export default function Recovery({ data }: { data: Data }) {
  const [hours, setHours] = useState(String(data.sleep.targetMin / 60))
  const d = data.deload
  const setDeload = (date: string, on: boolean) => router.post('/recovery/deload', { date, on }, { preserveScroll: true })
  const days = [...data.days].reverse()
  const maxLoad = Math.max(1, ...data.load.weeks.map((w) => Math.max(w.srpe, w.watch)))

  return (
    <AppLayout>
      <Head title="Recovery" />
      <header className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Recovery</h1>
        <Link href="/watch" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Watch data</Link>
      </header>
      <p className="mt-1 text-sm text-muted">Your watch&apos;s numbers set against your own normal. A suggestion from the flags listed, not a diagnosis and not medical advice. It never changes your calorie target.</p>

      {!data.connected ? (
        <p className="mt-4 rounded-2xl bg-surface p-4 text-sm">No watch data yet. <Link href="/watch" className="text-accent underline">Connect Intervals.icu</Link> and this fills in after the first sync.</p>
      ) : (
        <>
          <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="Recovery signal">
            <div className="flex items-center gap-2"><span className={`inline-block h-3 w-3 rounded-full ${TONE[data.signal.level]}`} aria-hidden /><h2 className="font-semibold">{data.signal.headline}</h2></div>
            {data.signal.flags.length ? <ul className="mt-2 list-disc space-y-1 pl-5 text-sm">{data.signal.flags.map((f) => <li key={f}>{f}</li>)}</ul> : null}
            {data.signal.goods.length ? <ul className="mt-2 list-disc space-y-1 pl-5 text-sm text-muted">{data.signal.goods.map((f) => <li key={f}>{f}</li>)}</ul> : null}
            <p className="mt-2 text-sm">{data.signal.suggestion}</p>
          </section>

          <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Sleep">
            <h2 className="font-semibold">Sleep</h2>
            <p className="mt-1 text-sm">{data.sleep.lastMin !== null ? <>Last night <span className="tabular-nums">{hm(data.sleep.lastMin)}</span>, {data.sleep.performancePct}% of your target.</> : 'No sleep recorded for last night yet.'}</p>
            <p className="text-xs text-muted tabular-nums">{data.sleep.nights7 ? `Average ${hm(data.sleep.avg7Min)} over ${data.sleep.nights7} nights. Short of target by ${hm(data.sleep.debt7Min)} in total.` : null} Duration only, not quality.</p>
            <form className="mt-3 flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); router.put('/recovery/sleep-target', { hours }, { preserveScroll: true }) }}>
              <label className="text-xs text-muted">Your sleep target (hours)<input type="number" step="0.25" min="5" max="11" value={hours} onChange={(e) => setHours(e.target.value)} className="mt-1 block min-h-11 w-28 rounded-xl bg-canvas/60 px-3 text-sm text-text" /></label>
              <Button type="submit">Save</Button>
            </form>
          </section>

          <BaselineCard title="HRV" unit=" ms" b={data.hrv} series={days.map((x) => x.hrv)} goodWhen="higher" />
          <BaselineCard title="Resting heart rate" unit=" bpm" b={data.rhr} series={days.map((x) => x.restingHr)} goodWhen="lower" />

          <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Training load">
            <h2 className="font-semibold">Training load</h2>
            {[['Sessions you rated (RPE x minutes)', data.load.srpe], ['Your watch\'s own load', data.load.watch]].map(([label, r]) => {
              const x = r as Ratio
              return <p key={label as string} className="mt-1 text-sm">{label as string}: {x.ratio === null ? <span className="text-muted">not enough history yet</span> : <><span className="tabular-nums">{x.week}</span> this week, <span className="tabular-nums">{x.ratio}x</span> your usual (<span className="tabular-nums">{x.usual}</span>).</>}</p>
            })}
            <ul className="mt-3 space-y-1" aria-label="Last 6 weeks">
              {data.load.weeks.map((w) => (
                <li key={w.weekStart} className="text-xs">
                  <div className="flex justify-between text-muted"><span>Week of {dayLabel(w.weekStart)}{w.deload ? ' (deload)' : ''}</span><span className="tabular-nums">{w.srpe} · {w.watch}</span></div>
                  <div className="mt-0.5 h-1.5 rounded bg-raised"><div className="h-1.5 rounded bg-accent" style={{ width: `${(w.srpe / maxLoad) * 100}%` }} /></div>
                  <div className="mt-0.5 h-1.5 rounded bg-raised"><div className="h-1.5 rounded bg-carbs" style={{ width: `${(w.watch / maxLoad) * 100}%` }} /></div>
                </li>
              ))}
            </ul>
            <p className="mt-2 text-xs text-muted">Green = rated sessions, amber = watch load. Two different scales, so they are not added together. Rest days count as zero, so it assumes your sessions are being recorded somewhere.</p>
          </section>

          <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Deload">
            <h2 className="font-semibold">Deload weeks</h2>
            <p className="mt-1 text-sm">{d.now ? 'This week is labelled a deload.' : d.weeksSince === null ? 'No deload weeks labelled yet.' : `Your last labelled deload was ${d.weeksSince} week${d.weeksSince === 1 ? '' : 's'} ago.`}</p>
            {d.suggestion.suggest ? (
              <div className="mt-2 rounded-xl bg-raised p-3 text-sm">
                <p className="font-medium">A deload might be worth considering.</p>
                <ul className="mt-1 list-disc pl-5 text-muted">{d.suggestion.reasons.map((r) => <li key={r}>{r}</li>)}</ul>
                <p className="mt-1 text-xs text-muted">Only a suggestion. It is your call.</p>
              </div>
            ) : null}
            <div className="mt-3 flex flex-wrap gap-2">
              <Button type="button" onClick={() => setDeload(d.thisWeek, !d.now)}>{d.now ? 'Remove label from this week' : 'Mark this week as a deload'}</Button>
              <button type="button" className="min-h-11 px-3 text-sm text-accent underline" onClick={() => setDeload(d.nextWeek, !d.nextWeekOn)}>{d.nextWeekOn ? 'Remove label from next week' : 'Plan next week as a deload'}</button>
            </div>
            <p className="mt-2 text-xs text-muted">A labelled week is left out of lifting stall checks, best-set comparisons and your &quot;usual&quot; load, and recovery does not flag its lower load. It does not change your food targets. FUEL does not tell you how to deload.</p>
          </section>

          <details className="mt-4 text-xs text-muted"><summary className="min-h-11 cursor-pointer leading-[2.75rem]">How this works</summary><ul className="list-disc space-y-1 pl-5">{data.assumptions.map((a) => <li key={a}>{a}</li>)}</ul></details>
        </>
      )}
    </AppLayout>
  )
}
