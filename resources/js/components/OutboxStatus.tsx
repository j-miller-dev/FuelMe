import { router } from '@inertiajs/react'
import { useEffect, useRef, useState } from 'react'
import { outbox, takeSent, useOutbox, type Entry } from '@/lib/outbox'

const describe = (e: Entry): string => {
  if (e.label) return e.label
  if (e.kind === 'logs.add') return `Log: ${((e.preview as { rows?: { name: string }[] } | undefined)?.rows ?? []).map((r) => r.name).join(', ') || 'food'}`
  if (e.kind === 'logs.delete') return 'Removed a log entry'
  if (e.kind === 'logs.update') return 'Changed a log entry'
  if (e.kind === 'logs.copy') return 'Copied a day'
  return 'A change'
}

/**
 * The truth about what is saved where. Shows only when something needs saying: you are offline, changes are waiting, a change was refused,
 * or a new version of the app is ready. When the last waiting change is delivered the page refreshes so you see the real rows.
 */
export function OutboxStatus() {
  const { pending, failed, online } = useOutbox()
  const [update, setUpdate] = useState<ServiceWorkerRegistration | null>(null)
  const [open, setOpen] = useState(false)
  const had = useRef(0)

  // When the queue drains after delivering something, reload the page data once: the optimistic rows are replaced by the server's.
  useEffect(() => {
    if (had.current > 0 && pending.length === 0) { if (takeSent() > 0) router.reload() }
    had.current = pending.length
  }, [pending.length])

  useEffect(() => {
    const on = (e: Event) => setUpdate((e as CustomEvent<ServiceWorkerRegistration>).detail)
    window.addEventListener('fuel-sw-update', on)
    return () => window.removeEventListener('fuel-sw-update', on)
  }, [])

  const reloadForUpdate = () => {
    navigator.serviceWorker.addEventListener('controllerchange', () => window.location.reload(), { once: true })
    update?.waiting?.postMessage({ type: 'SKIP_WAITING' })
  }

  if (online && pending.length === 0 && failed.length === 0 && !update) return null
  return (
    <div className="sticky top-0 z-40 mx-auto max-w-md" role="status" aria-live="polite">
      {update ? (
        <div className="flex items-center gap-2 bg-accent/20 px-4 py-2 text-sm"><span className="flex-1">A new version of FUEL is ready.</span><button onClick={reloadForUpdate} className="min-h-9 font-semibold text-accent">Update</button></div>
      ) : null}
      {!online ? (
        <div className="bg-carbs/20 px-4 py-2 text-sm"><span className="font-medium">You are offline.</span> <span className="text-muted">{pending.length ? `${pending.length} change${pending.length === 1 ? ' is' : 's are'} saved on this phone and will sync by themselves.` : 'You can still log from your recent foods, favourites and Quick add. It syncs when you are back.'}</span></div>
      ) : pending.length > 0 ? (
        <div className="flex items-center gap-2 bg-raised px-4 py-2 text-sm"><span className="flex-1">Syncing {pending.length} change{pending.length === 1 ? '' : 's'}…{pending[0]?.error ? <span className="text-muted"> {pending[0].error}</span> : null}</span>
          <button onClick={() => void outbox.flush()} className="min-h-9 font-semibold text-accent">Try now</button></div>
      ) : null}
      {failed.length > 0 ? (
        <div className="bg-danger/15 px-4 py-2 text-sm">
          <button onClick={() => setOpen((o) => !o)} aria-expanded={open} className="flex min-h-9 w-full items-center text-left"><span className="flex-1 font-medium">{failed.length} change{failed.length === 1 ? '' : 's'} could not be saved</span><span aria-hidden>{open ? '▾' : '▸'}</span></button>
          {open ? (
            <ul className="mt-1 space-y-2">{failed.map((e) => (
              <li key={e.id} className="rounded-xl bg-canvas/60 p-2"><div className="font-medium">{describe(e)}</div><div className="text-xs text-muted">{e.error}</div>
                <div className="mt-1 flex gap-2"><button onClick={() => void outbox.retry(e.id)} className="min-h-9 text-accent">Try again</button><button onClick={() => void outbox.discard(e.id)} className="min-h-9 text-muted">Discard</button></div></li>))}</ul>
          ) : null}
        </div>
      ) : null}
    </div>
  )
}
