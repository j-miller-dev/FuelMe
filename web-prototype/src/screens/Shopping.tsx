import { useMemo, useState } from 'react'
import { aisleOrder, recipes } from '../mock'

type St = 'need' | 'have' | 'bought'
export default function Shopping() {
  const [sel, setSel] = useState<Record<string, number>>({})
  const [list, setList] = useState(false)
  const [tab, setTab] = useState<'need' | 'have'>('need')
  const [status, setStatus] = useState<Record<string, St>>({ 'basmati rice': 'have' })

  const items = useMemo(() => {
    const m = new Map<string, { key: string; name: string; qty: number; unit: string; staple: boolean; aisle: string; from: string[] }>()
    for (const r of recipes) if (sel[r.id]) for (const g of r.ings) {
      const k = g.name.toLowerCase(), e = m.get(k) ?? { key: k, name: g.name, qty: 0, unit: g.unit, staple: false, aisle: g.aisle, from: [] }
      if (g.qty === null) e.staple = true; else e.qty += g.qty * sel[r.id] / r.yield
      e.from.push(r.name); m.set(k, e)
    }
    return [...m.values()].sort((a, b) => aisleOrder.indexOf(a.aisle) - aisleOrder.indexOf(b.aisle) || a.name.localeCompare(b.name))
  }, [sel])

  const chosen = recipes.filter(r => sel[r.id])
  if (!list) return (
    <div className="px-4 pb-44 pt-6">
      <h1 className="text-xl font-semibold">Recipes</h1>
      <div className="mt-4 space-y-2">
        {recipes.map(r => (
          <div key={r.id} className={`rounded-2xl p-4 ${sel[r.id] ? 'bg-accent/10 ring-1 ring-accent/40' : 'bg-surface'}`}>
            <button className="flex w-full min-h-11 items-center justify-between text-left" onClick={() => setSel(s => { const n = { ...s }; if (n[r.id]) delete n[r.id]; else n[r.id] = r.yield; return n })}>
              <span><span className="font-medium">{r.name}</span><span className="block text-xs text-muted">{r.kcal} kcal · {r.p} g protein / serve</span></span>
              <span className={`size-6 rounded-full border ${sel[r.id] ? 'border-accent bg-accent' : 'border-muted'}`} aria-hidden />
            </button>
            {sel[r.id] ? <div className="mt-2 flex items-center gap-3">
              <button aria-label="Fewer serves" className="size-11 rounded-xl bg-raised" onClick={() => setSel(s => ({ ...s, [r.id]: Math.max(1, s[r.id] - 1) }))}>−</button>
              <span className="tabular-nums">{sel[r.id]} serves</span>
              <button aria-label="More serves" className="size-11 rounded-xl bg-raised" onClick={() => setSel(s => ({ ...s, [r.id]: s[r.id] + 1 }))}>+</button>
            </div> : null}
          </div>))}
      </div>
      {chosen.length > 0 && <div className="fixed inset-x-0 bottom-16 mx-auto max-w-md bg-canvas/95 px-4 py-3 backdrop-blur">
        <div className="mb-2 text-sm text-muted">{chosen.length} recipes · {chosen.reduce((a, r) => a + sel[r.id], 0)} serves</div>
        <button className="min-h-12 w-full rounded-2xl bg-accent font-semibold text-canvas" onClick={() => setList(true)}>Make list</button>
      </div>}
    </div>)

  const st = (k: string): St => status[k] ?? 'need'
  const shown = items.filter(i => tab === 'need' ? st(i.key) === 'need' : st(i.key) !== 'need')
  const done = items.filter(i => st(i.key) !== 'need').length
  const groups = aisleOrder.map(a => [a, shown.filter(i => i.aisle === a)] as const).filter(([, v]) => v.length)
  return (
    <div className="px-4 pb-28 pt-6">
      <div className="flex items-center justify-between"><h1 className="text-xl font-semibold">Shopping list</h1>
        <button className="text-sm text-accent min-h-11" onClick={() => setList(false)}>Edit recipes</button></div>
      <div className="mt-2 h-2 rounded-full bg-raised"><div className="h-full rounded-full bg-accent transition-all" style={{ width: `${items.length ? done / items.length * 100 : 0}%` }} /></div>
      <div className="mt-1 text-xs text-muted">{done} of {items.length}</div>
      <div className="mt-3 flex gap-2">{(['need', 'have'] as const).map(t => (
        <button key={t} onClick={() => setTab(t)} className={`min-h-11 flex-1 rounded-xl text-sm capitalize ${tab === t ? 'bg-accent text-canvas font-semibold' : 'bg-surface'}`}>{t}</button>))}</div>
      {groups.map(([a, v]) => (
        <section key={a} className="mt-4"><h2 className="mb-1 text-xs uppercase tracking-wide text-muted">{a}</h2>
          <ul className="space-y-1">{v.map(i => (
            <li key={i.key}><button className="flex min-h-14 w-full items-center gap-3 rounded-xl bg-surface px-3 text-left"
              onClick={() => { navigator.vibrate?.(8); setStatus(s => ({ ...s, [i.key]: st(i.key) === 'need' ? 'bought' : 'need' })) }}>
              <span className={`size-6 shrink-0 rounded-full border ${st(i.key) !== 'need' ? 'border-accent bg-accent' : 'border-muted'}`} />
              <span className="min-w-0 flex-1"><span className={st(i.key) !== 'need' ? 'line-through text-muted' : ''}>{i.name}</span>
                <span className="block truncate text-xs text-muted">{[...new Set(i.from)].join(', ')}</span></span>
              <span className="tabular-nums text-sm">{i.staple && !i.qty ? 'staple' : `${Math.round(i.qty * 10) / 10} ${i.unit === 'count' ? '' : i.unit}`}</span>
            </button></li>))}</ul></section>))}
    </div>)
}
