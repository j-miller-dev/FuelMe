import { useEffect, useRef, useState } from 'react'
import { api } from '@/lib/http'
import { hitName, unitOf, type FoodHit, type Per100 } from '@/lib/food'
import { MEAL_TAGS, TAG_LABEL, previewMeal, type Meal, type MealTag } from '@/lib/meal'
import { Sheet } from '@/components/today/Sheet'

interface Line { key: number; food_id: number | null; name: string; unit: 'g' | 'mL'; per100: Per100 | null; grams: string }
let k = 1

/** Build or edit a meal. The numbers shown are a preview; the server recomputes from the food rows when you save. */
export function MealEditor({ meal, onClose, onSaved }: { meal?: Meal; onClose: () => void; onSaved: (m: Meal) => void }) {
  const [name, setName] = useState(meal?.name ?? '')
  const [yieldText, setYield] = useState(String(meal?.yield_portions ?? 1))
  const [lossText, setLoss] = useState(String(Math.round((meal?.cook_loss ?? 0) * 100)))
  const [tags, setTags] = useState<MealTag[]>(meal?.tags ?? [])
  const [lines, setLines] = useState<Line[]>(meal?.items.map((i) => ({ key: k++, food_id: i.food_id, name: i.name, unit: i.unit, per100: i.per100, grams: String(i.grams) })) ?? [])
  const [q, setQ] = useState('')
  const [hits, setHits] = useState<FoodHit[] | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const seq = useRef(0)

  useEffect(() => {
    const term = q.trim()
    if (term.length < 2) { setHits(null); return }
    const mine = ++seq.current
    const ctl = new AbortController()
    const t = setTimeout(() => {
      api<{ results: FoodHit[] }>(`/foods/search?q=${encodeURIComponent(term)}`, { signal: ctl.signal })
        .then((r) => { if (mine === seq.current) setHits(r.results.slice(0, 8)) }).catch(() => undefined)
    }, 120)
    return () => { clearTimeout(t); ctl.abort() }
  }, [q])

  const num = (s: string) => { const n = Number(s.replace(',', '.')); return Number.isFinite(n) ? n : 0 }
  const yieldN = num(yieldText)
  const loss = num(lossText) / 100
  const grams = (l: Line) => num(l.grams)
  const calc = previewMeal(lines.map((l) => ({ per100: l.per100, grams: grams(l) })), yieldN, loss)
  const valid = name.trim().length > 0 && lines.length > 0 && lines.every((l) => grams(l) > 0) && yieldN >= 0.5 && loss >= 0 && loss <= 0.9

  const addFood = (h: FoodHit) => {
    setLines((x) => [...x, { key: k++, food_id: h.id, name: hitName(h), unit: unitOf(h) as 'g' | 'mL', per100: h.per100, grams: String(h.portion.grams ?? 100) }])
    setQ(''); setHits(null)
  }

  async function save() {
    setBusy(true); setErr(null)
    try {
      const body = { name: name.trim(), yield_portions: yieldN, cook_loss: loss, tags, items: lines.map((l) => ({ food_id: l.food_id, grams: grams(l) })) }
      const r = meal ? await api<{ meal: Meal }>(`/meals/${meal.id}`, { method: 'PUT', body }) : await api<{ meal: Meal }>('/meals', { method: 'POST', body })
      onSaved(r.meal)
    } catch (e) { setErr(e instanceof Error ? e.message : 'Could not save.'); setBusy(false) }
  }

  const field = 'mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-ink outline-none focus:ring-2 focus:ring-accent'
  return (
    <Sheet label={meal ? `Edit ${meal.name}` : 'New meal'} onClose={onClose} tall z={60}>
      <div className="flex items-center gap-2 px-4 pt-4">
        <h2 className="flex-1 text-lg font-semibold">{meal ? 'Edit meal' : 'New meal'}</h2>
        <button onClick={onClose} aria-label="Close" className="size-11 rounded-full bg-surface">✕</button>
      </div>
      <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-4 pt-2">
        <label className="block text-xs text-muted">Name
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder="Chicken and rice bowl" className={field} />
        </label>
        <div className="mt-3 grid grid-cols-2 gap-2">
          <label className="text-xs text-muted">Makes (portions)
            <input inputMode="decimal" value={yieldText} onChange={(e) => setYield(e.target.value)} className={field + ' tabular-nums'} />
          </label>
          <label className="text-xs text-muted">Weight lost cooking (%)
            <input inputMode="decimal" value={lossText} onChange={(e) => setLoss(e.target.value)} className={field + ' tabular-nums'} />
          </label>
        </div>
        <p className="mt-1 text-xs text-muted">Enter ingredient weights raw, as bought. Cooking only changes weight, so the calories stay the same. Leave the loss at 0 if you are unsure.</p>
        <div className="mt-3 flex flex-wrap gap-2" role="group" aria-label="Tags">
          {MEAL_TAGS.map((t) => (
            <button key={t} aria-pressed={tags.includes(t)} onClick={() => setTags((x) => x.includes(t) ? x.filter((y) => y !== t) : [...x, t])}
              className={`min-h-10 rounded-full px-3 text-xs ${tags.includes(t) ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-surface text-muted'}`}>{TAG_LABEL[t]}</button>
          ))}
        </div>

        <h3 className="mt-5 text-sm font-semibold text-muted">Ingredients</h3>
        {lines.length === 0 ? <p className="mt-1 text-sm text-muted">Search below to add the first one.</p> : (
          <ul className="mt-1">
            {lines.map((l) => (
              <li key={l.key} className="flex items-center gap-2 border-b border-raised/70 py-1">
                <div className="min-w-0 flex-1 text-sm">
                  <div className="truncate">{l.name}</div>
                  {!l.per100 ? <div className="text-xs text-danger">No energy data. This food was removed or is incomplete.</div> : null}
                </div>
                <input aria-label={`Amount of ${l.name}`} inputMode="decimal" value={l.grams} onChange={(e) => setLines((x) => x.map((y) => y.key === l.key ? { ...y, grams: e.target.value.replace(',', '.') } : y))}
                  className="min-h-11 w-20 rounded-xl bg-raised px-2 text-right tabular-nums outline-none focus:ring-2 focus:ring-accent" />
                <span className="w-7 text-xs text-muted">{l.unit}</span>
                <button aria-label={`Remove ${l.name}`} onClick={() => setLines((x) => x.filter((y) => y.key !== l.key))} className="size-11 rounded-full text-muted">✕</button>
              </li>
            ))}
          </ul>
        )}
        <input aria-label="Search for an ingredient" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Add an ingredient, e.g. chicken breast"
          autoComplete="off" className={field + ' mt-3'} />
        {hits ? (
          <ul className="mt-1 rounded-xl bg-surface">
            {hits.length === 0 ? <li className="p-3 text-sm text-muted">No match.</li> : hits.map((h) => (
              <li key={h.id}><button onClick={() => addFood(h)} className="flex min-h-12 w-full items-center justify-between px-3 text-left text-sm">
                <span className="truncate">{hitName(h)}</span><span className="ml-2 text-xs text-muted tabular-nums">{Math.round(h.per100.kcal ?? 0)} kcal/100</span>
              </button></li>
            ))}
          </ul>
        ) : null}

        {calc ? (
          <div className="mt-5 rounded-2xl bg-surface p-3" aria-live="polite">
            <div className="text-xs text-muted">Per portion ({Math.round(calc.portion.grams)} g cooked)</div>
            <div className="mt-1 grid grid-cols-4 gap-2 text-center">
              <div><div className="text-xl font-bold tabular-nums">{Math.round(calc.portion.kcal)}</div><div className="text-xs text-muted">kcal</div></div>
              <div><div className="text-lg font-semibold tabular-nums" style={{ color: 'var(--protein)' }}>{calc.portion.protein.toFixed(1)}</div><div className="text-xs text-muted">protein g</div></div>
              <div><div className="text-lg font-semibold tabular-nums" style={{ color: 'var(--carbs)' }}>{calc.portion.carbs.toFixed(1)}</div><div className="text-xs text-muted">carbs g</div></div>
              <div><div className="text-lg font-semibold tabular-nums" style={{ color: 'var(--fat)' }}>{calc.portion.fat.toFixed(1)}</div><div className="text-xs text-muted">fat g</div></div>
            </div>
            <div className="mt-2 text-xs text-muted tabular-nums">Whole recipe: {Math.round(calc.total.kcal)} kcal · {Math.round(calc.raw_grams)} g raw → {Math.round(calc.cooked_grams)} g cooked · {Math.round(calc.per100.kcal)} kcal per 100 g cooked</div>
            {calc.incomplete > 0 ? <p className="mt-1 text-xs text-danger">{calc.incomplete} ingredient{calc.incomplete === 1 ? '' : 's'} with no energy data are counted as zero.</p> : null}
          </div>
        ) : null}
        <p className="mt-2 text-xs text-muted">Editing a meal changes future logs only. What you already logged keeps its numbers.</p>
        {err ? <p role="alert" className="mt-2 text-sm text-danger">{err}</p> : null}
      </div>
      <div className="px-4 pb-4 pt-2">
        <button disabled={!valid || busy} onClick={() => void save()} className="min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">{busy ? 'Saving…' : 'Save meal'}</button>
      </div>
    </Sheet>
  )
}
