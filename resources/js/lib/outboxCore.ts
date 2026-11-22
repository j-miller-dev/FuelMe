/**
 * The offline outbox (BLUEPRINT M9): every change is written to durable storage FIRST, then sent. If the network is down (airplane mode,
 * a tunnel, a supermarket) the entry simply stays and goes out, in order, when the connection returns. Nothing here assumes the network:
 * dependencies are injected so the rules are tested in plain Node (tests/js/outbox.test.ts).
 *
 * Safe to replay: the server treats every queued write as idempotent (per-item idempotency keys, set-state PATCHes, upserts by date), so a
 * request that actually reached the server but whose reply was lost can be sent again without doing anything twice.
 */

export interface Entry {
  id: string
  seq: number
  method: 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  url: string
  body?: unknown
  /** what the entry is, so the UI can show it before it is saved: 'logs.add', 'logs.delete', 'logs.update', 'logs.copy', 'raw' */
  kind: string
  date?: string
  /** whatever the UI needs to draw the change while it is waiting (e.g. the optimistic log rows) */
  preview?: unknown
  label?: string
  createdAt: number
  attempts: number
  state: 'pending' | 'failed'
  error?: string
}

export type NewEntry = Pick<Entry, 'method' | 'url' | 'kind'> & Partial<Pick<Entry, 'body' | 'date' | 'preview' | 'label'>>

export type Result = { status: 'sent'; data: unknown; gone?: boolean } | { status: 'queued'; reason?: string } | { status: 'failed'; message: string }

export interface Store {
  all(): Promise<Entry[]>
  put(e: Entry): Promise<void>
  del(id: string): Promise<void>
}

export interface Deps {
  store: Store
  /** like fetch, but resolves with the status and parsed body so tests need no Response */
  send(e: Entry): Promise<{ status: number; json: unknown }>
  isOnline?: () => boolean
  now?: () => number
  onChange?: () => void
  /** an entry reached the server (not called for discards) */
  onSent?: (e: Entry, r: Result) => void
  /** run fn while holding a cross-tab lock (navigator.locks); default: just run it */
  lock?: <T>(fn: () => Promise<T>) => Promise<T>
  newId?: () => string
}

const RETRY_STATUS = (s: number) => s === 408 || s === 429 || s >= 500 || s === 401 || s === 419
export const isNetworkError = (e: unknown) => e instanceof TypeError || (e instanceof Error && /network|fetch|failed to fetch|load failed/i.test(e.message))

/** First human message in a Laravel JSON error body. */
export function messageOf(json: unknown, status: number): string {
  const b = (json ?? {}) as { message?: string; errors?: Record<string, string[]> }
  return (b.errors ? Object.values(b.errors)[0]?.[0] : undefined) ?? b.message ?? `The server said no (${status}).`
}

export class Outbox {
  private list: Entry[] = []
  private flushing: Promise<void> | null = null
  private results = new Map<string, Result>()
  private counter = 0
  private loaded = false

  private d: Deps
  constructor(d: Deps) { this.d = d }

  private now = () => (this.d.now ?? Date.now)()
  private online = () => (this.d.isOnline ?? (() => true))()
  private changed = () => this.d.onChange?.()

  async load(): Promise<void> {
    this.list = (await this.d.store.all()).sort((a, b) => a.seq - b.seq)
    this.counter = this.list.reduce((m, e) => Math.max(m, e.seq % 1000), 0)
    this.loaded = true
    this.changed()
  }

  /** A snapshot for the UI, oldest first. */
  entries(): Entry[] { return this.list.slice() }
  pending(): Entry[] { return this.list.filter((e) => e.state === 'pending') }
  failed(): Entry[] { return this.list.filter((e) => e.state === 'failed') }

  /**
   * Save the change, then try to send it. Resolves with the server's answer when it went out (even if it had to wait behind older
   * entries), with {status:'queued'} when it is saved and waiting for the network, or {status:'failed'} when the server refused it.
   */
  async send(n: NewEntry): Promise<Result> {
    return (await this.enqueue(n)).done
  }

  /** Like send, but returns the entry id at once (so the UI can cancel it) and the outcome as a promise. */
  async enqueue(n: NewEntry): Promise<{ id: string; done: Promise<Result> }> {
    if (!this.loaded) await this.load()
    const e: Entry = { ...n, id: this.d.newId?.() ?? `${this.now()}-${Math.random().toString(36).slice(2)}`, seq: this.now() * 1000 + (this.counter++ % 1000), createdAt: this.now(), attempts: 0, state: 'pending' }
    this.list.push(e)
    await this.d.store.put(e)   // durable BEFORE the request: a crash or a lost reply can never lose the change
    this.changed()
    const done = this.flush().then((): Result => this.results.get(e.id) ?? { status: 'queued', reason: this.online() ? 'The server could not be reached.' : 'You are offline.' })

    return { id: e.id, done }
  }

  /** Send everything waiting, oldest first. Stops at the first entry that must wait for the network, so order is always kept. */
  flush(): Promise<void> {
    if (this.flushing) return this.flushing
    this.flushing = (this.d.lock ?? ((fn) => fn()))(() => this.run()).finally(() => { this.flushing = null })
    return this.flushing
  }

  private async run(): Promise<void> {
    if (!this.loaded) await this.load()
    else this.list = (await this.d.store.all()).sort((a, b) => a.seq - b.seq)   // another tab may have sent some already
    for (const e of this.list.filter((x) => x.state === 'pending')) {
      if (!this.online()) return
      let res: { status: number; json: unknown }
      try {
        res = await this.d.send(e)
      } catch {
        e.attempts++
        await this.d.store.put(e)
        return   // no network: keep this and everything after it
      }
      e.attempts++
      if (res.status >= 200 && res.status < 300) {
        await this.finish(e, { status: 'sent', data: res.json })
      } else if (res.status === 404 && (e.method === 'DELETE' || e.method === 'PATCH')) {
        await this.finish(e, { status: 'sent', data: null, gone: true })   // already deleted elsewhere: the intent is satisfied
      } else if (RETRY_STATUS(res.status)) {
        e.error = res.status === 401 || res.status === 419 ? 'Your session expired. Sign in again and this will go through.' : 'The server is busy. Trying again soon.'
        await this.d.store.put(e)
        this.changed()
        return
      } else {
        e.state = 'failed'
        e.error = messageOf(res.json, res.status)
        await this.d.store.put(e)
        this.results.set(e.id, { status: 'failed', message: e.error })
        this.changed()
      }
    }
  }

  private async finish(e: Entry, r: Result): Promise<void> {
    this.results.set(e.id, r)
    this.list = this.list.filter((x) => x.id !== e.id)
    await this.d.store.del(e.id)
    this.changed()
    this.d.onSent?.(e, r)
  }

  /** Change a waiting entry (e.g. drop one item from a queued multi-item log). */
  async update(id: string, patch: Partial<Entry>): Promise<void> {
    const e = this.list.find((x) => x.id === id)
    if (!e) return
    Object.assign(e, patch)
    await this.d.store.put(e)
    this.changed()
  }

  /** Throw a waiting or failed entry away (undo while offline, or "discard" on a failed one). */
  async discard(id: string): Promise<void> {
    this.list = this.list.filter((x) => x.id !== id)
    await this.d.store.del(id)
    this.changed()
  }

  /** Put a failed entry back in the queue and try again. */
  async retry(id: string): Promise<void> {
    const e = this.list.find((x) => x.id === id)
    if (!e) return
    e.state = 'pending'
    e.error = undefined
    await this.d.store.put(e)
    this.changed()
    await this.flush()
  }
}

/** In-memory store: tests, and the fallback when IndexedDB is unavailable (private mode). */
export class MemoryStore implements Store {
  private m = new Map<string, Entry>()
  async all() { return [...this.m.values()].map((e) => structuredClone(e)) }
  async put(e: Entry) { this.m.set(e.id, structuredClone(e)) }
  async del(id: string) { this.m.delete(id) }
}
