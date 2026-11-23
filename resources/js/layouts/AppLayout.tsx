import { Link, usePage } from '@inertiajs/react'
import { Dumbbell, Flame, LineChart, type LucideIcon, Menu, Utensils } from 'lucide-react'
import type { ReactNode } from 'react'
import { OutboxStatus } from '@/components/OutboxStatus'
import { cn } from '@/lib/utils'

/** `match` lists every path that should light the tab, so sub-pages (Lifting, Review, Shopping...) keep their parent tab active. */
const tabs: { href: string; label: string; Icon: LucideIcon; match: string[] }[] = [
  { href: '/', label: 'Today', Icon: Flame, match: ['/'] },
  { href: '/training', label: 'Training', Icon: Dumbbell, match: ['/training', '/lifting', '/timer'] },
  { href: '/progress', label: 'Progress', Icon: LineChart, match: ['/progress', '/review', '/recovery', '/watch'] },
  { href: '/meals', label: 'Food', Icon: Utensils, match: ['/meals', '/foods', '/shopping'] },
  { href: '/more', label: 'More', Icon: Menu, match: ['/more', '/plan', '/targets', '/profile', '/coach', '/principles', '/settings'] },
]

const isActive = (path: string, match: string[]) => match.some((m) => (m === '/' ? path === '/' : path === m || path.startsWith(m + '/')))

export default function AppLayout({ children }: { children: ReactNode }) {
  const { url } = usePage()
  const path = url.split('?')[0] ?? ''
  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col">
      <OutboxStatus />
      <main key={path} className="page-in flex-1 px-4 pb-44 pt-6">{children}</main>
      <nav aria-label="Main" className="fixed inset-x-0 bottom-0 z-10 border-t border-raised/80 bg-canvas/90 backdrop-blur-xl" style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}>
        <ul className="mx-auto flex max-w-md px-1 pt-1">
          {tabs.map(({ href, label, Icon, match }) => {
            const on = isActive(path, match)
            return (
              <li key={href} className="flex-1">
                <Link href={href} prefetch="click" aria-current={on ? 'page' : undefined} onClick={() => navigator.vibrate?.(6)}
                  className={cn('group flex min-h-16 flex-col items-center justify-center gap-1 text-[11px] font-medium transition-colors', on ? 'text-accent' : 'text-muted active:text-ink')}>
                  <span className={cn('grid h-8 w-14 place-items-center rounded-full transition-all duration-200', on ? 'bg-accent/15' : 'group-active:bg-raised')}>
                    <Icon aria-hidden size={21} strokeWidth={on ? 2.4 : 1.8} />
                  </span>
                  {label}
                </Link>
              </li>
            )
          })}
        </ul>
      </nav>
    </div>
  )
}

export function Guest({ title, children }: { title: string; children: ReactNode }) {
  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col justify-center px-4 py-10">
      <div className="mb-8 text-center">
        <div className="text-3xl font-bold tracking-tight text-accent">FUEL</div>
        <h1 className="mt-2 text-lg text-muted">{title}</h1>
      </div>
      {children}
    </div>
  )
}
