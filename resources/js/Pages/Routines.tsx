import { Head, Link } from '@inertiajs/react'
import { ChevronRight, ListChecks } from 'lucide-react'
import AppLayout from '@/layouts/AppLayout'

interface Item { id: number; title: string; folder: string | null; exercises: number; working_sets: number; muscles: string[]; last_done: string | null }
interface Props { connected: boolean; data: { routines: Item[]; synced_at: string | null; error: string | null } | null }

const pretty = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', timeZone: 'UTC' })

export default function Routines({ connected, data }: Props) {
  const groups = new Map<string, Item[]>()
  for (const r of data?.routines ?? []) groups.set(r.folder ?? 'No folder', [...(groups.get(r.folder ?? 'No folder') ?? []), r])
  return (
    <AppLayout>
      <Head title="Routines" />
      <header className="flex items-center justify-between">
        <h1 className="flex items-center gap-2 text-lg font-semibold"><ListChecks className="size-5 text-accent" aria-hidden />Routines</h1>
        <span className="flex gap-1"><Link href="/lifting/progression" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Progression</Link><Link href="/lifting" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Lifting</Link></span>
      </header>
      <p className="mt-1 text-sm text-muted">Your Hevy routines, read-only. Edit them in Hevy; FUEL shows them next to your own last numbers.</p>

      {!connected || !data ? (
        <p className="mt-4 rounded-2xl bg-surface p-4 text-sm">Connect Hevy first. <Link href="/lifting" className="text-accent underline">Go to Lifting</Link></p>
      ) : (
        <>
          {data.error ? <p role="alert" className="mt-3 rounded-2xl bg-surface p-3 text-sm text-fat">Routines could not be read from Hevy: {data.error} Your workouts still sync.</p> : null}
          {data.routines.length === 0 ? (
            <p className="mt-4 rounded-2xl bg-surface p-4 text-sm text-muted">{data.synced_at ? 'Hevy has no routines for this account.' : 'No routines yet. They appear after the next sync (press Sync now on Lifting).'}</p>
          ) : (
            [...groups.entries()].map(([folder, items]) => (
              <section key={folder} className="mt-4" aria-label={folder}>
                <h2 className="text-sm font-medium text-muted">{folder}</h2>
                <ul className="mt-1 space-y-2">
                  {items.map((r) => (
                    <li key={r.id}>
                      <Link href={`/lifting/routines/${r.id}`} prefetch className="flex items-center justify-between gap-2 rounded-2xl bg-surface p-4 active:bg-raised">
                        <span>
                          <span className="block font-medium">{r.title}</span>
                          <span className="block text-xs text-muted capitalize">{r.exercises} exercises, {r.working_sets} working sets{r.muscles.length ? ` · ${r.muscles.map((m) => m.replace(/_/g, ' ')).join(', ')}` : ''}</span>
                          <span className="block text-xs text-muted">{r.last_done ? `Last done ${pretty(r.last_done)}` : 'Not done yet'}</span>
                        </span>
                        <ChevronRight className="size-4 shrink-0 text-muted" aria-hidden />
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            ))
          )}
          {data.synced_at ? <p className="mt-4 text-xs text-muted">Routines read from Hevy {new Date(data.synced_at).toLocaleString('en-AU', { dateStyle: 'medium', timeStyle: 'short' })}. They refresh with every sync.</p> : null}
        </>
      )}
    </AppLayout>
  )
}
