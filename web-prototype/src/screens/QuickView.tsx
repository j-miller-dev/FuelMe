import { useEffect, useMemo, useState } from 'react'
import { allItems, loadIds, move, saveIds, toggleId, type QItem } from '../lib/quickview'
import { weight } from '../engine'
import { sunOn } from '../lib/sun'
import { listSupps, logsOn, totals } from '../lib/supplements'
import { todayIso } from '../lib/data'

const fmt = (n: number) => n >= 100 ? Math.round(n).toLocaleString() : String(Math.round(n * 10) / 10)

function Card({ it }: { it: QItem }) {
  const pct = it.target ? Math.min(100, it.value / it.target * 100) : null
  const over = it.limit && it.target ? it.value > it.target : false
  return (
    <div className="w-[72%] shrink-0 snap-center rounded-2xl bg-surface p-4" role="group" aria-label={it.name}>
      <div className="flex items-baseline justify-between"><span className="text-sm font-medium">{it.name}</span><span className="text-[10px] text-muted">{it.tag}</span></div>
      <div className="mt-1 text-2xl font-bold tabular-nums">{fmt(it.value)} <span className="text-sm font-normal text-muted">{it.target ? `/ ${it.limit ? 'max ' : ''}${fmt(it.target)} ` : ''}{it.unit}</span></div>
      {pct != null ? <div className="mt-2 h-2 rounded-full bg-raised" role="progressbar" aria-valuenow={Math.round(pct)} aria-valuemin={0} aria-valuemax={100} aria-label={`${it.name} progress`}>
        <div className={`h-2 rounded-full ${over ? 'bg-fat' : 'bg-protein'}`} style={{ width: `${pct}%` }} /></div>
        : <div className="mt-2 text-xs text-muted">Intake only. No requirement set.</div>}
      {it.note && <p className="mt-2 text-xs text-muted">{it.note}</p>}
    </div>)
}

export default function QuickView() {
  const [vitD, setVitD] = useState(0)
  useEffect(() => { void (async () => { const d = todayIso(); const sun = (await sunOn(d)).reduce((a, l) => a + l.iuMid, 0); const t = totals(await listSupps(), await logsOn(d)); setVitD(sun + (t.d ?? 0) * 40) })() }, [])
  const items = useMemo(() => allItems(weight, vitD), [vitD])
  const [ids, setIds] = useState(() => loadIds(items.map(i => i.id)))
  const [edit, setEdit] = useState(false)
  const upd = (n: string[]) => { setIds(n); saveIds(n) }
  const shown = ids.map(i => items.find(x => x.id === i)).filter(Boolean) as QItem[]
  return (
    <section className="mt-3" aria-label="Nutrient quick view">
      <div className="mb-1 flex items-center justify-between px-1"><span className="text-sm text-muted">Quick view <span className="text-[10px]">· swipe</span></span>
        <button className="min-h-10 px-2 text-sm text-accent" onClick={() => setEdit(e => !e)}>{edit ? 'Done' : 'Edit'}</button></div>
      {!edit ? <div className="-mx-4 flex items-start snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-1" tabIndex={0}>{shown.map(it => <Card key={it.id} it={it} />)}</div>
        : <div className="rounded-2xl bg-surface p-3">
          <p className="mb-2 text-xs text-muted">Tick what you want to see. Use the arrows to reorder your selected cards.</p>
          <div className="mb-2 space-y-1">{ids.map(i => { const it = items.find(x => x.id === i); return it ? (
            <div key={i} className="flex items-center justify-between rounded-xl bg-raised px-3 text-sm"><span>{it.name}</span>
              <span><button className="min-h-10 px-2" aria-label={`Move ${it.name} earlier`} onClick={() => upd(move(ids, i, -1))}>↑</button><button className="min-h-10 px-2" aria-label={`Move ${it.name} later`} onClick={() => upd(move(ids, i, 1))}>↓</button></span></div>) : null })}</div>
          <div className="max-h-64 overflow-y-auto">{items.map(it => <label key={it.id} className="flex min-h-11 items-center gap-3 text-sm"><input type="checkbox" checked={ids.includes(it.id)} onChange={() => upd(toggleId(ids, it.id))} />{it.name}<span className="ml-auto text-[10px] text-muted">{it.unit}</span></label>)}</div></div>}
    </section>)
}
