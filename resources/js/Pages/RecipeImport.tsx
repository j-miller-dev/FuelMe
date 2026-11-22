import { Head, Link } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/layouts/AppLayout'
import { Sheet } from '@/components/today/Sheet'
import { api, apiForm } from '@/lib/http'
import { cn } from '@/lib/utils'

interface Cand { id: number; name: string; brand: string | null; stores: string[]; source: string; per100: { kcal: number | null; protein: number | null } }
interface Match {
  parsed: { name_query: string; store?: string | null; brand?: string | null }
  candidates: Cand[]
  chosen: { food_id: number; grams: number; needs_amount: boolean } | null
  confidence: 'green' | 'amber' | 'red'
  notes: string[]
}
interface Ing { key: number; raw: string; group: string | null; note: string | null; match: Match | null }
interface Rec { file: string; title: string; servings: number | null; tags: string[]; warnings: string[]; ingredients: Ing[] }

interface Row { key: number; raw: string; group: string | null; note: string | null; phrase: string | null; cand: Cand | null; grams: string; confidence: 'green' | 'amber' | 'red'; hint: string | null; options: Cand[]; dropped: boolean }
interface Draft { file: string; title: string; servings: string; tags: string[]; warnings: string[]; rows: Row[]; include: boolean; saved?: boolean; error?: string }

const SRC: Record<string, string> = { afcd: 'AFCD', off: 'Packaged', user: 'Yours', recipe: 'Recipe' }
const DOT = { green: 'bg-accent', amber: 'bg-carbs', red: 'bg-danger' } as const

function toDraft(r: Rec): Draft {
  return {
    file: r.file, title: r.title, servings: String(r.servings ?? 1), tags: r.tags, warnings: r.warnings, include: r.ingredients.length > 0,
    rows: r.ingredients.map((i) => {
      const m = i.match
      const cand = m?.chosen ? m.candidates.find((c) => c.id === m.chosen!.food_id) ?? null : null
      return {
        key: i.key, raw: i.raw, group: i.group, note: i.note, phrase: m?.parsed.name_query ?? null, cand, grams: m?.chosen ? String(m.chosen.grams) : '', confidence: m?.confidence ?? 'red',
        hint: m ? (m.notes[0] ?? null) : 'Nothing food-like found on this line.', options: m?.candidates ?? [], dropped: false,
      }
    }),
  }
}

export default function RecipeImport() {
  const [drafts, setDrafts] = useState<Draft[] | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [swap, setSwap] = useState<{ d: number; r: number } | null>(null)
  const [skipped, setSkipped] = useState(0)

  async function pick(files: FileList | null) {
    if (!files || files.length === 0) return
    setBusy(true); setError(null)
    const fd = new FormData()
    Array.from(files).forEach((f) => fd.append('files[]', f))
    try {
      const r = await apiForm<{ recipes: Rec[]; skipped: number }>('/meals/import/preview', fd)
      setDrafts(r.recipes.map(toDraft)); setSkipped(r.skipped)
    } catch (e) { setError(e instanceof Error ? e.message : 'Could not read those files.') }
    setBusy(false)
  }

  const setRow = (d: number, r: number, patch: Partial<Row>) => setDrafts((all) => all!.map((x, i) => (i !== d ? x : { ...x, rows: x.rows.map((y, j) => (j === r ? { ...y, ...patch } : y)) })))
  const setDraft = (d: number, patch: Partial<Draft>) => setDrafts((all) => all!.map((x, i) => (i === d ? { ...x, ...patch } : x)))
  const usable = (x: Draft) => x.rows.filter((r) => !r.dropped && r.cand && Number(r.grams) >= 0.1)
  const ready = (x: Draft) => x.include && !x.saved && x.title.trim() !== '' && usable(x).length > 0

  async function save() {
    if (!drafts) return
    setBusy(true); setError(null)
    for (let d = 0; d < drafts.length; d++) {
      const x = drafts[d]
      if (!x || !ready(x)) continue
      const items = usable(x)
      try {
        await api('/meals', { method: 'POST', body: { name: x.title.trim(), yield_portions: Math.max(0.5, Number(x.servings) || 1), cook_loss: 0, tags: x.tags, items: items.map((r) => ({ food_id: r.cand!.id, grams: Number(r.grams) })) } })
        // Remember which product you meant, so the next "chicken breast" lands on it. Not the grams: this recipe's weight is not your usual portion.
        const learn = items.filter((r) => r.phrase).map((r) => ({ phrase: r.phrase!, food_id: r.cand!.id, grams: null }))
        if (learn.length) await api('/capture/learn', { method: 'POST', body: { items: learn } }).catch(() => undefined)
        setDraft(d, { saved: true, error: undefined })
      } catch (e) { setDraft(d, { error: e instanceof Error ? e.message : 'Could not save.' }) }
    }
    setBusy(false)
  }

  const swapRow = swap ? drafts?.[swap.d]?.rows[swap.r] : undefined
  const toSave = drafts?.filter(ready).length ?? 0
  const savedCount = drafts?.filter((x) => x.saved).length ?? 0

  return (
    <AppLayout>
      <Head title="Import recipes" />
      <div className="flex items-center justify-between">
        <h1 className="text-2xl font-bold">Import recipes</h1>
        <Link href="/meals" className="text-sm text-accent">Meals</Link>
      </div>
      <p className="mt-1 text-sm text-muted">Pick recipe notes from your Obsidian vault (.md files). FUEL reads the ingredient list, then you confirm which food each line means, including which chicken and from which shop. Nothing is saved until you press Save.</p>

      {!drafts ? (
        <label className="mt-4 block rounded-2xl bg-surface p-5 text-center">
          <span className="block font-medium">{busy ? 'Reading…' : 'Choose recipe files'}</span>
          <span className="mt-1 block text-xs text-muted">Up to 40 notes at a time. Needs an “Ingredients” heading with a list under it.</span>
          <input type="file" multiple accept=".md,.markdown,.txt,text/markdown,text/plain" disabled={busy} onChange={(e) => void pick(e.target.files)} className="sr-only" />
          <span className="mt-3 inline-block min-h-11 rounded-2xl bg-accent px-5 py-3 font-semibold text-on-accent">Browse files</span>
        </label>
      ) : (
        <>
          {skipped > 0 ? <p className="mt-3 rounded-xl bg-surface px-4 py-3 text-sm text-muted">Only the first {300} ingredient lines were matched. Import the rest in a second go.</p> : null}
          <div className="mt-4 space-y-4">
            {drafts.map((x, d) => (
              <section key={x.file + d} className={cn('rounded-2xl bg-surface p-4', x.saved && 'opacity-70')} aria-label={x.title}>
                <div className="flex items-start gap-2">
                  <input type="checkbox" checked={x.include} disabled={x.saved} onChange={(e) => setDraft(d, { include: e.target.checked })} aria-label={`Import ${x.title}`} className="mt-3 size-5" />
                  <div className="min-w-0 flex-1">
                    <input value={x.title} onChange={(e) => setDraft(d, { title: e.target.value })} disabled={x.saved} aria-label="Recipe name" className="min-h-11 w-full rounded-xl bg-canvas/60 px-3 font-semibold outline-none ring-1 ring-transparent focus:ring-accent" />
                    <div className="mt-1 flex items-center gap-2 text-sm text-muted">
                      <label className="flex items-center gap-1">Makes <input inputMode="decimal" value={x.servings} onChange={(e) => setDraft(d, { servings: e.target.value.replace(',', '.') })} disabled={x.saved} aria-label="Servings" className="min-h-9 w-14 rounded-lg bg-canvas/60 px-2 text-center text-ink outline-none" /> servings</label>
                      <span className="truncate text-xs">{x.file}</span>
                    </div>
                  </div>
                </div>
                {x.warnings.map((w) => <p key={w} className="mt-2 text-xs text-carbs">{w}</p>)}
                {x.saved ? <p role="status" className="mt-2 text-sm text-accent">Saved to Meals.</p> : null}
                {x.error ? <p role="alert" className="mt-2 text-sm text-danger">{x.error}</p> : null}

                {!x.saved && x.include ? (
                  <ul className="mt-3 divide-y divide-raised">
                    {x.rows.map((r, i) => r.dropped ? null : (
                      <li key={r.key} className="py-2">
                        {r.group && (i === 0 || x.rows[i - 1]?.group !== r.group) ? <div className="mb-1 text-xs uppercase tracking-wide text-muted">{r.group}</div> : null}
                        <div className="flex items-start gap-2">
                          <span className={cn('mt-2 size-2.5 shrink-0 rounded-full', DOT[r.confidence])} aria-label={r.confidence === 'green' ? 'Confident match' : r.confidence === 'amber' ? 'Check this one' : 'No match yet'} />
                          <div className="min-w-0 flex-1">
                            <div className="truncate text-xs text-muted">{r.raw}</div>
                            <button onClick={() => setSwap({ d, r: i })} className="mt-0.5 block w-full text-left" aria-label={`Change food for ${r.raw}`}>
                              {r.cand ? (
                                <>
                                  <span className="block truncate text-sm font-medium">{r.cand.brand && !r.cand.name.toLowerCase().includes(r.cand.brand.toLowerCase()) ? `${r.cand.brand} ${r.cand.name}` : r.cand.name}</span>
                                  <span className="text-xs text-muted">{SRC[r.cand.source] ?? r.cand.source}{r.cand.stores.length ? ` · ${r.cand.stores.slice(0, 3).join(', ')}` : ''} · tap to change</span>
                                </>
                              ) : <span className="text-sm text-danger">Pick a food…</span>}
                            </button>
                            {r.hint && r.confidence !== 'green' ? <div className="mt-0.5 text-xs text-carbs">{r.hint}</div> : null}
                            {r.note ? <div className="mt-0.5 text-xs text-muted">Note: {r.note}</div> : null}
                          </div>
                          <label className="flex shrink-0 items-baseline gap-1 text-sm">
                            <span className="sr-only">Grams</span>
                            <input inputMode="decimal" value={r.grams} onChange={(e) => setRow(d, i, { grams: e.target.value.replace(',', '.') })} placeholder="g" className="min-h-10 w-16 rounded-lg bg-canvas/60 px-2 text-right tabular-nums outline-none ring-1 ring-transparent focus:ring-accent" />
                            <span className="text-xs text-muted">g</span>
                          </label>
                          <button onClick={() => setRow(d, i, { dropped: true })} aria-label={`Leave out ${r.raw}`} className="size-10 shrink-0 rounded-full text-muted">✕</button>
                        </div>
                      </li>
                    ))}
                  </ul>
                ) : null}
              </section>
            ))}
          </div>

          {savedCount > 0 && toSave === 0 ? (
            <Link href="/meals" className="mt-4 block min-h-12 rounded-2xl bg-accent py-3 text-center font-semibold text-on-accent">Done: see {savedCount} recipe{savedCount === 1 ? '' : 's'} in Meals</Link>
          ) : (
            <button onClick={() => void save()} disabled={busy || toSave === 0} className="mt-4 min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">{busy ? 'Saving…' : `Save ${toSave} recipe${toSave === 1 ? '' : 's'}`}</button>
          )}
          <p className="mt-3 text-xs text-muted">Weights are read as RAW weights, like the rest of Meals. A line with a count (“2 eggs”) and no stored serving needs its grams typed. Save your own serving once in the Add sheet and it fills in next time.</p>
        </>
      )}
      {error ? <p role="alert" className="mt-3 text-sm text-danger">{error}</p> : null}

      {swap && swapRow ? <SwapSheet raw={swapRow.raw} options={swapRow.options} currentId={swapRow.cand?.id ?? null}
        onPick={(c) => { setRow(swap.d, swap.r, { cand: c, confidence: 'green', hint: null }); setSwap(null) }} onClose={() => setSwap(null)} /> : null}
    </AppLayout>
  )
}

function SwapSheet({ raw, options, currentId, onPick, onClose }: { raw: string; options: Cand[]; currentId: number | null; onPick: (c: Cand) => void; onClose: () => void }) {
  const [q, setQ] = useState('')
  const [found, setFound] = useState<Cand[] | null>(null)
  const list = found ?? options
  async function search(v: string) {
    setQ(v)
    if (v.trim().length < 2) { setFound(null); return }
    try { const r = await api<{ results: Cand[] }>(`/foods/search?q=${encodeURIComponent(v.trim())}`); setFound(r.results.slice(0, 12)) } catch { /* keep what is shown */ }
  }
  return (
    <Sheet label="Choose the food" onClose={onClose} z={50}>
      <div className="px-4 pt-4">
        <div className="flex items-center gap-2"><h2 className="flex-1 truncate font-semibold">{raw}</h2><button onClick={onClose} aria-label="Close" className="size-11 rounded-full bg-surface">✕</button></div>
        <input value={q} onChange={(e) => void search(e.target.value)} placeholder="Search, e.g. woolworths chicken breast" aria-label="Search foods" className="mt-2 min-h-12 w-full rounded-xl bg-surface px-3 outline-none ring-1 ring-transparent focus:ring-accent" />
      </div>
      <ul className="max-h-[55vh] divide-y divide-raised overflow-y-auto px-4 pb-4 pt-2">
        {list.length === 0 ? <li className="py-4 text-sm text-muted">No matches. Try fewer words.</li> : list.map((c) => (
          <li key={c.id}>
            <button onClick={() => onPick(c)} className={cn('flex min-h-14 w-full items-center justify-between gap-3 py-2 text-left', c.id === currentId && 'text-accent')}>
              <span className="min-w-0">
                <span className="block truncate text-sm font-medium">{c.brand && !c.name.toLowerCase().includes(c.brand.toLowerCase()) ? `${c.brand} ${c.name}` : c.name}</span>
                <span className="text-xs text-muted">{SRC[c.source] ?? c.source}{c.stores.length ? ` · ${c.stores.slice(0, 3).join(', ')}` : ''}</span>
              </span>
              <span className="shrink-0 text-xs text-muted tabular-nums">{c.per100.kcal != null ? `${Math.round(c.per100.kcal)} kcal` : ''}{c.per100.protein != null ? ` · ${Math.round(c.per100.protein)}p` : ''} /100</span>
            </button>
          </li>
        ))}
      </ul>
    </Sheet>
  )
}
