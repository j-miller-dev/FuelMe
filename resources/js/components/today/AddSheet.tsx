import { useCallback, useEffect, useRef, useState } from 'react'
import { api } from '@/lib/http'
import { SLOTS, SLOT_LABEL, defaultGrams, hitName, macrosFor, unitOf, type FoodHit, type Slot } from '@/lib/food'
import type { NewLog } from '@/lib/useLogging'
import { fmtEnergy, energyLabel, type EnergyUnit } from '@/lib/units'
import { FoodForm, type FoodPrefill } from '@/components/foods/FoodForm'
import { PortionSheet } from './PortionSheet'
import { ScanTab } from './ScanTab'
import { Sheet } from './Sheet'

type Tab = 'search' | 'scan' | 'quick' | 'copy'
interface Quick { frequent: FoodHit[]; recents: FoodHit[]; favourites: FoodHit[] }
interface SearchResponse { query: { term: string; qty: number | null; unit: string | null }; results: FoodHit[] }

export function AddSheet({ slot, setSlot, eaten, target, unit, previous, onAdd, onCopy, onClose }: {
  slot: Slot; setSlot: (s: Slot) => void; eaten: number; target: number; unit: EnergyUnit
  previous: { date: string; count: number; kcal: number }
  onAdd: (items: NewLog[]) => Promise<unknown>; onCopy: (from: string, slot?: Slot) => Promise<void>; onClose: () => void
}) {
  const [tab, setTab] = useState<Tab>('search')
  const [q, setQ] = useState('')
  const [results, setResults] = useState<FoodHit[] | null>(null)
  const [searchErr, setSearchErr] = useState<string | null>(null)
  const [quick, setQuick] = useState<Quick | null>(null)
  const [picked, setPicked] = useState<FoodHit | null>(null)
  const [added, setAdded] = useState<Record<number, number>>({})
  const [creating, setCreating] = useState<FoodPrefill | null>(null)
  const [injected, setInjected] = useState<FoodHit | null>(null)
  const seq = useRef(0)

  const loadQuick = useCallback(() => api<Quick>('/foods/quick').then(setQuick).catch(() => setQuick({ frequent: [], recents: [], favourites: [] })), [])
  useEffect(() => { void loadQuick() }, [loadQuick])

  // Debounced search; stale responses are ignored so fast typing never shows old results.
  useEffect(() => {
    const term = q.trim()
    if (term.length < 2) { setResults(null); setSearchErr(null); return }
    const mine = ++seq.current
    const ctl = new AbortController()
    const t = setTimeout(() => {
      api<SearchResponse>(`/foods/search?q=${encodeURIComponent(term)}`, { signal: ctl.signal })
        .then((r) => { if (mine === seq.current) { setResults(r.results); setSearchErr(null) } })
        .catch((e) => { if (mine === seq.current && e?.name !== 'AbortError') setSearchErr('Search failed. Check your connection.') })
    }, 120)
    return () => { clearTimeout(t); ctl.abort() }
  }, [q])

  const addNow = (h: FoodHit) => {
    const grams = defaultGrams(h)
    setAdded((a) => ({ ...a, [h.id]: (a[h.id] ?? 0) + 1 }))
    navigator.vibrate?.(8)
    void onAdd([{ kind: 'food', food: h, grams, slot, serving_label: h.portion.grams ? h.portion.label : null }])
  }
  const toggleFav = (h: FoodHit) => {
    void api<{ favourite: boolean }>(`/foods/${h.id}/favourite`, { method: 'POST' }).then((r) => {
      const patch = (l: FoodHit[] | null) => l?.map((x) => (x.id === h.id ? { ...x, is_favourite: r.favourite } : x)) ?? null
      setResults(patch); setPicked((p) => (p && p.id === h.id ? { ...p, is_favourite: r.favourite } : p)); void loadQuick()
    })
  }

  const Row = ({ h, hint }: { h: FoodHit; hint?: string }) => {
    const g = defaultGrams(h)
    const m = macrosFor(h.per100, g)
    const n = added[h.id] ?? 0
    return (
      <li className="flex min-h-16 items-center gap-2 border-b border-raised/70 py-1">
        <button onClick={() => setPicked(h)} className="min-w-0 flex-1 py-2 text-left" aria-label={`Choose amount for ${hitName(h)}`}>
          <div className="truncate text-sm font-medium">{hitName(h)}{h.needs_check ? <span className="ml-1 rounded bg-carbs/20 px-1 text-[10px] text-carbs">check</span> : null}</div>
          <div className="truncate text-xs text-muted tabular-nums">
            {Math.round(m.kcal)} kcal · {Math.round(m.protein)}P {Math.round(m.carbs)}C {Math.round(m.fat)}F · {g} {unitOf(h)}{hint ? ` · ${hint}` : ''}
          </div>
        </button>
        <button onClick={() => addNow(h)} aria-label={`Log ${hitName(h)}, ${g} ${unitOf(h)}`}
          className={`relative size-12 shrink-0 rounded-full text-xl active:scale-90 ${n ? 'bg-accent text-on-accent' : 'bg-raised'}`}>
          {n ? '✓' : '+'}{n > 1 ? <span className="absolute -right-1 -top-1 rounded-full bg-canvas px-1.5 text-[10px] text-accent">{n}</span> : null}
        </button>
      </li>
    )
  }
  const Section = ({ title, items, hint }: { title: string; items: FoodHit[]; hint?: (h: FoodHit) => string | undefined }) =>
    items.length ? <section className="mt-4"><h2 className="text-sm font-semibold text-muted">{title}</h2><ul>{items.map((h) => <Row key={h.id} h={h} hint={hint?.(h)} />)}</ul></section> : null

  const pct = target > 0 ? Math.min(eaten / target, 1) : 0
  const searching = q.trim().length >= 2
  const emptyQuick = quick && !quick.frequent.length && !quick.recents.length && !quick.favourites.length

  return (
    <Sheet label="Add food" onClose={onClose} tall>
      <div className="flex items-center gap-2 px-4 pt-4">
        <button aria-label="Close" onClick={onClose} className="size-11 rounded-full bg-surface">✕</button>
        <div className="relative min-h-11 flex-1 overflow-hidden rounded-full bg-surface" role="img" aria-label={`${fmtEnergy(eaten, unit)} of ${fmtEnergy(target, unit)} ${energyLabel(unit)}`}>
          <div className="absolute inset-y-0 left-0 bg-accent/25 transition-all" style={{ width: `${pct * 100}%` }} />
          <div className="relative flex h-11 items-center justify-center text-sm tabular-nums">{fmtEnergy(eaten, unit)} / {fmtEnergy(target, unit)} {energyLabel(unit)}</div>
        </div>
      </div>
      <div className="mt-3 flex gap-1 px-4" role="group" aria-label="Meal">
        {SLOTS.map((s) => (
          <button key={s} aria-pressed={slot === s} onClick={() => setSlot(s)}
            className={`min-h-10 flex-1 rounded-full text-xs ${slot === s ? 'bg-raised font-semibold text-accent' : 'bg-surface text-muted'}`}>{SLOT_LABEL[s]}</button>
        ))}
      </div>
      <div className="mt-3 flex border-b border-raised px-4" role="tablist">
        {([['search', 'Search'], ['scan', 'Scan'], ['quick', 'Quick add'], ['copy', 'Copy']] as const).map(([k, l]) => (
          <button key={k} role="tab" aria-selected={tab === k} onClick={() => setTab(k)}
            className={`min-h-11 flex-1 text-sm ${tab === k ? 'border-b-2 border-accent font-medium' : 'text-muted'}`}>{l}</button>
        ))}
      </div>

      <div className="min-h-0 flex-1 overflow-y-auto px-4 pb-6 pt-3">
        {tab === 'search' && (
          <>
            <input aria-label="Search for a food" autoFocus enterKeyHint="search" placeholder="Search, e.g. 150g chicken breast, weetbix 2"
              value={q} onChange={(e) => setQ(e.target.value)} className="min-h-12 w-full rounded-xl bg-surface px-4 outline-none ring-accent focus:ring-2" />
            {searching ? (
              searchErr ? <p role="alert" className="mt-4 text-sm text-danger">{searchErr}</p>
                : results === null ? <p className="mt-4 text-sm text-muted">Searching…</p>
                : results.length ? <ul className="mt-2">{results.map((h) => <Row key={h.id} h={h} hint={h.source === 'afcd' ? 'AFCD' : h.source === 'off' ? 'Packaged' : undefined} />)}</ul>
                : <p className="mt-4 text-sm text-muted">No match. Try fewer words, or create the food yourself.</p>
            ) : quick === null ? <p className="mt-4 text-sm text-muted">Loading…</p> : (
              <>
                <Section title="Frequent now" items={quick.frequent} />
                <Section title="Favourites" items={quick.favourites} />
                {/* Recents without the foods already shown above, so a short history does not list everything twice. */}
                <Section title="Recents" items={quick.recents.filter((r) => !quick.frequent.some((f) => f.id === r.id) && !quick.favourites.some((f) => f.id === r.id))} />
                {emptyQuick ? <p className="mt-6 text-sm text-muted">Type at least two letters to search AFCD and Australian packaged foods. Foods you log show up here for one-tap repeats.</p> : null}
              </>
            )}
            <button onClick={() => setCreating({ name: q.trim() })} className="mt-4 min-h-11 w-full rounded-xl bg-surface text-sm text-accent">Can't find it? Create a food</button>
          </>
        )}

        {tab === 'scan' && <ScanTab slot={slot} injected={injected} onAdd={onAdd} onPick={setPicked} onCreate={setCreating} />}

        {tab === 'quick' && <QuickAdd slot={slot} onAdd={(it) => { void onAdd([it]); onClose() }} />}

        {tab === 'copy' && (
          <div className="space-y-3">
            <button disabled={!previous.count} onClick={() => { void onCopy(previous.date); onClose() }}
              className="flex min-h-14 w-full items-center justify-between rounded-2xl bg-surface px-4 text-left disabled:opacity-40">
              <span><span className="font-medium">Copy yesterday</span><span className="block text-xs text-muted">{previous.count ? `${previous.count} entries · ${fmtEnergy(previous.kcal, unit)} ${energyLabel(unit)}` : 'Nothing logged yesterday'}</span></span>
              <span className="text-accent">+</span>
            </button>
            <div>
              <div className="mb-1 text-xs text-muted">Or copy just one meal from yesterday into {SLOT_LABEL[slot].toLowerCase()}</div>
              <div className="flex gap-2">
                {SLOTS.map((s) => (
                  <button key={s} disabled={!previous.count} onClick={() => { void onCopy(previous.date, s); onClose() }}
                    className="min-h-11 flex-1 rounded-xl bg-surface text-xs disabled:opacity-40">{SLOT_LABEL[s]}</button>
                ))}
              </div>
            </div>
          </div>
        )}
      </div>

      {creating && (
        <FoodForm prefill={creating} onClose={() => setCreating(null)}
          onUseExisting={(h) => { setCreating(null); setPicked(h) }}
          onSaved={(h) => { setCreating(null); if (tab === 'scan') setInjected(h); else setPicked(h) }} />
      )}

      {picked && (
        <PortionSheet title={hitName(picked)} subtitle={[picked.category, picked.state].filter(Boolean).join(' · ')} per100={picked.per100} unit={unitOf(picked) as 'g' | 'mL'}
          servings={picked.servings} initialGrams={defaultGrams(picked)} initialSlot={slot} mode="add" favourite={picked.is_favourite} onFavourite={() => toggleFav(picked)}
          onClose={() => setPicked(null)}
          onSubmit={(r) => { setAdded((a) => ({ ...a, [picked.id]: (a[picked.id] ?? 0) + 1 })); void onAdd([{ kind: 'food', food: picked, grams: r.grams, slot: r.slot, serving_label: r.serving_label }]); setPicked(null) }} />
      )}
    </Sheet>
  )
}

function QuickAdd({ slot, onAdd }: { slot: Slot; onAdd: (it: NewLog) => void }) {
  const [v, setV] = useState({ name: '', kcal: '', protein: '', carbs: '', fat: '' })
  const n = (s: string) => (Number(s.replace(',', '.')) > 0 ? Number(s.replace(',', '.')) : 0)
  const derived = Math.round(4 * n(v.protein) + 4 * n(v.carbs) + 9 * n(v.fat))
  const kcal = n(v.kcal) || derived
  const field = (k: 'kcal' | 'protein' | 'carbs' | 'fat', label: string, ph?: string) => (
    <label className="text-xs text-muted">{label}
      <input inputMode="decimal" placeholder={ph} value={v[k]} onChange={(e) => setV((s) => ({ ...s, [k]: e.target.value }))}
        className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-lg tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" />
    </label>
  )
  return (
    <div className="rounded-2xl bg-surface p-4">
      <label className="text-xs text-muted">Name (optional)
        <input value={v.name} onChange={(e) => setV((s) => ({ ...s, name: e.target.value }))} placeholder="Quick add"
          className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-ink outline-none focus:ring-2 focus:ring-accent" />
      </label>
      <div className="mt-3 grid grid-cols-2 gap-2">
        {field('kcal', 'Calories', derived ? String(derived) : undefined)}{field('protein', 'Protein g')}{field('carbs', 'Carbs g')}{field('fat', 'Fat g')}
      </div>
      <p className="mt-2 text-xs text-muted">Leave calories blank to work them out from the macros (4 · 4 · 9).</p>
      <button disabled={kcal <= 0} onClick={() => onAdd({ kind: 'quick', name: v.name.trim(), kcal: n(v.kcal) || null, protein: n(v.protein), carbs: n(v.carbs), fat: n(v.fat), slot })}
        className="mt-3 min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">Log {kcal > 0 ? `${kcal} kcal ` : ''}to {SLOT_LABEL[slot].toLowerCase()}</button>
    </div>
  )
}
