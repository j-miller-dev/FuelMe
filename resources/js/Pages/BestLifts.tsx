import { useMemo, useState } from 'react'
import { Head, Link } from '@inertiajs/react'
import { ChevronRight, StickyNote, Trophy } from 'lucide-react'
import AppLayout from '@/layouts/AppLayout'

interface Rec { kg: number; reps: number; date: string; new: boolean }
interface E1 { e1rm: number; kg: number; reps: number; date: string; new: boolean }
interface Vol { kg: number; sets: number; date: string; new: boolean }
interface Row { id: string; title: string; muscle: string | null; sessions: number; last_date: string; heaviest: Rec; best_e1rm: E1 | null; best_volume: Vol; has_new: boolean; notes: number }
interface Props { connected: boolean; board: { exercises: Row[]; new_count: number; notes: string[] } | null }

const pretty = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: '2-digit', timeZone: 'UTC' })
const kg = (n: number) => `${Number.isInteger(n) ? n : n.toFixed(1)} kg`
type Sort = 'recent' | 'az' | 'heaviest'

export default function BestLifts({ connected, board }: Props) {
  const [q, setQ] = useState('')
  const [sort, setSort] = useState<Sort>('recent')
  const rows = useMemo(() => {
    const list = (board?.exercises ?? []).filter((r) => r.title.toLowerCase().includes(q.trim().toLowerCase()))
    if (sort === 'az') return [...list].sort((a, b) => a.title.localeCompare(b.title))
    if (sort === 'heaviest') return [...list].sort((a, b) => b.heaviest.kg - a.heaviest.kg)
    return list
  }, [board, q, sort])

  return (
    <AppLayout>
      <Head title="Best lifts" />
      <header className="flex items-center justify-between">
        <h1 className="flex items-center gap-2 text-lg font-semibold"><Trophy className="size-5 text-accent" aria-hidden />Best lifts</h1>
        <Link href="/lifting" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Lifting</Link>
      </header>
      <p className="mt-1 text-sm text-muted">Your records from every synced Hevy session. Read-only: FUEL never changes anything in Hevy.</p>

      {!connected || !board ? (
        <p className="mt-4 rounded-2xl bg-surface p-4 text-sm">Connect Hevy first and your records appear here. <Link href="/lifting" className="text-accent underline">Go to Lifting</Link></p>
      ) : board.exercises.length === 0 ? (
        <p className="mt-4 rounded-2xl bg-surface p-4 text-sm text-muted">No lifts with a weight and reps yet. They appear after the first sync finishes.</p>
      ) : (
        <>
          {board.new_count > 0 ? <p className="mt-3 rounded-2xl bg-surface p-3 text-sm"><span className="font-medium text-accent">{board.new_count} lift{board.new_count === 1 ? '' : 's'} with a new PR</span> in the last 14 days.</p> : null}

          <label className="sr-only" htmlFor="lift-filter">Find a lift</label>
          <input id="lift-filter" type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder="Find a lift" className="mt-3 min-h-11 w-full rounded-xl bg-surface px-3 text-sm" />
          <div className="mt-2 flex gap-2" role="group" aria-label="Sort">
            {([['recent', 'Recent'], ['az', 'A to Z'], ['heaviest', 'Heaviest']] as [Sort, string][]).map(([k, label]) => (
              <button key={k} type="button" aria-pressed={sort === k} onClick={() => setSort(k)} className={`min-h-11 rounded-full px-4 text-sm ${sort === k ? 'bg-accent text-canvas' : 'bg-raised'}`}>{label}</button>
            ))}
          </div>

          <ul className="mt-3 space-y-2">
            {rows.map((r) => (
              <li key={r.id}>
                <Link href={`/lifting/exercise/${encodeURIComponent(r.id)}`} className="block rounded-2xl bg-surface p-4 active:bg-raised" prefetch>
                  <span className="flex items-start justify-between gap-2">
                    <span>
                      <span className="block font-medium">{r.title}</span>
                      <span className="text-xs text-muted capitalize">{r.muscle ? r.muscle.replace(/_/g, ' ') + ' · ' : ''}{r.sessions} session{r.sessions === 1 ? '' : 's'}, last {pretty(r.last_date)}</span>
                    </span>
                    <span className="flex items-center gap-2">
                      {r.has_new ? <span className="rounded-full bg-accent px-2 py-0.5 text-xs font-medium text-canvas">New PR</span> : null}
                      {r.notes > 0 ? <span className="flex items-center gap-1 text-xs text-muted"><StickyNote className="size-3.5" aria-hidden />{r.notes}</span> : null}
                      <ChevronRight className="size-4 text-muted" aria-hidden />
                    </span>
                  </span>
                  <span className="mt-2 grid grid-cols-3 gap-2 text-sm">
                    <span><span className="block text-xs text-muted">Heaviest</span>{kg(r.heaviest.kg)} × {r.heaviest.reps}</span>
                    <span><span className="block text-xs text-muted">Est. 1RM</span>{r.best_e1rm ? kg(r.best_e1rm.e1rm) : '-'}</span>
                    <span><span className="block text-xs text-muted">Best session</span>{Math.round(r.best_volume.kg).toLocaleString('en-AU')} kg</span>
                  </span>
                </Link>
              </li>
            ))}
          </ul>
          {rows.length === 0 ? <p className="mt-3 text-sm text-muted">No lift matches that.</p> : null}
          <ul className="mt-4 list-disc space-y-1 pl-5 text-xs text-muted">{board.notes.map((n) => <li key={n}>{n}</li>)}</ul>
        </>
      )}
    </AppLayout>
  )
}
