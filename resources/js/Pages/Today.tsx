import { Head, Link, usePage } from '@inertiajs/react'
import { useState } from 'react'
import AppLayout from '@/layouts/AppLayout'
import { AddSheet } from '@/components/today/AddSheet'
import { PortionSheet } from '@/components/today/PortionSheet'
import { CalorieRing, MacroRings } from '@/components/today/Rings'
import { SLOTS, SLOT_LABEL, slotForHour, totals, type LogRow, type Slot } from '@/lib/food'
import { useLogging } from '@/lib/useLogging'
import { energyLabel, fmtEnergy, type EnergyUnit } from '@/lib/units'

interface Props {
  date: string; isToday: boolean; dayClass: string | null
  target: { kcal: number; protein: number; carbs: number; fat: number }
  logs: LogRow[]; previous: { date: string; count: number; kcal: number }
  calibrating: boolean; serverHour: number; weighedToday: boolean
  nudge: { lowDays: number; loggedDays: number; gapKcal: number; gapProtein: number; adds: { name: string; grams: number; unit: string; kcal: number; protein: number }[] } | null
}

const CLASS_LABEL: Record<string, string> = { rest: 'Rest day', light: 'Light day', moderate: 'Moderate day', high: 'High day', very_high: 'Very high day' }
const shift = (ymd: string, d: number) => { const t = new Date(`${ymd}T12:00:00Z`); t.setUTCDate(t.getUTCDate() + d); return t.toISOString().slice(0, 10) }
const prettyDate = (ymd: string) => new Date(`${ymd}T12:00:00Z`).toLocaleDateString('en-AU', { weekday: 'long', day: 'numeric', month: 'short', timeZone: 'UTC' })

export default function Today(p: Props) {
  const unit = (usePage().props as { energyUnit?: EnergyUnit }).energyUnit ?? 'kcal'
  const { logs, toast, setToast, add, repeat, remove, update, copy } = useLogging(p.logs, p.date)
  const [adding, setAdding] = useState(false)
  const [editing, setEditing] = useState<LogRow | null>(null)
  const [slot, setSlot] = useState<Slot>(() => slotForHour(p.serverHour))
  const t = totals(logs)
  const u = energyLabel(unit)

  const bySlot = SLOTS.map((s) => ({ s, rows: logs.filter((l) => l.meal_slot === s), kcal: logs.filter((l) => l.meal_slot === s).reduce((a, l) => a + l.kcal, 0) })).filter((g) => g.rows.length)

  return (
    <AppLayout>
      <Head title="Today" />
      <header className="flex items-center justify-between">
        <div className="flex items-center gap-1">
          <Link href={`/?date=${shift(p.date, -1)}`} preserveScroll aria-label="Previous day" className="grid size-11 place-items-center rounded-full bg-surface">‹</Link>
          <div className="px-2">
            <h1 className="text-lg font-semibold leading-tight">{p.isToday ? 'Today' : prettyDate(p.date)}</h1>
            {p.isToday ? <div className="text-xs text-muted">{prettyDate(p.date)}</div> : null}
          </div>
          {!p.isToday ? <Link href={`/?date=${shift(p.date, 1)}`} aria-label="Next day" className="grid size-11 place-items-center rounded-full bg-surface">›</Link> : null}
        </div>
        {p.dayClass ? <span className="rounded-full bg-raised px-3 py-1 text-sm text-accent">{CLASS_LABEL[p.dayClass] ?? p.dayClass}</span> : null}
      </header>

      <div className="mt-3"><CalorieRing eaten={t.kcal} target={p.target.kcal} unit={unit} calibrating={p.calibrating} /></div>
      {p.calibrating ? (
        <div className="mt-2 rounded-2xl bg-surface p-3 text-sm"><span className="text-carbs">Calibrating.</span> <span className="text-muted">Targets are a starting estimate until your weigh-ins and food logs refine your expenditure.</span></div>
      ) : null}
      {p.isToday && !p.weighedToday ? <Link href="/progress" className="mt-2 flex min-h-11 items-center justify-between rounded-2xl bg-surface px-4 text-sm"><span>Weigh in today</span><span className="text-accent" aria-hidden>›</span></Link> : null}
      {p.nudge ? (
        <Link href="/progress" className="mt-2 block rounded-2xl bg-surface p-3 text-sm">
          <span className="font-medium">A little under target lately.</span>{' '}
          <span className="text-muted">{p.nudge.adds.length ? `Easy adds from your usual foods: ${p.nudge.adds.map((a) => a.name).join(', ')}.` : 'Tap for ideas.'}</span>
        </Link>
      ) : null}
      <MacroRings eaten={t} target={p.target} />

      {bySlot.length === 0 ? (
        <section className="mt-4 rounded-2xl bg-surface p-5 text-center">
          <p className="font-medium">Nothing logged {p.isToday ? 'yet' : 'this day'}</p>
          <p className="mt-1 text-sm text-muted">Tap the + button to search, or start from yesterday.</p>
          {p.previous.count > 0 ? (
            <button onClick={() => void copy(p.previous.date)} className="mt-3 min-h-11 rounded-full bg-raised px-5 text-sm font-medium text-accent">
              Copy yesterday · {fmtEnergy(p.previous.kcal, unit)} {u}
            </button>
          ) : null}
        </section>
      ) : (
        <div className="mt-4 space-y-3">
          {bySlot.map(({ s, rows, kcal }) => (
            <section key={s} className="rounded-2xl bg-surface p-3" aria-label={SLOT_LABEL[s]}>
              <div className="flex items-baseline justify-between px-1">
                <h2 className="text-sm font-semibold">{SLOT_LABEL[s]}</h2>
                <span className="text-sm text-muted tabular-nums">{fmtEnergy(kcal, unit)} {u}</span>
              </div>
              <ul className="mt-1">
                {rows.map((l) => (
                  <li key={l.id} className="flex min-h-14 items-center gap-1 border-t border-raised/70 first:border-t-0">
                    <button onClick={() => l.id > 0 && setEditing(l)} className="min-w-0 flex-1 py-2 pl-1 text-left" aria-label={`Edit ${l.name}`}>
                      <div className="truncate text-sm">{l.name}</div>
                      <div className="truncate text-xs text-muted tabular-nums">{l.grams ? `${Math.round(l.grams)} g · ` : ''}{Math.round(l.protein)}P {Math.round(l.carbs)}C {Math.round(l.fat)}F</div>
                    </button>
                    <span className="text-sm tabular-nums">{fmtEnergy(l.kcal, unit)}</span>
                    <button onClick={() => l.id > 0 && void repeat(l)} aria-label={`Add ${l.name} again`} className="grid size-11 place-items-center rounded-full bg-raised text-lg text-accent active:scale-90">+</button>
                  </li>
                ))}
              </ul>
            </section>
          ))}
          {p.previous.count > 0 && p.isToday ? (
            <button onClick={() => void copy(p.previous.date)} className="min-h-11 w-full rounded-2xl bg-surface text-sm text-muted">Copy yesterday</button>
          ) : null}
        </div>
      )}

      <button onClick={() => setAdding(true)} aria-label="Add food"
        className="fixed left-1/2 z-20 grid size-14 -translate-x-1/2 place-items-center rounded-full bg-accent text-3xl text-on-accent shadow-lg shadow-black/40 active:scale-95"
        style={{ bottom: 'calc(4rem + env(safe-area-inset-bottom))' }}>+</button>

      {toast ? (
        <div role={toast.error ? 'alert' : 'status'} className="fixed inset-x-4 z-30 mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-raised px-4 py-3 text-sm shadow-lg"
          style={{ bottom: 'calc(8rem + env(safe-area-inset-bottom))' }}>
          <span className={`min-w-0 flex-1 truncate ${toast.error ? 'text-danger' : ''}`}>{toast.msg}</span>
          {toast.undo ? <button onClick={toast.undo} className="min-h-9 shrink-0 font-semibold text-accent">Undo</button> : <button onClick={() => setToast(null)} className="min-h-9 shrink-0 text-muted">Dismiss</button>}
        </div>
      ) : null}

      {adding ? (
        <AddSheet slot={slot} setSlot={setSlot} eaten={t.kcal} target={p.target.kcal} unit={unit} previous={p.previous} onAdd={add}
          onCopy={copy} onClose={() => setAdding(false)} />
      ) : null}

      {editing ? (
        <PortionSheet title={editing.name} subtitle={editing.grams ? undefined : 'Quick add: only the meal can change'} mode="edit"
          per100={editing.grams ? { kcal: (editing.kcal / editing.grams) * 100, protein: (editing.protein / editing.grams) * 100, carbs: (editing.carbs / editing.grams) * 100, fat: (editing.fat / editing.grams) * 100, fibre: null } : { kcal: 0, protein: 0, carbs: 0, fat: 0, fibre: null }}
          unit="g" servings={[]} initialGrams={editing.grams ?? 1} initialSlot={editing.meal_slot}
          onClose={() => setEditing(null)}
          onRemove={() => { void remove(editing); setEditing(null) }}
          onSubmit={(r) => { void update(editing, editing.grams ? r : { ...r, grams: 1 }); setEditing(null) }} />
      ) : null}
    </AppLayout>
  )
}
