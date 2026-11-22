import { useSyncExternalStore } from 'react'
import { xsrf } from '@/lib/http'
import { MemoryStore, Outbox, type Entry, type Store } from '@/lib/outboxCore'

export type { Entry, Result } from '@/lib/outboxCore'

/** IndexedDB so the queue survives closing the app and the phone restarting. Falls back to memory (private mode), where it still works until the tab closes. */
class IdbStore implements Store {
  private db: Promise<IDBDatabase> | null = null
  private open(): Promise<IDBDatabase> {
    this.db ??= new Promise((resolve, reject) => {
      const r = indexedDB.open('fuel-outbox', 1)
      r.onupgradeneeded = () => r.result.createObjectStore('entries', { keyPath: 'id' })
      r.onsuccess = () => resolve(r.result)
      r.onerror = () => reject(r.error)
    })
    return this.db
  }
  private async tx<T>(mode: IDBTransactionMode, fn: (s: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await this.open()
    return new Promise((resolve, reject) => { const r = fn(db.transaction('entries', mode).objectStore('entries')); r.onsuccess = () => resolve(r.result); r.onerror = () => reject(r.error) })
  }
  all() { return this.tx<Entry[]>('readonly', (s) => s.getAll()) }
  put(e: Entry) { return this.tx('readwrite', (s) => s.put(e)).then(() => undefined) }
  del(id: string) { return this.tx('readwrite', (s) => s.delete(id)).then(() => undefined) }
}

/** Tries IndexedDB; if it is unavailable or throws, the same calls quietly use memory. */
class SafeStore implements Store {
  private idb: IdbStore | null = typeof indexedDB !== 'undefined' ? new IdbStore() : null
  private mem = new MemoryStore()
  private async run<T>(fn: (s: Store) => Promise<T>): Promise<T> {
    if (this.idb) { try { return await fn(this.idb) } catch { this.idb = null } }
    return fn(this.mem)
  }
  all() { return this.run((s) => s.all()) }
  put(e: Entry) { return this.run((s) => s.put(e)) }
  del(id: string) { return this.run((s) => s.del(id)) }
}

let version = 0
let snapshot: { entries: Entry[]; version: number } = { entries: [], version: 0 }
let sentSince = 0
const listeners = new Set<() => void>()

export const outbox = new Outbox({
  store: new SafeStore(),
  isOnline: () => navigator.onLine,
  lock: typeof navigator !== 'undefined' && navigator.locks ? <T,>(fn: () => Promise<T>) => navigator.locks.request('fuel-outbox', fn) as Promise<T> : undefined,
  send: async (e) => {
    const res = await fetch(e.url, {
      method: e.method, credentials: 'same-origin',
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrf() },
      body: e.body === undefined ? undefined : JSON.stringify(e.body),
    })
    let json: unknown = null
    try { json = await res.json() } catch { /* an empty or non-JSON body */ }
    return { status: res.status, json }
  },
  onChange: () => { snapshot = { entries: outbox.entries(), version: ++version }; listeners.forEach((l) => l()) },
  onSent: () => { sentSince++ },
})

const subscribe = (l: () => void) => { listeners.add(l); return () => { listeners.delete(l) } }

/** Live view of the queue for React. `sent` counts entries delivered since the last time you asked to clear it (to refresh the page once). */
export function useOutbox() {
  const snap = useSyncExternalStore(subscribe, () => snapshot, () => snapshot)
  const online = useSyncExternalStore((cb) => { window.addEventListener('online', cb); window.addEventListener('offline', cb); return () => { window.removeEventListener('online', cb); window.removeEventListener('offline', cb) } }, () => navigator.onLine, () => true)
  return { entries: snap.entries, pending: snap.entries.filter((e) => e.state === 'pending'), failed: snap.entries.filter((e) => e.state === 'failed'), online }
}

export const takeSent = () => { const n = sentSince; sentSince = 0; return n }

// Start-up, once, in the browser: load the saved queue, ask the browser not to evict our storage, and keep trying while there is work.
if (typeof window !== 'undefined') {
  void outbox.load().then(() => outbox.flush())
  void navigator.storage?.persist?.()
  const kick = () => { if (outbox.pending().length) void outbox.flush() }
  window.addEventListener('online', kick)
  document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') kick() })
  setInterval(kick, 20000)
}
