import { db, type Supplement, type SuppLog } from './outbox'

/** Editable EXAMPLES, not advice. Doses are what you type; the app never recommends one. */
export const PRESETS: Omit<Supplement, 'id'>[] = [
  { name: 'Creatine monohydrate', dose: 5, unit: 'g' },
  { name: 'Vitamin D3', dose: 1000, unit: 'IU', contrib: { key: 'd', amount: 25 } },
]
export const IU_PER_UG_D = 40

export const listSupps = () => db.supplements.toArray()
export const saveSupp = (s: Supplement) => s.id ? db.supplements.put(s) : db.supplements.add(s)
export const deleteSupp = async (id: number) => { await db.supplements.delete(id); await db.suppLog.where('suppId').equals(id).delete() }
export const logsOn = (date: string) => db.suppLog.where('date').equals(date).toArray()
export const take = (suppId: number, date: string) => db.suppLog.add({ suppId, date, at: Date.now() })
/** Tap once to take, tap again to undo the latest dose. */
export async function toggleTaken(suppId: number, date: string) {
  const mine = (await logsOn(date)).filter(l => l.suppId === suppId).sort((a, b) => b.at - a.at)
  if (mine.length) { await db.suppLog.delete(mine[0].id!); return false }
  await take(suppId, date); return true
}
/** Sum of nutrient contributions for the given logs (pure, tested). */
export const totals = (supps: Supplement[], logs: SuppLog[]) => {
  const out: Record<string, number> = {}
  for (const l of logs) { const c = supps.find(s => s.id === l.suppId)?.contrib; if (c) out[c.key] = (out[c.key] ?? 0) + c.amount }
  return out
}
