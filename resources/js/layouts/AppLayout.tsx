import { Link, usePage } from '@inertiajs/react'
import type { ReactNode } from 'react'
import { OutboxStatus } from '@/components/OutboxStatus'
import { cn } from '@/lib/utils'

const tabs = [
  { href: '/', label: 'Today' },
  { href: '/progress', label: 'Progress' },
  { href: '/plan', label: 'Plan' },
  { href: '/profile', label: 'Profile' },
  { href: '/settings/security', label: 'Security' },
] as const

export default function AppLayout({ children }: { children: ReactNode }) {
  const { url } = usePage()
  const path = url.split('?')[0]
  return (
    <div className="mx-auto flex min-h-dvh max-w-md flex-col">
      <OutboxStatus />
      <main className="flex-1 px-4 pb-40 pt-6">{children}</main>
      <nav aria-label="Main" className="fixed inset-x-0 bottom-0 z-10 border-t border-raised bg-canvas/95 backdrop-blur" style={{ paddingBottom: 'env(safe-area-inset-bottom)' }}>
        <ul className="mx-auto flex max-w-md">
          {tabs.map((t) => (
            <li key={t.href} className="flex-1">
              <Link href={t.href} aria-current={path === t.href ? 'page' : undefined}
                className={cn('flex min-h-14 items-center justify-center text-sm font-medium', path === t.href ? 'text-accent' : 'text-muted')}>
                {t.label}
              </Link>
            </li>
          ))}
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
