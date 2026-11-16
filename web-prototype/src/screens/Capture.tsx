import { useState } from 'react'
import { capture } from '../mock'
import { SLOTS, type LogItem } from '../lib/foods'

const chip = (c: number) => c >= 0.85 ? ['Sure', 'text-accent bg-accent/10'] : c >= 0.65 ? ['Check', 'text-carbs bg-carbs/10'] : ['Unsure', 'text-fat bg-fat/10']

export default function Capture({ onLog }: { onLog: (items: LogItem[]) => void }) {
  const [items, setItems] = useState(capture.items)
  const [open, setOpen] = useState<string | null>(null)
  const [grams, setGrams] = useState<Record<string, number>>(Object.fromEntries(capture.items.map(i => [i.id, i.grams])))
  const cur = (i: typeof items[number]) => i.candidates[i.picked]
  const tot = items.reduce((a, i) => { const f = cur(i), k = grams[i.id] / 100
    return { kcal: a.kcal + f.kcal * k, p: a.p + f.p * k, c: a.c + f.c * k, f: a.f + f.f * k } }, { kcal: 0, p: 0, c: 0, f: 0 })
  return (
    <div className="px-4 pb-32 pt-6">
      <h1 className="text-xl font-semibold">Review</h1>
      <p className="mt-2 text-sm text-muted italic">“{capture.transcript}”</p>
      <div className="mt-4 space-y-3">
        {items.map(i => { const f = cur(i), [label, cls] = chip(i.conf), k = grams[i.id] / 100
          return (
          <div key={i.id} className="rounded-2xl bg-surface p-4">
            <div className="flex items-start justify-between gap-3">
              <div className="min-w-0">
                <div className="text-xs text-muted">{i.said}</div>
                <div className="font-medium truncate">{f.brand ? `${f.brand} ` : ''}{f.name}</div>
              </div>
              <span className={`shrink-0 rounded-full px-2 py-0.5 text-xs ${cls}`}>{label} {Math.round(i.conf * 100)}%</span>
            </div>
            <div className="mt-3 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <button aria-label="Less" className="size-11 rounded-xl bg-raised" onClick={() => setGrams(g => ({ ...g, [i.id]: Math.max(5, g[i.id] - 10) }))}>−</button>
                <span className="w-16 text-center tabular-nums">{grams[i.id]} g</span>
                <button aria-label="More" className="size-11 rounded-xl bg-raised" onClick={() => setGrams(g => ({ ...g, [i.id]: g[i.id] + 10 }))}>+</button>
              </div>
              <div className="text-right text-sm tabular-nums"><div className="text-base">{Math.round(f.kcal * k)} kcal</div>
                <div className="text-muted">P {Math.round(f.p * k)} · C {Math.round(f.c * k)} · F {Math.round(f.f * k)}</div></div>
            </div>
            <button className="mt-3 min-h-11 w-full rounded-xl bg-raised text-sm text-accent" onClick={() => setOpen(open === i.id ? null : i.id)}>
              Swap ({i.candidates.length} matches)
            </button>
            {open === i.id && <ul className="mt-2 space-y-1">
              {i.candidates.map((c, idx) => (
                <li key={idx}><button className={`w-full min-h-11 rounded-xl px-3 text-left text-sm ${idx === i.picked ? 'bg-accent/15 text-accent' : 'bg-raised'}`}
                  onClick={() => { setItems(s => s.map(x => x.id === i.id ? { ...x, picked: idx, conf: 1 } : x)); setOpen(null) }}>
                  {c.brand ? `${c.brand} · ` : ''}{c.name} <span className="text-muted">· {c.kcal} kcal/100g</span></button></li>))}
            </ul>}
          </div>) })}
      </div>
      <div className="fixed inset-x-0 bottom-16 mx-auto max-w-md bg-canvas/95 px-4 py-3 backdrop-blur">
        <div className="mb-2 flex justify-between text-sm tabular-nums"><span>{Math.round(tot.kcal)} kcal</span>
          <span className="text-muted">P {Math.round(tot.p)} · C {Math.round(tot.c)} · F {Math.round(tot.f)}</span></div>
        <button className="min-h-12 w-full rounded-2xl bg-accent font-semibold text-canvas active:scale-[.98] transition" onClick={() => {
          const h = new Date().getHours(), slot = SLOTS[h < 10 ? 0 : h < 15 ? 1 : h < 21 ? 2 : 3]
          onLog(items.map(i => { const f = cur(i), k = grams[i.id] / 100
            return { id: 'cap-' + i.id, name: f.brand ? `${f.brand} ${f.name}` : f.name, serving: `${grams[i.id]} g`, kcal: Math.round(f.kcal * k), p: Math.round(f.p * k), c: Math.round(f.c * k), f: Math.round(f.f * k), slot, n: 1 } })) }}>Log all {items.length} items</button>
      </div>
    </div>)
}
