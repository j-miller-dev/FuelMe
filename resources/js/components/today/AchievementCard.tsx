import { CalendarCheck, Medal, Moon, Trophy, TrendingUp, Utensils, X, type LucideIcon } from 'lucide-react'
import { useRef, useState } from 'react'
import { api } from '@/lib/http'

export interface Achievement { key: string; kind: string; icon: string; title: string; detail: string; date: string; rank: number }

const ICONS: Record<string, LucideIcon> = { trophy: Trophy, 'trending-up': TrendingUp, medal: Medal, 'calendar-check': CalendarCheck, utensils: Utensils, moon: Moon }
const THRESHOLD = 100

/**
 * Something worth a pause: a personal best, a volume best, a milestone, or a good last week. Swipe it sideways (or press the X) to clear it; it
 * stays cleared. Only real wins ever show, so a quiet day shows nothing, and there is no streak and no "you missed" version of this card.
 */
export function AchievementCard({ items }: { items: Achievement[] }) {
  const [gone, setGone] = useState<string[]>([])
  const [dx, setDx] = useState(0)
  const [drag, setDrag] = useState(false)
  const from = useRef<number | null>(null)
  const shown = items.filter((i) => !gone.includes(i.key))
  if (shown.length === 0) return null
  const [hero, ...rest] = shown
  const Icon = ICONS[hero!.icon] ?? Trophy

  const dismiss = (dir: number) => {
    const keys = shown.map((i) => i.key)
    setDrag(false)
    setDx(dir * 600)
    window.setTimeout(() => { setGone((g) => [...g, ...keys]); setDx(0) }, 200)
    void api('/achievements/dismiss', { method: 'POST', body: { keys } }).catch(() => undefined)   // offline: hidden now, may come back on reload
  }

  return (
    <section
      aria-label="Achievements" role="group"
      onPointerDown={(e) => { if ((e.target as HTMLElement).closest('button')) return; from.current = e.clientX; setDrag(true); e.currentTarget.setPointerCapture(e.pointerId) }}
      onPointerMove={(e) => { if (from.current !== null) setDx(e.clientX - from.current) }}
      onPointerUp={() => { const d = dx; from.current = null; if (Math.abs(d) > THRESHOLD) dismiss(d > 0 ? 1 : -1); else { setDrag(false); setDx(0) } }}
      onPointerCancel={() => { from.current = null; setDrag(false); setDx(0) }}
      style={{ transform: `translateX(${dx}px) rotate(${dx / 60}deg)`, opacity: Math.max(0.2, 1 - Math.abs(dx) / 400), touchAction: 'pan-y', background: 'linear-gradient(135deg, color-mix(in srgb, var(--color-accent) 30%, var(--color-surface)), var(--color-surface) 75%)' }}
      className={`relative mt-3 select-none rounded-2xl p-4 ring-1 ring-accent/40 ${drag ? '' : 'motion-safe:transition-[transform,opacity] motion-safe:duration-200'}`}
    >
      <button type="button" onClick={() => dismiss(1)} aria-label="Dismiss achievements" className="absolute right-1 top-1 flex size-11 items-center justify-center text-muted"><X className="size-4" aria-hidden /></button>
      <div className="flex items-center gap-3 pr-8">
        <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-accent text-on-accent"><Icon className="size-6" aria-hidden /></span>
        <div className="min-w-0">
          <p className="text-xs font-medium uppercase tracking-wide text-accent">{hero!.kind === 'week' ? 'Last week' : hero!.kind === 'milestone' ? 'Milestone' : 'Nice one'}</p>
          <h2 className="text-lg font-semibold leading-snug">{hero!.title}</h2>
        </div>
      </div>
      <p className="mt-2 text-sm">{hero!.detail}</p>
      {rest.length ? (
        <ul className="mt-3 space-y-1 border-t border-accent/25 pt-3 text-sm">
          {rest.map((r) => { const I = ICONS[r.icon] ?? Trophy; return <li key={r.key} className="flex items-start gap-2"><I className="mt-0.5 size-4 shrink-0 text-accent" aria-hidden /><span><span className="font-medium">{r.title}</span><span className="text-muted"> · {r.detail}</span></span></li> })}
        </ul>
      ) : null}
      <p className="mt-3 text-xs text-muted">Swipe sideways to clear</p>
    </section>
  )
}
