import { Head, Link, router } from '@inertiajs/react'
import { ChevronRight, ListChecks } from 'lucide-react'
import { useState } from 'react'
import AppLayout from '@/layouts/AppLayout'

interface Item { id: number; title: string; folder: string | null; exercises: number; working_sets: number; muscles: string[]; last_done: string | null }
interface FolderOpt { key: string; title: string; routines: number; shown: boolean }
interface Props { connected: boolean; data: { routines: Item[]; folders: FolderOpt[]; auto_folders: boolean; hidden_folders: number; synced_at: string | null; error: string | null } | null }

const pretty = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', timeZone: 'UTC' })

function FolderPicker({ folders, auto }: { folders: FolderOpt[]; auto: boolean }) {
  const [open, setOpen] = useState(false)
  const [sel, setSel] = useState<string[]>(folders.filter((f) => f.shown).map((f) => f.key))
  const toggle = (k: string) => setSel((s) => (s.includes(k) ? s.filter((x) => x !== k) : [...s, k]))
  return (
    <div className="mt-3 rounded-2xl bg-surface p-3 text-sm">
      <button type="button" onClick={() => setOpen(!open)} aria-expanded={open} className="flex min-h-11 w-full items-center justify-between text-left">
        <span>{auto ? 'Showing your current program folders.' : 'Showing the folders you chose.'} <span className="text-muted">{folders.filter((f) => f.shown).length} of {folders.length}</span></span>
        <span className="text-accent underline">{open ? 'Close' : 'Choose'}</span>
      </button>
      {open ? (
        <div className="mt-2 border-t border-raised pt-2">
          <p className="text-xs text-muted">Hiding a folder only hides it here. Nothing is deleted in Hevy or in FUEL.</p>
          <ul className="mt-2">{folders.map((f) => (
            <li key={f.key}><label className="flex min-h-11 items-center gap-3"><input type="checkbox" className="size-5" checked={sel.includes(f.key)} onChange={() => toggle(f.key)} /><span>{f.title} <span className="text-xs text-muted">({f.routines})</span></span></label></li>
          ))}</ul>
          <div className="mt-2 flex gap-2">
            <button type="button" disabled={sel.length === 0} onClick={() => router.put('/lifting/routines/folders', { keys: sel }, { preserveScroll: true, onSuccess: () => setOpen(false) })} className="min-h-11 rounded-xl bg-accent px-4 text-sm font-medium text-on-accent disabled:opacity-40">Save</button>
            {!auto ? <button type="button" onClick={() => router.delete('/lifting/routines/folders', { preserveScroll: true, onSuccess: () => setOpen(false) })} className="min-h-11 px-3 text-sm text-muted underline">Back to automatic</button> : null}
          </div>
        </div>
      ) : null}
    </div>
  )
}

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
          {data.folders.length > 1 ? <FolderPicker key={data.folders.map((f) => f.key + f.shown).join()} folders={data.folders} auto={data.auto_folders} /> : null}
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
