import Dexie, { type Table } from 'dexie'

/** Offline-first write queue. Every mutation is stored locally with an idempotency key, then flushed to the API. */
export type OutboxItem = { id?: number; key: string; method: 'POST' | 'PUT' | 'DELETE'; url: string; body?: unknown; createdAt: number; attempts: number }

export type Photo = { id?: number; date: string; pose: 'front' | 'side' | 'back'; blob: Blob; createdAt: number }

class FuelDB extends Dexie {
  outbox!: Table<OutboxItem, number>
  photos!: Table<Photo, number>
  constructor() { super('fuel'); this.version(1).stores({ outbox: '++id, &key, createdAt' }); this.version(2).stores({ outbox: '++id, &key, createdAt', photos: '++id, date, pose' }) }
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
