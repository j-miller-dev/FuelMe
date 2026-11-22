import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { uuid } from '@/lib/http'
import { outbox, useOutbox, type Entry } from '@/lib/outbox'
import { hitName, macrosFor, type FoodHit, type LogRow, type Slot } from '@/lib/food'
import type { Meal } from '@/lib/meal'

export type NewLog =
  | { kind: 'food'; food: FoodHit; grams: number; slot: Slot; serving_label: string | null }
  | { kind: 'meal'; meal: Meal; portions: number; batch_id: number | null; slot: Slot }
  | { kind: 'quick'; name: string; kcal: number | null; protein: number; carbs: number; fat: number; slot: Slot }

export interface Toast { msg: string; undo?: () => void; error?: boolean }

// Temporary rows for entries still waiting to be saved get big negative ids, unique across sessions (the queue outlives the page).
let tempSeq = 0
const tempId = () => -(Date.now() % 1_000_000_000) * 100 - (tempSeq++ % 100) - 1

interface AddPreview { rows: LogRow[] }
interface UpdatePreview { id: number; grams: number | null; slot: Slot }

/** Scale a row's macros when its weight changes (the server does the same to the stored snapshot). */
const scaleRow = (r: LogRow, grams: number | null, slot: Slot): LogRow => {
  if (!r.grams || grams === null) return { ...r, meal_slot: slot }
  const f = grams / r.grams
  return { ...r, meal_slot: slot, grams, kcal: r.kcal * f, protein: r.protein * f, carbs: r.carbs * f, fat: r.fat * f }
}

/** What the screen shows: the server's rows, with every change still waiting in the outbox laid on top. */
function withPending(base: LogRow[], entries: Entry[], date: string): LogRow[] {
  let rows = base.slice()
  for (const e of entries) {
    if (e.state !== 'pending') continue
    if (e.kind === 'logs.add' && e.date === date) rows = [...rows, ...(e.preview as AddPreview).rows.map((r) => ({ ...r, pending: true }))]
    else if (e.kind === 'logs.delete') rows = rows.filter((r) => r.id !== (e.preview as { id: number }).id)
    else if (e.kind === 'logs.update') { const p = e.preview as UpdatePreview; rows = rows.map((r) => (r.id === p.id ? { ...scaleRow(r, p.grams, p.slot), pending: true } : r)) }
  }
  return rows
}

const OFFLINE_MSG = 'Saved on this phone. It will sync when you are back online.'

/**
 * Logging that works with no signal. Every change goes through the outbox: saved on the phone first, sent right away if there is a
 * connection, otherwise sent later in order. The rows you see are the server's rows plus whatever is still waiting, so an offline log
 * appears at once, survives closing the app, and is replaced by the real row when it syncs. Every request carries an idempotency key.
 */
export function useLogging(initial: LogRow[], date: string) {
  const { entries } = useOutbox()
  const [confirmed, setConfirmed] = useState<LogRow[]>([])        // rows the server created since the page props were loaded
  const [gone, setGone] = useState<number[]>([])                  // ids the server confirmed deleted
  const [patched, setPatched] = useState<Record<number, LogRow>>({})
  const [toast, setToastState] = useState<Toast | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  useEffect(() => { setConfirmed([]); setGone([]); setPatched({}) }, [initial])   // fresh server data replaces local confirmations

  const logs = useMemo(() => {
    const base = [...initial, ...confirmed].filter((r) => !gone.includes(r.id)).map((r) => patched[r.id] ?? r)
    return withPending(base, entries, date)
  }, [initial, confirmed, gone, patched, entries, date])

  const setToast = useCallback((t: Toast | null) => {
    clearTimeout(timer.current)
    setToastState(t)
    if (t) timer.current = setTimeout(() => setToastState(null), t.error ? 6000 : 5000)
  }, [])

  /** Send one change. Resolves with the server's JSON when it went out now, null when it is saved and waiting; throws a plain message when refused. */
  const submit = useCallback(async (e: Parameters<typeof outbox.enqueue>[0]): Promise<{ id: string; data: unknown } | { id: string; data: null; queued: true }> => {
    const { id, done } = await outbox.enqueue(e)
    const r = await done
    if (r.status === 'failed') { await outbox.discard(id); throw new Error(r.message) }   // refused while you were watching: roll back and say why
    if (r.status === 'queued') return { id, data: null, queued: true }
    return { id, data: r.data }
  }, [])

  const del = useCallback(async (ids: number[]) => {
    for (const id of ids) {
      if (id < 0) { const e = entries.find((x) => x.kind === 'logs.add' && (x.preview as AddPreview).rows.some((r) => r.id === id)); if (e) await outbox.discard(e.id); continue }
      const r = await submit({ method: 'DELETE', url: `/logs/${id}`, kind: 'logs.delete', preview: { id } }).catch(() => null)
      if (r && !('queued' in r)) setGone((g) => [...g, id])
    }
  }, [entries, submit])

  const send = useCallback(async (temps: LogRow[], items: Record<string, unknown>[], doneMsg: (rows: LogRow[]) => string) => {
    try {
      const r = await submit({ method: 'POST', url: '/logs', kind: 'logs.add', date, body: { local_date: date, items }, preview: { rows: temps } })
      if ('queued' in r) {
        setToast({ msg: OFFLINE_MSG, undo: () => { void outbox.discard(r.id); setToast(null) } })
        return []
      }
      const rows = (r.data as { logs: LogRow[] }).logs
      setConfirmed((c) => [...c, ...rows])
      setToast({ msg: doneMsg(rows), undo: () => { void del(rows.map((x) => x.id)); setToast(null) } })
      return rows
    } catch (e) {
      setToast({ msg: e instanceof Error ? e.message : 'Could not log that. Nothing was saved.', error: true })
      return []
    }
  }, [date, del, setToast, submit])

  const add = useCallback(async (items: NewLog[]): Promise<LogRow[]> => {
    const temps: LogRow[] = items.map((it) => {
      if (it.kind === 'quick') {
        const kcal = it.kcal && it.kcal > 0 ? it.kcal : Math.round(4 * it.protein + 4 * it.carbs + 9 * it.fat)
        return { id: tempId(), local_date: date, meal_slot: it.slot, food_id: null, name: it.name || 'Quick add', grams: null, serving_label: null, kcal, protein: it.protein, carbs: it.carbs, fat: it.fat, fibre: null, source: 'quick' }
      }
      if (it.kind === 'meal') {
        const p = it.meal.computed.portion
        return { id: tempId(), local_date: date, meal_slot: it.slot, food_id: null, name: it.meal.name, grams: p.grams * it.portions, serving_label: `${it.portions} ${it.portions === 1 ? 'portion' : 'portions'}`,
          kcal: p.kcal * it.portions, protein: p.protein * it.portions, carbs: p.carbs * it.portions, fat: p.fat * it.portions, fibre: null, source: 'meal', meal_id: it.meal.id, portions: it.portions }
      }
      const m = macrosFor(it.food.per100, it.grams)
      return { id: tempId(), local_date: date, meal_slot: it.slot, food_id: it.food.id, name: hitName(it.food), grams: it.grams, serving_label: it.serving_label, ...m, fibre: null, source: 'search' }
    })
    const body = items.map((it) => it.kind === 'meal'
      ? { idempotency_key: uuid(), meal_slot: it.slot, source: 'meal', meal_id: it.meal.id, portions: it.portions, batch_id: it.batch_id }
      : it.kind === 'quick'
      ? { idempotency_key: uuid(), meal_slot: it.slot, source: 'quick', name: it.name, kcal: it.kcal, protein: it.protein, carbs: it.carbs, fat: it.fat }
      : { idempotency_key: uuid(), meal_slot: it.slot, source: 'search', food_id: it.food.id, grams: it.grams, serving_label: it.serving_label })
    return send(temps, body, (rows) => `Logged ${rows.length === 1 ? (rows[0]?.name ?? 'food') : `${rows.length} foods`}`)
  }, [date, send])

  /** One-tap repeat of an existing entry (same food and weight, or the same raw macros). */
  const repeat = useCallback(async (l: LogRow, slot: Slot = l.meal_slot) => {
    const temp: LogRow = { ...l, id: tempId(), meal_slot: slot, source: 'search' }
    const item = l.meal_id && l.portions
      ? { idempotency_key: uuid(), meal_slot: slot, source: 'meal', meal_id: l.meal_id, portions: l.portions }
      : l.food_id && l.grams
      ? { idempotency_key: uuid(), meal_slot: slot, source: 'search', food_id: l.food_id, grams: l.grams, serving_label: l.serving_label }
      : { idempotency_key: uuid(), meal_slot: slot, source: 'quick', name: l.name, kcal: l.kcal, protein: l.protein, carbs: l.carbs, fat: l.fat }
    await send([temp], [item], () => `Added ${l.name} again`)
  }, [send])

  const remove = useCallback(async (l: LogRow) => {
    if (l.id < 0) { await del([l.id]); setToast({ msg: `Cancelled ${l.name}` }); return }
    try {
      const r = await submit({ method: 'DELETE', url: `/logs/${l.id}`, kind: 'logs.delete', preview: { id: l.id } })
      if (!('queued' in r)) setGone((g) => [...g, l.id])
      setToast({ msg: 'queued' in r ? `Removal saved. ${OFFLINE_MSG}` : `Removed ${l.name}`, undo: 'queued' in r ? () => { void outbox.discard(r.id); setToast(null) } : () => { setToast(null); void repeat(l) } })
    } catch (e) {
      setToast({ msg: e instanceof Error ? e.message : 'Could not remove that.', error: true })
    }
  }, [del, repeat, setToast, submit])

  const update = useCallback(async (l: LogRow, r: { grams: number; slot: Slot; serving_label: string | null }) => {
    try {
      const res = await submit({ method: 'PATCH', url: `/logs/${l.id}`, kind: 'logs.update', preview: { id: l.id, grams: l.grams ? r.grams : null, slot: r.slot } as UpdatePreview,
        body: l.grams ? { grams: r.grams, meal_slot: r.slot, serving_label: r.serving_label } : { meal_slot: r.slot } })
      if (!('queued' in res)) setPatched((p) => ({ ...p, [l.id]: (res.data as { log: LogRow }).log }))
      else setToast({ msg: OFFLINE_MSG })
    } catch (e) {
      setToast({ msg: e instanceof Error ? e.message : 'Could not save that change.', error: true })
    }
  }, [setToast, submit])

  const copy = useCallback(async (from: string, slot?: Slot) => {
    try {
      const r = await submit({ method: 'POST', url: '/logs/copy', kind: 'logs.copy', date, body: { from_date: from, to_date: date, meal_slot: slot, batch_key: uuid() } })
      if ('queued' in r) { setToast({ msg: `Copy saved. ${OFFLINE_MSG}`, undo: () => { void outbox.discard(r.id); setToast(null) } }); return }
      const rows = (r.data as { logs: LogRow[] }).logs
      setConfirmed((c) => [...c, ...rows])
      setToast({ msg: rows.length ? `Copied ${rows.length} ${rows.length === 1 ? 'entry' : 'entries'}` : 'Nothing to copy', undo: rows.length ? () => { void del(rows.map((y) => y.id)); setToast(null) } : undefined })
    } catch (e) {
      setToast({ msg: e instanceof Error ? e.message : 'Could not copy.', error: true })
    }
  }, [date, del, setToast, submit])

  return { logs, toast, setToast, add, repeat, remove, update, copy }
}
