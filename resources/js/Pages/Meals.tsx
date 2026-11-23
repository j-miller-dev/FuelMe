import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/layouts/AppLayout'
import { MealEditor } from '@/components/meals/MealEditor'
import { api } from '@/lib/http'
import { TAG_LABEL, fmtPortions, type Meal } from '@/lib/meal'

export default function Meals({ meals }: { meals: Meal[] }) {
  const [editing, setEditing] = useState<Meal | 'new' | null>(null)
  const [confirm, setConfirm] = useState<number | null>(null)
  const [prep, setPrep] = useState<{ id: number; text: string } | null>(null)
  const [error, setError] = useState<string | null>(null)
  // Select mode (BLUEPRINT 5.6): tick recipes, set servings, make one shopping list.
  const [select, setSelect] = useState(() => typeof window !== 'undefined' && new URLSearchParams(window.location.search).has('select'))
  const [picks, setPicks] = useState<Record<number, number>>({})
  const [trayOpen, setTrayOpen] = useState(false)
  const [making, setMaking] = useState(false)
  const chosen = meals.filter((m) => picks[m.id] !== undefined)
  const sum = (f: (m: Meal) => number) => chosen.reduce((a, m) => a + f(m) * (picks[m.id] ?? 0), 0)
  const toggle = (m: Meal) => setPicks((p) => { const n = { ...p }; if (n[m.id] !== undefined) delete n[m.id]; else n[m.id] = m.yield_portions; return n })
  const bumpServes = (m: Meal, d: number) => setPicks((p) => ({ ...p, [m.id]: Math.max(0.5, Math.round(((p[m.id] ?? m.yield_portions) + d) * 2) / 2) }))
  async function makeList() {
    setMaking(true); setError(null)
    try {
      const r = await api<{ id: number }>('/shopping', { method: 'POST', body: { recipes: chosen.map((m) => ({ meal_id: m.id, servings: picks[m.id] })) } })
      router.visit(`/shopping/${r.id}`)
    } catch (e) { setError(e instanceof Error ? e.message : 'Could not make the list.'); setMaking(false) }
  }
  const refresh = () => router.reload({ only: ['meals'] })
  const run = async (fn: () => Promise<unknown>) => { setError(null); try { await fn(); refresh() } catch (e) { setError(e instanceof Error ? e.message : 'Something went wrong.') } }

  return (
    <AppLayout>
      <Head title="Meals" />
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Meals</h1>
        <div className="flex gap-2">
          <button onClick={() => { setSelect((v) => !v); setPicks({}) }} aria-pressed={select} className={`min-h-11 rounded-full px-4 text-sm ${select ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-surface'}`}>{select ? 'Cancel' : 'Select'}</button>
          <Link href="/meals/import" className="grid min-h-11 place-items-center rounded-full bg-surface px-4 text-sm">Import</Link>
          <button onClick={() => setEditing('new')} className="min-h-11 rounded-full bg-accent px-4 text-sm font-semibold text-on-accent">New meal</button>
        </div>
      </div>
      <p className="mt-1 text-sm text-muted">Recipes you cook. Log one portion in two taps from the Add sheet. For meal prep, record a batch and your portions count down as you log them.</p>
      {error ? <p role="alert" className="mt-3 text-sm text-danger">{error}</p> : null}

      {meals.length === 0 ? <p className="mt-6 rounded-2xl bg-surface p-5 text-sm text-muted">No meals yet. Tap New meal and add the ingredients with their raw weights.</p> : (
        <ul className="mt-4 space-y-2">
          {meals.map((m) => (
            <li key={m.id} className={`rounded-2xl bg-surface p-3 ${picks[m.id] !== undefined ? 'ring-2 ring-accent' : ''}`}>
              <div className="flex items-start gap-2">
                {select ? (
                  <button role="checkbox" aria-checked={picks[m.id] !== undefined} aria-label={`Select ${m.name}`} onClick={() => toggle(m)}
                    className={`mt-1 flex size-8 shrink-0 items-center justify-center rounded-full border-2 ${picks[m.id] !== undefined ? 'border-accent bg-accent text-on-accent' : 'border-muted'}`}>{picks[m.id] !== undefined ? '✓' : ''}</button>
                ) : null}
                <div className="min-w-0 flex-1">
                  <div className="truncate font-medium">{m.name}</div>
                  <div className="text-xs text-muted tabular-nums">{Math.round(m.computed.portion.kcal)} kcal · {Math.round(m.computed.portion.protein)}P {Math.round(m.computed.portion.carbs)}C {Math.round(m.computed.portion.fat)}F per portion · makes {fmtPortions(m.yield_portions)}</div>
                  {m.tags.length ? <div className="mt-1 flex flex-wrap gap-1">{m.tags.map((t) => <span key={t} className="rounded-full bg-raised px-2 text-[10px] text-muted">{TAG_LABEL[t]}</span>)}</div> : null}
                  {m.computed.incomplete > 0 ? <div className="mt-1 text-xs text-danger">An ingredient has no energy data. Edit this meal.</div> : null}
                </div>
                <button onClick={() => setEditing(m)} className="min-h-11 px-2 text-sm text-accent" aria-label={`Edit ${m.name}`}>Edit</button>
                <button onClick={() => setConfirm(confirm === m.id ? null : m.id)} className="min-h-11 px-2 text-sm text-danger" aria-label={`Delete ${m.name}`}>Delete</button>
              </div>

              {m.batches.length ? (
                <ul className="mt-2 space-y-1">
                  {m.batches.map((b) => (
                    <li key={b.id} className="flex items-center gap-2 rounded-xl bg-raised px-3 py-2 text-sm">
                      <span className="flex-1 tabular-nums">{b.remaining > 0 ? `${fmtPortions(b.remaining)} of ${fmtPortions(b.portions)} left` : 'All eaten'} <span className="text-xs text-muted">made {b.made_on}</span></span>
                      <button onClick={() => void run(() => api(`/meals/batches/${b.id}`, { method: 'DELETE' }))} className="min-h-10 px-2 text-xs text-muted" aria-label="Remove this batch">Remove</button>
                    </li>
                  ))}
                </ul>
              ) : null}

              {prep?.id === m.id ? (
                <div className="mt-2 flex items-center gap-2">
                  <label className="text-xs text-muted">Portions made
                    <input autoFocus inputMode="decimal" value={prep.text} onChange={(e) => setPrep({ id: m.id, text: e.target.value.replace(',', '.') })}
                      className="ml-2 min-h-11 w-20 rounded-xl bg-raised px-2 text-right tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" />
                  </label>
                  <button disabled={!(Number(prep.text) >= 0.5)} onClick={() => void run(async () => { await api(`/meals/${m.id}/batches`, { method: 'POST', body: { portions: Number(prep.text) } }); setPrep(null) })}
                    className="min-h-11 rounded-xl bg-accent px-3 text-sm font-semibold text-on-accent disabled:opacity-40">Start batch</button>
                  <button onClick={() => setPrep(null)} className="min-h-11 px-2 text-sm text-muted">Cancel</button>
                </div>
              ) : <button onClick={() => setPrep({ id: m.id, text: String(m.yield_portions) })} className="mt-2 min-h-10 text-sm text-accent">Made a batch</button>}

              {confirm === m.id ? (
                <div className="mt-2 flex items-center gap-2 rounded-xl bg-raised p-2 text-sm">
                  <span className="flex-1">Delete this meal? Your log history keeps its numbers.</span>
                  <button onClick={() => void run(async () => { await api(`/meals/${m.id}`, { method: 'DELETE' }); setConfirm(null) })} className="min-h-10 rounded-lg bg-danger/20 px-3 text-danger">Delete</button>
                  <button onClick={() => setConfirm(null)} className="min-h-10 px-2 text-muted">Cancel</button>
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      <Link href="/shopping" className="mt-6 block text-center text-sm text-accent">Shopping lists</Link>
      <Link href="/more" className="mt-2 block text-center text-sm text-muted underline">Back to More</Link>
      {select ? <div className="h-44" aria-hidden /> : null}
      {select && chosen.length > 0 ? (
        <div className="fixed inset-x-0 bottom-0 z-30 mx-auto max-w-md rounded-t-3xl bg-raised p-4 shadow-2xl" style={{ paddingBottom: 'max(1rem, env(safe-area-inset-bottom))' }}>
          <button onClick={() => setTrayOpen((v) => !v)} aria-expanded={trayOpen} className="flex min-h-11 w-full items-center justify-between text-left">
            <span className="font-semibold">{chosen.length} recipe{chosen.length === 1 ? '' : 's'} · {fmtPortions(chosen.reduce((a, m) => a + (picks[m.id] ?? 0), 0))} serves</span>
            <span className="text-muted" aria-hidden>{trayOpen ? '▾' : '▴'}</span>
          </button>
          <div className="text-xs text-muted tabular-nums">≈ {Math.round(sum((m) => m.computed.portion.kcal)).toLocaleString()} kcal · {Math.round(sum((m) => m.computed.portion.protein))} g protein in total</div>
          {trayOpen ? (
            <ul className="mt-2 max-h-48 space-y-1 overflow-y-auto">
              {chosen.map((m) => (
                <li key={m.id} className="flex items-center gap-2">
                  <span className="min-w-0 flex-1 truncate text-sm">{m.name}</span>
                  <button aria-label={`Fewer servings of ${m.name}`} onClick={() => bumpServes(m, -1)} className="size-11 rounded-full bg-surface">−</button>
                  <span className="w-10 text-center tabular-nums">{fmtPortions(picks[m.id] ?? 0)}</span>
                  <button aria-label={`More servings of ${m.name}`} onClick={() => bumpServes(m, 1)} className="size-11 rounded-full bg-surface">+</button>
                </li>
              ))}
            </ul>
          ) : null}
          <button disabled={making} onClick={() => void makeList()} className="mt-3 min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">{making ? 'Making…' : 'Make list'}</button>
        </div>
      ) : null}
      {editing ? <MealEditor meal={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh() }} /> : null}
    </AppLayout>
  )
}
