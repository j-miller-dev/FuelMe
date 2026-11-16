import { useEffect, useState } from 'react'
import type { LabResult } from '../lib/outbox'
import { MARKERS, addResult, deleteResult, labStatus, latest, listResults } from '../lib/labs'
import { todayIso } from '../lib/data'

const inp = 'mt-1 min-h-11 w-full rounded-xl bg-raised px-3 text-white'
const chip = { low: 'bg-fat/25 text-fat', high: 'bg-carbs/25 text-carbs', ok: 'bg-protein/25 text-protein', unset: 'bg-raised text-muted' } as const
const label = { low: 'Below range', high: 'Above range', ok: 'In range', unset: 'No range entered' } as const

export default function BloodTests() {
  const [all, setAll] = useState<LabResult[]>([])
  const [f, setF] = useState({ marker: 'vitd', date: todayIso(), value: '', low: '', high: '' })
  const [open, setOpen] = useState<string | null>(null)
  const refresh = () => void listResults().then(setAll)
  useEffect(refresh, [])
  const m = MARKERS.find(x => x.key === f.marker)!
  const pickMarker = (key: string) => { const prev = all.find(r => r.marker === key); setF({ ...f, marker: key, low: prev?.refLow != null ? String(prev.refLow) : '', high: prev?.refHigh != null ? String(prev.refHigh) : '' }) }
  const lat = [...latest(all).values()]
  return (
    <div className="space-y-3 pb-28">
      <p className="rounded-2xl border border-carbs/40 bg-surface p-3 text-xs text-carbs">Private notes, not medical advice. Type the reference range printed on your own lab report, because ranges differ between labs. Talk to your GP before starting or changing any supplement, especially iron and high-dose vitamin D.</p>
      <section className="space-y-2 rounded-2xl bg-surface p-4">
        <div className="text-sm font-medium">Add a result</div>
        <label className="block text-sm text-muted">Test<select className={inp} value={f.marker} onChange={e => pickMarker(e.target.value)}>{MARKERS.map(x => <option key={x.key} value={x.key}>{x.name} ({x.unit})</option>)}</select></label>
        <div className="flex gap-2"><label className="flex-1 text-sm text-muted">Date<input type="date" className={inp} value={f.date} onChange={e => setF({ ...f, date: e.target.value })} /></label>
          <label className="w-28 text-sm text-muted">Result<input type="number" step="any" inputMode="decimal" className={inp} value={f.value} onChange={e => setF({ ...f, value: e.target.value })} /></label></div>
        <div className="flex gap-2"><label className="flex-1 text-sm text-muted">Lab low ({m.unit})<input type="number" step="any" className={inp} value={f.low} onChange={e => setF({ ...f, low: e.target.value })} /></label>
          <label className="flex-1 text-sm text-muted">Lab high<input type="number" step="any" className={inp} value={f.high} onChange={e => setF({ ...f, high: e.target.value })} /></label></div>
        {m.note && <p className="text-xs text-muted">{m.note}</p>}
        <button className="min-h-12 w-full rounded-xl bg-accent font-semibold text-canvas disabled:opacity-40" disabled={f.value === ''}
          onClick={async () => { await addResult({ date: f.date, marker: f.marker, value: +f.value, refLow: f.low === '' ? undefined : +f.low, refHigh: f.high === '' ? undefined : +f.high }); setF({ ...f, value: '' }); refresh() }}>Save result</button>
      </section>
      {lat.length === 0 ? <p className="py-6 text-center text-sm text-muted">No results yet.</p> : lat.map(r => {
        const mk = MARKERS.find(x => x.key === r.marker)!, st = labStatus(r), hist = all.filter(x => x.marker === r.marker)
        return (<section key={r.marker} className="rounded-2xl bg-surface p-4">
          <button className="flex w-full items-center justify-between text-left" onClick={() => setOpen(open === r.marker ? null : r.marker)} aria-expanded={open === r.marker}>
            <span><span className="block font-medium">{mk.name}</span><span className="block text-xs text-muted">{r.date}{r.refLow != null || r.refHigh != null ? ` · lab range ${r.refLow ?? '–'} to ${r.refHigh ?? '–'}` : ''}</span></span>
            <span className="text-right"><span className="block text-lg font-semibold tabular-nums">{r.value} <span className="text-xs font-normal text-muted">{mk.unit}</span></span><span className={`rounded-full px-2 py-0.5 text-[11px] ${chip[st]}`}>{label[st]}</span></span></button>
          {open === r.marker && <div className="mt-3 space-y-2 text-sm">
            {st === 'low' && mk.foods && <p><span className="text-accent">Food ideas: </span>{mk.foods} Ask your GP whether a supplement and a retest make sense.</p>}
            {st === 'high' && <p className="text-muted">Above your lab's range. Raise it with your GP rather than changing diet or supplements on your own.</p>}
            {mk.note && <p className="text-xs text-muted">{mk.note}</p>}
            <div className="text-xs text-muted">History: {hist.map(h => `${h.date} ${h.value}`).join(' · ')}</div>
            <button className="min-h-10 text-xs text-fat" onClick={async () => { await deleteResult(r.id!); refresh() }}>Delete latest result</button></div>}
        </section>) })}
    </div>)
}
