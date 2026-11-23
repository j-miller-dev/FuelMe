import { Head, Link, router, usePage } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import AppLayout from '@/layouts/AppLayout'
import { TrendChart } from '@/components/progress/TrendChart'
import { api } from '@/lib/http'
import { outbox } from '@/lib/outbox'
import { RANGES, longDate, type ProgressData } from '@/lib/progress'
import { energyLabel, fmtEnergy, type EnergyUnit } from '@/lib/units'

export default function Progress({ data, today }: { data: ProgressData | null; today: string }) {
  const unit = (usePage().props as { energyUnit?: EnergyUnit }).energyUnit ?? 'kcal'
  const [range, setRange] = useState<number>(84)
  const [kg, setKg] = useState('')
  const [msg, setMsg] = useState<{ text: string; error?: boolean } | null>(null)
  const [busy, setBusy] = useState(false)
  const [applying, setApplying] = useState(false)
  const [showWhy, setShowWhy] = useState(false)
  const reload = () => router.reload({ only: ['data'] })
  const fmtE = (k: number) => `${fmtEnergy(k, unit)} ${energyLabel(unit)}/day`

  const points = useMemo(() => (data ? (range === 0 ? data.series : data.series.slice(-range - 1)) : []), [data, range])
  const num = Number(kg.replace(',', '.'))
  const valid = Number.isFinite(num) && num >= 30 && num <= 300

  async function weigh() {
    setBusy(true); setMsg(null)
    try {
      // Through the outbox: a weigh-in in a gym basement with no signal is saved on the phone and sent later (one per day, so a replay is harmless).
      const r = await outbox.send({ method: 'POST', url: '/weights', kind: 'raw', label: `Weigh-in ${num} kg`, body: { kg: num } })
      if (r.status === 'failed') throw new Error(r.message)
      setKg('')
      if (r.status === 'queued') { setMsg({ text: `Saved ${num} kg on this phone. It will sync when you are back online.` }); return }
      const d = r.data as { saved: { kg: number }; note: string | null }
      setMsg({ text: `Saved ${d.saved.kg} kg.${d.note ? ' ' + d.note : ''}` }); reload()
    } catch (e) { setMsg({ text: e instanceof Error ? e.message : 'Could not save.', error: true }) } finally { setBusy(false) }
  }
  async function removeWeight(id: number) {
    setMsg(null)
    try { await api(`/weights/${id}`, { method: 'DELETE' }); reload() } catch (e) { setMsg({ text: e instanceof Error ? e.message : 'Could not delete.', error: true }) }
  }
  async function apply(kcal: number) {
    setApplying(true); setMsg(null)
    try { await api('/progress/checkin', { method: 'POST', body: { kcal } }); setMsg({ text: 'Target updated. See Today and Plan.' }); reload() } catch (e) { setMsg({ text: e instanceof Error ? e.message : 'Could not apply.', error: true }) } finally { setApplying(false) }
  }

  const c = data?.checkin
  const s = data?.summary
  return (
    <AppLayout>
      <Head title="Progress" />
      <h1 className="text-2xl font-bold">Progress</h1>
      <Link href="/lifting" className="mt-2 flex min-h-11 items-center justify-between rounded-2xl bg-surface px-4 text-sm"><span>Lifting (Hevy)</span><span className="text-accent" aria-hidden>›</span></Link>
      <Link href="/watch" className="mt-2 flex min-h-11 items-center justify-between rounded-2xl bg-surface px-4 text-sm"><span>Watch data (Intervals.icu)</span><span className="text-accent" aria-hidden>›</span></Link>
      <Link href="/principles" className="mt-2 flex min-h-11 items-center justify-between rounded-2xl bg-surface px-4 text-sm"><span>My notes (principles)</span><span className="text-accent" aria-hidden>›</span></Link>
      <Link href="/coach" className="mt-2 flex min-h-11 items-center justify-between rounded-2xl bg-surface px-4 text-sm"><span>Ask the coach</span><span className="text-accent" aria-hidden>›</span></Link>
      <Link href="/review" className="mt-2 flex min-h-11 items-center justify-between rounded-2xl bg-surface px-4 text-sm"><span>Weekly review and event mode</span><span className="text-accent" aria-hidden>›</span></Link>

      <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="Weigh in">
        <form className="flex items-end gap-2" onSubmit={(e) => { e.preventDefault(); if (valid && !busy) void weigh() }}>
          <label className="flex-1 text-xs text-muted">Today's weight (kg)
            <input inputMode="decimal" value={kg} onChange={(e) => setKg(e.target.value)} placeholder={s ? s.trendKg.toFixed(1) : '74.0'} autoComplete="off" data-1p-ignore
              className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-xl tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" />
          </label>
          <button type="submit" disabled={!valid || busy} className="min-h-12 rounded-xl bg-accent px-5 font-semibold text-on-accent disabled:opacity-40">{busy ? 'Saving…' : 'Weigh in'}</button>
        </form>
        <p className="mt-2 text-xs text-muted">Weigh yourself under the same conditions (after the toilet, before eating). One reading is noisy, so the trend uses all of them.</p>
        {msg ? <p role={msg.error ? 'alert' : 'status'} className={`mt-2 text-sm ${msg.error ? 'text-danger' : 'text-accent'}`}>{msg.text}</p> : null}
      </section>

      {!data || !s ? <p className="mt-4 rounded-2xl bg-surface p-5 text-sm text-muted">Log your first weigh-in to start your trend.</p> : (
        <>
          <section className="mt-4 grid grid-cols-3 gap-2 text-center" aria-label="Summary">
            <div className="rounded-2xl bg-surface p-3"><div className="text-xl font-bold tabular-nums">{s.trendKg.toFixed(1)}</div><div className="text-xs text-muted">trend kg</div></div>
            <div className="rounded-2xl bg-surface p-3">
              <div className="text-xl font-bold tabular-nums">{s.ratePctPerWeek === null ? '–' : `${s.ratePctPerWeek > 0 ? '+' : ''}${s.ratePctPerWeek.toFixed(2)}%`}</div>
              <div className="text-xs text-muted">per week{s.ratePctPerWeek !== null ? ` (goal ${s.goalPctPerWeek > 0 ? '+' : ''}${s.goalPctPerWeek.toFixed(2)}%)` : ''}</div>
            </div>
            <div className="rounded-2xl bg-surface p-3">
              <div className="text-xl font-bold tabular-nums">{fmtEnergy(s.tdee, unit)}</div>
              <div className="text-xs text-muted">{energyLabel(unit)}/day burned{s.calibrating ? ' (calibrating)' : ` ±${fmtEnergy(s.tdeeSd, unit)}`}</div>
            </div>
          </section>
          {s.calibrating ? <p className="mt-2 text-xs text-muted">Calibrating: your expenditure is still a starting estimate. It settles as weigh-ins and food logs build up, usually within three to four weeks.</p> : null}

          <div className="mt-4 flex gap-2" role="group" aria-label="Chart range">
            {RANGES.map((r) => <button key={r.k} aria-pressed={range === r.k} onClick={() => setRange(r.k)} className={`min-h-10 flex-1 rounded-full text-sm ${range === r.k ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-surface text-muted'}`}>{r.label}</button>)}
          </div>
          <div className="mt-2 rounded-2xl bg-surface p-3"><TrendChart points={points} energyFmt={fmtE} /></div>

          {c ? (
            <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="Weekly check-in">
              <div className="flex items-center justify-between"><h2 className="font-semibold">Weekly check-in</h2>
                <span className={`rounded-full px-2 py-0.5 text-xs ${c.status === 'ready' ? 'bg-accent/20 text-accent' : 'bg-raised text-muted'}`}>{c.status === 'ready' ? 'Ready' : c.status === 'wait' ? 'Next week' : 'Needs more data'}</span></div>
              <p className="mt-2 text-sm">Target now: <span className="font-semibold tabular-nums">{fmtEnergy(s.target, unit)} {energyLabel(unit)}</span>{c.status === 'ready' && c.change !== 0 ? <> → <span className="font-semibold tabular-nums text-accent">{fmtEnergy(c.proposedKcal, unit)} {energyLabel(unit)}</span> ({c.change > 0 ? '+' : ''}{fmtEnergy(c.change, unit)})</> : null}</p>
              <ul className="mt-2 space-y-1 text-sm text-muted">{(showWhy || c.status !== 'ready' ? c.lines : c.lines.slice(-1)).map((l, i) => <li key={i}>{l}</li>)}</ul>
              {c.status === 'ready' ? (
                <>
                  <button onClick={() => setShowWhy((v) => !v)} aria-expanded={showWhy} className="mt-2 min-h-10 text-sm text-accent">{showWhy ? 'Hide the working' : 'Why this change?'}</button>
                  {c.change !== 0 ? (
                    <div className="mt-2 flex gap-2">
                      <button disabled={applying} onClick={() => void apply(c.proposedKcal)} className="min-h-12 flex-1 rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">{applying ? 'Applying…' : 'Update my target'}</button>
                    </div>
                  ) : null}
                  <p className="mt-2 text-xs text-muted">Nothing changes until you tap. Protein stays set by your body weight; carbohydrate takes the change.</p>
                </>
              ) : null}
            </section>
          ) : null}

          {data.undereating.flag ? (
            <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="Eating a little under target">
              <h2 className="font-semibold">Eating a little under target</h2>
              <p className="mt-1 text-sm text-muted">On {data.undereating.lowDays} of your last {data.undereating.loggedDays} logged days you came in under 90% of your target, averaging {Math.round(data.undereating.avgPct)}%. That happens, and it is easy to fix.
                {data.undereating.gapKcal > 0 ? ` Closing about ${fmtEnergy(data.undereating.gapKcal, unit)} ${energyLabel(unit)}${data.undereating.gapProtein > 0 ? ` and ${data.undereating.gapProtein} g protein` : ''} a day would get you there.` : ''}</p>
              {data.undereating.adds.length ? (
                <>
                  <p className="mt-2 text-sm">Easy adds from foods you already eat:</p>
                  <ul className="mt-1 space-y-1">{data.undereating.adds.map((a) => <li key={a.name} className="flex items-center justify-between rounded-xl bg-raised px-3 py-2 text-sm"><span className="truncate">{a.name} <span className="text-muted">{Math.round(a.grams)} {a.unit}</span></span><span className="ml-2 shrink-0 tabular-nums text-muted">+{fmtEnergy(a.kcal, unit)} · {Math.round(a.protein)} g P</span></li>)}</ul>
                </>
              ) : null}
            </section>
          ) : null}

          {data.insights.notes.length ? (
            <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="This week">
              <h2 className="font-semibold">This week</h2>
              {Object.keys(data.insights.slots).length ? (
                <div className="mt-2" aria-label="Average protein per meal">
                  {(['breakfast', 'lunch', 'dinner'] as const).map((k) => data.insights.slots[k] !== undefined ? (
                    <div key={k} className="flex items-center gap-2 text-xs"><span className="w-16 capitalize text-muted">{k}</span>
                      <div className="h-2 flex-1 overflow-hidden rounded-full bg-raised"><div className="h-full bg-protein" style={{ width: `${Math.min(100, (data.insights.slots[k]! / Math.max(1, data.insights.perMealG)) * 100)}%`, background: 'var(--protein)' }} /></div>
                      <span className="w-12 text-right tabular-nums">{data.insights.slots[k]} g</span></div>
                  ) : null)}
                  <p className="mt-1 text-xs text-muted">Protein per meal, averaged over the days you ate it. A good dose is about {data.insights.perMealG} g.</p>
                </div>
              ) : null}
              <ul className="mt-3 space-y-1 text-sm text-muted">{data.insights.notes.map((n, i) => <li key={i}>{n}</li>)}</ul>
            </section>
          ) : null}

          <section className="mt-4" aria-label="Recent weigh-ins">
            <h2 className="text-sm font-semibold text-muted">Recent weigh-ins</h2>
            <ul className="mt-1">{data.weights.map((w) => (
              <li key={w.date} className="flex min-h-12 items-center border-b border-raised/70 text-sm"><span className="flex-1">{w.date === today ? 'Today' : longDate(w.date)}</span><span className="w-20 text-right tabular-nums">{w.kg.toFixed(1)} kg</span>
                <button onClick={() => void removeWeight(w.id)} aria-label={`Delete the ${longDate(w.date)} weigh-in`} className="size-11 text-muted">✕</button></li>))}</ul>
            <p className="mt-2 text-xs text-muted">{data.adherence.weighIns14} weigh-ins in the last 14 days · food logged on {data.adherence.loggedDays7} of the last 7 days.</p>
          </section>
        </>
      )}
    </AppLayout>
  )
}
