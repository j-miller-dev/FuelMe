import { Head, Link, usePage } from '@inertiajs/react'
import AppLayout from '@/layouts/AppLayout'
import { energyLabel, fmtEnergy, type EnergyUnit } from '@/lib/units'
import { cn } from '@/lib/utils'

interface Day { class: string; kcal: number; protein: number; fat: number; carbs: number; carbGPerKg: number; netKcal: number; energyAvailability: number }
interface Warning { level: 'info' | 'warn' | 'critical'; code: string; msg: string }
interface Props {
  target: { effectiveFrom: string; reason: string; kcal: number; protein: number; fat: number; carbs: number }
  goal: { phase: string; ratePctPerWeek: number }
  prior: { tdee: number; sd: number; rmr: number; pal: number; exerciseKcalPerDay: number }
  days: Record<string, Day>
  weekly: { kcalAvg: number; protein: number; fat: number; carbsAvg: number; proteinGPerKg: number; avgExerciseKcal: number }
  warnings: Warning[]
  why: string[]
  weightKg: number
  style: string
  todayKey: string
}

const ORDER = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'] as const
const PHASE: Record<string, string> = { bulk: 'Lean bulk', maintain: 'Maintain', cut: 'Cut' }
const CLASS_LABEL: Record<string, string> = { rest: 'Rest', light: 'Light', moderate: 'Moderate', high: 'High', very_high: 'Very high' }

function Macro({ label, grams, color }: { label: string; grams: number; color: string }) {
  return (
    <div className="flex-1 rounded-xl bg-surface p-3">
      <div className="text-xs text-muted">{label}</div>
      <div className="text-xl font-semibold">{grams}<span className="text-sm text-muted"> g</span></div>
      <div className={cn('mt-2 h-1.5 rounded-full')} style={{ background: color }} />
    </div>
  )
}

export default function Plan(p: Props) {
  const unit = (usePage().props as { energyUnit?: EnergyUnit }).energyUnit ?? 'kcal'
  const e = (kcal: number) => fmtEnergy(kcal, unit)
  const u = energyLabel(unit)
  const rate = p.goal.ratePctPerWeek

  return (
    <AppLayout>
      <Head title="Plan" />
      <header className="mb-6">
        <div className="text-sm text-muted">{PHASE[p.goal.phase] ?? p.goal.phase} · {rate === 0 ? 'hold weight' : `${rate > 0 ? '+' : '−'}${Math.abs(rate).toFixed(2)}% bodyweight/week`}</div>
        <div className="mt-1 text-6xl font-bold tracking-tight" aria-label={`Weekly average target ${e(p.weekly.kcalAvg)} ${u} per day`}>{e(p.weekly.kcalAvg)}</div>
        <div className="text-muted">{u} per day, weekly average</div>
      </header>

      <div className="mb-6 flex gap-2">
        <Macro label="Protein" grams={p.weekly.protein} color="var(--protein)" />
        <Macro label="Carbs (avg)" grams={p.weekly.carbsAvg} color="var(--carbs)" />
        <Macro label="Fat" grams={p.weekly.fat} color="var(--fat)" />
      </div>

      <section className="mb-6 rounded-2xl bg-surface p-4" aria-labelledby="calib">
        <div className="flex items-center justify-between">
          <h2 id="calib" className="font-semibold">Your expenditure</h2>
          <span className="rounded-full bg-accent/15 px-3 py-1 text-xs font-medium text-accent">Calibrating</span>
        </div>
        <div className="mt-2 text-3xl font-semibold">{e(p.prior.tdee)} <span className="text-base font-normal text-muted">± {e(p.prior.sd)} {u}/day</span></div>
        <p className="mt-2 text-sm text-muted">A starting estimate from your height, weight, age, job and training. It is a guess until your own weigh-ins and food logs refine it, so treat it as a range, not a fact.</p>
      </section>

      {p.warnings.length > 0 && (
        <ul className="mb-6 space-y-2" aria-label="Notes">
          {p.warnings.map((w, i) => (
            <li key={`${w.code}${i}`} className={cn('rounded-xl px-4 py-3 text-sm', w.level === 'critical' ? 'bg-danger/15 text-danger' : 'bg-surface text-muted')}>{w.msg}</li>
          ))}
        </ul>
      )}

      <section className="mb-6" aria-labelledby="week">
        <h2 id="week" className="mb-2 font-semibold">This week by training day</h2>
        <ul className="space-y-1.5">
          {ORDER.map((k) => {
            const d = p.days[k]
            if (!d) return null
            const today = k === p.todayKey
            return (
              <li key={k} className={cn('rounded-xl bg-surface px-4 py-3', today && 'ring-1 ring-accent')} aria-current={today ? 'date' : undefined}>
                <div className="flex items-baseline justify-between">
                  <span className="font-medium uppercase">{k}{today ? <span className="ml-2 text-xs normal-case text-accent">today</span> : null}</span>
                  <span className="text-lg font-semibold">{e(d.kcal)} <span className="text-xs font-normal text-muted">{u}</span></span>
                </div>
                <div className="mt-1 flex justify-between text-xs text-muted">
                  <span>{CLASS_LABEL[d.class] ?? d.class} day</span>
                  <span><span style={{ color: 'var(--protein)' }}>P {d.protein}</span> · <span style={{ color: 'var(--carbs)' }}>C {d.carbs}</span> · <span style={{ color: 'var(--fat)' }}>F {d.fat}</span> g</span>
                </div>
              </li>
            )
          })}
        </ul>
        <p className="mt-2 text-xs text-muted">Based on a default training week ({p.style === 'hit' ? 'low-volume lifting' : 'higher-volume lifting'}, BJJ and conditioning) until you set your own schedule. Exercise is not added on top of these numbers.</p>
      </section>

      <details className="mb-6 rounded-2xl bg-surface p-4">
        <summary className="min-h-8 cursor-pointer font-semibold">Why these numbers</summary>
        <ol className="mt-3 list-decimal space-y-2 pl-5 text-sm text-muted">
          {p.why.map((line, i) => <li key={i}>{line}</li>)}
        </ol>
      </details>

      <Link href="/training" className="mb-2 block min-h-11 rounded-2xl bg-surface px-4 py-3 text-center text-sm text-accent">Edit your training week</Link>
      <p className="text-xs text-muted">Estimates for planning, not medical advice. <Link href="/profile" className="text-accent underline">Edit your details</Link> to recompute.</p>
    </AppLayout>
  )
}
