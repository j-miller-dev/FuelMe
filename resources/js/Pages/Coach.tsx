import { useState } from 'react'
import { Head, Link, router } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { Button } from '@/components/ui/button'
import { api } from '@/lib/http'

interface Msg { id: number | string; role: 'user' | 'assistant'; content: string; grounded?: boolean; tools?: string[] }
interface Props { enabled: boolean; overCap: boolean; threadId: number | null; messages: Msg[]; maxChars: number; suggestions: string[] }
interface Reply { thread_id: number; message: Msg }

const TOOL_LABEL: Record<string, string> = {
  get_today_status: 'today', get_targets: 'targets', get_week_plan: 'week plan', get_trend: 'weight trend', get_expenditure: 'expenditure estimate',
  get_weekly_checkin: 'weekly check-in', get_training: 'training', get_adherence: 'logging consistency', get_next_session_fuel: 'next session fuel', get_assumptions: 'assumptions',
}

export default function Coach({ enabled, overCap, threadId, messages, maxChars, suggestions }: Props) {
  const [thread, setThread] = useState<number | null>(threadId)
  const [list, setList] = useState<Msg[]>(messages)
  const [text, setText] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  async function send(q: string) {
    const t = q.trim()
    if (t.length < 2 || busy) return
    setBusy(true); setError(null); setText('')
    setList((l) => [...l, { id: `u${Date.now()}`, role: 'user', content: t }])
    try {
      const r = await api<Reply>('/coach/messages', { method: 'POST', body: { text: t, thread_id: thread } })
      setThread(r.thread_id)
      setList((l) => [...l, r.message])
    } catch (e) {
      setError(e instanceof Error ? e.message : 'Something went wrong.')
    } finally { setBusy(false) }
  }

  return (
    <AppLayout>
      <Head title="Coach" />
      <header className="flex items-center justify-between">
        <h1 className="text-lg font-semibold">Coach</h1>
        <Link href="/progress" className="min-h-11 px-2 text-sm text-accent underline leading-[2.75rem]">Progress</Link>
      </header>
      <p className="mt-1 text-sm text-muted">Answers questions about your own numbers. It reads your data and explains it; it cannot change targets, log food or move sessions. Not medical advice.</p>

      {!enabled ? (
        <section className="mt-4 rounded-2xl bg-surface p-4 text-sm" aria-label="Coach is off">
          <p className="font-medium">The coach is off.</p>
          <p className="mt-1 text-muted">It needs an Anthropic key (<code>ANTHROPIC_API_KEY</code>) and <code>FUEL_COACH_ENABLED=true</code>. Everything else in the app works without it.</p>
        </section>
      ) : (
        <>
          <ul className="mt-4 space-y-3" aria-live="polite">
            {list.map((m) => (
              <li key={m.id} className={m.role === 'user' ? 'ml-8 rounded-2xl bg-raised p-3 text-sm' : 'mr-4 rounded-2xl bg-surface p-3 text-sm'}>
                <p className="whitespace-pre-wrap">{m.content}</p>
                {m.role === 'assistant' && m.tools && m.tools.length ? (
                  <details className="mt-2 text-xs text-muted">
                    <summary className="min-h-8 cursor-pointer">Based on</summary>
                    <p className="mt-1">{m.tools.map((t) => TOOL_LABEL[t] ?? t).join(', ')}</p>
                  </details>
                ) : null}
                {m.role === 'assistant' && m.grounded === false ? <p className="mt-2 text-xs text-fat">Not backed by your data, so numbers were left out.</p> : null}
              </li>
            ))}
            {busy ? <li className="mr-4 rounded-2xl bg-surface p-3 text-sm text-muted">Checking your numbers…</li> : null}
          </ul>

          {list.length === 0 ? (
            <div className="mt-4 flex flex-wrap gap-2">
              {suggestions.map((s) => <button key={s} type="button" className="min-h-11 rounded-full bg-surface px-3 text-sm" onClick={() => send(s)}>{s}</button>)}
            </div>
          ) : null}

          {overCap ? <p className="mt-4 text-sm text-fat">The coach has reached this month's limit. Your numbers are on Today, Targets and Progress.</p> : (
            <form className="mt-4 flex gap-2" onSubmit={(e) => { e.preventDefault(); void send(text) }}>
              <label className="sr-only" htmlFor="coach-q">Ask the coach</label>
              <input id="coach-q" value={text} maxLength={maxChars} onChange={(e) => setText(e.target.value)} placeholder="Ask about your numbers" className="min-h-11 flex-1 rounded-xl bg-surface px-3 text-sm" />
              <Button type="submit" disabled={busy || text.trim().length < 2}>Ask</Button>
            </form>
          )}
          {error ? <p role="alert" className="mt-2 text-sm text-fat">{error}</p> : null}
          {thread ? (
            <button type="button" className="mt-4 min-h-11 text-sm text-muted underline" onClick={() => { if (confirm('Delete this conversation?')) router.delete(`/coach/threads/${thread}`) }}>Delete conversation</button>
          ) : null}
        </>
      )}
    </AppLayout>
  )
}
