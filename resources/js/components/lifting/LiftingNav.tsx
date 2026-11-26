import { Link } from '@inertiajs/react'
import { Dumbbell, History, ListChecks, Trophy, TrendingUp, CalendarDays } from 'lucide-react'

const ITEMS = [
  { key: 'lifting', href: '/lifting', label: 'Overview', hint: 'Sync and your lifts', Icon: Dumbbell },
  { key: 'sessions', href: '/lifting/sessions', label: 'Sessions', hint: 'Every workout', Icon: History },
  { key: 'progression', href: '/lifting/progression', label: 'Next time', hint: 'What to lift next', Icon: TrendingUp },
  { key: 'routines', href: '/lifting/routines', label: 'Routines', hint: 'Your Hevy plan', Icon: ListChecks },
  { key: 'best', href: '/lifting/best', label: 'Best lifts', hint: 'Records and notes', Icon: Trophy },
  { key: 'training', href: '/training', label: 'Week', hint: 'Training plan', Icon: CalendarDays },
] as const

export type LiftingNavKey = (typeof ITEMS)[number]['key']

/** Tiles for the lifting pages: icon, name and what is behind it. The current page is filled in. */
export default function LiftingNav({ current }: { current: LiftingNavKey }) {
  return (
    <nav aria-label="Lifting" className="mt-3 grid grid-cols-3 gap-2">
      {ITEMS.map(({ key, href, label, hint, Icon }) => {
        const on = key === current
        return (
          <Link key={key} href={href} prefetch aria-current={on ? 'page' : undefined} className={`flex min-h-[4.5rem] flex-col items-start justify-center gap-0.5 rounded-2xl px-3 py-2 ${on ? 'bg-accent text-on-accent' : 'bg-surface active:bg-raised'}`}>
            <Icon className={`size-5 ${on ? '' : 'text-accent'}`} aria-hidden />
            <span className="text-sm font-medium leading-tight">{label}</span>
            <span className={`text-[11px] leading-tight ${on ? 'opacity-80' : 'text-muted'}`}>{hint}</span>
          </Link>
        )
      })}
    </nav>
  )
}
