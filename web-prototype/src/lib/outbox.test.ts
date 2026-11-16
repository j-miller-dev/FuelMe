// Run with: npx tsx src/lib/outbox.test.ts  (uses fake-indexeddb)
import 'fake-indexeddb/auto'
import { db, enqueue, flush } from './outbox'
const assert = (c: boolean, m: string) => { if (!c) { console.error('FAIL', m); process.exit(1) } console.log('ok  ', m) }

const calls: { url: string; key: string }[] = []
let mode: 'down' | 'up' = 'down'
const fake = (async (url: string, init: RequestInit) => {
  if (mode === 'down') throw new Error('offline')
  calls.push({ url, key: (init.headers as Record<string, string>)['Idempotency-Key'] })
  return new Response('{}', { status: 200 })
}) as unknown as typeof fetch

await db.outbox.clear()
await db.outbox.add({ key: 'k1', method: 'POST', url: '/api/food-logs', body: { a: 1 }, createdAt: 1, attempts: 0 })
await db.outbox.add({ key: 'k2', method: 'POST', url: '/api/weight-logs', body: { kg: 74 }, createdAt: 2, attempts: 0 })
await flush(fake)
assert((await db.outbox.count()) === 2, 'items retained while offline')
assert((await db.outbox.toArray())[0].attempts === 1, 'attempt counted')
mode = 'up'; await flush(fake)
assert((await db.outbox.count()) === 0, 'queue drained when online')
assert(calls[0].key === 'k1' && calls[1].key === 'k2', 'FIFO order with idempotency keys')
void enqueue
