import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/layouts/AppLayout'
import { FoodForm } from '@/components/foods/FoodForm'
import { api } from '@/lib/http'
import { hitName, type FoodHit } from '@/lib/food'

export default function Foods({ foods }: { foods: FoodHit[] }) {
  const [editing, setEditing] = useState<FoodHit | 'new' | null>(null)
  const [confirm, setConfirm] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const refresh = () => router.reload({ only: ['foods'] })

  async function remove(id: number) {
    setError(null)
    try { await api(`/foods/${id}`, { method: 'DELETE' }); setConfirm(null); refresh() } catch (e) { setError(e instanceof Error ? e.message : 'Could not delete.') }
  }

  return (
    <AppLayout>
      <Head title="Your foods" />
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Your foods</h1>
        <button onClick={() => setEditing('new')} className="min-h-11 rounded-full bg-accent px-4 text-sm font-semibold text-on-accent">New food</button>
      </div>
      <p className="mt-1 text-sm text-muted">Foods and products you added yourself. They rank first in search. Deleting one never changes what you already logged.</p>
      {error ? <p role="alert" className="mt-3 text-sm text-danger">{error}</p> : null}

      {foods.length === 0 ? (
        <p className="mt-6 rounded-2xl bg-surface p-5 text-sm text-muted">Nothing here yet. Scan a product that is not in the database, or tap New food.</p>
      ) : (
        <ul className="mt-4 space-y-2">
          {foods.map((f) => (
            <li key={f.id} className="rounded-2xl bg-surface p-3">
              <div className="flex items-start gap-2">
                <div className="min-w-0 flex-1">
                  <div className="truncate font-medium">{hitName(f)}</div>
                  <div className="text-xs text-muted tabular-nums">{Math.round(f.per100.kcal ?? 0)} kcal · {Math.round(f.per100.protein ?? 0)}P {Math.round(f.per100.carbs ?? 0)}C {Math.round(f.per100.fat ?? 0)}F per 100 {f.is_liquid ? 'mL' : 'g'}{f.barcode ? ` · ${f.barcode}` : ''}</div>
                </div>
                <button onClick={() => setEditing(f)} className="min-h-11 px-2 text-sm text-accent" aria-label={`Edit ${f.name}`}>Edit</button>
                <button onClick={() => setConfirm(confirm === f.id ? null : f.id)} className="min-h-11 px-2 text-sm text-danger" aria-label={`Delete ${f.name}`}>Delete</button>
              </div>
              {confirm === f.id ? (
                <div className="mt-2 flex items-center gap-2 rounded-xl bg-raised p-2 text-sm">
                  <span className="flex-1">Delete this food? Your log history keeps its numbers.</span>
                  <button onClick={() => void remove(f.id)} className="min-h-10 rounded-lg bg-danger/20 px-3 text-danger">Delete</button>
                  <button onClick={() => setConfirm(null)} className="min-h-10 px-2 text-muted">Cancel</button>
                </div>
              ) : null}
            </li>
          ))}
        </ul>
      )}
      <Link href="/settings/security" className="mt-6 block text-center text-sm text-muted underline">Back to Security</Link>

      {editing ? <FoodForm food={editing === 'new' ? undefined : editing} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); refresh() }} /> : null}
    </AppLayout>
  )
}
