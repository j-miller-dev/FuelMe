import { Head, Link } from '@inertiajs/react'
import { TrendingUp } from 'lucide-react'
import LiftingNav from '@/components/lifting/LiftingNav'
import { FolderPicker } from '@/components/lifting/PhasePoster'
import AppLayout from '@/layouts/AppLayout'
import NextTime, { type Next, type Projection, type StepInfo, targetText } from '@/components/lifting/NextTime'

interface Ex { template_id: string; title: string; muscle: string | null; next: Next; projection: Projection; step: StepInfo }
interface Rt { id: number; title: string; exercises: Ex[] }
interface Folder { key: string; title: string; routines: number }
interface Program {
  connected: boolean; folders: Folder[]; selected: string | null; routines: Rt[]
  effort_now: { min: number; max: number; label: string } | null
  summary: Record<string, number> | null; assumptions: string[]
}

export default function Progression({ program: p }: { program: Program }) {
  const folder = p.folders.find((f) => f.key === p.selected)
  const s = p.summary
  return (
    <AppLayout>
      <Head title="Progression" />
      <header className="flex items-center justify-between">
        <h1 className="flex items-center gap-2 text-lg font-semibold"><TrendingUp className="size-5 text-accent" aria-hidden />Progression</h1>
      </header>
      <LiftingNav current="progression" />
      <p className="mt-1 text-sm text-muted">What to aim for next time on each exercise, from your own logged sets, with a rough projection. Suggestions only: you decide, and nothing is changed in Hevy.</p>

      {!p.connected && p.folders.length === 0 ? (
        <p className="mt-4 rounded-2xl bg-surface p-4 text-sm">No routines yet. Connect Hevy and press Sync now. <Link href="/lifting" className="text-accent underline">Go to Lifting</Link></p>
      ) : (
        <>
          {p.folders.length > 1 ? <FolderPicker folders={p.folders} selected={p.selected} /> : folder ? <p className="mt-3 text-sm font-medium">{folder.title}</p> : null}

          {p.effort_now ? <p className="mt-3 rounded-2xl bg-surface p-3 text-sm">Your effort plan this week: <span className="font-medium">{p.effort_now.label}</span>. The reps shown for a new weight assume that effort.</p> : null}
          {s ? <p className="mt-3 rounded-2xl bg-surface p-3 text-sm"><span className="font-medium">{s.increase ?? 0} ready to go up</span>, {s.hold ?? 0} same weight, {(s.repeat ?? 0) + (s.reduce ?? 0)} to repeat or ease back{(s.start ?? 0) ? `, ${s.start} not done yet` : ''}.</p> : null}

          {p.routines.map((r) => (
            <section key={r.id} className="mt-4" aria-label={r.title}>
              <h2 className="flex items-center justify-between font-semibold"><span>{r.title}</span><Link href={`/lifting/routines/${r.id}`} className="text-sm font-normal text-accent underline">Open</Link></h2>
              <ul className="mt-2 space-y-2">
                {r.exercises.filter((e) => e.next.action !== 'no_load').map((e) => (
                  <li key={e.template_id + e.title} className="rounded-2xl bg-surface p-4">
                    <div className="flex items-start justify-between gap-2">
                      <div>
                        <Link href={`/lifting/exercise/${encodeURIComponent(e.template_id)}`} className="font-medium underline decoration-raised underline-offset-4">{e.title}</Link>
                        {e.muscle ? <p className="text-xs text-muted capitalize">{e.muscle.replace(/_/g, ' ')}</p> : null}
                      </div>
                      {e.next.last ? <span className="text-right text-xs text-muted">Last: {e.next.last.kg} kg × {e.next.last.reps.join(', ')}</span> : null}
                    </div>
                    <NextTime next={e.next} projection={e.projection} step={e.step} />
                    <span className="sr-only">{targetText(e.next)}</span>
                  </li>
                ))}
              </ul>
              {r.exercises.some((e) => e.next.action === 'no_load') ? <p className="mt-1 text-xs text-muted">Not shown (no weight to progress): {r.exercises.filter((e) => e.next.action === 'no_load').map((e) => e.title).join(', ')}.</p> : null}
            </section>
          ))}
          <ul className="mt-5 list-disc space-y-1 pl-5 text-xs text-muted">{p.assumptions.map((a) => <li key={a}>{a}</li>)}</ul>
        </>
      )}
    </AppLayout>
  )
}
