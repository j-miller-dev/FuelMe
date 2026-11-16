import { useEffect, useState } from 'react'
import type { Supplement } from '../lib/outbox'
import { PRESETS, deleteSupp, listSupps, logsOn, saveSupp, toggleTaken, totals } from '../lib/supplements'
import { todayIso } from '../lib/data'

const inp = 'mt-1 min-h-11 w-full rounded-xl bg-raised px-3 text-white'
const UNITS: Supplement['unit'][] = ['g', 'mg', 'µg', 'IU', 'caps']

export default function Supplements() {
  const today = todayIso()
  const [supps, setSupps] = useState<Supplement[]>([]), [taken, setTaken] = useState<number[]>([]), [tot, setTot] = useState<Record<string, number>>({})
  const [draft, setDraft] = useState<Supplement | null>(null)
  const refresh = async () => { const s = await listSupps(), l = await logsOn(today); setSupps(s); setTaken(l.map(x => x.suppId)); setTot(totals(s, l)) }
  useEffect(() => { void refresh() }, [])
  return (
    <div className="space-y-3 pb-28">
      <p className="rounded-2xl bg-surface p-3 text-xs text-muted">Save what you take and its dose, then tap to log it for today. Doses are whatever you enter. The app does not recommend amounts. Creatine and similar products add no micronutrient, so they only show as taken.</p>
      <section className="rounded-2xl bg-surface p-4">
        <div className="text-sm font-medium">Today</div>
        {supps.length > 0 && <div className="mt-2 space-y-2">{supps.map(s => { const on = taken.includes(s.id!); return (
            <div key={s.id} className="flex items-center gap-2">
              <button aria-pressed={on} onClick={async () => { await toggleTaken(s.id!, today); void refresh() }} className={`min-h-12 flex-1 rounded-xl px-3 text-left ${on ? 'bg-accent/20 ring-1 ring-accent' : 'bg-raised'}`}>
                <span className="block">{on ? '✓ ' : ''}{s.name}</span><span className="block text-xs text-muted">{s.dose} {s.unit}{s.contrib ? ` · adds ${s.contrib.amount} ${s.contrib.key === 'd' ? 'µg vitamin D' : s.contrib.key}` : ''}</span></button>
              <button className="min-h-12 px-2 text-sm text-muted" onClick={() => setDraft(s)}>Edit</button></div>) })}</div>}
        {PRESETS.filter(p => !supps.some(s => s.name === p.name)).length > 0 && <div className="mt-3 text-sm text-muted">{supps.length ? 'Add an example (edit the dose to match your label):' : 'Nothing saved yet. Start from an example, then edit the dose to match your label:'}
          <div className="mt-2 flex flex-wrap gap-2">{PRESETS.filter(p => !supps.some(s => s.name === p.name)).map(p => <button key={p.name} className="min-h-11 rounded-xl bg-raised px-3 text-white" onClick={async () => { await saveSupp(p); void refresh() }}>+ {p.name}</button>)}</div></div>}
        {tot.d != null && <p className="mt-3 text-xs text-muted">Vitamin D from supplements today: {Math.round(tot.d * 40)} IU ({tot.d} µg)</p>}
        <button className="mt-3 min-h-11 text-sm text-accent" onClick={() => setDraft({ name: '', dose: 0, unit: 'g' })}>+ Add a supplement</button>
      </section>
      {draft && <section className="space-y-2 rounded-2xl border border-accent/40 bg-surface p-4">
        <label className="block text-sm text-muted">Name<input className={inp} value={draft.name} onChange={e => setDraft({ ...draft, name: e.target.value })} /></label>
        <div className="flex gap-2"><label className="flex-1 text-sm text-muted">Dose<input type="number" className={inp} value={draft.dose || ''} onChange={e => setDraft({ ...draft, dose: +e.target.value })} /></label>
          <label className="w-28 text-sm text-muted">Unit<select className={inp} value={draft.unit} onChange={e => setDraft({ ...draft, unit: e.target.value as Supplement['unit'] })}>{UNITS.map(u => <option key={u}>{u}</option>)}</select></label></div>
        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={draft.contrib?.key === 'd'} onChange={e => setDraft({ ...draft, contrib: e.target.checked ? { key: 'd', amount: draft.unit === 'IU' ? draft.dose / 40 : draft.unit === 'µg' ? draft.dose : 0 } : undefined })} />This is vitamin D (counts toward your vitamin D total)</label>
        <div className="flex gap-2">
          {draft.id && <button className="min-h-11 rounded-xl bg-raised px-4 text-fat" onClick={async () => { await deleteSupp(draft.id!); setDraft(null); void refresh() }}>Delete</button>}
          <button className="min-h-11 flex-1 rounded-xl bg-raised" onClick={() => setDraft(null)}>Cancel</button>
          <button className="min-h-11 flex-1 rounded-xl bg-accent font-semibold text-canvas" disabled={!draft.name || !draft.dose} onClick={async () => { await saveSupp(draft); setDraft(null); void refresh() }}>Save</button></div></section>}
    </div>)
}
