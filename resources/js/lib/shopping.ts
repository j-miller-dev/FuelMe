import { useCallback, useEffect, useRef, useState } from 'react'
import { api, uuid } from '@/lib/http'

export type ItemState = 'need' | 'have' | 'bought'
export interface ShopItem {
  id: number; key: string; label: string; qty: number | null; unit: 'g' | 'ml' | 'count' | null; have_qty: number | null; need_qty: number | null
  pack_size: number | null; aisle: string; state: ItemState; recipes: string[]; is_manual: boolean; is_staple: boolean; uuid?: string
}
export interface ShopRecipe { meal_id: number | null; name: string; servings: number; yield: number; missing: boolean; changed: boolean }
export interface ShopList { id: number; name: string; status: string; recipes: ShopRecipe[]; items: ShopItem[]; stale: boolean; aisle_order: string[] }

type Op =
  | { op: 'state'; item_id?: number; uuid?: string; state: ItemState }
  | { op: 'add'; uuid: string; label: string }
  | { op: 'remove'; item_id?: number; uuid?: string }

/** "1.2 kg", "640 g", "×3". Quantities are exact sums; only the display rounds. */
export function fmtQty(q: number | null, unit: ShopItem['unit']): string {
  if (q === null || unit === null) return ''
  if (unit === 'count') return `×${Math.round(q * 100) / 100}`
  if (q >= 1000) return `${Math.round(q / 10) / 100} ${unit === 'g' ? 'kg' : 'L'}`
  return `${Math.round(q)} ${unit}`
}

/** 640 g with 500 g packs = 2 packs (1000 g). Mirrors ShoppingListBuilder::roundToPacks. */
export const packs = (needed: number, size: number): { n: number; total: number } => {
  const n = Math.ceil(needed / size - 1e-9)
  return { n, total: n * size }
}

function applyOp(list: ShopList, op: Op): ShopList {
  const match = (i: ShopItem) => (op.op !== 'add' && ((op.item_id !== undefined && i.id === op.item_id) || (op.uuid !== undefined && i.uuid === op.uuid)))
  if (op.op === 'state') return { ...list, items: list.items.map((i) => (match(i) ? { ...i, state: op.state } : i)) }
  if (op.op === 'remove') return { ...list, items: list.items.filter((i) => !match(i)) }
  if (list.items.some((i) => i.uuid === op.uuid)) return list
  const temp: ShopItem = { id: -Math.abs(Date.now() % 1e9) - list.items.length, key: `manual:${op.uuid}`, label: op.label, qty: null, unit: null, have_qty: null, need_qty: null, pack_size: null,
    aisle: 'Other', state: 'need', recipes: [], is_manual: true, is_staple: false, uuid: op.uuid }
  return { ...list, items: [...list.items, temp] }
}

/**
 * The list works with no signal: every tick/add/remove applies instantly and is queued (kept in localStorage so it survives a
 * closed tab). The queue is replayed in order when the connection is back. Every op is idempotent on the server, so a replay after
 * a dropped request never duplicates or undoes anything.
 */
export function useShoppingList(initial: ShopList) {
  const key = `fuel.shop.queue.${initial.id}`
  const [list, setList] = useState<ShopList>(initial)
  const [pending, setPending] = useState(0)
  const [error, setError] = useState<string | null>(null)
  const queue = useRef<Op[]>([])
  const flushing = useRef(false)

  const save = () => { try { localStorage.setItem(key, JSON.stringify(queue.current)) } catch { /* private mode: the queue still works in memory */ } setPending(queue.current.length) }

  const flush = useCallback(async () => {
    if (flushing.current || queue.current.length === 0) return
    flushing.current = true
    const sent = queue.current.slice()
    try {
      const r = await api<{ list: ShopList }>(`/shopping/${initial.id}/sync`, { method: 'POST', body: { ops: sent } })
      queue.current = queue.current.slice(sent.length)
      // Ops made while the request was in flight are re-applied on top of the server's answer.
      setList(queue.current.reduce(applyOp, r.list))
      setError(null)
    } catch (e) {
      // Offline or a server hiccup: keep the queue, try again later. A 4xx would repeat forever, so surface it.
      if (e instanceof Error && !/Failed to fetch|NetworkError|Load failed|\(5\d\d\)/.test(e.message) && navigator.onLine) setError(e.message)
    } finally { flushing.current = false; save() }
  }, [initial.id]) // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    try { queue.current = JSON.parse(localStorage.getItem(key) ?? '[]') as Op[] } catch { queue.current = [] }
    if (queue.current.length) setList((l) => queue.current.reduce(applyOp, l))
    setPending(queue.current.length)
    void flush()
    const on = () => void flush()
    window.addEventListener('online', on)
    return () => window.removeEventListener('online', on)
  }, [key, flush])

  const run = useCallback((op: Op) => {
    setList((l) => applyOp(l, op))
    queue.current.push(op); save()
    void flush()
    navigator.vibrate?.(6)
  }, [flush]) // eslint-disable-line react-hooks/exhaustive-deps

  const idOf = (i: ShopItem) => (i.uuid ? { uuid: i.uuid } : { item_id: i.id })
  return {
    list, setList, pending, error,
    setState: (i: ShopItem, state: ItemState) => run({ op: 'state', ...idOf(i), state }),
    add: (label: string) => run({ op: 'add', uuid: uuid(), label }),
    remove: (i: ShopItem) => run({ op: 'remove', ...idOf(i) }),
  }
}

export function listAsText(list: ShopList): string {
  const need = list.items.filter((i) => i.state === 'need')
  const lines: string[] = [list.name]
  for (const a of list.aisle_order) {
    const rows = need.filter((i) => i.aisle === a)
    if (!rows.length) continue
    lines.push('', a.toUpperCase(), ...rows.map((i) => `- ${i.label}${i.need_qty !== null ? ` ${fmtQty(i.need_qty, i.unit)}` : ''}`))
  }
  return lines.join('\n')
}
