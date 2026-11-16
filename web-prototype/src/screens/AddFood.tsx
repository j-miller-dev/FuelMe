import { useState } from 'react'
import { SLOTS, foods, latestIds, matchFoods, perServe, type Food, type LogItem } from '../lib/foods'
import { recipes } from '../mock'

type Tab = 'scan' | 'search' | 'ai' | 'quick' | 'library'
const TABS: [Tab, string][] = [['scan', 'Scan'], ['search', 'Search'], ['ai', 'AI'], ['quick', 'Quick Add'], ['library', 'Library']]
type Basket = Record<string, LogItem>

export default function AddFood({ initialTab, eatenKcal, targetKcal, onLog, onClose, onCapture }:
  { initialTab: Tab; eatenKcal: number; targetKcal: number; onLog: (items: LogItem[]) => void; onClose: () => void; onCapture: () => void }) {
  const [tab, setTab] = useState<Tab>(initialTab)
  const [q, setQ] = useState('')
  const [slotIdx, setSlotIdx] = useState(() => { const h = new Date().getHours(); return h < 10 ? 0 : h < 15 ? 1 : h < 21 ? 2 : 3 })
  const [basket, setBasket] = useState<Basket>({})
  const [quick, setQuick] = useState({ kcal: '', p: '', c: '', f: '' })
  const slot = SLOTS[slotIdx]

  const bump = (item: Omit<LogItem, 'n' | 'slot'>, d: 1 | -1) => setBasket(b => {
    const n = (b[item.id]?.n ?? 0) + d, next = { ...b }
    if (n <= 0) delete next[item.id]; else next[item.id] = { ...item, slot, n }
    navigator.vibrate?.(8); return next
  })
  const fromFood = (f: Food) => ({ id: f.id, name: f.brand ? `${f.brand} ${f.name}` : f.name, serving: f.serving, ...perServe(f) })
  const items = Object.values(basket), bKcal = items.reduce((a, i) => a + i.kcal * i.n, 0), count = items.reduce((a, i) => a + i.n, 0)

  const Row = ({ it, sub }: { it: Omit<LogItem, 'n' | 'slot'>; sub?: string }) => { const n = basket[it.id]?.n ?? 0; return (
    <div className="flex min-h-14 items-center gap-2 border-b border-white/5 py-1">
      <div className="min-w-0 flex-1"><div className="truncate text-sm font-medium">{it.name}</div>
        <div className="truncate text-xs text-muted tabular-nums">{it.kcal} kcal · {it.p}P {it.c}C {it.f}F · {sub ?? it.serving}</div></div>
      {n > 0 && <><button aria-label={`Remove one ${it.name}`} className="size-11 rounded-full bg-raised" onClick={() => bump(it, -1)}>−</button><span className="w-5 text-center tabular-nums">{n}</span></>}
      <button aria-label={`Add ${it.name}`} className={`size-11 rounded-full ${n ? 'bg-accent text-canvas' : 'bg-raised'}`} onClick={() => bump(it, 1)}>+</button>
    </div>) }

  const searching = q.trim().length > 0, results = matchFoods(q)
  const pct = Math.min((eatenKcal + bKcal) / targetKcal, 1)
  return (
    <div className="flex min-h-dvh flex-col px-4 pb-44 pt-4">
      <div className="flex items-center gap-2">
        <button aria-label="Close" className="size-11 rounded-full bg-surface" onClick={onClose}>✕</button>
        <button className="min-h-11 rounded-full bg-surface px-4 text-sm" onClick={() => setSlotIdx(i => (i + 1) % SLOTS.length)} aria-label={`Meal: ${slot}. Tap to change`}>{slot}</button>
        <div className="relative min-h-11 flex-1 overflow-hidden rounded-full bg-surface" aria-label={`${eatenKcal + bKcal} of ${targetKcal} kcal`}>
          <div className="absolute inset-y-0 left-0 bg-accent/25 transition-all" style={{ width: `${pct * 100}%` }} />
          <div className="relative flex h-11 items-center justify-center text-sm tabular-nums">{eatenKcal + bKcal} / {targetKcal}{bKcal > 0 && <span className="ml-1 text-accent">(+{bKcal})</span>}</div>
        </div>
      </div>
      <div className="mt-3 flex border-b border-white/10" role="tablist">{TABS.map(([k, l]) => (
        <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={`min-h-11 flex-1 whitespace-nowrap text-xs ${tab === k ? 'border-b-2 border-accent text-white' : 'text-muted'}`}>{l}</button>))}</div>

      <div className="mt-3 flex-1">
        {tab === 'search' && <>
          <input aria-label="Search for a food" autoFocus placeholder="Search for a food" value={q} onChange={e => setQ(e.target.value)} className="min-h-12 w-full rounded-xl bg-surface px-4" />
          {searching ? (results.length ? results.map(f => <Row key={f.id} it={fromFood(f)} />) : <p className="mt-4 text-sm text-muted">No match here. The real search covers AFCD and Open Food Facts AU, and you can create your own food.</p>) : <>
            <h2 className="mt-4 text-sm font-medium">Favourites</h2>
            <div className="mt-2 flex gap-2 overflow-x-auto pb-1">{foods.filter(f => f.fav).map(f => (
              <button key={f.id} onClick={() => bump(fromFood(f), 1)} className="relative min-h-16 w-24 shrink-0 rounded-xl bg-surface p-2 text-left" aria-label={`Add ${f.name}`}>
                <div className="line-clamp-2 text-xs">{f.brand ? `${f.brand} ` : ''}{f.name}</div><span className="absolute right-1 top-1 text-accent">{(basket[f.id]?.n ?? 0) > 0 ? basket[f.id].n : '+'}</span></button>))}</div>
            <h2 className="mt-4 text-sm font-medium">Latest</h2>
            {latestIds.map(id => foods.find(f => f.id === id)!).map(f => <Row key={f.id} it={fromFood(f)} />)}</>}
        </>}
        {tab === 'scan' && <div className="rounded-2xl bg-surface p-5 text-sm"><div className="text-accent">Barcode scan</div>
          <p className="mt-2 text-muted">Opens the camera and looks the barcode up in your local database first, then Open Food Facts. This prototype has no camera or food database wired up yet. It arrives with the food importers (M2).</p></div>}
        {tab === 'ai' && <div className="rounded-2xl bg-surface p-5 text-sm"><div className="text-accent">Talk or type what you ate</div>
          <textarea aria-label="Describe your meal" placeholder="Two weetbix with milk, a Jalna greek yoghurt, and a 300g aldi porterhouse" className="mt-3 min-h-24 w-full rounded-xl bg-raised p-3" />
          <button className="mt-3 min-h-12 w-full rounded-xl bg-accent font-semibold text-canvas" onClick={onCapture}>Parse (demo review)</button></div>}
        {tab === 'quick' && <div className="rounded-2xl bg-surface p-5">
          <div className="grid grid-cols-2 gap-2">{([['kcal', 'Calories'], ['p', 'Protein g'], ['c', 'Carbs g'], ['f', 'Fat g']] as const).map(([k, l]) => (
            <label key={k} className="text-xs text-muted">{l}<input inputMode="decimal" value={quick[k]} onChange={e => setQuick(s => ({ ...s, [k]: e.target.value }))} className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-lg text-white tabular-nums" /></label>))}</div>
          <button className="mt-3 min-h-12 w-full rounded-xl bg-raised disabled:opacity-40" disabled={!(+quick.kcal > 0 || +quick.p > 0 || +quick.c > 0 || +quick.f > 0)} onClick={() => {
            const p = +quick.p || 0, c = +quick.c || 0, f = +quick.f || 0, kcal = +quick.kcal || Math.round(p * 4 + c * 4 + f * 9)
            bump({ id: 'q' + Date.now(), name: 'Quick add', serving: 'manual', kcal, p, c, f }, 1); setQuick({ kcal: '', p: '', c: '', f: '' }) }}>Add to basket</button></div>}
        {tab === 'library' && <><h2 className="text-sm font-medium">Your recipes and meals</h2>
          {recipes.map(r => <Row key={r.id} it={{ id: r.id, name: r.name, serving: '1 serve', kcal: r.kcal, p: r.p, c: Math.round((r.kcal - r.p * 4) * 0.55 / 4), f: Math.round((r.kcal - r.p * 4) * 0.45 / 9) }} />)}
          <p className="mt-2 text-xs text-muted">Carb and fat split for recipes is approximate in this prototype.</p></>}
      </div>

      <div className="fixed inset-x-0 bottom-0 z-30 mx-auto max-w-md bg-canvas/95 px-4 pb-6 pt-3 backdrop-blur">
        <button disabled={!count} className="min-h-12 w-full rounded-2xl bg-accent font-semibold text-canvas disabled:opacity-30 active:scale-[.98] transition"
          onClick={() => onLog(items)}>{count ? `Log ${count} ${count === 1 ? 'food' : 'foods'} · ${bKcal} kcal to ${slot.toLowerCase()}` : 'Log Foods'}</button>
      </div>
    </div>)
}
