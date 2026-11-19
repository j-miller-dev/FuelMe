import { useEffect, useState } from 'react'
import { api } from '@/lib/http'
import { cleanCode, gtinValid } from '@/lib/gtin'
import { hitName, type FoodHit } from '@/lib/food'
import { Sheet } from '@/components/today/Sheet'

export interface FoodPrefill { barcode?: string; name?: string; brand?: string; serving_label?: string | null; serving_grams?: number | null }

type Basis = 'per100' | 'serving'
const num = (s: string): number | null => { const n = Number(s.replace(',', '.')); return s.trim() !== '' && Number.isFinite(n) && n >= 0 ? n : null }

/**
 * Create or edit one of your own foods from a nutrition panel (BLUEPRINT 5.5): per 100 g or per serve (we convert), optional
 * extras, a duplicate warning from the same search the Add screen uses, and the server's own sanity checks shown verbatim.
 */
export function FoodForm({ food, prefill, onSaved, onUseExisting, onClose }: {
  food?: FoodHit; prefill?: FoodPrefill
  onSaved: (food: FoodHit, warnings: string[]) => void; onUseExisting?: (food: FoodHit) => void; onClose: () => void
}) {
  const editing = Boolean(food)
  const s0 = food?.servings[0]
  const [v, setV] = useState({
    name: food?.name ?? prefill?.name ?? '', brand: food?.brand ?? prefill?.brand ?? '', store: food?.stores?.[0] ?? '',
    barcode: food?.barcode ?? prefill?.barcode ?? '', basis: 'per100' as Basis, serving_grams: String(s0?.grams ?? prefill?.serving_grams ?? ''),
    serving_label: s0?.label ?? prefill?.serving_label ?? '', is_liquid: food?.is_liquid ?? false,
    kcal: food?.per100.kcal != null ? String(food.per100.kcal) : '', protein: food?.per100.protein != null ? String(food.per100.protein) : '',
    carbs: food?.per100.carbs != null ? String(food.per100.carbs) : '', fat: food?.per100.fat != null ? String(food.per100.fat) : '',
    fibre: food?.per100.fibre != null ? String(food.per100.fibre) : '', sugar: food?.per100.sugar != null ? String(food.per100.sugar) : '',
    sat_fat: food?.per100.sat_fat != null ? String(food.per100.sat_fat) : '', sodium_mg: food?.per100.sodium_mg != null ? String(food.per100.sodium_mg) : '',
  })
  const [more, setMore] = useState(Boolean(food?.per100.fibre || food?.per100.sodium_mg || prefill?.barcode))
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [clash, setClash] = useState<number | null>(null)
  const [similar, setSimilar] = useState<FoodHit[]>([])
  const set = (k: keyof typeof v, val: string | boolean) => setV((s) => ({ ...s, [k]: val }))

  // Duplicate warning: the same fuzzy search the Add screen uses, only for a new food.
  useEffect(() => {
    const q = v.name.trim()
    if (editing || q.length < 3) { setSimilar([]); return }
    const ctl = new AbortController()
    const t = setTimeout(() => {
      api<{ results: FoodHit[] }>(`/foods/search?q=${encodeURIComponent(q)}`, { signal: ctl.signal }).then((r) => setSimilar(r.results.slice(0, 3))).catch(() => undefined)
    }, 300)
    return () => { clearTimeout(t); ctl.abort() }
  }, [v.name, editing])

  const unit = v.is_liquid ? 'mL' : 'g'
  const bc = cleanCode(v.barcode)
  const barcodeBad = v.barcode.trim() !== '' && !gtinValid(bc)
  const hasMacros = [v.protein, v.carbs, v.fat].some((x) => (num(x) ?? 0) > 0) || (num(v.kcal) ?? 0) > 0
  const needsSize = v.basis === 'serving' && !(num(v.serving_grams) && num(v.serving_grams)! > 0)
  const ok = v.name.trim() !== '' && hasMacros && !needsSize && !barcodeBad && !busy

  async function save() {
    setBusy(true); setError(null); setClash(null)
    const body = {
      name: v.name.trim(), brand: v.brand.trim() || null, store: v.store.trim() || null, barcode: bc || null, is_liquid: v.is_liquid, basis: v.basis,
      serving_label: v.serving_label.trim() || null, serving_grams: num(v.serving_grams),
      kcal: num(v.kcal), protein: num(v.protein) ?? 0, carbs: num(v.carbs) ?? 0, fat: num(v.fat) ?? 0,
      fibre: num(v.fibre), sugar: num(v.sugar), sat_fat: num(v.sat_fat), sodium_mg: num(v.sodium_mg),
    }
    try {
      const r = await api<{ food: FoodHit; warnings: string[] }>(editing ? `/foods/${food!.id}` : '/foods', { method: editing ? 'PUT' : 'POST', body })
      onSaved(r.food, r.warnings)
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Could not save.')
      // 422 for a taken barcode carries the existing food's id; re-ask for it so we can offer it.
      if (e instanceof Error && /already in the database/i.test(e.message)) {
        const existing = await api<{ food?: FoodHit }>(`/foods/barcode?code=${encodeURIComponent(bc)}`).then((r) => r.food).catch(() => undefined)
        if (existing) setClash(existing.id)
      }
    } finally { setBusy(false) }
  }

  const field = (k: 'kcal' | 'protein' | 'carbs' | 'fat' | 'fibre' | 'sugar' | 'sat_fat' | 'sodium_mg', label: string, ph?: string) => (
    <label className="text-xs text-muted">{label}
      <input inputMode="decimal" placeholder={ph} value={v[k]} onChange={(e) => set(k, e.target.value)}
        className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-lg tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" />
    </label>
  )
  const text = (k: 'name' | 'brand' | 'store' | 'barcode' | 'serving_label', label: string, ph?: string, mode?: 'numeric') => (
    <label className="text-xs text-muted">{label}
      <input value={v[k]} inputMode={mode} placeholder={ph} autoComplete="off" data-1p-ignore data-lpignore="true" onChange={(e) => set(k, e.target.value)}
        className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-ink outline-none focus:ring-2 focus:ring-accent" />
    </label>
  )

  return (
    <Sheet label={editing ? 'Edit food' : 'Create a food'} onClose={onClose} tall z={60}>
      <div className="flex items-center gap-2 px-4 pt-4">
        <h2 className="flex-1 text-lg font-semibold">{editing ? 'Edit food' : 'Create a food'}</h2>
        <button onClick={onClose} aria-label="Close" className="size-11 rounded-full bg-surface">✕</button>
      </div>
      <div className="min-h-0 flex-1 space-y-3 overflow-y-auto px-4 pb-4 pt-3">
        {text('name', 'Name', 'e.g. Protein bar, choc brownie')}
        {similar.length > 0 && (
          <div className="rounded-xl bg-carbs/10 p-3 text-xs" role="status">
            <div className="font-medium text-carbs">Similar foods already exist</div>
            <ul className="mt-1 space-y-1">{similar.map((h) => (
              <li key={h.id}><button onClick={() => onUseExisting?.(h)} disabled={!onUseExisting} className="text-left text-ink underline decoration-dotted">{hitName(h)} <span className="text-muted">· {Math.round(h.per100.kcal ?? 0)} kcal/100</span></button></li>
            ))}</ul>
          </div>
        )}
        <div className="grid grid-cols-2 gap-2">{text('brand', 'Brand (optional)')}{text('store', 'Store (optional)', 'Aldi, Coles…')}</div>

        <div>
          <div className="mb-1 text-xs text-muted">Nutrition panel values are</div>
          <div className="flex gap-1 rounded-full bg-surface p-1" role="group" aria-label="Values are per">
            {([['per100', `per 100 ${unit}`], ['serving', 'per serve']] as const).map(([k, l]) => (
              <button key={k} aria-pressed={v.basis === k} onClick={() => set('basis', k)}
                className={`min-h-10 flex-1 rounded-full text-sm ${v.basis === k ? 'bg-raised font-semibold text-accent' : 'text-muted'}`}>{l}</button>
            ))}
          </div>
        </div>
        <div className="grid grid-cols-2 gap-2">
          <label className="text-xs text-muted">Serving size ({unit}){v.basis === 'serving' ? ' · required' : ''}
            <input inputMode="decimal" value={v.serving_grams} onChange={(e) => set('serving_grams', e.target.value)} placeholder="e.g. 45"
              className="mt-1 min-h-12 w-full rounded-xl bg-raised px-3 text-lg tabular-nums text-ink outline-none focus:ring-2 focus:ring-accent" />
          </label>
          {text('serving_label', 'Serving name', '1 bar (45 g)')}
        </div>
        <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={v.is_liquid} onChange={(e) => set('is_liquid', e.target.checked)} className="size-5 accent-[var(--accent)]" /> It is a drink (measured in mL)</label>

        <div className="grid grid-cols-2 gap-2">
          {field('kcal', 'Calories', 'blank = from macros')}{field('protein', 'Protein g')}{field('carbs', 'Carbs g')}{field('fat', 'Fat g')}
        </div>
        <button onClick={() => setMore((m) => !m)} className="min-h-10 text-sm text-accent" aria-expanded={more}>{more ? 'Hide extras' : 'Fibre, sugar, sodium, barcode…'}</button>
        {more && (
          <div className="space-y-3">
            <div className="grid grid-cols-2 gap-2">{field('fibre', 'Fibre g')}{field('sugar', 'Sugars g')}{field('sat_fat', 'Saturated fat g')}{field('sodium_mg', 'Sodium mg')}</div>
            {text('barcode', 'Barcode (so you can scan it next time)', '13 digits', 'numeric')}
            {barcodeBad ? <p className="text-xs text-danger" role="alert">That is not a valid barcode. Check the digits.</p> : null}
          </div>
        )}
        {error ? (
          <div role="alert" className="rounded-xl bg-danger/10 p-3 text-sm text-danger">
            {error}
            {clash && onUseExisting ? <button className="mt-2 block min-h-10 font-semibold underline" onClick={() => void api<{ food?: FoodHit }>(`/foods/barcode?code=${encodeURIComponent(bc)}`).then((r) => r.food && onUseExisting(r.food))}>Use that food instead</button> : null}
          </div>
        ) : null}
      </div>
      <div className="px-4 pb-4 pt-2">
        <button disabled={!ok} onClick={() => void save()} className="min-h-12 w-full rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40">{busy ? 'Saving…' : editing ? 'Save changes' : 'Save food'}</button>
      </div>
    </Sheet>
  )
}
