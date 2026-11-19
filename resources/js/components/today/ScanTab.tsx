import { useCallback, useEffect, useState } from 'react'
import { api } from '@/lib/http'
import { cleanCode, gtinValid } from '@/lib/gtin'
import { defaultGrams, hitName, macrosFor, unitOf, type FoodHit, type Slot } from '@/lib/food'
import type { NewLog } from '@/lib/useLogging'
import { BarcodeScanner } from '@/components/foods/BarcodeScanner'
import type { FoodPrefill } from '@/components/foods/FoodForm'

type Result =
  | { status: 'found'; code: string; food: FoodHit }
  | { status: 'not_found' | 'incomplete'; code: string; prefill?: FoodPrefill }
  | { status: 'invalid' | 'offline'; code: string }

/** Barcode tab: camera (or typed code) -> local database -> live Open Food Facts -> confirm / create. "Scan several" logs each at its default portion. */
export function ScanTab({ slot, injected, onAdd, onPick, onCreate }: {
  slot: Slot; injected: FoodHit | null
  onAdd: (items: NewLog[]) => Promise<unknown>; onPick: (f: FoodHit) => void; onCreate: (prefill: FoodPrefill) => void
}) {
  const [result, setResult] = useState<Result | null>(null)
  const [looking, setLooking] = useState(false)
  const [several, setSeveral] = useState(false)
  const [session, setSession] = useState<string[]>([])
  const [typed, setTyped] = useState('')

  // A food just created from this tab's "not found" flow: show it like a normal hit.
  useEffect(() => { if (injected) setResult({ status: 'found', code: injected.barcode ?? '', food: injected }) }, [injected])

  const add = useCallback((f: FoodHit) => {
    void onAdd([{ kind: 'food', food: f, grams: defaultGrams(f), slot, serving_label: f.portion.grams ? f.portion.label : null }])
  }, [onAdd, slot])

  const lookup = useCallback(async (raw: string) => {
    setLooking(true)
    setResult(null)
    try {
      const r = await api<{ status: Result['status']; code: string; food?: FoodHit; prefill?: FoodPrefill }>(`/foods/barcode?code=${encodeURIComponent(raw)}`)
      if (r.status === 'found' && r.food) {
        if (several) { add(r.food); setSession((s) => [hitName(r.food!), ...s].slice(0, 6)) }
        else setResult({ status: 'found', code: r.code, food: r.food })
      } else setResult({ status: r.status, code: r.code, prefill: r.prefill } as Result)
    } catch {
      setResult({ status: 'offline', code: raw })
    } finally { setLooking(false) }
  }, [add, several])

  const typedCode = cleanCode(typed)
  const f = result?.status === 'found' ? result.food : null

  return (
    <div className="space-y-3">
      <BarcodeScanner onCode={(c) => void lookup(c)} paused={looking || (result !== null && !several)} />

      <label className="flex min-h-11 items-center gap-2 text-sm"><input type="checkbox" checked={several} onChange={(e) => setSeveral(e.target.checked)} className="size-5 accent-[var(--accent)]" />
        Scan several (logs each at its usual amount)</label>
      {several && session.length > 0 ? <div className="flex flex-wrap gap-1" aria-live="polite">{session.map((n, i) => <span key={i} className="rounded-full bg-accent/15 px-2 py-1 text-xs text-accent">✓ {n}</span>)}</div> : null}

      <form className="flex gap-2" onSubmit={(e) => { e.preventDefault(); if (typedCode) void lookup(typedCode); setTyped('') }}>
        <input aria-label="Type a barcode" inputMode="numeric" autoComplete="off" data-1p-ignore data-lpignore="true" placeholder="Or type the barcode" value={typed} onChange={(e) => setTyped(e.target.value)}
          className="min-h-12 min-w-0 flex-1 rounded-xl bg-surface px-4 outline-none ring-accent focus:ring-2" />
        <button disabled={!typedCode || looking} className="min-h-12 rounded-xl bg-raised px-4 disabled:opacity-40">Look up</button>
      </form>
      {typedCode.length >= 8 && !gtinValid(typedCode) ? <p className="text-xs text-muted">That does not look like a valid barcode yet (check the digits).</p> : null}

      {looking ? <p role="status" className="text-sm text-muted">Looking up…</p> : null}

      {f ? (
        <div className="rounded-2xl bg-surface p-4" role="status">
          <div className="font-medium">{hitName(f)}{f.needs_check ? <span className="ml-1 rounded bg-carbs/20 px-1 text-[10px] text-carbs">check</span> : null}</div>
          <div className="text-xs text-muted tabular-nums">{(() => { const g = defaultGrams(f); const m = macrosFor(f.per100, g); return `${Math.round(m.kcal)} kcal · ${Math.round(m.protein)}P ${Math.round(m.carbs)}C ${Math.round(m.fat)}F · ${g} ${unitOf(f)}` })()}</div>
          {f.needs_check ? <p className="mt-1 text-xs text-carbs">Open Food Facts data is entered by volunteers and the numbers look inconsistent. Compare with the pack.</p> : null}
          <div className="mt-3 flex gap-2">
            <button onClick={() => { add(f); setResult(null) }} className="min-h-12 flex-1 rounded-2xl bg-accent font-semibold text-on-accent">Log it</button>
            <button onClick={() => onPick(f)} className="min-h-12 rounded-2xl bg-raised px-4">Amount…</button>
            <button onClick={() => setResult(null)} aria-label="Scan another" className="size-12 rounded-2xl bg-raised">✕</button>
          </div>
        </div>
      ) : null}

      {result && (result.status === 'not_found' || result.status === 'incomplete') ? (
        <div className="rounded-2xl bg-surface p-4" role="status">
          <div className="font-medium">{result.status === 'incomplete' ? 'Found, but the nutrition is missing' : 'Not in the database yet'}</div>
          <p className="mt-1 text-sm text-muted">Barcode {result.code}. Add it from the nutrition panel and it will scan next time.</p>
          <div className="mt-3 flex gap-2">
            <button onClick={() => onCreate({ barcode: result.code, ...(result.prefill ?? {}) })} className="min-h-12 flex-1 rounded-2xl bg-accent font-semibold text-on-accent">Add it</button>
            <button onClick={() => setResult(null)} className="min-h-12 rounded-2xl bg-raised px-4">Scan again</button>
          </div>
        </div>
      ) : null}

      {result?.status === 'invalid' ? (
        <div role="alert" className="rounded-2xl bg-surface p-4 text-sm">Could not read that code properly. Hold steady and try again.
          <button onClick={() => setResult(null)} className="ml-2 min-h-10 font-semibold text-accent">OK</button></div>
      ) : null}

      {result?.status === 'offline' ? (
        <div role="alert" className="rounded-2xl bg-surface p-4 text-sm">
          Could not reach Open Food Facts. Check your connection and try again, or add the product yourself.
          <div className="mt-3 flex gap-2">
            <button onClick={() => void lookup(result.code)} className="min-h-11 flex-1 rounded-xl bg-raised">Retry</button>
            <button onClick={() => onCreate({ barcode: result.code })} className="min-h-11 flex-1 rounded-xl bg-raised">Add it myself</button>
          </div>
        </div>
      ) : null}
    </div>
  )
}
