import { Head, Link, router } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/layouts/AppLayout'
import { SessionSheet } from '@/components/training/SessionSheet'
import { api } from '@/lib/http'
import { CLASS_LABEL, DAYS, DAY_LABEL, INTENSITY_LABEL, type Catalogue, type Session, type TemplateRow } from '@/lib/training'
import { longDate } from '@/lib/progress'

interface Props { template: TemplateRow[]; catalogue: Catalogue; recent: (Session & { date: string })[]; today: string; week: Record<string, { class: string; netKcal: number; carbs: number; kcal: number }>; kg: number }

export default function Training({ template, catalogue, recent, today, week, kg }: Props) {
  const [sheet, setSheet] = useState<{ kind: 'template'; day: string; row?: TemplateRow } | { kind: 'log'; date: string; row?: Session } | null>(null)
  const [error, setError] = useState<string | null>(null)
  const reload = () => router.reload({ only: ['template', 'week', 'recent'] })
  const remove = async (id: number) => { setError(null); try { await api(`/training/template/${id}`, { method: 'DELETE' }); reload() } catch (e) { setError(e instanceof Error ? e.message : 'Could not remove.') } }
  const label = (r: { type: string; name: string | null }) => r.name ?? catalogue[r.type]?.label ?? r.type

  return (
    <AppLayout>
      <Head title="Training" />
      <h1 className="text-2xl font-bold">Training</h1>
      <p className="mt-1 text-sm text-muted">Your usual week. It decides which days get more carbohydrate and when to fuel around sessions. Calories stay the same across the week; they just move to the days you train hardest.</p>
      {error ? <p role="alert" className="mt-2 text-sm text-danger">{error}</p> : null}

      <ul className="mt-4 space-y-2">
        {DAYS.map((d) => {
          const rows = template.filter((t) => t.day === d)
          const w = week[d]
          return (
            <li key={d} className="rounded-2xl bg-surface p-3">
              <div className="flex items-center gap-2">
                <h2 className="flex-1 font-semibold">{DAY_LABEL[d]}</h2>
                {w ? <span className="rounded-full bg-raised px-2 py-0.5 text-xs text-accent">{CLASS_LABEL[w.class] ?? w.class}</span> : null}
                <button onClick={() => setSheet({ kind: 'template', day: d })} aria-label={`Add a session on ${DAY_LABEL[d]}`} className="size-11 rounded-full bg-raised text-lg">+</button>
              </div>
              {rows.length === 0 ? <p className="text-sm text-muted">Rest</p> : (
                <ul>{rows.map((r) => (
                  <li key={r.id} className="flex min-h-12 items-center gap-2 border-t border-raised/70 text-sm">
                    <button onClick={() => setSheet({ kind: 'template', day: d, row: r })} className="min-w-0 flex-1 py-2 text-left" aria-label={`Edit ${label(r)} on ${DAY_LABEL[d]}`}>
                      <span className="block truncate font-medium">{label(r)} <span className="font-normal text-muted">· {INTENSITY_LABEL[r.intensity] ?? r.intensity}</span></span>
                      <span className="block text-xs text-muted tabular-nums">{r.start ?? 'no time set'} · {r.minutes} min</span>
                    </button>
                    <button onClick={() => void remove(r.id)} aria-label={`Remove ${label(r)} from ${DAY_LABEL[d]}`} className="size-11 text-muted">✕</button>
                  </li>))}</ul>
              )}
              {w ? <p className="mt-1 text-xs text-muted tabular-nums">{w.kcal.toLocaleString()} kcal · {w.carbs} g carbs{w.netKcal ? ` · training ≈ ${Math.round(w.netKcal)} kcal` : ''}</p> : null}
            </li>
          )
        })}
      </ul>
      <p className="mt-2 text-xs text-muted">Training energy is an estimate based on your weight ({kg} kg), good to about ±25%. It is never added to your food target.</p>

      <div className="mt-6 flex items-center justify-between"><h2 className="text-sm font-semibold text-muted">Last two weeks</h2>
        <button onClick={() => setSheet({ kind: 'log', date: today })} className="min-h-11 rounded-full bg-accent px-4 text-sm font-semibold text-on-accent">Log a session</button></div>
      {recent.length === 0 ? <p className="mt-2 text-sm text-muted">Nothing logged yet. Sessions you confirm or add from Today show up here.</p> : (
        <ul className="mt-1">{recent.map((s) => (
          <li key={s.key} className="flex min-h-14 items-center gap-2 border-b border-raised/70 text-sm">
            <button onClick={() => setSheet({ kind: 'log', date: s.date, row: s })} className="min-w-0 flex-1 py-2 text-left" aria-label={`Edit ${s.label} on ${longDate(s.date)}`}>
              <span className="block truncate font-medium">{s.label}{s.status === 'skipped' ? <span className="ml-1 font-normal text-muted">(skipped)</span> : null}</span>
              <span className="block text-xs text-muted tabular-nums">{longDate(s.date)} · {s.minutes} min · ≈ {Math.round(s.netKcal)} kcal{s.override ? ' (watch)' : ` (${Math.round(s.low)}–${Math.round(s.high)})`}</span>
            </button>
            <button aria-label={`Delete ${s.label} on ${longDate(s.date)}`} onClick={() => void api(`/workouts/${s.id}`, { method: 'DELETE' }).then(reload).catch((e: Error) => setError(e.message))} className="size-11 text-muted">✕</button>
          </li>))}</ul>
      )}
      <Link href="/plan" className="mt-6 block text-center text-sm text-muted underline">See the week's plan</Link>

      {sheet ? <SessionSheet mode={sheet} catalogue={catalogue} kg={kg} onClose={() => setSheet(null)} onSaved={() => { setSheet(null); reload() }} /> : null}
    </AppLayout>
  )
}
