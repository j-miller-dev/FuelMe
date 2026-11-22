import assert from 'node:assert/strict'
import { test } from 'node:test'
import { MemoryStore, Outbox, type Entry } from '../../resources/js/lib/outboxCore.ts'

/** A fake server with the same idempotency rule the real one has: a request key seen before returns the first result and does nothing twice. */
function server() {
  const seen = new Map<string, unknown>()
  const logs: string[] = []
  let up = true
  let dropReply = 0   // lose the reply to the next N requests AFTER the server handled them
  const calls: string[] = []
  const send = async (e: Entry) => {
    calls.push(`${e.method} ${e.url}`)
    if (!up) throw new TypeError('Failed to fetch')
    const body = e.body as { key?: string; status?: number } | undefined
    const key = body?.key
    let out: { status: number; json: unknown }
    if (body?.status) out = { status: body.status, json: { message: 'No.', errors: body.status === 422 ? { x: ['That is not valid.'] } : undefined } }
    else if (key && seen.has(key)) out = { status: 201, json: seen.get(key) }
    else { if (key) { seen.set(key, { logs: [key] }); logs.push(key) } out = { status: 201, json: { logs: [key] } } }
    if (dropReply > 0 && !body?.status) { dropReply--; throw new TypeError('Failed to fetch') }
    return out
  }
  return { send, logs, calls, set up(v: boolean) { up = v }, set dropReply(n: number) { dropReply = n } }
}

function make(online = () => true) {
  const s = server()
  const store = new MemoryStore()
  let t = 1000
  const ob = new Outbox({ store, send: s.send, isOnline: online, now: () => t++, newId: (() => { let n = 0; return () => `e${++n}` })() })
  return { s, store, ob }
}

const add = (key: string, extra: Record<string, unknown> = {}) => ({ method: 'POST' as const, url: '/logs', kind: 'logs.add', body: { key, ...extra } })

test('online: a change is saved, sent once and removed from the queue', async () => {
  const { s, ob, store } = make()
  const r = await ob.send(add('a'))

  assert.equal(r.status, 'sent')
  assert.deepEqual(s.logs, ['a'])
  assert.equal((await store.all()).length, 0)
  assert.equal(ob.entries().length, 0)
})

test('airplane mode: changes are kept in order and all go out when the network returns, with no duplicates', async () => {
  const { s, ob, store } = make()
  s.up = false
  const r1 = await ob.send(add('a'))
  const r2 = await ob.send(add('b'))
  const r3 = await ob.send(add('c'))

  assert.deepEqual([r1.status, r2.status, r3.status], ['queued', 'queued', 'queued'])
  assert.equal((await store.all()).length, 3)   // durable
  assert.deepEqual(s.logs, [])

  s.up = true
  await ob.flush()
  await ob.flush()   // a second flush (the "online" event firing twice) must change nothing

  assert.deepEqual(s.logs, ['a', 'b', 'c'])
  assert.equal(ob.entries().length, 0)
  assert.equal((await store.all()).length, 0)
})

test('a lost reply is replayed safely: the server saw it once, the app sends it again, nothing is doubled', async () => {
  const { s, ob } = make()
  s.dropReply = 1
  const r = await ob.send(add('a'))

  assert.equal(r.status, 'queued')           // the app does not know it arrived
  assert.deepEqual(s.logs, ['a'])             // but it did
  await ob.flush()                            // replay
  assert.deepEqual(s.logs, ['a'])             // still exactly one
  assert.equal(ob.entries().length, 0)
})

test('a crash while offline loses nothing: a new Outbox on the same storage picks the queue up', async () => {
  const { s, ob, store } = make()
  s.up = false
  await ob.send(add('a'))
  await ob.send(add('b'))

  const reopened = new Outbox({ store, send: s.send })   // app restarted
  await reopened.load()
  assert.equal(reopened.pending().length, 2)
  s.up = true
  await reopened.flush()
  assert.deepEqual(s.logs, ['a', 'b'])
})

test('order is kept: a waiting entry blocks later ones even when they could go through', async () => {
  const { s, ob } = make()
  s.up = false
  await ob.send(add('a'))
  s.up = true
  const r = await ob.send(add('b'))   // flush sends a then b, in that order

  assert.equal(r.status, 'sent')
  assert.deepEqual(s.logs, ['a', 'b'])
})

test('navigator says offline: nothing is attempted, everything waits', async () => {
  let online = false
  const { s, ob } = make(() => online)
  const r = await ob.send(add('a'))

  assert.equal(r.status, 'queued')
  assert.equal(r.status === 'queued' && r.reason, 'You are offline.')
  assert.deepEqual(s.calls, [])
  online = true
  await ob.flush()
  assert.deepEqual(s.logs, ['a'])
})

test('a refusal (422) is a permanent failure that does not block the rest, and can be retried or discarded', async () => {
  const { s, ob } = make()
  const bad = await ob.send(add('bad', { status: 422 }))
  const good = await ob.send(add('good'))

  assert.deepEqual([bad.status, good.status], ['failed', 'sent'])
  assert.equal(bad.status === 'failed' && bad.message, 'That is not valid.')
  assert.equal(ob.failed().length, 1)
  assert.deepEqual(s.logs, ['good'])

  await ob.discard(ob.failed()[0]!.id)
  assert.equal(ob.entries().length, 0)
})

test('a server error or an expired session keeps the change and stops, so nothing after it jumps the queue', async () => {
  for (const status of [500, 503, 429, 419, 401]) {
    const { s, ob } = make()
    const a = await ob.send(add('a', { status }))
    const b = await ob.send(add('b'))

    assert.equal(a.status, 'queued')
    assert.equal(b.status, 'queued')   // behind a
    assert.equal(ob.pending().length, 2)
    assert.deepEqual(s.logs, [])
  }
})

test('deleting or editing something that is already gone counts as done', async () => {
  const { ob } = make()
  const r = await ob.send({ method: 'DELETE', url: '/logs/9', kind: 'logs.delete', body: { status: 404 } })

  assert.deepEqual(r, { status: 'sent', data: null, gone: true })
  assert.equal(ob.entries().length, 0)
  // but a 404 on a CREATE is a real problem
  const c = await ob.send(add('x', { status: 404 }))
  assert.equal(c.status, 'failed')
})

test('undo while offline removes the waiting entry; update edits it', async () => {
  const { s, ob } = make()
  s.up = false
  await ob.send(add('a'))
  await ob.send(add('b'))
  const [a, b] = ob.pending()

  await ob.discard(a!.id)
  await ob.update(b!.id, { label: 'edited' })
  s.up = true
  await ob.flush()

  assert.deepEqual(s.logs, ['b'])
})

test('two flushes at once send each entry once', async () => {
  const { s, ob } = make()
  s.up = false
  await ob.send(add('a'))
  s.up = true
  await Promise.all([ob.flush(), ob.flush(), ob.flush()])

  assert.deepEqual(s.logs, ['a'])
})

test('a failed entry can be retried and goes out', async () => {
  const { s, ob } = make()
  const e = await ob.send(add('k', { status: 422 }))
  assert.equal(e.status, 'failed')
  const id = ob.failed()[0]!.id
  // the user fixes the cause; here the "cause" is simply the body, so patch it
  await ob.update(id, { body: { key: 'k' } })
  await ob.retry(id)

  assert.deepEqual(s.logs, ['k'])
  assert.equal(ob.entries().length, 0)
})
