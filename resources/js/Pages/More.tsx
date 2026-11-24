import { Head, Link } from '@inertiajs/react'
import { Bell, BookOpen, CalendarRange, ChevronRight, ClipboardCheck, Database, Dumbbell, HeartPulse, Info, ListChecks, ListTodo, type LucideIcon, MessageCircle, ShieldCheck, ShoppingCart, Target, Timer, TrendingUp, Trophy, User, Utensils, Watch } from 'lucide-react'
import AppLayout from '@/layouts/AppLayout'

interface Item { href: string; label: string; hint: string; Icon: LucideIcon }
const groups: { title: string; items: Item[] }[] = [
  {
    title: 'Coach and review',
    items: [
      { href: '/coach', label: 'Ask the coach', hint: 'Questions about your own numbers', Icon: MessageCircle },
      { href: '/recovery', label: 'Recovery and deload', hint: 'Sleep, HRV, load and deload weeks', Icon: HeartPulse },
      { href: '/watch', label: 'Watch data', hint: 'Intervals.icu sync', Icon: Watch },
      { href: '/review', label: 'Weekly review', hint: 'Last week, patterns, event mode', Icon: ClipboardCheck },
      { href: '/principles', label: 'My notes', hint: 'Principles from your books and mentors', Icon: BookOpen },
    ],
  },
  {
    title: 'Plan',
    items: [
      { href: '/plan', label: 'Week plan', hint: 'Each day’s calories and macros', Icon: CalendarRange },
      { href: '/targets', label: 'My targets', hint: 'Adjust protein, fat and calories', Icon: Target },
      { href: '/timer', label: 'Interval timer', hint: 'HIIT, Norwegian 4x4, jump rope, BJJ rounds', Icon: Timer },
      { href: '/training', label: 'Training week', hint: 'Your usual sessions', Icon: Dumbbell },
      { href: '/lifting', label: 'Lifting (Hevy)', hint: 'Sync, lifts, effort plan', Icon: Dumbbell },
      { href: '/lifting/progression', label: 'Progression', hint: 'What to lift next, and projections', Icon: TrendingUp },
      { href: '/lifting/routines', label: 'Routines', hint: 'Your Hevy routines with last numbers', Icon: ListChecks },
      { href: '/lifting/best', label: 'Best lifts', hint: 'Records, notes and cues per exercise', Icon: Trophy },
    ],
  },
  {
    title: 'Food',
    items: [
      { href: '/meals', label: 'Meals and recipes', hint: 'Create, import, meal prep', Icon: Utensils },
      { href: '/shopping', label: 'Shopping lists', hint: 'Built from your recipes', Icon: ShoppingCart },
      { href: '/foods', label: 'Your foods', hint: 'Foods you created', Icon: Utensils },
    ],
  },
  {
    title: 'You',
    items: [
      { href: '/profile', label: 'Profile', hint: 'Body, job, lifting style', Icon: User },
      { href: '/settings/notifications', label: 'Notifications', hint: 'Reminders on your phone', Icon: Bell },
      { href: '/settings/todoist', label: 'Todoist', hint: 'Export shopping lists', Icon: ListTodo },
      { href: '/settings/data', label: 'Your data', hint: 'Backups, exports, imports', Icon: Database },
      { href: '/settings/security', label: 'Security', hint: 'Passkeys and sign-in', Icon: ShieldCheck },
      { href: '/settings/about', label: 'About and data sources', hint: 'Licences and attributions', Icon: Info },
    ],
  },
]

export default function More() {
  return (
    <AppLayout>
      <Head title="More" />
      <h1 className="text-2xl font-bold tracking-tight">More</h1>
      {groups.map((g) => (
        <section key={g.title} className="mt-6" aria-label={g.title}>
          <h2 className="mb-2 px-1 text-xs font-semibold uppercase tracking-wide text-muted">{g.title}</h2>
          <ul className="overflow-hidden rounded-2xl bg-surface">
            {g.items.map(({ href, label, hint, Icon }, i) => (
              <li key={href} className={i > 0 ? 'border-t border-raised/70' : ''}>
                <Link href={href} prefetch="click" className="flex min-h-16 items-center gap-3 px-4 active:bg-raised">
                  <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-accent/12 text-accent"><Icon aria-hidden size={20} strokeWidth={2} /></span>
                  <span className="min-w-0 flex-1"><span className="block text-[15px] font-medium">{label}</span><span className="block truncate text-xs text-muted">{hint}</span></span>
                  <ChevronRight aria-hidden size={18} className="shrink-0 text-muted" />
                </Link>
              </li>
            ))}
          </ul>
        </section>
      ))}
    </AppLayout>
  )
}
