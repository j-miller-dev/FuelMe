import Dexie, { type Table } from 'dexie'

/** Offline-first write queue. Every mutation is stored locally with an idempotency key, then flushed to the API. */
export type OutboxItem = { id?: number; key: string; method: 'POST' | 'PUT' | 'DELETE'; url: string; body?: unknown; createdAt: number; attempts: number }

export type Photo = { id?: number; date: string; pose: 'front' | 'side' | 'back'; blob: Blob; createdAt: number }

/** contrib: what ONE dose adds to a nutrient total. key 'd' = vitamin D in µg (40 IU = 1 µg); other keys match lib/nutrients.ts. */
export type Supplement = { id?: number; name: string; dose: number; unit: 'g' | 'mg' | 'µg' | 'IU' | 'caps'; contrib?: { key: string; amount: number } }
export type SuppLog = { id?: number; date: string; suppId: number; at: number }
/** refLow/refHigh are copied from the lab's own report, never invented by the app. */
export type LabResult = { id?: number; date: string; marker: string; value: number; refLow?: number; refHigh?: number }
export type SunLog = { id?: number; date: string; minutes: number; uvi: number; area: number; skin: 'fair' | 'medium' | 'dark'; iuLow: number; iuMid: number; iuHigh: number }

class FuelDB extends Dexie {
  outbox!: Table<OutboxItem, number>
  photos!: Table<Photo, number>
  supplements!: Table<Supplement, number>
  suppLog!: Table<SuppLog, number>
  labs!: Table<LabResult, number>
  sun!: Table<SunLog, number>
  constructor() { super('fuel'); this.version(1).stores({ outbox: '++id, &key, createdAt' }); this.version(2).stores({ outbox: '++id, &key, createdAt', photos: '++id, date, pose' })
    this.version(3).stores({ outbox: '++id, &key, createdAt', photos: '++id, date, pose', supplements: '++id', suppLog: '++id, date, suppId', labs: '++id, date, marker', sun: '++id, date' }) }
}
export const db = new FuelDB()

export async function enqueue(method: OutboxItem['method'], url: string, body?: unknown) {
  const key = crypto.randomUUID()
  await db.outbox.add({ key, method, url, body, createdAt: Date.now(), attempts: 0 })
  void flush()
  return key
}

let flushing = false
export async function flush(fetchImpl: typeof fetch = fetch) {
  if (flushing || (typeof navigator !== "undefined" && navigator.onLine === false)) return
  flushing = true
  try {
    for (const item of await db.outbox.orderBy('createdAt').toArray()) {
      try {
        const res = await fetchImpl(item.url, {
          method: item.method, headers: { 'Content-Type': 'application/json', 'Idempotency-Key': item.key },
          body: item.body ? JSON.stringify(item.body) : undefined,
        })
        if (res.ok || (res.status >= 400 && res.status < 500 && res.status !== 429)) await db.outbox.delete(item.id!) // 4xx = drop (server says it's bad)
        else { await db.outbox.update(item.id!, { attempts: item.attempts + 1 }); break }
      } catch { await db.outbox.update(item.id!, { attempts: item.attempts + 1 }); break }
    }
  } finally { flushing = false }
}
if (typeof window !== 'undefined') window.addEventListener('online', () => void flush())
