import { Link } from '@inertiajs/react'
import { WatchGlance, type Glance } from '@/components/today/WatchGlance'

export interface RecoveryCompact { level: 'green' | 'amber' | 'red' | 'unknown'; headline: string; reasons: string[]; deload: boolean; glance?: Glance }
const TONE = { green: 'bg-accent', amber: 'bg-carbs', red: 'bg-fat', unknown: 'bg-muted' } as const

/** A small recovery line on Today: the signal and up to two reasons, linking to the full page. A suggestion, never an instruction. */
export function RecoveryCard({ r }: { r: RecoveryCompact }) {
  return (
    <>
    <Link href="/recovery" className="mt-3 block rounded-2xl bg-surface p-4 text-sm" aria-label="Recovery">
      <div className="flex items-center justify-between">
        <span className="flex items-center gap-2 font-medium"><span className={`inline-block h-2.5 w-2.5 rounded-full ${TONE[r.level]}`} aria-hidden />{r.headline}</span>
        <span className="text-xs text-muted">{r.deload ? 'Deload week · ' : ''}Recovery ›</span>
      </div>
      {r.reasons.length ? <ul className="mt-1 list-disc pl-5 text-xs text-muted">{r.reasons.map((x) => <li key={x}>{x}</li>)}</ul> : null}
    </Link>
    {r.glance ? <WatchGlance g={r.glance} /> : null}
    </>
  )
}
