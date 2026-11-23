import { Link } from '@inertiajs/react'
import { CalendarCheck } from 'lucide-react'
import { useState } from 'react'
import { cn } from '@/lib/utils'

type Hits = { kcal: boolean; protein: boolean; carbs: boolean; fat: boolean }
export interface StripDay { date: string; logged: boolean; hits: Hits | null; eaten: Record<keyof Hits, number>; target: Record<keyof Hits, number> }
export interface Strip { days: StripDay[]; summary: { logged: number; onTarget: number; total: number } }

const DOTS: { k: keyof Hits; label: string; colour: string }[] = [
  { k: 'kcal', label: 'Energy', colour: 'var(--accent)' },
  { k: 'protein', label: 'Protein', colour: 'var(--protein)' },
  { k: 'carbs', label: 'Carbs', colour: 'var(--carbs)' },
  { k: 'fat', label: 'Fat', colour: 'var(--fat)' },
]
const dow = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { weekday: 'short', timeZone: 'UTC' })
const unitOf = (k: keyof Hits, energy: string) => (k === 'kcal' ? energy : 'g')

/** The last few finished days at a glance. Filled dot = on target, hollow = missed, dashed = not enough logged to judge. No streak counter on purpose. */
export function HitStrip({ strip, energyLabel, fmt }: { strip: Strip; energyLabel: string; fmt: (kcal: number) => string }) {
  const [open, setOpen] = useState<string | null>(null)
  if (strip.days.length === 0) return null
  const { logged, onTarget } = strip.summary
  const day = strip.days.find((d) => d.date === open)

  return (
    <section className="mt-3 rounded-2xl bg-surface p-3" aria-label="Last days against target">
      <div className="mb-2 flex items-baseline justify-between">
        <h2 className="flex items-center gap-2 text-sm font-semibold"><CalendarCheck aria-hidden size={16} className="text-accent" />Last {strip.days.length} days</h2>
        <span className="text-xs text-muted">{logged === 0 ? 'Nothing logged yet' : `${onTarget} of ${logged} logged: energy and protein on target`}</span>
      </div>
      <ul className="flex justify-between">
        {strip.days.map((d) => (
          <li key={d.date}>
            <button type="button" onClick={() => setOpen(open === d.date ? null : d.date)} aria-pressed={open === d.date}
              aria-label={`${dow(d.date)}: ${d.logged ? DOTS.map((x) => `${x.label} ${d.hits![x.k] ? 'on target' : 'missed'}`).join(', ') : 'not enough logged'}`}
              className={cn('flex min-h-11 w-12 flex-col items-center gap-1 rounded-xl py-1.5', open === d.date && 'bg-raised')}>
              <span className="text-xs text-muted">{dow(d.date)}</span>
              <span className="grid grid-cols-2 gap-1">
                {DOTS.map((x) => (
                  <span key={x.k} className="size-3 rounded-full border"
                    style={d.logged ? { borderColor: x.colour, background: d.hits![x.k] ? x.colour : 'transparent' } : { borderColor: 'var(--muted)', borderStyle: 'dashed', opacity: 0.5 }} />
                ))}
              </span>
            </button>
          </li>
        ))}
      </ul>
      {day ? (
        <div className="mt-2 rounded-xl bg-canvas/60 p-3 text-sm">
          {!day.logged ? <p className="text-muted">Not enough logged on {dow(day.date)} to judge, so it is not counted as a miss.</p> : (
            <dl className="space-y-1">
              {DOTS.map((x) => (
                <div key={x.k} className="flex justify-between">
                  <dt style={{ color: x.colour }}>{x.label}</dt>
                  <dd><span className={day.hits![x.k] ? 'text-ink' : 'text-muted'}>{x.k === 'kcal' ? fmt(day.eaten.kcal) : day.eaten[x.k]}</span><span className="text-muted"> / {x.k === 'kcal' ? fmt(day.target.kcal) : day.target[x.k]} {unitOf(x.k, energyLabel)}</span> <span aria-hidden>{day.hits![x.k] ? '✓' : '·'}</span></dd>
                </div>
              ))}
            </dl>
          )}
        </div>
      ) : null}
      <p className="mt-2 text-xs text-muted">Carbs and fat show as dots but are not in the count. <Link href="/targets" className="text-accent underline">Change what counts</Link></p>
    </section>
  )
}
