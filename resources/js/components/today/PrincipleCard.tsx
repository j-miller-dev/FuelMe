import { useState } from 'react'

export interface PrincipleToday { id: number; title: string; body: string; source: string | null; grade: string; reason: string }
const GRADE: Record<string, string> = { strong: 'Well supported', rule_of_thumb: 'Rule of thumb', opinion: 'Opinion' }
const key = (date: string) => `fuel.principle.hidden.${date}`

/** One note from your own reading, picked by a visible rule (never random). Hide it for the day if it is not useful. */
export function PrincipleCard({ p, date }: { p: PrincipleToday; date: string }) {
  const [hidden, setHidden] = useState(() => { try { return localStorage.getItem(key(date)) === String(p.id) } catch { return false } })
  if (hidden) return null
  return (
    <section className="mt-3 rounded-2xl bg-surface p-4" aria-label="From your notes">
      <p className="text-xs text-muted">{p.reason}. From your notes{p.source ? `: ${p.source}` : ''}</p>
      <h2 className="mt-1 font-semibold">{p.title}</h2>
      <p className="mt-1 whitespace-pre-wrap text-sm">{p.body}</p>
      <div className="mt-2 flex items-center justify-between text-xs text-muted">
        <span>{GRADE[p.grade] ?? p.grade}</span>
        <button type="button" className="min-h-11 px-2 underline" onClick={() => { try { localStorage.setItem(key(date), String(p.id)) } catch { /* light preference only */ } setHidden(true) }}>Hide today</button>
      </div>
    </section>
  )
}
