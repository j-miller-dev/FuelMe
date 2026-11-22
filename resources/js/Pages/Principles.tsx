import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'
import { apiForm } from '@/lib/http'

type Stance = 'follow' | 'context' | 'ignore'
interface Card { id: number; title: string; body: string; source: string | null; tags: string[]; grade: string; stance: Stance; note_stance: Stance; overridden: boolean }
interface Result { created: number; updated: number; unchanged: number; archived: number; errors: { file: string; error: string }[]; warnings: string[] }
interface Props { cards: Card[]; stance: string; maxChars: number }

const STANCE: { key: Stance; label: string; hint: string }[] = [
  { key: 'follow', label: 'Follow', hint: 'The coach leads with these.' },
  { key: 'context', label: 'Context', hint: 'Mentioned as another view.' },
  { key: 'ignore', label: 'Ignore', hint: 'Never used.' },
]
const GRADE: Record<string, string> = { strong: 'Well supported', rule_of_thumb: 'Rule of thumb', opinion: 'Opinion' }

export default function Principles({ cards, stance, maxChars }: Props) {
  const [busy, setBusy] = useState(false)
  const [full, setFull] = useState(false)
  const [res, setRes] = useState<Result | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [mine, setMine] = useState(stance)

  async function pick(files: FileList | null) {
    if (!files || !files.length) return
    setBusy(true); setError(null); setRes(null)
    const fd = new FormData()
    Array.from(files).forEach((f) => fd.append('files[]', f))
    if (full) fd.append('full_sync', '1')
    try {
      setRes(await apiForm<Result>('/principles/import', fd))
      router.reload({ only: ['cards'] })
    } catch (e) { setError(e instanceof Error ? e.message : 'Could not read those files.') }
    setBusy(false)
  }

  return (
    <AppLayout>
      <Head title="My notes" />
      <header className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">My notes</h1>
        <Link href="/coach" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Coach</Link>
      </header>
      <p className="mt-1 text-sm text-muted">One short note per idea, in your own words, from your Obsidian vault. The coach and the Today card use only these. FUEL reads your notes and never writes to your vault.</p>

      <section className="mt-4 rounded-2xl bg-surface p-4" aria-label="Import notes">
        <label className="block text-center">
          <span className="block font-medium">{busy ? 'Reading…' : 'Import notes'}</span>
          <span className="mt-1 block text-xs text-muted">Pick the .md files from your Fuel/Principles folder. Re-importing updates by note id, no duplicates. Each note up to {maxChars} characters; frontmatter: id, source, tags, grade, stance.</span>
          <input type="file" multiple accept=".md,.markdown,.txt,text/markdown,text/plain" disabled={busy} onChange={(e) => void pick(e.target.files)} className="sr-only" />
          <span className="mt-3 inline-block min-h-11 rounded-2xl bg-accent px-5 py-3 font-semibold text-on-accent">Browse files</span>
        </label>
        <label className="mt-3 flex min-h-11 items-center gap-2 text-sm">
          <input type="checkbox" checked={full} onChange={(e) => setFull(e.target.checked)} className="size-5" />
          These are my whole folder: archive notes that are missing
        </label>
        {res ? (
          <div className="mt-2 text-sm" role="status">
            <p>{res.created} new, {res.updated} updated, {res.unchanged} unchanged{res.archived ? `, ${res.archived} archived` : ''}.</p>
            {res.errors.map((e) => <p key={e.file} className="text-fat">{e.file}: {e.error}</p>)}
            {res.warnings.map((w) => <p key={w} className="text-muted">{w}</p>)}
          </div>
        ) : null}
        {error ? <p role="alert" className="mt-2 text-sm text-fat">{error}</p> : null}
      </section>

      <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="My stance">
        <h2 className="font-semibold">My stance</h2>
        <p className="mt-1 text-xs text-muted">A few lines on whose ideas you follow for what. The coach reads this, and your choices below win over what a note says.</p>
        <textarea value={mine} maxLength={600} rows={3} onChange={(e) => setMine(e.target.value)} className="mt-2 w-full rounded-xl bg-canvas/60 p-3 text-sm" placeholder="HIT for lifting. Evidence-based ranges for protein and fibre. …" aria-label="My stance" />
        <Button type="button" className="mt-2" onClick={() => router.put('/principles/stance', { text: mine }, { preserveScroll: true })}>Save stance</Button>
      </section>

      {cards.length === 0 ? <p className="mt-4 text-sm text-muted">No notes yet. Write your first few from what you read and import them here.</p> : (
        <ul className="mt-4 space-y-3">
          {cards.map((c) => (
            <li key={c.id} className="rounded-2xl bg-surface p-4">
              <p className="text-xs text-muted">{c.source ?? 'No source'} · {GRADE[c.grade] ?? c.grade}{c.tags.length ? ` · ${c.tags.join(', ')}` : ''}</p>
              <h3 className="mt-1 font-semibold">{c.title}</h3>
              <p className="mt-1 whitespace-pre-wrap text-sm">{c.body}</p>
              <div className="mt-3 flex flex-wrap items-center gap-2" role="group" aria-label={`Stance for ${c.title}`}>
                {STANCE.map((s) => (
                  <button key={s.key} type="button" title={s.hint} aria-pressed={c.stance === s.key}
                    className={`min-h-11 rounded-full px-4 text-sm ${c.stance === s.key ? 'bg-accent font-semibold text-on-accent' : 'bg-raised'}`}
                    onClick={() => router.patch(`/principles/${c.id}/stance`, { stance: s.key }, { preserveScroll: true })}>{s.label}</button>
                ))}
                <button type="button" className="min-h-11 px-2 text-sm text-muted underline" onClick={() => router.delete(`/principles/${c.id}`, { preserveScroll: true })}>Archive</button>
              </div>
              {c.overridden ? <p className="mt-1 text-xs text-muted">You changed this from “{c.note_stance}” in the note.</p> : null}
            </li>
          ))}
        </ul>
      )}
    </AppLayout>
  )
}
