import { Head, Link, router } from '@inertiajs/react'
import { useMemo, useState } from 'react'
import AppLayout from '@/layouts/AppLayout'
import { api } from '@/lib/http'
import { fmtQty, listAsText, packs, useShoppingList, type ShopItem, type ShopList } from '@/lib/shopping'

export default function ShoppingShow({ list: initial }: { list: ShopList }) {
  const s = useShoppingList(initial)
  const { list } = s
  const [tab, setTab] = useState<'need' | 'have'>('need')
  const [text, setText] = useState('')
  const [open, setOpen] = useState<number | null>(null)
  const [showRecipes, setShowRecipes] = useState(false)
  const [msg, setMsg] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  const need = list.items.filter((i) => i.state !== 'have')
  const have = list.items.filter((i) => i.state === 'have')
  const bought = need.filter((i) => i.state === 'bought').length
  const shown = tab === 'need' ? need : have
  const groups = useMemo(() => list.aisle_order.map((a) => ({ aisle: a, rows: shown.filter((i) => i.aisle === a) })).filter((g) => g.rows.length), [list, shown])

  // Pantry, pack size and servings need the server; they say so when offline instead of pretending.
  async function online<T>(fn: () => Promise<T>): Promise<T | undefined> {
    setMsg(null)
    try { return await fn() } catch (e) { setMsg(navigator.onLine ? (e instanceof Error ? e.message : 'Something went wrong.') : 'You are offline. Ticking works; this needs a connection.'); return undefined }
  }
  const patch = (i: ShopItem, body: Record<string, unknown>) => online(async () => { const r = await api<{ list: ShopList }>(`/shopping/items/${i.id}`, { method: 'PATCH', body }); s.setList(r.list) })
  const servings = (mealId: number | null, delta: number) => online(async () => {
    const recipes = list.recipes.filter((r) => r.meal_id !== null).map((r) => ({ meal_id: r.meal_id, servings: r.meal_id === mealId ? Math.max(0.5, r.servings + delta) : r.servings }))
    const r = await api<{ list: ShopList }>(`/shopping/${list.id}/recipes`, { method: 'PUT', body: { recipes } }); s.setList(r.list)
  })
  const dropRecipe = (mealId: number | null) => online(async () => {
    const recipes = list.recipes.filter((r) => r.meal_id !== null && r.meal_id !== mealId).map((r) => ({ meal_id: r.meal_id, servings: r.servings }))
    const r = await api<{ list: ShopList }>(`/shopping/${list.id}/recipes`, { method: 'PUT', body: { recipes } }); s.setList(r.list)
  })
  const refresh = () => online(async () => { const r = await api<{ list: ShopList }>(`/shopping/${list.id}/refresh`, { method: 'POST' }); s.setList(r.list) })
  const copy = async () => { try { await navigator.clipboard.writeText(listAsText(list)); setMsg('Copied. Paste it into any notes app or message.') } catch { setMsg('Could not copy on this device.') } }
  const finish = async () => { setBusy(true); await online(() => api(`/shopping/${list.id}/done`, { method: 'POST' }).then(() => router.visit('/shopping'))); setBusy(false) }

  const row = (i: ShopItem) => {
    const done = i.state === 'bought'
    const p = i.pack_size && i.need_qty ? packs(i.need_qty, i.pack_size) : null
    return (
      <li key={`${i.id}-${i.key}`} className="border-b border-raised/70">
        <div className="flex min-h-16 items-center gap-1">
          <button role="checkbox" aria-checked={done} aria-label={`${done ? 'Untick' : 'Tick'} ${i.label}`} onClick={() => s.setState(i, done ? 'need' : 'bought')}
            className="flex min-h-16 min-w-0 flex-1 items-center gap-3 py-2 text-left">
            <span className={`flex size-8 shrink-0 items-center justify-center rounded-full border-2 ${done ? 'border-accent bg-accent text-on-accent' : 'border-muted'}`}>{done ? '✓' : ''}</span>
            <span className="min-w-0 flex-1">
              <span className={`block truncate ${done ? 'text-muted line-through' : 'font-medium'}`}>{i.label}</span>
              <span className="block truncate text-xs text-muted tabular-nums">
                {i.need_qty !== null ? (i.have_qty ? `${fmtQty(i.need_qty, i.unit)} to buy (have ${fmtQty(i.have_qty, i.unit)})` : fmtQty(i.need_qty, i.unit)) : 'to taste'}
                {p ? ` → ${p.n} × ${fmtQty(i.pack_size, i.unit)}` : ''}
                {i.recipes.length ? ` · ${i.recipes.join(', ')}` : ''}
              </span>
            </span>
          </button>
          <button onClick={() => s.setState(i, i.state === 'have' ? 'need' : 'have')} className="min-h-11 shrink-0 px-2 text-xs text-accent" aria-label={i.state === 'have' ? `Need ${i.label}` : `I have ${i.label}`}>{i.state === 'have' ? 'Need it' : 'Have it'}</button>
          <button onClick={() => setOpen(open === i.id ? null : i.id)} aria-label={`More for ${i.label}`} aria-expanded={open === i.id} className="size-11 shrink-0 text-muted">⋯</button>
        </div>
        {open === i.id ? (
          <div className="mb-2 space-y-2 rounded-xl bg-surface p-3 text-sm">
            {!i.is_manual ? (
              <>
                <label className="flex min-h-11 items-center gap-2"><input type="checkbox" checked={i.is_staple} onChange={(e) => void patch(i, { is_staple: e.target.checked })} className="size-5" />I always have this (move to Have on every list)</label>
                {i.unit && i.unit !== 'count' ? (
                  <>
                    <label className="flex items-center gap-2 text-xs text-muted">I have about
                      <input inputMode="decimal" defaultValue={i.have_qty ?? ''} onBlur={(e) => { const v = e.target.value.replace(',', '.'); void patch(i, { have_qty: v === '' ? null : Number(v) }) }}
                        className="min-h-11 w-24 rounded-xl bg-raised px-2 text-right tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" />{i.unit}</label>
                    <label className="flex items-center gap-2 text-xs text-muted">Pack size
                      <input inputMode="decimal" defaultValue={i.pack_size ?? ''} placeholder="e.g. 500" onBlur={(e) => { const v = e.target.value.replace(',', '.'); void patch(i, { pack_size: v === '' ? null : Number(v) }) }}
                        className="min-h-11 w-24 rounded-xl bg-raised px-2 text-right tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" />{i.unit}</label>
                  </>
                ) : null}
              </>
            ) : <button onClick={() => { s.remove(i); setOpen(null) }} className="min-h-11 text-danger">Remove this item</button>}
          </div>
        ) : null}
      </li>
    )
  }

  return (
    <AppLayout>
      <Head title={list.name} />
      <div className="flex items-center gap-2">
        <Link href="/shopping" aria-label="All lists" className="flex size-11 items-center justify-center rounded-full bg-surface">‹</Link>
        <h1 className="min-w-0 flex-1 truncate text-xl font-bold">{list.name}</h1>
        <button onClick={() => void copy()} className="min-h-11 rounded-full bg-surface px-3 text-sm">Copy</button>
      </div>

      <div className="mt-3" role="progressbar" aria-valuemin={0} aria-valuemax={need.length} aria-valuenow={bought} aria-label="Items bought">
        <div className="h-2 overflow-hidden rounded-full bg-surface"><div className="h-full bg-accent transition-all" style={{ width: `${need.length ? (bought / need.length) * 100 : 0}%` }} /></div>
        <div className="mt-1 text-xs text-muted tabular-nums">{bought} of {need.length}{s.pending ? ` · ${s.pending} change${s.pending === 1 ? '' : 's'} waiting to sync` : ''}</div>
      </div>

      {list.stale ? (
        <div role="alert" className="mt-3 flex items-center gap-2 rounded-2xl bg-carbs/15 p-3 text-sm">
          <span className="flex-1">A recipe changed since this list was made. Your ticks are kept if you refresh.</span>
          <button onClick={() => void refresh()} className="min-h-11 rounded-xl bg-accent px-3 font-semibold text-on-accent">Refresh list</button>
        </div>
      ) : null}
      {msg ? <p role="status" className="mt-2 text-sm text-muted">{msg}</p> : null}
      {s.error ? <p role="alert" className="mt-2 text-sm text-danger">{s.error}</p> : null}

      <div className="mt-3 flex gap-1 rounded-full bg-surface p-1" role="tablist">
        {([['need', `Need (${need.length})`], ['have', `Have (${have.length})`]] as const).map(([k, l]) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)} className={`min-h-11 flex-1 rounded-full text-sm ${tab === k ? 'bg-raised font-semibold text-accent' : 'text-muted'}`}>{l}</button>
        ))}
      </div>

      {groups.length === 0 ? <p className="mt-6 text-center text-sm text-muted">{tab === 'need' ? 'Nothing to buy. Add an item below or change the recipes.' : 'Nothing marked as already at home.'}</p> : groups.map((g) => (
        <section key={g.aisle} className="mt-4"><h2 className="text-sm font-semibold text-muted">{g.aisle}</h2><ul>{g.rows.map(row)}</ul></section>
      ))}

      <form className="mt-4 flex gap-2" onSubmit={(e) => { e.preventDefault(); if (text.trim()) { s.add(text.trim()); setText('') } }}>
        <input aria-label="Add an item" value={text} onChange={(e) => setText(e.target.value)} placeholder="Add an item, e.g. toilet paper" autoComplete="off"
          className="min-h-12 min-w-0 flex-1 rounded-xl bg-surface px-4 outline-none focus:ring-2 focus:ring-accent" />
        <button type="submit" disabled={!text.trim()} className="min-h-12 rounded-xl bg-accent px-4 font-semibold text-on-accent disabled:opacity-40">Add</button>
      </form>

      <button onClick={() => setShowRecipes((v) => !v)} aria-expanded={showRecipes} className="mt-6 min-h-11 w-full text-left text-sm font-semibold text-muted">Recipes on this list ({list.recipes.length}) {showRecipes ? '▾' : '▸'}</button>
      {showRecipes ? (
        <ul className="space-y-2">
          {list.recipes.map((r, k) => (
            <li key={`${r.meal_id}-${k}`} className="flex items-center gap-2 rounded-2xl bg-surface p-3">
              <div className="min-w-0 flex-1"><div className="truncate text-sm font-medium">{r.name}{r.missing ? ' (deleted)' : ''}</div>{r.changed && !r.missing ? <div className="text-xs text-carbs">changed since the list was made</div> : null}</div>
              {!r.missing ? (
                <>
                  <button aria-label={`Fewer servings of ${r.name}`} onClick={() => void servings(r.meal_id, -1)} className="size-11 rounded-full bg-raised">−</button>
                  <span className="w-10 text-center tabular-nums" aria-label={`${r.servings} servings`}>{r.servings}</span>
                  <button aria-label={`More servings of ${r.name}`} onClick={() => void servings(r.meal_id, 1)} className="size-11 rounded-full bg-raised">+</button>
                </>
              ) : null}
              <button aria-label={`Take ${r.name} off the list`} onClick={() => void dropRecipe(r.meal_id)} className="size-11 text-muted">✕</button>
            </li>
          ))}
        </ul>
      ) : null}

      <button disabled={busy} onClick={() => void finish()} className="mt-6 min-h-12 w-full rounded-2xl bg-surface text-sm text-muted">Done shopping (archive this list)</button>
    </AppLayout>
  )
}
