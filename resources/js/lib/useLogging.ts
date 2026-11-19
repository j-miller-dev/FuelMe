import { useCallback, useEffect, useRef, useState } from 'react'
import { api, uuid } from '@/lib/http'
import type { Meal } from '@/lib/meal'
import { hitName, macrosFor, type FoodHit, type LogRow, type Slot } from '@/lib/food'

export type NewLog =
  | { kind: 'food'; food: FoodHit; grams: number; slot: Slot; serving_label: string | null }
  | { kind: 'meal'; meal: Meal; portions: number; batch_id: number | null; slot: Slot }
  | { kind: 'quick'; name: string; kcal: number | null; protein: number; carbs: number; fat: number; slot: Slot }

export interface Toast { msg: string; undo?: () => void; error?: boolean }

let tempId = -1

/**
 * Optimistic logging: the row appears instantly, the server confirms (and recomputes every number), and a failure rolls the
 * row back with a message. Every request carries an idempotency key, so a retry can never double-log.
 */
export function useLogging(initial: LogRow[], date: string) {
  const [logs, setLogs] = useState<LogRow[]>(initial)
  const [toast, setToastState] = useState<Toast | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  useEffect(() => setLogs(initial), [initial])

  const setToast = useCallback((t: Toast | null) => {
    clearTimeout(timer.current)
    setToastState(t)
    if (t) timer.current = setTimeout(() => setToastState(null), t.error ? 6000 : 5000)
  }, [])

  const drop = (ids: number[]) => setLogs((l) => l.filter((x) => !ids.includes(x.id)))

  const del = useCallback(async (ids: number[]) => {
    drop(ids)
    await Promise.all(ids.map((id) => api(`/logs/${id}`, { method: 'DELETE' }).catch(() => undefined)))
  }, [])

  const add = useCallback(async (items: NewLog[]): Promise<LogRow[]> => {
    const temps: LogRow[] = items.map((it) => {
      if (it.kind === 'quick') {
        const kcal = it.kcal && it.kcal > 0 ? it.kcal : Math.round(4 * it.protein + 4 * it.carbs + 9 * it.fat)
        return { id: tempId--, local_date: date, meal_slot: it.slot, food_id: null, name: it.name || 'Quick add', grams: null, serving_label: null, kcal, protein: it.protein, carbs: it.carbs, fat: it.fat, fibre: null, source: 'quick' }
      }
      if (it.kind === 'meal') {
        const p = it.meal.computed.portion
        return { id: tempId--, local_date: date, meal_slot: it.slot, food_id: null, name: it.meal.name, grams: p.grams * it.portions, serving_label: `${it.portions} ${it.portions === 1 ? 'portion' : 'portions'}`,
          kcal: p.kcal * it.portions, protein: p.protein * it.portions, carbs: p.carbs * it.portions, fat: p.fat * it.portions, fibre: null, source: 'meal', meal_id: it.meal.id, portions: it.portions }
      }
      const m = macrosFor(it.food.per100, it.grams)
      return { id: tempId--, local_date: date, meal_slot: it.slot, food_id: it.food.id, name: hitName(it.food), grams: it.grams, serving_label: it.serving_label, ...m, fibre: null, source: 'search' }
    })
    setLogs((l) => [...l, ...temps])
    const body = {
      local_date: date,
      items: items.map((it) => it.kind === 'meal'
        ? { idempotency_key: uuid(), meal_slot: it.slot, source: 'meal', meal_id: it.meal.id, portions: it.portions, batch_id: it.batch_id }
        : it.kind === 'quick'
        ? { idempotency_key: uuid(), meal_slot: it.slot, source: 'quick', name: it.name, kcal: it.kcal, protein: it.protein, carbs: it.carbs, fat: it.fat }
        : { idempotency_key: uuid(), meal_slot: it.slot, source: 'search', food_id: it.food.id, grams: it.grams, serving_label: it.serving_label }),
    }
    try {
      const r = await api<{ logs: LogRow[] }>('/logs', { method: 'POST', body })
      setLogs((l) => [...l.filter((x) => !temps.some((t) => t.id === x.id)), ...r.logs])
      const label = r.logs.length === 1 ? (r.logs[0]?.name ?? 'food') : `${r.logs.length} foods`
      setToast({ msg: `Logged ${label}`, undo: () => { void del(r.logs.map((x) => x.id)); setToast(null) } })
      return r.logs
    } catch (e) {
      drop(temps.map((t) => t.id))
      setToast({ msg: e instanceof Error ? e.message : 'Could not log that. Nothing was saved.', error: true })
      return []
    }
  }, [date, del, setToast])

  /** One-tap repeat of an existing entry (same food and weight, or the same raw macros). */
  const repeat = useCallback(async (l: LogRow, slot: Slot = l.meal_slot) => {
    const temp: LogRow = { ...l, id: tempId--, meal_slot: slot, source: 'search' }
    setLogs((x) => [...x, temp])
    const item = l.meal_id && l.portions
      ? { idempotency_key: uuid(), meal_slot: slot, source: 'meal', meal_id: l.meal_id, portions: l.portions }
      : l.food_id && l.grams
      ? { idempotency_key: uuid(), meal_slot: slot, source: 'search', food_id: l.food_id, grams: l.grams, serving_label: l.serving_label }
      : { idempotency_key: uuid(), meal_slot: slot, source: 'quick', name: l.name, kcal: l.kcal, protein: l.protein, carbs: l.carbs, fat: l.fat }
    try {
      const r = await api<{ logs: LogRow[] }>('/logs', { method: 'POST', body: { local_date: date, items: [item] } })
      setLogs((x) => [...x.filter((y) => y.id !== temp.id), ...r.logs])
      setToast({ msg: `Added ${l.name} again`, undo: () => { void del(r.logs.map((y) => y.id)); setToast(null) } })
    } catch (e) {
      drop([temp.id])
      setToast({ msg: e instanceof Error ? e.message : 'Could not repeat that.', error: true })
    }
  }, [date, del, setToast])

  const remove = useCallback(async (l: LogRow) => {
    drop([l.id])
    try {
      await api(`/logs/${l.id}`, { method: 'DELETE' })
      setToast({ msg: `Removed ${l.name}`, undo: () => { setToast(null); void repeat(l) } })
    } catch (e) {
      setLogs((x) => [...x, l])
      setToast({ msg: e instanceof Error ? e.message : 'Could not remove that.', error: true })
    }
  }, [repeat, setToast])

  const update = useCallback(async (l: LogRow, r: { grams: number; slot: Slot; serving_label: string | null }) => {
    const before = l
    const f = l.grams ? r.grams / l.grams : 1
    setLogs((x) => x.map((y) => y.id === l.id ? { ...y, meal_slot: r.slot, grams: l.grams ? r.grams : null, kcal: y.kcal * f, protein: y.protein * f, carbs: y.carbs * f, fat: y.fat * f } : y))
    try {
      const res = await api<{ log: LogRow }>(`/logs/${l.id}`, { method: 'PATCH', body: l.grams ? { grams: r.grams, meal_slot: r.slot, serving_label: r.serving_label } : { meal_slot: r.slot } })
      setLogs((x) => x.map((y) => y.id === l.id ? res.log : y))
    } catch (e) {
      setLogs((x) => x.map((y) => y.id === l.id ? before : y))
      setToast({ msg: e instanceof Error ? e.message : 'Could not save that change.', error: true })
    }
  }, [setToast])

  const copy = useCallback(async (from: string, slot?: Slot) => {
    try {
      const r = await api<{ logs: LogRow[] }>('/logs/copy', { method: 'POST', body: { from_date: from, to_date: date, meal_slot: slot, batch_key: uuid() } })
      setLogs((x) => [...x, ...r.logs])
      setToast({ msg: r.logs.length ? `Copied ${r.logs.length} ${r.logs.length === 1 ? 'entry' : 'entries'}` : 'Nothing to copy', undo: r.logs.length ? () => { void del(r.logs.map((y) => y.id)); setToast(null) } : undefined })
    } catch (e) {
      setToast({ msg: e instanceof Error ? e.message : 'Could not copy.', error: true })
    }
  }, [date, del, setToast])

  return { logs, toast, setToast, add, repeat, remove, update, copy }
}
