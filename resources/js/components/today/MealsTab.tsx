import { useEffect, useState } from 'react'
import { Link } from '@inertiajs/react'
import { api } from '@/lib/http'
import { SLOT_LABEL, type Slot } from '@/lib/food'
import { fmtPortions, type Meal } from '@/lib/meal'
import type { NewLog } from '@/lib/useLogging'

/** Your meals. One tap logs one portion (from the freshest prep batch that still has some). Edit the amount with the stepper. */
export function MealsTab({ slot, onAdd }: { slot: Slot; onAdd: (it: NewLog) => void }) {
  const [meals, setMeals] = useState<Meal[] | null>(null)
  const [open, setOpen] = useState<Meal | null>(null)
  const [portions, setPortions] = useState(1)
  const [batch, setBatch] = useState<number | null>(null)

  useEffect(() => { api<{ meals: Meal[] }>('/meals/list').then((r) => setMeals(r.meals)).catch(() => setMeals([])) }, [])

  const live = (m: Meal) => m.batches.find((b) => b.remaining > 0) ?? null
  const choose = (m: Meal) => { setOpen(m); setPortions(1); setBatch(live(m)?.id ?? null) }

  if (meals === null) return <p className="text-sm text-muted">Loading…</p>
  if (meals.length === 0) return (
    <p className="rounded-2xl bg-surface p-4 text-sm text-muted">No meals yet. <Link href="/meals" className="text-accent underline">Create one</Link> from your usual recipes and log it in a tap.</p>
  )

  if (open) {
    const p = open.computed.portion
    const b = open.batches.find((x) => x.id === batch) ?? null
    const over = b !== null && portions > b.remaining + 0.0001
    const bad = open.computed.incomplete > 0
    return (
      <div className="rounded-2xl bg-surface p-4">
        <div className="flex items-start gap-2">
          <div className="min-w-0 flex-1"><div className="font-semibold">{open.name}</div><div className="text-xs text-muted">1 portion ≈ {Math.round(p.grams)} g</div></div>
          <button onClick={() => setOpen(null)} aria-label="Back to meals" className="size-11 rounded-full bg-raised">✕</button>
        </div>
        <div className="mt-3 flex items-center justify-center gap-3">
          <button aria-label="Less" onClick={() => setPortions((x) => Math.max(0.25, Math.round((x - 0.25) * 100) / 100))} className="size-14 rounded-full bg-raised text-2xl">−</button>
          <div className="min-w-24 text-center"><div className="text-4xl font-bold tabular-nums">{fmtPortions(portions)}</div><div className="text-xs text-muted">{portions === 1 ? 'portion' : 'portions'}</div></div>
          <button aria-label="More" onClick={() => setPortions((x) => Math.min(50, Math.round((x + 0.25) * 100) / 100))} className="size-14 rounded-full bg-raised text-2xl">+</button>
        </div>
        <div className="mt-3 grid grid-cols-4 gap-2 rounded-2xl bg-raised p-3 text-center tabular-nums" aria-live="polite">
          <div><div className="text-xl font-bold">{Math.round(p.kcal * portions)}</div><div className="text-xs text-muted">kcal</div></div>
          <div><div className="font-semibold" style={{ color: 'var(--protein)' }}>{(p.protein * portions).toFixed(1)}</div><div className="text-xs text-muted">protein g</div></div>
          <div><div className="font-semibold" style={{ color: 'var(--carbs)' }}>{(p.carbs * portions).toFixed(1)}</div><div className="text-xs text-muted">carbs g</div></div>
          <div><div className="font-semibold" style={{ color: 'var(--fat)' }}>{(p.fat * portions).toFixed(1)}</div><div className="text-xs text-muted">fat g</div></div>
        </div>
        {open.batches.some((x) => x.remaining > 0) ? (
          <label className="mt-3 block text-xs text-muted">Take it from
            <select value={batch ?? ''} onChange={(e) => setBatch(e.target.value ? Number(e.target.value) : null)} className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-ink">
              {open.batches.filter((x) => x.remaining > 0).map((x) => <option key={x.id} value={x.id}>Batch from {x.made_on}: {fmtPortions(x.remaining)} left</option>)}
              <option value="">Not from a batch</option>
            </select>
          </label>
        ) : null}
        {bad ? <p role="alert" className="mt-2 text-sm text-danger">An ingredient has no energy data. Edit the meal first.</p> : null}
        {over ? <p role="alert" className="mt-2 text-sm text-danger">Only {fmtPortions(b.remaining)} left in that batch.</p> : null}
        <button disabled={bad || over} onClick={() => onAdd({ kind: 'meal', meal: open, portions, batch_id: batch, slot })}
          className="mt-3 min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">Log {Math.round(p.kcal * portions)} kcal to {SLOT_LABEL[slot].toLowerCase()}</button>
      </div>
    )
  }

  return (
    <>
      <ul>
        {meals.map((m) => {
          const b = live(m)
          return (
            <li key={m.id} className="flex min-h-16 items-center gap-2 border-b border-raised/70 py-1">
              <button onClick={() => choose(m)} className="min-w-0 flex-1 py-2 text-left" aria-label={`Choose portions of ${m.name}`}>
                <div className="truncate text-sm font-medium">{m.name}</div>
                <div className="truncate text-xs text-muted tabular-nums">{Math.round(m.computed.portion.kcal)} kcal · {Math.round(m.computed.portion.protein)}P {Math.round(m.computed.portion.carbs)}C {Math.round(m.computed.portion.fat)}F · per portion{b ? ` · ${fmtPortions(b.remaining)} left` : ''}</div>
              </button>
              <button disabled={m.computed.incomplete > 0} onClick={() => onAdd({ kind: 'meal', meal: m, portions: 1, batch_id: b?.id ?? null, slot })} aria-label={`Log 1 portion of ${m.name}`}
                className="size-12 shrink-0 rounded-full bg-raised text-xl active:scale-90 disabled:opacity-40">+</button>
            </li>
          )
        })}
      </ul>
      <Link href="/meals" className="mt-4 block text-center text-sm text-accent">Manage meals</Link>
    </>
  )
}
