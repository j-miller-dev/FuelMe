import { Head, Link } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'

interface Row { id: number; name: string; total: number; bought: number; updated_at: string }

export default function ShoppingIndex({ lists }: { lists: Row[] }) {
  return (
    <AppLayout>
      <Head title="Shopping lists" />
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Shopping lists</h1>
        <Link href="/meals?select=1" className="inline-flex min-h-11 items-center rounded-full bg-accent px-4 text-sm font-semibold text-on-accent">New list</Link>
      </div>
      <p className="mt-1 text-sm text-muted">Pick recipes on the Meals page, set the servings, and get one list grouped by aisle.</p>
      {lists.length === 0 ? <p className="mt-6 rounded-2xl bg-surface p-5 text-sm text-muted">No lists yet. Tap New list, select your recipes, then Make list.</p> : (
        <ul className="mt-4 space-y-2">
          {lists.map((l) => (
            <li key={l.id}>
              <Link href={`/shopping/${l.id}`} className="flex min-h-16 items-center gap-3 rounded-2xl bg-surface p-4">
                <div className="min-w-0 flex-1"><div className="truncate font-medium">{l.name}</div><div className="text-xs text-muted tabular-nums">{l.bought} of {l.total} bought</div></div>
                <span className="text-muted" aria-hidden>›</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
      <Link href="/meals" className="mt-6 block text-center text-sm text-muted underline">Meals</Link>
    </AppLayout>
  )
}
