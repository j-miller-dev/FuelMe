import { useState } from 'react'
import { api } from '@/lib/http'
import { SLOTS, SLOT_LABEL, macrosFor, stepFor, type Per100, type Slot } from '@/lib/food'
import { Sheet } from './Sheet'

export interface PortionResult { grams: number; slot: Slot; serving_label: string | null }

/**
 * The serving stepper. Used to add a food (per100 from the food row) and to edit a logged one (per100 derived from the log's
 * own snapshot, so editing never re-reads a food that may have changed).
 */
type Serving = { id?: number | null; label: string; grams: number; own?: boolean }

export function PortionSheet({ title, subtitle, per100, unit, servings, foodId, onServingsChange, initialGrams, initialSlot, mode, favourite, onFavourite, onSubmit, onRemove, onClose }: {
  title: string; subtitle?: string; per100: Per100; unit: 'g' | 'mL'; servings: Serving[]
  /** When given, you can save the current amount as your own named serving ("1 egg") for this food. */
  foodId?: number; onServingsChange?: (s: Serving[]) => void
  initialGrams: number; initialSlot: Slot; mode: 'add' | 'edit'
  favourite?: boolean; onFavourite?: () => void
  onSubmit: (r: PortionResult) => void; onRemove?: () => void; onClose: () => void
}) {
  const [text, setText] = useState(String(initialGrams))
  const [slot, setSlot] = useState<Slot>(initialSlot)
  const [label, setLabel] = useState<string | null>(null)
  const [naming, setNaming] = useState(false)
  const [newLabel, setNewLabel] = useState('')
  const [svError, setSvError] = useState<string | null>(null)
  const grams = Number(text)
  const valid = Number.isFinite(grams) && grams >= 0.1 && grams <= 5000
  const m = macrosFor(per100, valid ? grams : 0)
  const bump = (d: 1 | -1) => {
    const g = valid ? grams : initialGrams
    const next = Math.max(1, Math.round((g + d * stepFor(g)) * 10) / 10)
    setText(String(next)); setLabel(null); navigator.vibrate?.(6)
  }
  const saveServing = async () => {
    if (!foodId || !valid || !newLabel.trim()) return
    setSvError(null)
    try {
      const r = await api<{ servings: Serving[] }>(`/foods/${foodId}/servings`, { method: 'POST', body: { label: newLabel.trim(), grams } })
      onServingsChange?.(r.servings); setNaming(false); setNewLabel('')
    } catch (e) { setSvError(e instanceof Error ? e.message : 'Could not save.') }
  }
  const dropServing = async (id: number) => {
    setSvError(null)
    try { const r = await api<{ servings: Serving[] }>(`/foods/servings/${id}`, { method: 'DELETE' }); onServingsChange?.(r.servings) }
    catch (e) { setSvError(e instanceof Error ? e.message : 'Could not remove.') }
  }
  return (
    <Sheet label={`${mode === 'add' ? 'Add' : 'Edit'} ${title}`} onClose={onClose} z={50}>
      <div className="flex items-start gap-2 px-4 pt-4">
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-semibold leading-tight">{title}</h2>
          {subtitle ? <p className="mt-0.5 truncate text-xs text-muted">{subtitle}</p> : null}
        </div>
        {onFavourite ? (
          <button onClick={onFavourite} aria-pressed={favourite} aria-label={favourite ? 'Remove from favourites' : 'Add to favourites'}
            className={`size-11 rounded-full ${favourite ? 'bg-accent/20 text-accent' : 'bg-surface text-muted'}`}>{favourite ? '★' : '☆'}</button>
        ) : null}
        <button onClick={onClose} aria-label="Close" className="size-11 rounded-full bg-surface">✕</button>
      </div>

      <div className="px-4 pt-4">
        <div className="flex items-center justify-center gap-3">
          <button aria-label="Less" onClick={() => bump(-1)} className="size-14 rounded-full bg-surface text-2xl active:scale-95">−</button>
          <label className="flex items-baseline gap-1 rounded-2xl bg-surface px-4 py-2">
            <span className="sr-only">Amount in {unit}</span>
            <input inputMode="decimal" value={text} onChange={(e) => { setText(e.target.value.replace(',', '.')); setLabel(null) }}
              className="w-24 bg-transparent text-center text-4xl font-bold tabular-nums outline-none" />
            <span className="text-muted">{unit}</span>
          </label>
          <button aria-label="More" onClick={() => bump(1)} className="size-14 rounded-full bg-surface text-2xl active:scale-95">+</button>
        </div>

        {(
          <div className="mt-3 flex gap-2 overflow-x-auto pb-1" role="group" aria-label="Common amounts">
            {servings.map((s) => (
              <span key={s.label} className="flex shrink-0 items-center">
                <button onClick={() => { setText(String(s.grams)); setLabel(s.label) }}
                  className={`min-h-11 rounded-full px-3 text-sm ${Number(text) === s.grams ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-surface'}`}>{s.label}</button>
                {s.own && s.id ? <button aria-label={`Remove your serving ${s.label}`} onClick={() => void dropServing(s.id as number)} className="ml-0.5 size-8 rounded-full text-xs text-muted">✕</button> : null}
              </span>
            ))}
            {[50, 100, 200].map((g) => (
              <button key={g} onClick={() => { setText(String(g)); setLabel(null) }}
                className={`min-h-11 shrink-0 rounded-full px-3 text-sm ${Number(text) === g && !label ? 'bg-accent/20 text-accent ring-1 ring-accent' : 'bg-surface'}`}>{g} {unit}</button>
            ))}
          </div>
        )}

        {foodId && mode === 'add' ? (
          <div className="mt-1">
            {!naming ? (
              <button onClick={() => setNaming(true)} disabled={!valid} className="min-h-9 text-xs text-accent disabled:opacity-40">＋ Save {valid ? `${grams} ${unit}` : 'this amount'} as a serving (for example “1 egg”)</button>
            ) : (
              <div className="flex gap-2">
                <input value={newLabel} onChange={(e) => setNewLabel(e.target.value)} maxLength={60} placeholder={`Name, e.g. 1 egg (= ${grams} ${unit})`} aria-label="Serving name"
                  className="min-h-11 min-w-0 flex-1 rounded-xl bg-surface px-3 text-sm outline-none ring-1 ring-transparent focus:ring-accent" />
                <button onClick={() => void saveServing()} disabled={!newLabel.trim()} className="min-h-11 rounded-xl bg-accent px-4 text-sm font-semibold text-on-accent disabled:opacity-40">Save</button>
                <button onClick={() => { setNaming(false); setNewLabel('') }} aria-label="Cancel" className="min-h-11 px-2 text-muted">✕</button>
              </div>
            )}
            {svError ? <p role="alert" className="mt-1 text-xs text-danger">{svError}</p> : null}
          </div>
        ) : null}

        <div className="mt-3 grid grid-cols-4 gap-2 rounded-2xl bg-surface p-3 text-center" aria-live="polite">
          <div><div className="text-xl font-bold tabular-nums">{Math.round(m.kcal)}</div><div className="text-xs text-muted">kcal</div></div>
          <div><div className="text-lg font-semibold tabular-nums" style={{ color: 'var(--protein)' }}>{m.protein.toFixed(1)}</div><div className="text-xs text-muted">protein g</div></div>
          <div><div className="text-lg font-semibold tabular-nums" style={{ color: 'var(--carbs)' }}>{m.carbs.toFixed(1)}</div><div className="text-xs text-muted">carbs g</div></div>
          <div><div className="text-lg font-semibold tabular-nums" style={{ color: 'var(--fat)' }}>{m.fat.toFixed(1)}</div><div className="text-xs text-muted">fat g</div></div>
        </div>

        <div className="mt-3 flex gap-1 rounded-full bg-surface p-1" role="group" aria-label="Meal">
          {SLOTS.map((s) => (
            <button key={s} aria-pressed={slot === s} onClick={() => setSlot(s)}
              className={`min-h-10 flex-1 rounded-full text-xs ${slot === s ? 'bg-raised font-semibold text-accent' : 'text-muted'}`}>{SLOT_LABEL[s]}</button>
          ))}
        </div>
      </div>

      <div className="flex gap-2 px-4 pb-4 pt-4">
        {onRemove ? <button onClick={onRemove} className="min-h-12 rounded-2xl bg-surface px-4 font-medium text-danger">Remove</button> : null}
        <button disabled={!valid} onClick={() => onSubmit({ grams, slot, serving_label: label })}
          className="min-h-12 flex-1 rounded-2xl bg-accent font-semibold text-on-accent disabled:opacity-40 active:scale-[.98]">
          {mode === 'add' ? `Log ${Math.round(m.kcal)} kcal` : `Save ${Math.round(m.kcal)} kcal`}
        </button>
      </div>
    </Sheet>
  )
}
