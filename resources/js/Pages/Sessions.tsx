import { Head, Link } from '@inertiajs/react'
import { Dumbbell } from 'lucide-react'
import LiftingNav from '@/components/lifting/LiftingNav'
import AppLayout from '@/layouts/AppLayout'
import { SessionCard, type LiftCard } from '@/components/lifting/SessionCard'

interface Month { month: string; sessions: number; sets: number; volume_kg: number; minutes: number; prs: number; cards: LiftCard[] }
const monthName = (m: string) => new Date(`${m}-01T12:00:00Z`).toLocaleDateString('en-AU', { month: 'long', year: 'numeric', timeZone: 'UTC' })

export default function Sessions({ connected, months, notes }: { connected: boolean; months: Month[]; notes: string[] }) {
  return (
    <AppLayout>
      <Head title="Lifting sessions" />
      <header className="flex items-center justify-between">
        <h1 className="flex items-center gap-2 text-lg font-semibold"><Dumbbell className="size-5 text-accent" aria-hidden />Lifting sessions</h1>
      </header>
      <LiftingNav current="sessions" />
      {!connected ? <p className="mt-4 rounded-2xl bg-surface p-4 text-sm">Connect Hevy first and your sessions appear here. <Link href="/lifting" className="text-accent underline">Go to Lifting</Link></p>
        : months.length === 0 ? <p className="mt-4 rounded-2xl bg-surface p-4 text-sm">No synced sessions yet. Press Sync now on the Lifting page.</p>
        : months.map((m) => (
          <section key={m.month} className="mt-4" aria-label={monthName(m.month)}>
            <div className="flex items-end justify-between px-1">
              <h2 className="text-base font-semibold">{monthName(m.month)}</h2>
              <p className="text-right text-xs text-muted tabular-nums"><span className="block text-lg font-semibold text-ink">{(m.volume_kg / 1000).toFixed(1)} t</span>{m.sessions} {m.sessions === 1 ? 'session' : 'sessions'} · {m.sets} sets{m.prs ? ` · ${m.prs} PR ${m.prs === 1 ? 'lift' : 'lifts'}` : ''}</p>
            </div>
            <div className="mt-2 space-y-3">{m.cards.map((c) => <SessionCard key={c.id ?? c.date + c.title} c={c} />)}</div>
          </section>
        ))}
      <ul className="mt-5 list-disc space-y-1 pl-5 text-xs text-muted">{notes.map((x) => <li key={x}>{x}</li>)}</ul>
    </AppLayout>
  )
}
