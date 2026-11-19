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
  const refresh = () => router.reload({ only: ['meals'] })
  const run = async (fn: () => Promise<unknown>) => { setError(null); try { await fn(); refresh() } catch (e) { setError(e instanceof Error ? e.message : 'Something went wrong.') } }

  return (
    <AppLayout>
      <Head title="Meals" />
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Meals</h1>
        <button onClick={() => setEditing('new')} className="min-h-11 rounded-full bg-accent px-4 text-sm font-semibold text-on-accent">New meal</button>
      </div>
      <p className="mt-1 text-sm text-muted">Recipes you cook. Log one portion in two taps from the Add sheet. For meal prep, record a batch and your portions count down as you log them.</p>
      {error ? <p role="alert" className="mt-3 text-sm text-danger">{error}</p> : null}

      {meals.length === 0 ? <p className="mt-6 rounded-2xl bg-surface p-5 text-sm text-muted">No meals yet. Tap New meal and add the ingredients with their raw weights.</p> : (
        <ul className="mt-4 space-y-2">
          {meals.map((m) => (
            <li key={m.id} className="rounded-2xl bg-surface p-3">
              <div className="flex items-start gap-2">
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
      <Link href="/settings/security" className="mt-6 block text-center text-sm text-muted underline">Back to Security</Link>
      {editing ? <MealEditor meal={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh() }} /> : null}
    </AppLayout>
  )
}
